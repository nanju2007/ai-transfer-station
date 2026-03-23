<?php

namespace app\service\adapter;

use Workerman\Protocols\Http\Chunk;

class AnthropicAdapter
{
    const DEFAULT_BASE_URL = 'https://api.anthropic.com';
    const ANTHROPIC_VERSION = '2023-06-01';

    /**
     * 构建请求URL，智能处理路径避免重复
     */
    protected static function buildUrl(string $baseUrl, string $path): string
    {
        $baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');

        // 如果 base_url 已经以 /v1 结尾，不再追加 /v1 前缀
        if (preg_match('#/v1/?$#', $baseUrl)) {
            $baseUrl = rtrim($baseUrl, '/');
            $path = preg_replace('#^/v1#', '', $path);
        }

        return $baseUrl . $path;
    }

    /**
     * 从多key中随机选一个（兼容 \r\n 和 \n 换行符）
     */
    protected static function pickKey(string $keys): string
    {
        $keys = str_replace("\r\n", "\n", $keys);
        $keys = str_replace("\r", "\n", $keys);
        $keyList = array_filter(array_map('trim', explode("\n", $keys)));
        if (empty($keyList)) {
            return '';
        }
        return $keyList[array_rand($keyList)];
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

    // ================================================================
    // 公开方法：Anthropic客户端格式转换工具
    // ================================================================

    /**
     * 将Anthropic格式请求体转为OpenAI格式请求体
     * 用于：Anthropic客户端 → OpenAI上游
     */
    public static function convertAnthropicToOpenAI(array $anthropicBody): array
    {
        $openAIBody = [
            'model' => $anthropicBody['model'] ?? '',
            'max_tokens' => $anthropicBody['max_tokens'] ?? 4096,
        ];

        $messages = [];

        // 处理system字段 → system角色消息
        if (!empty($anthropicBody['system'])) {
            $systemContent = $anthropicBody['system'];
            if (is_array($systemContent)) {
                $text = '';
                foreach ($systemContent as $block) {
                    if (is_string($block)) {
                        $text .= $block;
                    } elseif (isset($block['text'])) {
                        $text .= $block['text'];
                    }
                }
                $systemContent = $text;
            }
            $messages[] = ['role' => 'system', 'content' => $systemContent];
        }

        // 转换messages（注意：转为OpenAI格式时cache_control会丢失，这是预期行为）
        foreach ($anthropicBody['messages'] ?? [] as $msg) {
            $content = $msg['content'] ?? '';
            // 如果content是数组（Anthropic content blocks），提取纯文本
            if (is_array($content)) {
                $textParts = [];
                foreach ($content as $block) {
                    if (is_string($block)) {
                        $textParts[] = $block;
                    } elseif (isset($block['text'])) {
                        $textParts[] = $block['text'];
                    }
                }
                $content = implode('', $textParts);
            }
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $content,
            ];
        }

        $openAIBody['messages'] = $messages;

        // 透传参数
        if (isset($anthropicBody['temperature'])) {
            $openAIBody['temperature'] = $anthropicBody['temperature'];
        }
        if (isset($anthropicBody['top_p'])) {
            $openAIBody['top_p'] = $anthropicBody['top_p'];
        }
        if (isset($anthropicBody['stop_sequences'])) {
            $openAIBody['stop'] = $anthropicBody['stop_sequences'];
        }
        if (isset($anthropicBody['stream'])) {
            $openAIBody['stream'] = $anthropicBody['stream'];
        }

        return $openAIBody;
    }

    /**
     * 将OpenAI格式响应转为Anthropic格式响应
     * 用于：OpenAI上游响应 → Anthropic客户端
     */
    public static function convertOpenAIResponseToAnthropic(array $openAIResponse, string $model): array
    {
        $content = '';
        $finishReason = 'end_turn';

        if (!empty($openAIResponse['choices'][0]['message']['content'])) {
            $content = $openAIResponse['choices'][0]['message']['content'];
        }

        $openAIFinish = $openAIResponse['choices'][0]['finish_reason'] ?? 'stop';
        $finishReason = match ($openAIFinish) {
            'stop' => 'end_turn',
            'length' => 'max_tokens',
            default => 'end_turn',
        };

        $inputTokens = $openAIResponse['usage']['prompt_tokens'] ?? 0;
        $outputTokens = $openAIResponse['usage']['completion_tokens'] ?? 0;

        return [
            'id' => 'msg_' . bin2hex(random_bytes(12)),
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $content,
                ],
            ],
            'model' => $model,
            'stop_reason' => $finishReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ],
        ];
    }

    // ================================================================
    // 非流式请求方法
    // ================================================================

    /**
     * 非流式chat completions请求（OpenAI格式入 → Anthropic API → OpenAI格式出）
     * 用于：OpenAI客户端 → Anthropic上游
     */
    public static function chatCompletions(array $channelConfig, array $requestBody): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/messages');
        $apiKey = self::pickKey($channelConfig['key'] ?? '');

        // 将OpenAI格式转为Anthropic格式
        $anthropicBody = self::convertToAnthropicFormat($requestBody);
        $anthropicBody = self::applyModelMapping($anthropicBody, $channelConfig['model_mapping'] ?? null);

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($anthropicBody, JSON_UNESCAPED_UNICODE),
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
                'error' => $data['error']['message'] ?? "HTTP {$httpCode}",
                'http_code' => $httpCode,
            ];
        }

        // 将Anthropic响应转为OpenAI格式
        $openAIResponse = self::convertToOpenAIResponse($data, $requestBody['model'] ?? '');
        $promptTokens = $data['usage']['input_tokens'] ?? 0;
        $completionTokens = $data['usage']['output_tokens'] ?? 0;
        $cacheCreationTokens = $data['usage']['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $data['usage']['cache_read_input_tokens'] ?? 0;

        return [
            'success' => true,
            'data' => $openAIResponse,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'error' => null,
        ];
    }

    /**
     * 直接透传到Anthropic上游，返回原始Anthropic格式响应
     * 用于：Anthropic客户端 → Anthropic上游
     */
    public static function passThrough(array $channelConfig, array $requestBody): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/messages');
        $apiKey = self::pickKey($channelConfig['key'] ?? '');

        // 应用模型映射
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);

        // 确保非流式
        unset($requestBody['stream']);

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];

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
                'error' => $data['error']['message'] ?? "HTTP {$httpCode}",
                'http_code' => $httpCode,
            ];
        }

        $promptTokens = $data['usage']['input_tokens'] ?? 0;
        $completionTokens = $data['usage']['output_tokens'] ?? 0;
        $cacheCreationTokens = $data['usage']['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $data['usage']['cache_read_input_tokens'] ?? 0;

        return [
            'success' => true,
            'data' => $data,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'error' => null,
        ];
    }

    // ================================================================
    // 流式请求方法
    // ================================================================

    /**
     * 流式chat completions请求（OpenAI格式入 → Anthropic API → OpenAI SSE格式出）
     * 用于：OpenAI客户端 → Anthropic上游
     */
    public static function chatCompletionsStream(array $channelConfig, array $requestBody, $connection, float $startTime = 0): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/messages');
        $apiKey = self::pickKey($channelConfig['key'] ?? '');

        $originalModel = $requestBody['model'] ?? '';

        // 将OpenAI格式转为Anthropic格式
        $anthropicBody = self::convertToAnthropicFormat($requestBody);
        $anthropicBody = self::applyModelMapping($anthropicBody, $channelConfig['model_mapping'] ?? null);
        $anthropicBody['stream'] = true;

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
        $ttft = 0;
        $firstChunkReceived = false;
        $buffer = '';
        $responseId = 'chatcmpl-' . bin2hex(random_bytes(12));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($anthropicBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                $connection, &$promptTokens, &$completionTokens,
                &$cacheCreationTokens, &$cacheReadTokens,
                &$buffer, $originalModel, $responseId,
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

                    if ($line === '' || str_starts_with($line, 'event: ')) {
                        continue;
                    }

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonStr = substr($line, 6);
                    $event = json_decode($jsonStr, true);
                    if (!$event) {
                        continue;
                    }

                    // 提取usage（含缓存token）
                    if (($event['type'] ?? '') === 'message_start' && isset($event['message']['usage'])) {
                        $promptTokens = $event['message']['usage']['input_tokens'] ?? $promptTokens;
                        $cacheCreationTokens = $event['message']['usage']['cache_creation_input_tokens'] ?? $cacheCreationTokens;
                        $cacheReadTokens = $event['message']['usage']['cache_read_input_tokens'] ?? $cacheReadTokens;
                    }
                    if (($event['type'] ?? '') === 'message_delta' && isset($event['usage'])) {
                        $completionTokens = $event['usage']['output_tokens'] ?? $completionTokens;
                    }

                    $openAIChunk = self::convertStreamEventToOpenAI($event, $originalModel, $responseId);

                    if ($openAIChunk !== null) {
                        $connection->send(new Chunk("data: " . json_encode($openAIChunk, JSON_UNESCAPED_UNICODE) . "\n\n"));
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
            $errorData = [
                'error' => [
                    'message' => $errorMsg,
                    'type' => 'upstream_error',
                    'code' => 'upstream_error',
                ]
            ];
            $connection->send(new Chunk("data: " . json_encode($errorData, JSON_UNESCAPED_UNICODE) . "\n\n"));
        }

        $connection->send(new Chunk("data: [DONE]\n\n"));
        $connection->send(new Chunk(''));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'ttft' => $ttft,
        ];
    }

    /**
     * 流式直接透传到Anthropic上游，返回原始Anthropic SSE格式
     * 用于：Anthropic客户端 → Anthropic上游
     */
    public static function passThroughStream(array $channelConfig, array $requestBody, $connection, float $startTime = 0): array
    {
        $url = self::buildUrl($channelConfig['base_url'] ?? '', '/v1/messages');
        $apiKey = self::pickKey($channelConfig['key'] ?? '');

        // 应用模型映射
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);
        $requestBody['stream'] = true;

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: ' . self::ANTHROPIC_VERSION,
        ];

        $promptTokens = 0;
        $completionTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
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
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                $connection, &$promptTokens, &$completionTokens,
                &$cacheCreationTokens, &$cacheReadTokens,
                &$buffer, &$ttft, &$firstChunkReceived, $startTime
            ) {
                if (!$firstChunkReceived && $startTime > 0) {
                    $ttft = (int)((microtime(true) - $startTime) * 1000);
                    $firstChunkReceived = true;
                }

                $buffer .= $data;

                // 直接透传原始SSE数据（event: + data: 格式）
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $trimmedLine = trim($line);

                    // 提取usage信息用于计费（含缓存token）
                    if (str_starts_with($trimmedLine, 'data: ')) {
                        $jsonStr = substr($trimmedLine, 6);
                        $event = json_decode($jsonStr, true);
                        if ($event) {
                            if (($event['type'] ?? '') === 'message_start' && isset($event['message']['usage'])) {
                                $promptTokens = $event['message']['usage']['input_tokens'] ?? $promptTokens;
                                $cacheCreationTokens = $event['message']['usage']['cache_creation_input_tokens'] ?? $cacheCreationTokens;
                                $cacheReadTokens = $event['message']['usage']['cache_read_input_tokens'] ?? $cacheReadTokens;
                            }
                            if (($event['type'] ?? '') === 'message_delta' && isset($event['usage'])) {
                                $completionTokens = $event['usage']['output_tokens'] ?? $completionTokens;
                            }
                        }
                    }

                    // 透传原始行（保留 event: 和 data: 行）
                    if ($trimmedLine !== '') {
                        $connection->send(new Chunk($trimmedLine . "\n"));
                    } else {
                        // 空行是SSE事件分隔符
                        $connection->send(new Chunk("\n"));
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

        $connection->send(new Chunk(''));

        return [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'ttft' => $ttft,
        ];
    }

    // ================================================================
    // 内部格式转换方法
    // ================================================================

    /**
     * OpenAI请求格式 → Anthropic请求格式
     */
    protected static function convertToAnthropicFormat(array $openAIBody): array
    {
        $anthropicBody = [
            'model' => $openAIBody['model'] ?? '',
            'max_tokens' => $openAIBody['max_tokens'] ?? 4096,
        ];

        // 提取system消息
        $messages = $openAIBody['messages'] ?? [];
        $systemParts = [];
        $anthropicMessages = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $systemParts[] = is_string($msg['content']) ? $msg['content'] : json_encode($msg['content']);
            } else {
                $anthropicMessages[] = [
                    'role' => $msg['role'] === 'assistant' ? 'assistant' : 'user',
                    'content' => $msg['content'] ?? '',
                ];
            }
        }

        if (!empty($systemParts)) {
            $anthropicBody['system'] = implode("\n\n", $systemParts);
        }

        // 确保 messages 不为空
        if (empty($anthropicMessages)) {
            $anthropicMessages[] = [
                'role' => 'user',
                'content' => 'Hello',
            ];
        }

        $anthropicBody['messages'] = $anthropicMessages;

        // 透传温度等参数
        if (isset($openAIBody['temperature'])) {
            $anthropicBody['temperature'] = $openAIBody['temperature'];
        }
        if (isset($openAIBody['top_p'])) {
            $anthropicBody['top_p'] = $openAIBody['top_p'];
        }
        if (isset($openAIBody['stop'])) {
            $anthropicBody['stop_sequences'] = is_array($openAIBody['stop']) ? $openAIBody['stop'] : [$openAIBody['stop']];
        }

        return $anthropicBody;
    }

    /**
     * Anthropic响应 → OpenAI chat.completion格式
     */
    protected static function convertToOpenAIResponse(array $anthropicResponse, string $model): array
    {
        $content = '';
        foreach ($anthropicResponse['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= $block['text'] ?? '';
            }
        }

        $finishReason = match ($anthropicResponse['stop_reason'] ?? null) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            default => 'stop',
        };

        return [
            'id' => 'chatcmpl-' . bin2hex(random_bytes(12)),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $model,
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => $content,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $anthropicResponse['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $anthropicResponse['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($anthropicResponse['usage']['input_tokens'] ?? 0) + ($anthropicResponse['usage']['output_tokens'] ?? 0),
            ],
        ];
    }

    /**
     * Anthropic流式事件 → OpenAI流式chunk格式
     */
    protected static function convertStreamEventToOpenAI(array $event, string $model, string $responseId): ?array
    {
        $type = $event['type'] ?? '';

        switch ($type) {
            case 'content_block_start':
                return [
                    'id' => $responseId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => ['role' => 'assistant', 'content' => ''],
                            'finish_reason' => null,
                        ],
                    ],
                ];

            case 'content_block_delta':
                $delta = $event['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    return [
                        'id' => $responseId,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $model,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => ['content' => $delta['text'] ?? ''],
                                'finish_reason' => null,
                            ],
                        ],
                    ];
                }
                return null;

            case 'message_delta':
                $stopReason = $event['delta']['stop_reason'] ?? null;
                $finishReason = match ($stopReason) {
                    'end_turn' => 'stop',
                    'max_tokens' => 'length',
                    'stop_sequence' => 'stop',
                    default => null,
                };
                if ($finishReason) {
                    return [
                        'id' => $responseId,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $model,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => [],
                                'finish_reason' => $finishReason,
                            ],
                        ],
                    ];
                }
                return null;

            default:
                return null;
        }
    }
}
