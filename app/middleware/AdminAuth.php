<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AdminAuth implements MiddlewareInterface
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
            return redirect('/admin/login');
        }

        // 权限不足（role >= 10 为管理员）
        if (($user['role'] ?? 0) < 10) {
            if (str_starts_with($request->path(), '/api/') || $request->isAjax() || $request->expectsJson()) {
                return json(['code' => 403, 'message' => '权限不足'], 403);
            }
            return redirect('/admin/login');
        }

        // 将用户信息注入请求对象
        $request->user = $user;

        return $handler($request);
    }
}
