<?php

namespace app\controller\admin;

use support\Request;
use app\model\User;
use app\service\AuthService;

class AuthController
{
    /**
     * 管理员登录
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (!$username || !$password) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        $user = User::where('username', $username)->first();
        if (!$user || !password_verify($password, $user->password)) {
            return json(['code' => 401, 'msg' => '用户名或密码错误']);
        }

        if ($user->role < 10) {
            return json(['code' => 403, 'msg' => '权限不足，非管理员账号']);
        }

        if ($user->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用']);
        }

        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        $sessionData = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];
        $request->session()->set('user', $sessionData);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $sessionData]);
    }

    /**
     * 退出登录
     */
    public function logout(Request $request)
    {
        $request->session()->delete('user');
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 获取当前管理员信息
     */
    public function info(Request $request)
    {
        $user = $request->session()->get('user');
        if (!$user) {
            return json(['code' => 401, 'msg' => '未登录']);
        }

        $userModel = User::find($user['id']);
        if (!$userModel) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'id' => $userModel->id,
            'username' => $userModel->username,
            'display_name' => $userModel->display_name,
            'email' => $userModel->email,
            'role' => $userModel->role,
            'status' => $userModel->status,
            'last_login_at' => $userModel->last_login_at,
            'last_login_ip' => $userModel->last_login_ip,
            'created_at' => $userModel->created_at,
        ]]);
    }
}
