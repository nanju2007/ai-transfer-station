<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\model\BlockedWord;
use support\Redis;

class BlockedWordFilter implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 检查用户输入屏蔽词开关
        $inputEnabled = $this->getOption('blocked_word_input_enabled');
        if ($inputEnabled) {
            $content = $this->extractInputContent($request);
            if ($content) {
                $result = $this->checkBlockedWords($content, [BlockedWord::TYPE_INPUT, BlockedWord::TYPE_BOTH]);
                if ($result) {
                    if ($result['action'] === BlockedWord::ACTION_REJECT) {
                        return $this->rejectResponse($result['word']);
                    }
                    // 替换模式：修改请求体中的屏蔽词
                    if ($result['action'] === BlockedWord::ACTION_REPLACE) {
                        $this->replaceInputContent($request, $result['words']);
                    }
                }
            }
        }

        return $handler($request);
    }

    /**
     * 从请求体中提取用户输入内容
     */
    protected function extractInputContent(Request $request): string
    {
        $body = json_decode($request->rawBody(), true);
        if (!$body) {
            return '';
        }

        $contents = [];

        // OpenAI格式 messages
        if (isset($body['messages']) && is_array($body['messages'])) {
            foreach ($body['messages'] as $message) {
                if (isset($message['content'])) {
                    if (is_string($message['content'])) {
                        $contents[] = $message['content'];
                    } elseif (is_array($message['content'])) {
                        foreach ($message['content'] as $part) {
                            if (isset($part['text'])) {
                                $contents[] = $part['text'];
                            }
                        }
                    }
                }
            }
        }

        // prompt字段
        if (isset($body['prompt'])) {
            $contents[] = is_array($body['prompt']) ? implode(' ', $body['prompt']) : $body['prompt'];
        }

        // input字段
        if (isset($body['input'])) {
            $contents[] = is_array($body['input']) ? implode(' ', $body['input']) : $body['input'];
        }

        return implode(' ', $contents);
    }

    /**
     * 检查屏蔽词
     */
    protected function checkBlockedWords(string $content, array $types): ?array
    {
        $words = $this->getBlockedWords($types);
        $matchedWords = [];
        $rejectWord = null;

        foreach ($words as $word) {
            if (mb_stripos($content, $word['word']) !== false) {
                if ($word['action'] === BlockedWord::ACTION_REJECT) {
                    return [
                        'action' => BlockedWord::ACTION_REJECT,
                        'word' => $word['word'],
                    ];
                }
                $matchedWords[] = $word;
            }
        }

        if (!empty($matchedWords)) {
            return [
                'action' => BlockedWord::ACTION_REPLACE,
                'words' => $matchedWords,
            ];
        }

        return null;
    }

    /**
     * 获取屏蔽词列表（带缓存）
     */
    protected function getBlockedWords(array $types): array
    {
        $cacheKey = 'blocked_words:' . implode(',', $types);

        try {
            $cached = Redis::get($cacheKey);
            if ($cached) {
                return json_decode($cached, true);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $words = BlockedWord::where('status', 1)
            ->whereIn('type', $types)
            ->get(['word', 'action', 'replacement'])
            ->toArray();

        try {
            Redis::setex($cacheKey, 300, json_encode($words));
        } catch (\Throwable $e) {
            // ignore
        }

        return $words;
    }

    /**
     * 替换请求体中的屏蔽词
     */
    protected function replaceInputContent(Request $request, array $words): void
    {
        $rawBody = $request->rawBody();
        foreach ($words as $word) {
            $replacement = $word['replacement'] ?? '***';
            $rawBody = str_ireplace($word['word'], $replacement, $rawBody);
        }
        // 注入修改后的请求体（通过属性传递给后续处理）
        $request->filteredBody = $rawBody;
    }

    /**
     * 返回拒绝响应
     */
    protected function rejectResponse(string $word): Response
    {
        return json([
            'error' => [
                'message' => '请求包含不允许的内容',
                'type' => 'content_filter_error',
                'code' => 'content_blocked',
            ]
        ], 400);
    }

    /**
     * 获取系统设置
     */
    protected function getOption(string $key): bool
    {
        try {
            $cached = Redis::hGet('options', $key);
            if ($cached !== false) {
                return (bool)$cached;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return (bool)\app\model\Option::getOption($key, '0');
    }
}
