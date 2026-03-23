<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class UserAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        $user = $request->session()->get('user');

        // 未登录
        if (!$user) {
            // API请求返回JSON
            if (str_starts_with($request->path(), '/api/') || $request->isAjax() || $request->expectsJson()) {
                return json(['code' => 401, 'message' => '未登录，请先登录'], 401);
            }
            // 页面请求重定向到登录页
            return redirect('/user/login');
        }

        // 检查用户状态
        if (($user['status'] ?? 0) !== 1) {
            if (str_starts_with($request->path(), '/api/') || $request->isAjax() || $request->expectsJson()) {
                return json(['code' => 403, 'message' => '账号已被禁用'], 403);
            }
            return redirect('/user/login');
        }

        // 将用户信息注入请求对象
        $request->user = $user;

        return $handler($request);
    }
}
