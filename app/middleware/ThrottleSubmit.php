<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Redis;

class ThrottleSubmit implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 只对 POST/PUT/DELETE 请求做防重复
        if (!in_array($request->method(), ['POST', 'PUT', 'DELETE'])) {
            return $handler($request);
        }

        // 排除 AI 中转接口（/v1/*），这些接口有独立的限流策略
        if (str_starts_with($request->path(), '/v1/')) {
            return $handler($request);
        }

        // 生成请求指纹（用户ID + 路径 + 请求体hash）
        $userId = $request->session()->get('user.id') ?? 'anonymous';
        $path = $request->path();
        $bodyHash = md5($request->rawBody());
        $key = "throttle:{$userId}:{$path}:{$bodyHash}";

        $redis = Redis::connection();
        // 3秒内不允许重复提交
        if ($redis->exists($key)) {
            return json(['code' => 429, 'msg' => '操作过于频繁，请稍后再试']);
        }

        $redis->setex($key, 3, '1');

        return $handler($request);
    }
}
