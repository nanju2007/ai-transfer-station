<?php

namespace app\service\adapter;

use Workerman\Protocols\Http\Chunk;

class OpenAIAdapter
{
    /**
     * 默认API地址
     */
    const DEFAULT_BASE_URL = 'https://api.openai.com';

    /**
     * reasoning_effort 值映射表：将非标准值映射为上游支持的值
     * 上游支持: low, medium, high, xhigh
     */
    const REASONING_EFFORT_MAP = [
        'minimal' => 'low',
        'none'    => 'low',
        'min'     => 'low',
        'low'     => 'low',
        'medium'  => 'medium',
        'mid'     => 'medium',
        'default' => 'medium',
        'high'    => 'high',
        'max'     => 'xhigh',
        'maximum' => 'xhigh',
        'xhigh'  => 'xhigh',
    ];

    /**
     * 构建请求URL，智能处理 /v1 路径避免重复
     */
    protected static function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');

        // 如果 base_url 已经以 /v1 结尾，不再追加 /v1 前缀
        if (preg_match('#/v1/?$#', $baseUrl)) {
            $baseUrl = rtrim($baseUrl, '/');
            // path 形如 /v1/chat/completions，去掉前面的 /v1
            $path = preg_replace('#^/v1#', '', $path);
        }

        return $baseUrl . $path;
    }

    /**
     * 清理/映射请求体中不兼容的参数
     * 解决上游不支持某些参数值导致 HTTP 400 的问题
     */
    protected static function sanitizeRequestBody(array $body): array
    {
        // 处理 reasoning_effort 参数值映射
        if (isset($body['reasoning_effort'])) {
            $level = strtolower(trim($body['reasoning_effort']));
            if (isset(self::REASONING_EFFORT_MAP[$level])) {
                $body['reasoning_effort'] = self::REASONING_EFFORT_MAP[$level];
            } else {
                unset($body['reasoning_effort']);
            }
        }

        // 处理 reasoning 对象中的 effort 字段（某些客户端用这种格式）
        if (isset($body['reasoning']['effort'])) {
            $level = strtolower(trim($body['reasoning']['effort']));
            if (isset(self::REASONING_EFFORT_MAP[$level])) {
                $body['reasoning']['effort'] = self::REASONING_EFFORT_MAP[$level];
            } else {
                unset($body['reasoning']['effort']);
                if (empty($body['reasoning'])) {
                    unset($body['reasoning']);
                }
            }
        }

        return $body;
    }

    /**
     * 非流式chat completions请求
     *
     * @param array $channelConfig ['base_url', 'key', 'pass_through', 'model_mapping']
     * @param array $requestBody 原始请求体
     * @return array ['success', 'data', 'prompt_tokens', 'completion_tokens', 'error']
     */
    /**
     * 构建请求头，根据渠道类型添加/跳过特定头
     */
    protected static function buildHeaders(array $channelConfig): array
    {
        $headers = [
            'Content-Type: application/json',
        ];

        $channelType = $channelConfig['channel_type'] ?? 0;
        $apiKey = self::pickKey($channelConfig['key'] ?? '');

        // Ollama 无需 API Key，跳过 Authorization 头
        if ($channelType == 5 && empty($apiKey)) {
            // 不添加 Authorization
        } else {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        // OpenRouter 额外头
        if ($channelType == 6) {
            $headers[] = 'HTTP-Referer: https://ai-relay.local';
            $headers[] = 'X-Title: AI Relay';
        }

        return $headers;
    }

    /**
     * 非流式chat completions请求
     *
     * @param array $channelConfig ['base_url', 'key', 'pass_through', 'model_mapping', 'channel_type']
     * @param array $requestBody 原始请求体
     * @return array ['success', 'data', 'prompt_tokens', 'completion_tokens', 'error']
     */
    public static function chatCompletions(array $channelConfig, array $requestBody): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/chat/completions');

        // 模型映射
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);

        // 清理/映射不兼容的参数（如 reasoning_effort 的 level 值）
        $requestBody = self::sanitizeRequestBody($requestBody);

        // 确保非流式
        $requestBody['stream'] = false;

        // 移除流式专用字段，避免某些上游不兼容
        unset($requestBody['stream_options']);

        $headers = self::buildHeaders($channelConfig);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return [
                'success' => false,
                'data' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
                'cache_read_tokens' => 0,
                'error' => 'Curl error: ' . $curlError,
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || isset($data['error'])) {
            return [
                'success' => false,
                'data' => $data,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
                'cache_read_tokens' => 0,
                'error' => $data['error']['message'] ?? "上游返回HTTP {$httpCode}",
                'http_code' => $httpCode,
            ];
        }

        $promptTokens = $data['usage']['prompt_tokens'] ?? 0;
        $completionTokens = $data['usage']['completion_tokens'] ?? 0;
        $cachedTokens = $data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;

        return [
            'success' => true,
            'data' => $data,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => $cachedTokens,
            'error' => null,
        ];
    }

    /**
     * 流式chat completions请求
     *
     * @param array $channelConfig 渠道配置
     * @param array $requestBody 请求体
     * @param mixed $connection webman连接对象
     * @return array ['prompt_tokens', 'completion_tokens']
     */
    public static function chatCompletionsStream(array $channelConfig, array $requestBody, $connection, float $startTime = 0): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/chat/completions');

        // 模型映射
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);

        // 清理/映射不兼容的参数
        $requestBody = self::sanitizeRequestBody($requestBody);

        // 确保流式
        $requestBody['stream'] = true;
        // 请求stream_options以获取usage
        if (!isset($requestBody['stream_options'])) {
            $requestBody['stream_options'] = ['include_usage' => true];
        }

        $headers = self::buildHeaders($channelConfig);

        $promptTokens = 0;
        $completionTokens = 0;
        $cachedTokens = 0;
        $ttft = 0;
        $firstChunkReceived = false;
        $buffer = '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($connection, &$promptTokens, &$completionTokens, &$cachedTokens, &$buffer, &$ttft, &$firstChunkReceived, $startTime) {
                // 记录首字时间（第一个数据chunk到达时）
                if (!$firstChunkReceived && $startTime > 0) {
                    $ttft = (int)((microtime(true) - $startTime) * 1000);
                    $firstChunkReceived = true;
                }

                $buffer .= $data;

                // 按行处理SSE数据
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '') {
                        continue;
                    }

                    if (str_starts_with($line, 'data: ')) {
                        $jsonStr = substr($line, 6);

                        if ($jsonStr === '[DONE]') {
                            $connection->send(new Chunk("data: [DONE]\n\n"));
                            continue;
                        }

                        $chunk = json_decode($jsonStr, true);
                        if ($chunk) {
                            // 提取usage信息（最后一个chunk可能包含）
                            if (isset($chunk['usage'])) {
                                $promptTokens = $chunk['usage']['prompt_tokens'] ?? $promptTokens;
                                $completionTokens = $chunk['usage']['completion_tokens'] ?? $completionTokens;
                                $cachedTokens = $chunk['usage']['prompt_tokens_details']['cached_tokens'] ?? $cachedTokens;
                            }

                            // 转发给客户端
                            $connection->send(new Chunk("data: " . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n"));
                        }
                    }
                }

                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 处理buffer中剩余数据
        if (!empty(trim($buffer))) {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'data: ')) {
                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') {
                        $connection->send(new Chunk("data: [DONE]\n\n"));
                    } else {
                        $chunk = json_decode($jsonStr, true);
                        if ($chunk) {
                            if (isset($chunk['usage'])) {
                                $promptTokens = $chunk['usage']['prompt_tokens'] ?? $promptTokens;
                                $completionTokens = $chunk['usage']['completion_tokens'] ?? $completionTokens;
                                $cachedTokens = $chunk['usage']['prompt_tokens_details']['cached_tokens'] ?? $cachedTokens;
                            }
                            $connection->send(new Chunk("data: " . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n"));
                        }
                    }
                }
            }
        }

        if ($curlError || $httpCode !== 200) {
            $errorMsg = $curlError ?: "上游返回HTTP {$httpCode}";
            $errorData = [
                'error' => [
                    'message' => $errorMsg,
                    'type' => 'upstream_error',
                    'code' => 'upstream_error',
                ]
            ];
            $connection->send(new Chunk("data: " . json_encode($errorData, JSON_UNESCAPED_UNICODE) . "\n\n"));
            $connection->send(new Chunk("data: [DONE]\n\n"));
        }

        // 发送空Chunk结束响应
        $connection->send(new Chunk(''));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => $cachedTokens,
            'ttft' => $ttft,
        ];
    }

    /**
     * 流式chat completions请求，输出Anthropic SSE格式
     * 用于：Anthropic客户端 → OpenAI上游（将OpenAI SSE转为Anthropic SSE）
     *
     * @param array $channelConfig 渠道配置
     * @param array $requestBody 已转为OpenAI格式的请求体
     * @param mixed $connection webman连接对象
     * @param float $startTime 请求开始时间
     * @param string $originalModel 原始模型名（用于响应）
     * @return array ['prompt_tokens', 'completion_tokens', 'ttft']
     */
    public static function chatCompletionsStreamAsAnthropic(array $channelConfig, array $requestBody, $connection, float $startTime = 0, string $originalModel = ''): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/chat/completions');

        // 模型映射
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);
        $requestBody = self::sanitizeRequestBody($requestBody);

        // 确保流式
        $requestBody['stream'] = true;
        if (!isset($requestBody['stream_options'])) {
            $requestBody['stream_options'] = ['include_usage' => true];
        }

        $headers = self::buildHeaders($channelConfig);

        $promptTokens = 0;
        $completionTokens = 0;
        $cachedTokens = 0;
        $ttft = 0;
        $firstChunkReceived = false;
        $buffer = '';
        $messageId = 'msg_' . bin2hex(random_bytes(12));
        $model = $originalModel ?: ($requestBody['model'] ?? 'unknown');
        $contentStarted = false;

        // 先发送 message_start 事件
        $messageStartEvent = [
            'type' => 'message_start',
            'message' => [
                'id' => $messageId,
                'type' => 'message',
                'role' => 'assistant',
                'content' => [],
                'model' => $model,
                'stop_reason' => null,
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                ],
            ],
        ];
        $connection->send(new Chunk("event: message_start\ndata: " . json_encode($messageStartEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                $connection, &$promptTokens, &$completionTokens, &$cachedTokens,
                &$buffer, $model, $messageId, &$contentStarted,
                &$ttft, &$firstChunkReceived, $startTime
            ) {
                if (!$firstChunkReceived && $startTime > 0) {
                    $ttft = (int)((microtime(true) - $startTime) * 1000);
                    $firstChunkReceived = true;
                }

                $buffer .= $data;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '') continue;

                    if (!str_starts_with($line, 'data: ')) continue;

                    $jsonStr = substr($line, 6);
                    if ($jsonStr === '[DONE]') {
                        continue;
                    }

                    $chunk = json_decode($jsonStr, true);
                    if (!$chunk) continue;

                    // 提取usage
                    if (isset($chunk['usage'])) {
                        $promptTokens = $chunk['usage']['prompt_tokens'] ?? $promptTokens;
                        $completionTokens = $chunk['usage']['completion_tokens'] ?? $completionTokens;
                        $cachedTokens = $chunk['usage']['prompt_tokens_details']['cached_tokens'] ?? $cachedTokens;
                    }

                    // 转换OpenAI chunk为Anthropic SSE事件
                    $delta = $chunk['choices'][0]['delta'] ?? [];
                    $finishReason = $chunk['choices'][0]['finish_reason'] ?? null;

                    // 如果有内容delta
                    if (isset($delta['content']) && $delta['content'] !== '') {
                        // 首次内容需要先发送 content_block_start
                        if (!$contentStarted) {
                            $contentStarted = true;
                            $blockStartEvent = [
                                'type' => 'content_block_start',
                                'index' => 0,
                                'content_block' => [
                                    'type' => 'text',
                                    'text' => '',
                                ],
                            ];
                            $connection->send(new Chunk("event: content_block_start\ndata: " . json_encode($blockStartEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
                        }

                        $textDeltaEvent = [
                            'type' => 'content_block_delta',
                            'index' => 0,
                            'delta' => [
                                'type' => 'text_delta',
                                'text' => $delta['content'],
                            ],
                        ];
                        $connection->send(new Chunk("event: content_block_delta\ndata: " . json_encode($textDeltaEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
                    } elseif (isset($delta['role']) && !$contentStarted) {
                        // role delta（第一个chunk通常只有role），发送content_block_start
                        $contentStarted = true;
                        $blockStartEvent = [
                            'type' => 'content_block_start',
                            'index' => 0,
                            'content_block' => [
                                'type' => 'text',
                                'text' => '',
                            ],
                        ];
                        $connection->send(new Chunk("event: content_block_start\ndata: " . json_encode($blockStartEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
                    }

                    // 如果有finish_reason，发送结束事件
                    if ($finishReason !== null) {
                        // content_block_stop
                        if ($contentStarted) {
                            $blockStopEvent = [
                                'type' => 'content_block_stop',
                                'index' => 0,
                            ];
                            $connection->send(new Chunk("event: content_block_stop\ndata: " . json_encode($blockStopEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
                        }

                        $stopReason = match ($finishReason) {
                            'stop' => 'end_turn',
                            'length' => 'max_tokens',
                            default => 'end_turn',
                        };

                        // message_delta with stop_reason
                        $messageDeltaEvent = [
                            'type' => 'message_delta',
                            'delta' => [
                                'stop_reason' => $stopReason,
                                'stop_sequence' => null,
                            ],
                            'usage' => [
                                'output_tokens' => $completionTokens,
                            ],
                        ];
                        $connection->send(new Chunk("event: message_delta\ndata: " . json_encode($messageDeltaEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
                    }
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError || $httpCode !== 200) {
            $errorMsg = $curlError ?: "上游返回HTTP {$httpCode}";
            $errorEvent = [
                'type' => 'error',
                'error' => [
                    'type' => 'upstream_error',
                    'message' => $errorMsg,
                ],
            ];
            $connection->send(new Chunk("event: error\ndata: " . json_encode($errorEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));
        }

        // message_stop
        $messageStopEvent = ['type' => 'message_stop'];
        $connection->send(new Chunk("event: message_stop\ndata: " . json_encode($messageStopEvent, JSON_UNESCAPED_UNICODE) . "\n\n"));

        $connection->send(new Chunk(''));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => $cachedTokens,
            'ttft' => $ttft,
        ];
    }

    /**
     * 应用模型映射
     */
    protected static function applyModelMapping(array $requestBody, ?string $modelMapping): array
    {
        if (empty($modelMapping) || empty($requestBody['model'])) {
            return $requestBody;
        }

        $mapping = json_decode($modelMapping, true);
        if ($mapping && isset($mapping[$requestBody['model']])) {
            $requestBody['model'] = $mapping[$requestBody['model']];
        }

        return $requestBody;
    }

    /**
     * 从多key中随机选一个（兼容 \r\n 和 \n 换行符）
     */
    protected static function pickKey(string $keys): string
    {
        // 统一换行符，兼容 Windows \r\n
        $keys = str_replace("\r\n", "\n", $keys);
        $keys = str_replace("\r", "\n", $keys);
        $keyList = array_filter(array_map('trim', explode("\n", $keys)));
        if (empty($keyList)) {
            return '';
        }
        return $keyList[array_rand($keyList)];
    }
}
