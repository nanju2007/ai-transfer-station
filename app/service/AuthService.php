<?php

namespace app\service;

use app\model\User;
use app\model\Wallet;
use app\model\WalletTransaction;
use app\model\Option;
use support\Db;
use support\Request;

class AuthService
{
    /**
     * 用户登录
     */
    public function login(string $username, string $password, Request $request): array
    {
        $user = User::where('username', $username)->first();

        if (!$user || !password_verify($password, $user->password)) {
            return ['code' => 401, 'message' => '用户名或密码错误'];
        }

        if ($user->status !== 1) {
            return ['code' => 403, 'message' => '账号已被禁用'];
        }

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        // 设置Session
        $sessionData = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];
        $request->session()->set('user', $sessionData);

        return [
            'code' => 0,
            'message' => '登录成功',
            'data' => $sessionData,
        ];
    }

    /**
     * 用户注册
     */
    public function register(array $data, Request $request): array
    {
        // 检查注册开关
        $registerEnabled = Option::getOption('register_enabled', '1');
        if (!$registerEnabled || $registerEnabled === '0') {
            return ['code' => 403, 'message' => '注册功能已关闭'];
        }

        // 检查用户名是否已存在
        if (User::where('username', $data['username'])->exists()) {
            return ['code' => 400, 'message' => '用户名已存在'];
        }

        // 检查邮箱是否已存在
        if (!empty($data['email']) && User::where('email', $data['email'])->exists()) {
            return ['code' => 400, 'message' => '邮箱已被注册'];
        }

        // 创建用户
        $user = new User();
        $user->username = $data['username'];
        $user->password = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->display_name = $data['display_name'] ?? $data['username'];
        $user->email = $data['email'] ?? '';
        $user->role = 1;
        $user->status = 1;
        $user->aff_code = $this->generateAffCode();
        $user->invite_code = $this->generateInviteCode();
        $user->last_login_ip = $request->getRealIp();

        // 处理邀请码（兼容 aff_code 和 invite_code）
        $inviter = null;
        $inviteCode = $data['aff_code'] ?? $data['invite_code'] ?? '';
        if (!empty($inviteCode)) {
            $inviter = User::where('aff_code', $inviteCode)
                ->orWhere('invite_code', $inviteCode)
                ->first();
            if ($inviter) {
                $user->inviter_id = $inviter->id;
                $user->invited_by = $inviter->id;
                $inviter->increment('aff_count');
            }
        }

        $user->save();

        // 获取新用户初始额度
        $initialBalance = (float)Option::getOption('new_user_initial_balance', '0');

        // 创建钱包（含初始额度）
        $wallet = Wallet::create([
            'user_id' => $user->id,
            'balance' => $initialBalance,
            'total_recharge' => $initialBalance,
            'currency' => 'CNY',
        ]);

        // 记录初始额度交易
        if ($initialBalance > 0) {
            WalletTransaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => WalletTransaction::TYPE_RECHARGE,
                'amount' => $initialBalance,
                'balance_before' => 0,
                'balance_after' => $initialBalance,
                'description' => '新用户初始额度',
                'related_id' => 0,
                'related_type' => 'initial_balance',
            ]);
        }

        // 邀请人奖励
        if ($inviter) {
            $inviteReward = (float)Option::getOption('invite_reward_amount', '0');
            if ($inviteReward > 0) {
                try {
                    Db::beginTransaction();
                    $inviterWallet = Wallet::where('user_id', $inviter->id)->lockForUpdate()->first();
                    if ($inviterWallet) {
                        $beforeBalance = (float)$inviterWallet->balance;
                        $afterBalance = round($beforeBalance + $inviteReward, 4);
                        Wallet::where('id', $inviterWallet->id)->update([
                            'balance' => $afterBalance,
                            'total_recharge' => Db::raw("total_recharge + " . (string)$inviteReward),
                        ]);
                        WalletTransaction::create([
                            'user_id' => $inviter->id,
                            'wallet_id' => $inviterWallet->id,
                            'type' => WalletTransaction::TYPE_RECHARGE,
                            'amount' => $inviteReward,
                            'balance_before' => $beforeBalance,
                            'balance_after' => $afterBalance,
                            'description' => "邀请用户 {$user->username} 奖励",
                            'related_id' => $user->id,
                            'related_type' => 'invite_reward',
                        ]);
                    }
                    Db::commit();
                } catch (\Throwable $e) {
                    Db::rollBack();
                    \support\Log::error('invite reward failed', [
                        'inviter_id' => $inviter->id,
                        'new_user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 自动登录
        $sessionData = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];
        $request->session()->set('user', $sessionData);

        return [
            'code' => 0,
            'message' => '注册成功',
            'data' => $sessionData,
        ];
    }

    /**
     * 退出登录
     */
    public function logout(Request $request): array
    {
        $request->session()->delete('user');
        return ['code' => 0, 'message' => '退出成功'];
    }

    /**
     * 修改密码
     */
    public function changePassword(int $userId, string $oldPassword, string $newPassword): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['code' => 404, 'message' => '用户不存在'];
        }

        if (!password_verify($oldPassword, $user->password)) {
            return ['code' => 400, 'message' => '原密码错误'];
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return ['code' => 0, 'message' => '密码修改成功'];
    }

    /**
     * 重置密码（管理员操作）
     */
    public function resetPassword(int $userId, string $newPassword): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['code' => 404, 'message' => '用户不存在'];
        }

        $user->password = password_hash($newPassword, PASSWORD_DEFAULT);
        $user->save();

        return ['code' => 0, 'message' => '密码重置成功'];
    }

    /**
     * 生成访问令牌
     */
    public function generateAccessToken(int $userId): array
    {
        $user = User::find($userId);
        if (!$user) {
            return ['code' => 404, 'message' => '用户不存在'];
        }

        $user->access_token = md5(uniqid((string)mt_rand(), true));
        $user->save();

        return [
            'code' => 0,
            'message' => '令牌生成成功',
            'data' => ['access_token' => $user->access_token],
        ];
    }

    /**
     * 生成邀请码
     */
    protected function generateAffCode(): string
    {
        do {
            $code = strtolower(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
        } while (User::where('aff_code', $code)->exists());

        return $code;
    }

    /**
     * 生成用户端邀请码（唯一）
     */
    protected function generateInviteCode(): string
    {
        do {
            $code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        } while (User::where('invite_code', $code)->exists());

        return $code;
    }
}
