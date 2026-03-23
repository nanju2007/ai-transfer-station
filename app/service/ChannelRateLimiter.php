<?php

namespace app\service;

use support\Redis;

class ChannelRateLimiter
{
    /**
     * 检查渠道是否可以接受请求
     * @param int $channelId 渠道ID
     * @param int $rateLimit 最大请求数（0表示不限制）
     * @param int $window 时间窗口（秒）
     * @return bool 是否允许
     */
    public static function check(int $channelId, int $rateLimit, int $window = 60): bool
    {
        if ($rateLimit <= 0) {
            return true;
        }

        $key = "channel_rate:{$channelId}";
        $now = microtime(true);
        $windowStart = $now - $window;

        // 清理窗口外的旧记录
        Redis::zRemRangeByScore($key, '-inf', (string)$windowStart);

        // 获取窗口内请求数
        $count = Redis::zCard($key);

        return $count < $rateLimit;
    }

    /**
     * 记录一次请求（在请求被允许后调用）
     * @param int $channelId
     * @param int $window
     */
    public static function record(int $channelId, int $window = 60): void
    {
        $key = "channel_rate:{$channelId}";
        $now = microtime(true);

        // 使用微秒时间戳 + 随机数作为唯一值，避免冲突
        $member = $now . ':' . mt_rand();

        Redis::zAdd($key, $now, $member);
        Redis::expire($key, $window + 1);
    }

    /**
     * 获取渠道当前请求计数
     * @param int $channelId
     * @param int $window
     * @return int
     */
    public static function getCount(int $channelId, int $window = 60): int
    {
        $key = "channel_rate:{$channelId}";
        $now = microtime(true);
        $windowStart = $now - $window;

        // 清理窗口外的旧记录后计数
        Redis::zRemRangeByScore($key, '-inf', (string)$windowStart);

        return (int)Redis::zCard($key);
    }

    /**
     * 重置渠道计数
     * @param int $channelId
     */
    public static function reset(int $channelId): void
    {
        $key = "channel_rate:{$channelId}";
        Redis::del($key);
    }
}
