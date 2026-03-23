<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Redis;

class RateLimit implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        try {
            $tokenData = $request->tokenData ?? null;
            $userId = $request->user['id'] ?? 0;

            // 全局速率限制
            $globalLimit = (int)($this->getOption('rate_limit_global') ?: 600);
            if ($globalLimit > 0) {
                $result = $this->checkRateLimit('global', 'all', $globalLimit, 60);
                if (!$result['allowed']) {
                    return $this->tooManyRequests($result, $globalLimit);
                }
            }

            // 用户级速率限制
            if ($userId) {
                $userLimit = (int)($this->getOption('rate_limit_per_user') ?: 120);
                if ($userLimit > 0) {
                    $result = $this->checkRateLimit('user', (string)$userId, $userLimit, 60);
                    if (!$result['allowed']) {
                        return $this->tooManyRequests($result, $userLimit);
                    }
                }
            }

            $response = $handler($request);

            // 添加速率限制响应头
            $limit = $userId ? ($userLimit ?? 120) : $globalLimit;
            $remaining = max(0, $limit - ($result['count'] ?? 0));
            $response->withHeaders([
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => $remaining,
                'X-RateLimit-Reset' => time() + 60,
            ]);

            return $response;
        } catch (\Throwable $e) {
            // Redis不可用时不阻断请求
            return $handler($request);
        }
    }

    /**
     * 滑动窗口速率限制检查
     */
    protected function checkRateLimit(string $type, string $id, int $limit, int $window): array
    {
        $key = "rate_limit:{$type}:{$id}";
        $now = microtime(true);
        $windowStart = $now - $window;

        // 使用Redis有序集合实现滑动窗口
        $pipe = Redis::pipeline();
        // 移除窗口外的记录
        $pipe->zRemRangeByScore($key, '-inf', (string)$windowStart);
        // 添加当前请求
        $pipe->zAdd($key, $now, $now . ':' . mt_rand());
        // 获取窗口内请求数
        $pipe->zCard($key);
        // 设置过期时间
        $pipe->expire($key, $window + 1);
        $results = $pipe->exec();

        $count = $results[2] ?? 0;

        return [
            'allowed' => $count <= $limit,
            'count' => $count,
            'limit' => $limit,
            'reset' => (int)($now + $window),
        ];
    }

    /**
     * 返回429响应
     */
    protected function tooManyRequests(array $result, int $limit): Response
    {
        $response = json([
            'error' => [
                'message' => '请求过于频繁，请稍后再试',
                'type' => 'rate_limit_error',
                'code' => 'rate_limit_exceeded',
            ]
        ], 429);

        $response->withHeaders([
            'X-RateLimit-Limit' => $limit,
            'X-RateLimit-Remaining' => 0,
            'X-RateLimit-Reset' => $result['reset'] ?? (time() + 60),
            'Retry-After' => 60,
        ]);

        return $response;
    }

    /**
     * 获取系统设置
     */
    protected function getOption(string $key): ?string
    {
        try {
            $cached = Redis::hGet('options', $key);
            if ($cached !== false) {
                return $cached;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return \app\model\Option::getOption($key);
    }
}
