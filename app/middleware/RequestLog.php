<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use support\Log as Logger;

class RequestLog implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $startTime = microtime(true);

        $response = $handler($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $statusCode = $response->getStatusCode();
        $method = $request->method();
        $uri = $request->uri();
        $ip = $request->getRealIp();

        // 记录请求日志
        $logMessage = sprintf(
            '%s %s %s %dms %s',
            $method,
            $uri,
            $statusCode,
            $duration,
            $ip
        );

        if ($statusCode >= 500) {
            Logger::error($logMessage);
        } elseif ($statusCode >= 400) {
            Logger::warning($logMessage);
        } else {
            Logger::info($logMessage);
        }

        // 添加请求耗时响应头
        $response->withHeaders([
            'X-Request-Time' => $duration . 'ms',
        ]);

        return $response;
    }
}
