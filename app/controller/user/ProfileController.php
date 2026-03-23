<?php

namespace app\controller\user;

use support\Request;
use app\model\User;
use app\service\AuthService;

class ProfileController
{
    /**
     * 获取个人信息
     */
    public function index(Request $request)
    {
        $userId = $request->user['id'];
        $user = User::find($userId);

        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'aff_code' => $user->aff_code,
            'aff_count' => $user->aff_count,
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'last_login_ip' => $user->last_login_ip,
            'created_at' => $user->created_at?->toDateTimeString(),
        ]]);
    }

    /**
     * 更新个人信息（昵称、邮箱等，不含密码）
     */
    public function update(Request $request)
    {
        $userId = $request->user['id'];
        $user = User::find($userId);

        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        $displayName = $request->post('display_name');
        if ($displayName !== null) {
            $user->display_name = $displayName;
        }

        $email = $request->post('email');
        if ($email !== null) {
            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return json(['code' => 400, 'msg' => '邮箱格式不正确']);
            }
            // 检查邮箱是否被其他用户使用
            if (!empty($email)) {
                $exists = User::where('email', $email)->where('id', '!=', $userId)->exists();
                if ($exists) {
                    return json(['code' => 400, 'msg' => '该邮箱已被其他用户使用']);
                }
            }
            $user->email = $email;
        }

        $user->save();

        // 同步更新session
        $sessionData = $request->session()->get('user');
        $sessionData['display_name'] = $user->display_name;
        $sessionData['email'] = $user->email;
        $request->session()->set('user', $sessionData);

        return json(['code' => 0, 'msg' => '更新成功', 'data' => [
            'display_name' => $user->display_name,
            'email' => $user->email,
        ]]);
    }

    /**
     * 修改密码（需验证旧密码）
     */
    public function changePassword(Request $request)
    {
        $userId = $request->user['id'];
        $oldPassword = $request->post('old_password', '');
        $newPassword = $request->post('new_password', '');

        if (empty($oldPassword) || empty($newPassword)) {
            return json(['code' => 400, 'msg' => '旧密码和新密码不能为空']);
        }

        if (strlen($newPassword) < 6) {
            return json(['code' => 400, 'msg' => '新密码长度不能少于6个字符']);
        }

        $authService = new AuthService();
        $result = $authService->changePassword($userId, $oldPassword, $newPassword);

        if ($result['code'] !== 0) {
            return json(['code' => $result['code'], 'msg' => $result['message']]);
        }

        return json(['code' => 0, 'msg' => '密码修改成功']);
    }
}
