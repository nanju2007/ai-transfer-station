<?php

namespace app\service\adapter;

use Workerman\Protocols\Http\Chunk;

class GeminiAdapter
{
    const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com';

    /**
     * 从多key中随机选一个
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

    /**
     * 将 OpenAI messages 转为 Gemini contents + systemInstruction
     */
    protected static function convertMessages(array $messages): array
    {
        $contents = [];
        $systemParts = [];

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';

            // 提取文本内容（兼容 string 和 array content）
            $text = '';
            if (is_string($msg['content'] ?? null)) {
                $text = $msg['content'];
            } elseif (is_array($msg['content'] ?? null)) {
                // 多模态消息，只取文本部分
                foreach ($msg['content'] as $part) {
                    if (is_string($part)) {
                        $text .= $part;
                    } elseif (isset($part['type']) && $part['type'] === 'text') {
                        $text .= $part['text'] ?? '';
                    }
                }
            }

            if ($role === 'system') {
                $systemParts[] = ['text' => $text];
            } else {
                $geminiRole = ($role === 'assistant') ? 'model' : 'user';
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => $text]],
                ];
            }
        }

        return [
            'contents' => $contents,
            'systemInstruction' => !empty($systemParts) ? ['parts' => $systemParts] : null,
        ];
    }

    /**
     * 将 OpenAI 参数转为 Gemini generationConfig
     */
    protected static function buildGenerationConfig(array $body): array
    {
        $config = [];

        if (isset($body['temperature'])) {
            $config['temperature'] = (float) $body['temperature'];
        }
        if (isset($body['top_p'])) {
            $config['topP'] = (float) $body['top_p'];
        }
        if (isset($body['max_tokens'])) {
            $config['maxOutputTokens'] = (int) $body['max_tokens'];
        }
        if (isset($body['max_completion_tokens'])) {
            $config['maxOutputTokens'] = (int) $body['max_completion_tokens'];
        }
        if (isset($body['stop'])) {
            $config['stopSequences'] = is_array($body['stop']) ? $body['stop'] : [$body['stop']];
        }
        if (isset($body['top_k'])) {
            $config['topK'] = (int) $body['top_k'];
        }

        return $config;
    }

    /**
     * 将 Gemini 非流式响应转为 OpenAI 格式
     */
    protected static function convertToOpenAIResponse(array $geminiData, string $model): array
    {
        $text = '';
        $finishReason = 'stop';

        if (!empty($geminiData['candidates'][0]['content']['parts'])) {
            foreach ($geminiData['candidates'][0]['content']['parts'] as $part) {
                $text .= $part['text'] ?? '';
            }
        }

        $geminiFinish = $geminiData['candidates'][0]['finishReason'] ?? 'STOP';
        $finishReason = match ($geminiFinish) {
            'STOP' => 'stop',
            'MAX_TOKENS' => 'length',
            'SAFETY' => 'content_filter',
            default => 'stop',
        };

        $promptTokens = $geminiData['usageMetadata']['promptTokenCount'] ?? 0;
        $completionTokens = $geminiData['usageMetadata']['candidatesTokenCount'] ?? 0;
        $totalTokens = $geminiData['usageMetadata']['totalTokenCount'] ?? ($promptTokens + $completionTokens);
        $cachedTokens = $geminiData['usageMetadata']['cachedContentTokenCount'] ?? 0;

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
                        'content' => $text,
                    ],
                    'finish_reason' => $finishReason,
                ],
            ],
            'usage' => [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'prompt_tokens_details' => [
                    'cached_tokens' => $cachedTokens,
                ],
            ],
        ];
    }

    /**
     * 非流式 chat completions
     */
    public static function chatCompletions(array $channelConfig, array $requestBody): array
    {
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);
        $model = $requestBody['model'] ?? 'gemini-2.0-flash';
        $apiKey = self::pickKey($channelConfig['key'] ?? '');
        $baseUrl = rtrim($channelConfig['base_url'] ?? '', '/') ?: self::DEFAULT_BASE_URL;

        $url = $baseUrl . '/v1beta/models/' . $model . ':generateContent?key=' . urlencode($apiKey);

        // 构建 Gemini 请求体
        $converted = self::convertMessages($requestBody['messages'] ?? []);
        $geminiBody = [
            'contents' => $converted['contents'],
        ];
        if ($converted['systemInstruction']) {
            $geminiBody['systemInstruction'] = $converted['systemInstruction'];
        }

        $generationConfig = self::buildGenerationConfig($requestBody);
        if (!empty($generationConfig)) {
            $geminiBody['generationConfig'] = $generationConfig;
        }

        $headers = [
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($geminiBody, JSON_UNESCAPED_UNICODE),
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
                'success' => false, 'data' => null,
                'prompt_tokens' => 0, 'completion_tokens' => 0,
                'cached_tokens' => 0, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0,
                'error' => 'Curl error: ' . $curlError,
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || isset($data['error'])) {
            return [
                'success' => false, 'data' => $data,
                'prompt_tokens' => 0, 'completion_tokens' => 0,
                'cached_tokens' => 0, 'cache_creation_tokens' => 0, 'cache_read_tokens' => 0,
                'error' => $data['error']['message'] ?? "上游返回HTTP {$httpCode}",
                'http_code' => $httpCode,
            ];
        }

        $openAIResponse = self::convertToOpenAIResponse($data, $model);
        $promptTokens = $openAIResponse['usage']['prompt_tokens'];
        $completionTokens = $openAIResponse['usage']['completion_tokens'];
        $cachedTokens = $openAIResponse['usage']['prompt_tokens_details']['cached_tokens'] ?? 0;

        return [
            'success' => true,
            'data' => $openAIResponse,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cached_tokens' => $cachedTokens,
            'cache_creation_tokens' => 0,
            'cache_read_tokens' => $cachedTokens,
            'error' => null,
        ];
    }

    /**
     * 流式 chat completions（Gemini SSE → OpenAI SSE）
     */
    public static function chatCompletionsStream(array $channelConfig, array $requestBody, $connection, float $startTime = 0): array
    {
        $requestBody = self::applyModelMapping($requestBody, $channelConfig['model_mapping'] ?? null);
        $model = $requestBody['model'] ?? 'gemini-2.0-flash';
        $apiKey = self::pickKey($channelConfig['key'] ?? '');
        $baseUrl = rtrim($channelConfig['base_url'] ?? '', '/') ?: self::DEFAULT_BASE_URL;

        $url = $baseUrl . '/v1beta/models/' . $model . ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);

        // 构建 Gemini 请求体
        $converted = self::convertMessages($requestBody['messages'] ?? []);
        $geminiBody = [
            'contents' => $converted['contents'],
        ];
        if ($converted['systemInstruction']) {
            $geminiBody['systemInstruction'] = $converted['systemInstruction'];
        }

        $generationConfig = self::buildGenerationConfig($requestBody);
        if (!empty($generationConfig)) {
            $geminiBody['generationConfig'] = $generationConfig;
        }

        $headers = [
            'Content-Type: application/json',
        ];

        $promptTokens = 0;
        $completionTokens = 0;
        $cachedTokens = 0;
        $ttft = 0;
        $firstChunkReceived = false;
        $buffer = '';
        $chatcmplId = 'chatcmpl-' . bin2hex(random_bytes(12));

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($geminiBody, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (
                $connection, &$promptTokens, &$completionTokens, &$cachedTokens,
                &$buffer, &$ttft, &$firstChunkReceived, $startTime, $model, $chatcmplId
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
                    $chunk = json_decode($jsonStr, true);
                    if (!$chunk) continue;

                    // 提取 usage
                    if (isset($chunk['usageMetadata'])) {
                        $promptTokens = $chunk['usageMetadata']['promptTokenCount'] ?? $promptTokens;
                        $completionTokens = $chunk['usageMetadata']['candidatesTokenCount'] ?? $completionTokens;
                        $cachedTokens = $chunk['usageMetadata']['cachedContentTokenCount'] ?? $cachedTokens;
                    }

                    // 提取文本 delta
                    $text = '';
                    if (!empty($chunk['candidates'][0]['content']['parts'])) {
                        foreach ($chunk['candidates'][0]['content']['parts'] as $part) {
                            $text .= $part['text'] ?? '';
                        }
                    }

                    $finishReason = null;
                    $geminiFinish = $chunk['candidates'][0]['finishReason'] ?? null;
                    if ($geminiFinish) {
                        $finishReason = match ($geminiFinish) {
                            'STOP' => 'stop',
                            'MAX_TOKENS' => 'length',
                            'SAFETY' => 'content_filter',
                            default => 'stop',
                        };
                    }

                    // 转为 OpenAI 流式 chunk
                    $openAIChunk = [
                        'id' => $chatcmplId,
                        'object' => 'chat.completion.chunk',
                        'created' => time(),
                        'model' => $model,
                        'choices' => [
                            [
                                'index' => 0,
                                'delta' => $text !== '' ? ['content' => $text] : (object)[],
                                'finish_reason' => $finishReason,
                            ],
                        ],
                    ];

                    // 最后一个 chunk 附带 usage
                    if ($finishReason !== null && isset($chunk['usageMetadata'])) {
                        $openAIChunk['usage'] = [
                            'prompt_tokens' => $promptTokens,
                            'completion_tokens' => $completionTokens,
                            'total_tokens' => $promptTokens + $completionTokens,
                        ];
                    }

                    $connection->send(new Chunk("data: " . json_encode($openAIChunk, JSON_UNESCAPED_UNICODE) . "\n\n"));
                }

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 处理 buffer 中剩余数据
        if (!empty(trim($buffer))) {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $line = trim($line);
                if (!str_starts_with($line, 'data: ')) continue;
                $jsonStr = substr($line, 6);
                $chunk = json_decode($jsonStr, true);
                if (!$chunk) continue;

                if (isset($chunk['usageMetadata'])) {
                    $promptTokens = $chunk['usageMetadata']['promptTokenCount'] ?? $promptTokens;
                    $completionTokens = $chunk['usageMetadata']['candidatesTokenCount'] ?? $completionTokens;
                    $cachedTokens = $chunk['usageMetadata']['cachedContentTokenCount'] ?? $cachedTokens;
                }

                $text = '';
                if (!empty($chunk['candidates'][0]['content']['parts'])) {
                    foreach ($chunk['candidates'][0]['content']['parts'] as $part) {
                        $text .= $part['text'] ?? '';
                    }
                }

                $finishReason = null;
                $geminiFinish = $chunk['candidates'][0]['finishReason'] ?? null;
                if ($geminiFinish) {
                    $finishReason = match ($geminiFinish) {
                        'STOP' => 'stop',
                        'MAX_TOKENS' => 'length',
                        'SAFETY' => 'content_filter',
                        default => 'stop',
                    };
                }

                $openAIChunk = [
                    'id' => $chatcmplId,
                    'object' => 'chat.completion.chunk',
                    'created' => time(),
                    'model' => $model,
                    'choices' => [
                        [
                            'index' => 0,
                            'delta' => $text !== '' ? ['content' => $text] : (object)[],
                            'finish_reason' => $finishReason,
                        ],
                    ],
                ];

                $connection->send(new Chunk("data: " . json_encode($openAIChunk, JSON_UNESCAPED_UNICODE) . "\n\n"));
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
        }

        $connection->send(new Chunk("data: [DONE]\n\n"));
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
}
