<?php

namespace app\service;

use app\model\CategoryChannel;
use app\model\Channel;
use app\model\ChannelModel;
use app\model\Group;
use app\model\Log;
use app\model\Model_;
use app\model\ModelPricing;
use app\model\Option;
use app\model\Token;
use app\model\User;
use app\model\Wallet;
use app\model\WalletTransaction;
use app\service\adapter\OpenAIAdapter;
use app\service\adapter\AnthropicAdapter;
use app\service\adapter\GeminiAdapter;
use app\service\ChannelRateLimiter;
use support\Db;
use support\Request;
use support\Response;
use Webman\RedisQueue\Client;
use Workerman\Protocols\Http\Chunk;

class RelayService
{
    const CHANNEL_TYPE_OPENAI = 1;
    const CHANNEL_TYPE_ANTHROPIC = 2;
    const CHANNEL_TYPE_GEMINI = 3;
    const CHANNEL_TYPE_DEEPSEEK = 4;
    const CHANNEL_TYPE_OLLAMA = 5;
    const CHANNEL_TYPE_OPENROUTER = 6;
    const CHANNEL_TYPE_CUSTOM = 99;

    /**
     * 处理chat completions请求
     */
    public static function handleChatCompletions(Request $request): Response
    {
        $startTime = microtime(true);
        $requestBody = json_decode($request->rawBody(), true);
        $clientFormat = $request->clientFormat ?? 'openai';

        if (empty($requestBody) || empty($requestBody['model'])) {
            return self::errorResponse('请求体无效或缺少model参数', 'invalid_request_error', 400, $clientFormat);
        }

        $modelName = $requestBody['model'];
        $isStream = !empty($requestBody['stream']);
        $userId = $request->user['id'] ?? 0;
        $tokenData = $request->tokenData ?? [];
        $tokenId = $tokenData['id'] ?? 0;
        $requestId = 'req-' . bin2hex(random_bytes(12));

        // 获取预扣费金额
        $preDeductAmount = (float)Option::getOption('pre_deduct_amount', '0');

        // 余额检查：钱包余额不足时拒绝请求
        $wallet = Wallet::where('user_id', $userId)->first();
        $minRequired = $preDeductAmount > 0 ? $preDeductAmount : 0;
        if (!$wallet || (float)$wallet->balance <= $minRequired) {
            return self::errorResponse('钱包余额不足，请充值', 'insufficient_balance', 402, $clientFormat);
        }

        // 预扣费：请求开始前先冻结预扣金额
        $preDeducted = false;
        if ($preDeductAmount > 0) {
            $preDeducted = self::preDeduct($userId, $preDeductAmount);
            if (!$preDeducted) {
                return self::errorResponse('钱包余额不足，无法预扣费', 'insufficient_balance', 402, $clientFormat);
            }
        }

        // 检查令牌消费额度
        $maxBudget = (float)($tokenData['max_budget'] ?? 0);
        $usedAmount = (float)($tokenData['used_amount'] ?? 0);
        if ($maxBudget > 0 && $usedAmount >= $maxBudget) {
            return self::errorResponse('令牌消费已达上限', 'token_budget_exceeded', 429, $clientFormat);
        }

        // 获取分类ID
        $categoryId = $request->categoryId ?? 0;

        // 选择渠道（根据分类路由或旧逻辑）
        $selected = self::selectChannel($modelName, $categoryId);

        // selectChannel 返回 null 表示无可用渠道
        if (!$selected) {
            if ($preDeducted) {
                self::refundPreDeduct($userId, $preDeductAmount);
            }
            return self::errorResponse("模型 {$modelName} 当前无可用渠道", 'model_not_available', 503, $clientFormat);
        }

        // selectChannel 返回带 error 键的数组表示有具体错误（如限流、分类无渠道）
        if (!empty($selected['error'])) {
            if ($preDeducted) {
                self::refundPreDeduct($userId, $preDeductAmount);
            }
            $httpCode = $selected['http_code'] ?? 503;
            return self::errorResponse($selected['error'], $selected['error_type'] ?? 'model_not_available', $httpCode, $clientFormat);
        }

        $channel = $selected['channel'];
        $channelModel = $selected['channel_model'] ?? null;
        $categoryChannel = $selected['category_channel'] ?? null;

        // 构建渠道配置
        $channelConfig = [
            'base_url' => $channel->base_url ?? '',
            'key' => $channel->key ?? '',
            'pass_through' => $channel->pass_through ?? 0,
            'model_mapping' => $channel->model_mapping ?? null,
            'channel_type' => (int) $channel->type,
        ];

        // 如果channel_model有自定义模型名，添加到映射
        if ($channelModel && !empty($channelModel->custom_model_name)) {
            $mapping = json_decode($channelConfig['model_mapping'] ?? '{}', true) ?: [];
            $mapping[$modelName] = $channelModel->custom_model_name;
            $channelConfig['model_mapping'] = json_encode($mapping);
        }

        // 将自定义价格信息附加到 tokenData 中，供计费队列使用
        if ($categoryChannel) {
            $tokenData['_custom_input_price'] = $categoryChannel->custom_input_price;
            $tokenData['_custom_output_price'] = $categoryChannel->custom_output_price;
            $tokenData['_category_channel_id'] = $categoryChannel->id;
        }

        if ($isStream) {
            return self::handleStreamRequest(
                $channel, $channelConfig, $requestBody, $modelName,
                $userId, $tokenId, $tokenData, $requestId, $startTime, $request, $clientFormat,
                $preDeducted ? $preDeductAmount : 0
            );
        }

        return self::handleNonStreamRequest(
            $channel, $channelConfig, $requestBody, $modelName,
            $userId, $tokenId, $tokenData, $requestId, $startTime, $request, $clientFormat,
            $preDeducted ? $preDeductAmount : 0
        );
    }

    /**
     * 处理非流式请求
     *
     * 格式转换矩阵：
     * - 客户端OpenAI + 上游OpenAI → 直接透传
     * - 客户端OpenAI + 上游Anthropic → AnthropicAdapter（OpenAI→Anthropic请求，Anthropic→OpenAI响应）
     * - 客户端Anthropic + 上游Anthropic → AnthropicAdapter直接透传
     * - 客户端Anthropic + 上游OpenAI → OpenAIAdapter（Anthropic→OpenAI请求），再转回Anthropic响应
     */
    protected static function handleNonStreamRequest(
        Channel $channel, array $channelConfig, array $requestBody, string $modelName,
        int $userId, int $tokenId, array $tokenData, string $requestId, float $startTime, Request $request,
        string $clientFormat = 'openai', float $preDeductAmount = 0
    ): Response {
        $channelType = (int) $channel->type;

        // 根据客户端格式和渠道类型选择处理方式
        if ($channelType === self::CHANNEL_TYPE_GEMINI) {
            // Gemini 上游：始终通过 GeminiAdapter（内部转换 OpenAI↔Gemini 格式）
            if ($clientFormat === 'anthropic') {
                $openAIBody = AnthropicAdapter::convertAnthropicToOpenAI($requestBody);
                $result = GeminiAdapter::chatCompletions($channelConfig, $openAIBody);
                if ($result['success']) {
                    $result['data'] = AnthropicAdapter::convertOpenAIResponseToAnthropic($result['data'], $modelName);
                }
            } else {
                $result = GeminiAdapter::chatCompletions($channelConfig, $requestBody);
            }
        } elseif ($clientFormat === 'anthropic' && $channelType === self::CHANNEL_TYPE_ANTHROPIC) {
            // Anthropic客户端 → Anthropic上游：直接透传，返回原始Anthropic格式
            $result = AnthropicAdapter::passThrough($channelConfig, $requestBody);
        } elseif ($clientFormat === 'anthropic' && $channelType !== self::CHANNEL_TYPE_ANTHROPIC) {
            // Anthropic客户端 → OpenAI兼容上游：先转为OpenAI格式请求，再转回Anthropic格式响应
            $openAIBody = AnthropicAdapter::convertAnthropicToOpenAI($requestBody);
            $result = OpenAIAdapter::chatCompletions($channelConfig, $openAIBody);
            if ($result['success']) {
                $result['data'] = AnthropicAdapter::convertOpenAIResponseToAnthropic($result['data'], $modelName);
            }
        } elseif ($clientFormat === 'openai' && $channelType === self::CHANNEL_TYPE_ANTHROPIC) {
            // OpenAI客户端 → Anthropic上游：转为Anthropic格式请求，再转回OpenAI格式响应
            $result = AnthropicAdapter::chatCompletions($channelConfig, $requestBody);
        } else {
            // OpenAI客户端 → OpenAI兼容上游（OpenAI/DeepSeek/Ollama/OpenRouter/Custom）：直接透传
            $result = OpenAIAdapter::chatCompletions($channelConfig, $requestBody);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        if (!$result['success']) {
            // 请求失败时退回预扣费
            if ($preDeductAmount > 0) {
                self::refundPreDeduct($userId, $preDeductAmount);
            }
            // 错误日志通过队列异步记录
            self::sendBillingToQueue(
                $userId, $tokenId, $channel->id, $modelName,
                0, 0, false, $duration,
                $tokenData, $channel, $request,
                Log::TYPE_ERROR, $result['error'] ?? '上游请求失败', $requestId
            );
            $httpCode = $result['http_code'] ?? 502;
            return self::errorResponse($result['error'] ?? '上游请求失败', 'upstream_error', $httpCode, $clientFormat);
        }

        $promptTokens = $result['prompt_tokens'];
        $completionTokens = $result['completion_tokens'];
        $cachedTokens = $result['cached_tokens'] ?? 0;
        $cacheCreationTokens = $result['cache_creation_tokens'] ?? 0;
        $cacheReadTokens = $result['cache_read_tokens'] ?? 0;

        // 扣费和日志通过Redis队列异步处理（传入预扣金额用于多退少补）
        self::sendBillingToQueue(
            $userId, $tokenId, $channel->id, $modelName,
            $promptTokens, $completionTokens, false, $duration,
            $tokenData, $channel, $request,
            Log::TYPE_CONSUME, '', $requestId, 0,
            $cachedTokens, $cacheCreationTokens, $cacheReadTokens,
            $preDeductAmount
        );

        return new Response(200, [
            'Content-Type' => 'application/json',
            'X-Request-Id' => $requestId,
        ], json_encode($result['data'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 处理流式请求
     */
    protected static function handleStreamRequest(
        Channel $channel, array $channelConfig, array $requestBody, string $modelName,
        int $userId, int $tokenId, array $tokenData, string $requestId, float $startTime, Request $request,
        string $clientFormat = 'openai', float $preDeductAmount = 0
    ): Response {
        $connection = $request->connection;
        $channelType = (int) $channel->type;

        // 先通过connection发送SSE响应头
        $headerResponse = response('')->withHeaders([
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Request-Id' => $requestId,
            'Transfer-Encoding' => 'chunked',
        ]);
        $connection->send($headerResponse);

        // 根据客户端格式和渠道类型选择流式处理方式
        if ($channelType === self::CHANNEL_TYPE_GEMINI) {
            // Gemini 上游：通过 GeminiAdapter 流式处理（Gemini SSE → OpenAI SSE）
            if ($clientFormat === 'anthropic') {
                // Anthropic客户端 → Gemini上游：先转OpenAI格式，再走Gemini流式，输出OpenAI SSE
                // 注意：此处输出的是OpenAI SSE格式，Anthropic客户端需要额外转换（暂不支持完美转换）
                $openAIBody = AnthropicAdapter::convertAnthropicToOpenAI($requestBody);
                $openAIBody['stream'] = true;
                $usage = GeminiAdapter::chatCompletionsStream($channelConfig, $openAIBody, $connection, $startTime);
            } else {
                $usage = GeminiAdapter::chatCompletionsStream($channelConfig, $requestBody, $connection, $startTime);
            }
        } elseif ($clientFormat === 'anthropic' && $channelType === self::CHANNEL_TYPE_ANTHROPIC) {
            // Anthropic客户端 → Anthropic上游：直接透传Anthropic SSE格式
            $usage = AnthropicAdapter::passThroughStream($channelConfig, $requestBody, $connection, $startTime);
        } elseif ($clientFormat === 'anthropic' && $channelType !== self::CHANNEL_TYPE_ANTHROPIC) {
            // Anthropic客户端 → OpenAI兼容上游：转换请求格式，将OpenAI SSE转为Anthropic SSE
            $openAIBody = AnthropicAdapter::convertAnthropicToOpenAI($requestBody);
            $openAIBody['stream'] = true;
            $usage = OpenAIAdapter::chatCompletionsStreamAsAnthropic($channelConfig, $openAIBody, $connection, $startTime, $modelName);
        } elseif ($clientFormat === 'openai' && $channelType === self::CHANNEL_TYPE_ANTHROPIC) {
            // OpenAI客户端 → Anthropic上游：转换请求格式，将Anthropic SSE转为OpenAI SSE
            $usage = AnthropicAdapter::chatCompletionsStream($channelConfig, $requestBody, $connection, $startTime);
        } else {
            // OpenAI客户端 → OpenAI兼容上游（OpenAI/DeepSeek/Ollama/OpenRouter/Custom）：直接透传
            $usage = OpenAIAdapter::chatCompletionsStream($channelConfig, $requestBody, $connection, $startTime);
        }

        $duration = (int) ((microtime(true) - $startTime) * 1000);
        $ttft = $usage['ttft'] ?? 0;
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $cachedTokens = $usage['cached_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_tokens'] ?? 0;

        // 扣费和日志通过Redis队列异步处理（传入预扣金额用于多退少补）
        self::sendBillingToQueue(
            $userId, $tokenId, $channel->id, $modelName,
            $promptTokens, $completionTokens, true, $duration,
            $tokenData, $channel, $request,
            Log::TYPE_CONSUME, '', $requestId, $ttft,
            $cachedTokens, $cacheCreationTokens, $cacheReadTokens,
            $preDeductAmount
        );

        // 响应已通过connection发送，返回空响应（webman框架需要返回值）
        return response('');
    }

    /**
     * 发送计费任务到Redis队列
     */
    protected static function sendBillingToQueue(
        int $userId, int $tokenId, int $channelId, string $modelName,
        int $promptTokens, int $completionTokens, bool $isStream, int $duration,
        array $tokenData, Channel $channel, Request $request,
        int $logType, string $errorContent, string $requestId, int $ttft = 0,
        int $cachedTokens = 0, int $cacheCreationTokens = 0, int $cacheReadTokens = 0,
        float $preDeductAmount = 0
    ): void {
        // 检查是否开启详细日志
        $logDetailEnabled = Option::getOption('log_detail_enabled', 'false') === 'true';
        $requestContent = '';
        if ($logDetailEnabled && $logType === Log::TYPE_CONSUME) {
            $body = json_decode($request->rawBody(), true);
            // 记录请求内容摘要（截取前500字符避免过大）
            $messages = $body['messages'] ?? [];
            if (!empty($messages)) {
                $lastMsg = end($messages);
                $content = is_array($lastMsg['content'] ?? null) ? json_encode($lastMsg['content'], JSON_UNESCAPED_UNICODE) : ($lastMsg['content'] ?? '');
                $requestContent = mb_substr($content, 0, 500);
            }
        }

        Client::send('relay-billing', [
            'user_id' => $userId,
            'token_id' => $tokenId,
            'channel_id' => $channelId,
            'model_name' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'is_stream' => $isStream,
            'duration' => $duration,
            'ttft' => $ttft,
            'token_data' => $tokenData,
            'channel_name' => $channel->name ?? '',
            'username' => $request->user['username'] ?? '',
            'ip' => $request->getRealIp() ?? '',
            'log_type' => $logType,
            'error_content' => $errorContent,
            'request_id' => $requestId,
            'pre_deduct_amount' => $preDeductAmount,
            'request_content' => $requestContent,
        ]);
    }

    /**
     * 预扣费：从钱包扣除预扣金额
     */
    public static function preDeduct(int $userId, float $amount): bool
    {
        if ($amount <= 0) return true;

        try {
            Db::beginTransaction();
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if (!$wallet || (float)$wallet->balance < $amount) {
                Db::rollBack();
                return false;
            }

            $balanceBefore = (float)$wallet->balance;
            $balanceAfter = round($balanceBefore - $amount, 4);

            Wallet::where('id', $wallet->id)->update([
                'balance' => $balanceAfter,
                'frozen_balance' => Db::raw("frozen_balance + " . (string)$amount),
            ]);

            Db::commit();
            return true;
        } catch (\Throwable $e) {
            Db::rollBack();
            \support\Log::error('preDeduct failed', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 退回预扣费（请求失败时全额退回）
     */
    public static function refundPreDeduct(int $userId, float $amount): void
    {
        if ($amount <= 0) return;

        try {
            Db::beginTransaction();
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if ($wallet) {
                Wallet::where('id', $wallet->id)->update([
                    'balance' => Db::raw("balance + " . (string)$amount),
                    'frozen_balance' => Db::raw("GREATEST(frozen_balance - " . (string)$amount . ", 0)"),
                ]);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            \support\Log::error('refundPreDeduct failed', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 预扣费结算：多退少补
     * @param int $userId
     * @param float $preDeductAmount 预扣金额
     * @param float $actualCost 实际费用
     */
    public static function settlePreDeduct(int $userId, float $preDeductAmount, float $actualCost): void
    {
        if ($preDeductAmount <= 0) return;

        $diff = round($preDeductAmount - $actualCost, 4);

        try {
            Db::beginTransaction();
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if (!$wallet) {
                Db::rollBack();
                return;
            }

            // 解冻预扣金额
            Wallet::where('id', $wallet->id)->update([
                'frozen_balance' => Db::raw("GREATEST(frozen_balance - " . (string)$preDeductAmount . ", 0)"),
            ]);

            if ($diff > 0) {
                // 预扣 > 实际：退回差额到余额
                Wallet::where('id', $wallet->id)->update([
                    'balance' => Db::raw("balance + " . (string)$diff),
                ]);
            } elseif ($diff < 0) {
                // 预扣 < 实际：补扣差额
                $supplement = abs($diff);
                Wallet::where('id', $wallet->id)->update([
                    'balance' => Db::raw("balance - " . (string)$supplement),
                ]);
            }
            // diff == 0 时无需额外操作

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            \support\Log::error('settlePreDeduct failed', [
                'user_id' => $userId,
                'pre_deduct' => $preDeductAmount,
                'actual_cost' => $actualCost,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 选择渠道（支持分类路由和旧逻辑双路径）
     *
     * @param string $modelName 请求的模型名称
     * @param int $categoryId 令牌的分类ID（0=旧逻辑）
     * @return array|null 成功返回 ['channel'=>Channel, 'channel_model'=>?, 'category_channel'=>?]，
     *                    失败返回 null 或 ['error'=>string, 'error_type'=>string, 'http_code'=>int]
     */
    public static function selectChannel(string $modelName, int $categoryId = 0): ?array
    {
        if ($categoryId > 0) {
            return self::selectChannelByCategory($modelName, $categoryId);
        }
        return self::selectChannelLegacy($modelName);
    }

    /**
     * 路径A - 分类路由选择渠道
     */
    protected static function selectChannelByCategory(string $modelName, int $categoryId): ?array
    {
        // 查询分类下匹配模型的渠道绑定，关联渠道一起加载
        $bindings = CategoryChannel::where('category_id', $categoryId)
            ->where('model_name', $modelName)
            ->where('status', 1)
            ->with(['channel'])
            ->orderBy('priority', 'desc')
            ->orderBy('weight', 'desc')
            ->get();

        // 过滤掉渠道不存在或已禁用的绑定
        $bindings = $bindings->filter(function ($binding) {
            return $binding->channel && (int)$binding->channel->status === 1;
        });

        if ($bindings->isEmpty()) {
            return ['error' => '该分类下没有可用的渠道提供此模型', 'error_type' => 'model_not_available', 'http_code' => 503];
        }

        // 按优先级分组
        $grouped = [];
        foreach ($bindings as $binding) {
            $grouped[$binding->priority][] = $binding;
        }
        krsort($grouped);

        $allRateLimited = true;

        foreach ($grouped as $bindingGroup) {
            // 在同优先级组内按权重随机排序
            $shuffled = self::weightedShuffleBindings($bindingGroup);

            foreach ($shuffled as $binding) {
                $channel = $binding->channel;

                // 检查渠道限流（Redis异常时降级为不限流）
                if (!self::checkRateLimit($channel)) {
                    continue; // 被限流，跳过
                }

                $allRateLimited = false;

                // 选中：记录限流计数
                self::recordRateLimit($channel);

                return [
                    'channel' => $channel,
                    'channel_model' => null,
                    'category_channel' => $binding,
                ];
            }
        }

        // 所有渠道都被限流
        if ($allRateLimited) {
            return ['error' => '所有渠道当前请求繁忙，请稍后重试', 'error_type' => 'rate_limit_exceeded', 'http_code' => 429];
        }

        return null;
    }

    /**
     * 路径B - 旧逻辑选择渠道（带限流检查）
     */
    protected static function selectChannelLegacy(string $modelName): ?array
    {
        $model = Model_::where('model_name', $modelName)->where('status', 1)->first();
        if (!$model) {
            return null;
        }

        $channelModels = ChannelModel::where('model_id', $model->id)
            ->where('status', 1)
            ->get();

        if ($channelModels->isEmpty()) {
            return null;
        }

        $channelIds = $channelModels->pluck('channel_id')->toArray();

        $channels = Channel::whereIn('id', $channelIds)
            ->where('status', 1)
            ->orderBy('priority', 'desc')
            ->get();

        if ($channels->isEmpty()) {
            return null;
        }

        // 按优先级分组，加权随机 + 限流检查
        $grouped = [];
        foreach ($channels as $ch) {
            $grouped[$ch->priority][] = $ch;
        }
        krsort($grouped);

        foreach ($grouped as $channelGroup) {
            // 在同优先级组内按权重随机排序
            $shuffled = self::weightedShuffleChannels($channelGroup);

            foreach ($shuffled as $selected) {
                // 检查渠道限流
                if (!self::checkRateLimit($selected)) {
                    continue;
                }

                // 选中：记录限流计数
                self::recordRateLimit($selected);

                $cm = $channelModels->first(fn($item) => $item->channel_id === $selected->id);
                return ['channel' => $selected, 'channel_model' => $cm, 'category_channel' => null];
            }
        }

        // 所有渠道都被限流时，降级为不限流选择第一个可用渠道
        foreach ($grouped as $channelGroup) {
            $selected = self::weightedRandom($channelGroup);
            if ($selected) {
                self::recordRateLimit($selected);
                $cm = $channelModels->first(fn($item) => $item->channel_id === $selected->id);
                return ['channel' => $selected, 'channel_model' => $cm, 'category_channel' => null];
            }
        }

        return null;
    }

    /**
     * 检查渠道限流（Redis异常时降级为允许通过）
     */
    protected static function checkRateLimit(Channel $channel): bool
    {
        $rateLimit = (int)($channel->rate_limit ?? 0);
        if ($rateLimit <= 0) {
            return true; // 未配置限流，直接通过
        }

        $window = (int)($channel->rate_limit_window ?? 60);
        if ($window <= 0) $window = 60;

        try {
            return ChannelRateLimiter::check($channel->id, $rateLimit, $window);
        } catch (\Throwable $e) {
            // Redis异常，降级为不限流
            \support\Log::warning('ChannelRateLimiter check failed, degrading to no limit', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * 记录渠道限流计数（Redis异常时静默忽略）
     */
    protected static function recordRateLimit(Channel $channel): void
    {
        $rateLimit = (int)($channel->rate_limit ?? 0);
        if ($rateLimit <= 0) {
            return; // 未配置限流，无需记录
        }

        $window = (int)($channel->rate_limit_window ?? 60);
        if ($window <= 0) $window = 60;

        try {
            ChannelRateLimiter::record($channel->id, $window);
        } catch (\Throwable $e) {
            \support\Log::warning('ChannelRateLimiter record failed', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 加权随机选择（单个）
     */
    protected static function weightedRandom(array $channels): ?Channel
    {
        if (empty($channels)) return null;
        if (count($channels) === 1) return $channels[0];

        $totalWeight = 0;
        foreach ($channels as $ch) {
            $totalWeight += max(1, $ch->weight);
        }

        $rand = mt_rand(1, $totalWeight);
        $cumulative = 0;
        foreach ($channels as $ch) {
            $cumulative += max(1, $ch->weight);
            if ($rand <= $cumulative) return $ch;
        }

        return $channels[0];
    }

    /**
     * 按权重随机打乱 Channel 数组（用于遍历时按权重优先尝试）
     */
    protected static function weightedShuffleChannels(array $channels): array
    {
        if (count($channels) <= 1) return $channels;

        $result = [];
        $remaining = $channels;

        while (!empty($remaining)) {
            $selected = self::weightedRandom($remaining);
            if (!$selected) break;
            $result[] = $selected;
            $remaining = array_values(array_filter($remaining, fn($ch) => $ch->id !== $selected->id));
        }

        return $result;
    }

    /**
     * 按权重随机打乱 CategoryChannel 绑定数组
     */
    protected static function weightedShuffleBindings(array $bindings): array
    {
        if (count($bindings) <= 1) return $bindings;

        // 将绑定按权重排序打乱
        $result = [];
        $remaining = $bindings;

        while (!empty($remaining)) {
            $totalWeight = 0;
            foreach ($remaining as $b) {
                $totalWeight += max(1, $b->weight);
            }

            $rand = mt_rand(1, $totalWeight);
            $cumulative = 0;
            $selectedIdx = 0;
            foreach ($remaining as $idx => $b) {
                $cumulative += max(1, $b->weight);
                if ($rand <= $cumulative) {
                    $selectedIdx = $idx;
                    break;
                }
            }

            $result[] = $remaining[$selectedIdx];
            unset($remaining[$selectedIdx]);
            $remaining = array_values($remaining);
        }

        return $result;
    }

    /**
     * 计算费用（支持自定义价格覆盖）
     *
     * @param string $modelName
     * @param int $promptTokens
     * @param int $completionTokens
     * @param int $cachedTokens
     * @param int $cacheCreationTokens
     * @param int $cacheReadTokens
     * @param array $tokenData 令牌数据，可能包含 _custom_input_price / _custom_output_price
     */
    public static function calculateCost(
        string $modelName, int $promptTokens, int $completionTokens,
        int $cachedTokens = 0, int $cacheCreationTokens = 0, int $cacheReadTokens = 0,
        array $tokenData = []
    ): float {
        // 检查是否有分类自定义价格
        $customInputPrice = $tokenData['_custom_input_price'] ?? null;
        $customOutputPrice = $tokenData['_custom_output_price'] ?? null;

        if ($customInputPrice !== null && $customOutputPrice !== null
            && (float)$customInputPrice > 0 && (float)$customOutputPrice > 0) {
            // 使用分类绑定的自定义价格（按量计费，价格单位同 model_pricing：每百万token）
            $cost = ($promptTokens * (float)$customInputPrice + $completionTokens * (float)$customOutputPrice) / 1000000;
            return round(max(0, $cost), 6);
        }

        // 回退到默认 model_pricing 价格
        $model = Model_::where('model_name', $modelName)->first();
        if (!$model) return 0;

        $pricing = ModelPricing::where('model_id', $model->id)->where('status', 1)->first();
        if (!$pricing) return 0;

        $cost = 0;
        if ($pricing->billing_type === 1) {
            // 按量计费：价格单位是每百万token
            if ($pricing->cache_enabled) {
                // 启用缓存独立计费
                // 非缓存输入token = 总输入token - 缓存读取token - 缓存创建token
                $nonCachedInputTokens = max(0, $promptTokens - $cacheReadTokens - $cacheCreationTokens);
                $inputCost = $nonCachedInputTokens * (float)$pricing->input_price / 1000000;
                $cacheReadCost = $cacheReadTokens * (float)$pricing->cache_read_price / 1000000;
                $cacheCreationCost = $cacheCreationTokens * (float)$pricing->cache_creation_price / 1000000;
                $outputCost = $completionTokens * (float)$pricing->output_price / 1000000;
                $cost = $inputCost + $cacheReadCost + $cacheCreationCost + $outputCost;
            } else {
                // 不启用缓存独立计费，所有输入token（含缓存）按 input_price 计费
                $cost = ($promptTokens * (float)$pricing->input_price + $completionTokens * (float)$pricing->output_price) / 1000000;
            }
        } elseif ($pricing->billing_type === 2) {
            // 按次计费
            $cost = (float)$pricing->per_request_price;
        }

        $minCharge = (float)$pricing->min_charge;
        if ($minCharge > 0 && $cost < $minCharge) {
            $cost = $minCharge;
        }

        return round($cost, 6);
    }

    /**
     * 应用分组倍率
     */
    public static function applyGroupRatio(int $userId, float $cost): float
    {
        if ($cost <= 0) return $cost;

        $user = User::find($userId);
        if (!$user || empty($user->group_name)) {
            return $cost;
        }

        $group = Group::where('name', $user->group_name)->where('status', 1)->first();
        if (!$group) {
            return $cost;
        }

        $ratio = (float)$group->ratio;
        if ($ratio <= 0) {
            return $cost;
        }

        return round($cost * $ratio, 6);
    }

    /**
     * 扣费
     */
    public static function deductBalance(int $userId, float $cost, string $modelName, int $tokenId = 0, array $tokenData = []): bool
    {
        if ($cost <= 0) return true;

        try {
            Db::beginTransaction();

            // 更新用户请求计数
            User::where('id', $userId)->update([
                'request_count' => Db::raw("request_count + 1"),
            ]);

            // 更新令牌已消费金额
            if ($tokenId > 0) {
                Token::where('id', $tokenId)->update([
                    'used_amount' => Db::raw("used_amount + " . (string)$cost),
                    'last_used_at' => date('Y-m-d H:i:s'),
                ]);
            }

            // 从钱包扣款（使用 SELECT FOR UPDATE 防止并发竞争）
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if ($wallet) {
                $balanceBefore = (float)$wallet->balance;
                $balanceAfter = round($balanceBefore - $cost, 4); // 允许小额透支

                Wallet::where('id', $wallet->id)->update([
                    'balance' => $balanceAfter,
                    'total_consumption' => Db::raw("total_consumption + " . (string)$cost),
                    'used_amount' => Db::raw("used_amount + " . (string)$cost),
                ]);

                WalletTransaction::create([
                    'user_id' => $userId,
                    'wallet_id' => $wallet->id,
                    'type' => WalletTransaction::TYPE_CONSUME,
                    'amount' => -$cost,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'description' => "API调用: {$modelName}",
                    'related_id' => 0,
                    'related_type' => 'api_usage',
                ]);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            \support\Log::error('deductBalance failed', [
                'user_id' => $userId,
                'cost' => $cost,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * 记录使用日志（从控制器/Service直接调用，带完整对象）
     */
    public static function logUsage(
        int $userId, int $tokenId, int $channelId, string $modelName,
        int $promptTokens, int $completionTokens, float $cost, bool $isStream, int $duration,
        array $tokenData = [], ?Channel $channel = null, ?Request $request = null,
        int $type = Log::TYPE_CONSUME, string $errorContent = '', string $requestId = '',
        int $ttft = 0,
        int $cachedTokens = 0, int $cacheCreationTokens = 0, int $cacheReadTokens = 0
    ): void {
        Log::create([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'channel_id' => $channelId,
            'type' => $type,
            'model_name' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cost' => round($cost, 6),
            'content' => $errorContent ?: "模型调用: {$modelName}",
            'token_name' => $tokenData['name'] ?? '',
            'username' => $request->user['username'] ?? '',
            'channel_name' => $channel->name ?? '',
            'use_time' => $duration,
            'duration' => $duration,
            'ttft' => $ttft,
            'is_stream' => $isStream ? 1 : 0,
            'ip' => $request ? $request->getRealIp() : '',
            'request_id' => $requestId,
        ]);
    }

    /**
     * 记录使用日志（从Redis队列消费者调用，使用纯数据参数）
     */
    public static function logUsageFromQueue(
        int $userId, int $tokenId, int $channelId, string $modelName,
        int $promptTokens, int $completionTokens, float $cost, bool $isStream, int $duration,
        array $tokenData, string $channelName, string $username, string $ip,
        int $type = Log::TYPE_CONSUME, string $errorContent = '', string $requestId = '',
        int $ttft = 0,
        int $cachedTokens = 0, int $cacheCreationTokens = 0, int $cacheReadTokens = 0,
        string $requestContent = ''
    ): void {
        // 构建日志内容：错误日志优先显示错误信息，消费日志根据详细日志设置决定内容
        $content = $errorContent ?: "模型调用: {$modelName}";
        if ($requestContent && $type === Log::TYPE_CONSUME) {
            $content = $requestContent;
        }

        Log::create([
            'user_id' => $userId,
            'token_id' => $tokenId,
            'channel_id' => $channelId,
            'type' => $type,
            'model_name' => $modelName,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cost' => round($cost, 6),
            'content' => $content,
            'token_name' => $tokenData['name'] ?? '',
            'username' => $username,
            'channel_name' => $channelName,
            'use_time' => $duration,
            'duration' => $duration,
            'ttft' => $ttft,
            'is_stream' => $isStream ? 1 : 0,
            'ip' => $ip,
            'request_id' => $requestId,
        ]);
    }

    /**
     * 返回错误响应（根据客户端格式返回对应格式）
     */
    public static function errorResponse(string $message, string $type = 'error', int $httpStatus = 400, string $clientFormat = 'openai'): Response
    {
        if ($clientFormat === 'anthropic') {
            // Anthropic格式错误响应
            return new Response($httpStatus, ['Content-Type' => 'application/json'], json_encode([
                'type' => 'error',
                'error' => [
                    'type' => $type,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_UNICODE));
        }

        // OpenAI格式错误响应
        return new Response($httpStatus, ['Content-Type' => 'application/json'], json_encode([
            'error' => [
                'message' => $message,
                'type' => $type,
                'code' => $type,
            ]
        ], JSON_UNESCAPED_UNICODE));
    }
}
