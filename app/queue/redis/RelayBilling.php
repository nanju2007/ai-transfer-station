<?php

namespace app\queue\redis;

use Webman\RedisQueue\Consumer;
use app\service\RelayService;
use support\Log;

/**
 * API中转计费队列消费者
 * 处理流式/非流式请求完成后的扣费和日志记录
 */
class RelayBilling implements Consumer
{
    /**
     * 要消费的队列名
     */
    public $queue = 'relay-billing';

    /**
     * 连接名
     */
    public $connection = 'default';

    /**
     * 消费
     */
    public function consume($data)
    {
        $userId = $data['user_id'] ?? 0;
        $tokenId = $data['token_id'] ?? 0;
        $channelId = $data['channel_id'] ?? 0;
        $modelName = $data['model_name'] ?? '';
        $promptTokens = $data['prompt_tokens'] ?? 0;
        $completionTokens = $data['completion_tokens'] ?? 0;
        $cachedTokens = $data['cached_tokens'] ?? 0;
        $cacheCreationTokens = $data['cache_creation_tokens'] ?? 0;
        $cacheReadTokens = $data['cache_read_tokens'] ?? 0;
        $isStream = $data['is_stream'] ?? false;
        $duration = $data['duration'] ?? 0;
        $ttft = $data['ttft'] ?? 0;
        $tokenData = $data['token_data'] ?? [];
        $channelName = $data['channel_name'] ?? '';
        $username = $data['username'] ?? '';
        $ip = $data['ip'] ?? '';
        $requestId = $data['request_id'] ?? '';
        $logType = $data['log_type'] ?? \app\model\Log::TYPE_CONSUME;
        $errorContent = $data['error_content'] ?? '';
        $preDeductAmount = (float)($data['pre_deduct_amount'] ?? 0);

        // 计算费用并扣费（传入缓存token用于独立计费，传入tokenData支持自定义价格）
        $cost = RelayService::calculateCost(
            $modelName, $promptTokens, $completionTokens,
            $cachedTokens, $cacheCreationTokens, $cacheReadTokens,
            $tokenData
        );
        $cost = RelayService::applyGroupRatio($userId, $cost);

        // 预扣费结算：多退少补
        if ($preDeductAmount > 0) {
            RelayService::settlePreDeduct($userId, $preDeductAmount, $cost);
            // 实际费用中已由预扣覆盖的部分不再重复扣费
            $remainingCost = max(0, $cost - $preDeductAmount);
            if ($remainingCost > 0) {
                RelayService::deductBalance($userId, $remainingCost, $modelName, $tokenId, $tokenData);
            } else {
                // 仍需更新令牌使用量和请求计数
                if ($cost > 0) {
                    \app\model\User::where('id', $userId)->update([
                        'request_count' => \support\Db::raw("request_count + 1"),
                    ]);
                    if ($tokenId > 0) {
                        \app\model\Token::where('id', $tokenId)->update([
                            'used_amount' => \support\Db::raw("used_amount + " . (string)$cost),
                            'last_used_at' => date('Y-m-d H:i:s'),
                        ]);
                    }
                    // 更新钱包消费统计
                    \app\model\Wallet::where('user_id', $userId)->update([
                        'total_consumption' => \support\Db::raw("total_consumption + " . (string)$cost),
                        'used_amount' => \support\Db::raw("used_amount + " . (string)$cost),
                    ]);
                }
            }
        } else {
            if ($cost > 0) {
                RelayService::deductBalance($userId, $cost, $modelName, $tokenId, $tokenData);
            }
        }

        // 记录日志（含缓存token和详细请求内容）
        $requestContent = $data['request_content'] ?? '';
        RelayService::logUsageFromQueue(
            $userId, $tokenId, $channelId, $modelName,
            $promptTokens, $completionTokens, $cost, $isStream, $duration,
            $tokenData, $channelName, $username, $ip,
            $logType, $errorContent, $requestId, $ttft,
            $cachedTokens, $cacheCreationTokens, $cacheReadTokens,
            $requestContent
        );

        Log::debug("RelayBilling: 计费完成", [
            'request_id' => $requestId,
            'model' => $modelName,
            'cost' => $cost,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
        ]);
    }

    /**
     * 消费失败回调
     */
    public function onConsumeFailure(\Throwable $e, $package)
    {
        Log::error('RelayBilling: 消费失败', [
            'error' => $e->getMessage(),
            'queue' => $package['queue'] ?? '',
            'attempts' => $package['attempts'] ?? 0,
            'data' => $package['data'] ?? [],
        ]);
    }
}
