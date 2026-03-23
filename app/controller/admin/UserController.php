<?php

namespace app\controller\admin;

use support\Request;
use app\model\User;
use app\model\Wallet;
use app\model\WalletTransaction;

class UserController
{
    /**
     * 用户列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $keyword = $request->input('keyword', '');
        $status = $request->input('status');
        $role = $request->input('role');

        $query = User::query()->orderBy('id', 'desc');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%")
                  ->orWhere('email', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }
        if ($role !== null && $role !== '') {
            $query->where('role', (int)$role);
        }

        $paginator = $query->paginate($perPage);

        // 为每个用户附加钱包余额
        $items = collect($paginator->items())->map(function ($user) {
            $wallet = Wallet::where('user_id', $user->id)->first();
            $userData = $user->toArray();
            $userData['balance'] = $wallet ? $wallet->balance : '0.0000';
            return $userData;
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $items->values()->all(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]]);
    }

    /**
     * 创建用户
     */
    public function store(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');

        if (!$username || !$password) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        if (User::where('username', $username)->exists()) {
            return json(['code' => 400, 'msg' => '用户名已存在']);
        }

        $email = $request->post('email', '');
        if ($email && User::where('email', $email)->exists()) {
            return json(['code' => 400, 'msg' => '邮箱已被注册']);
        }

        $user = new User();
        $user->username = $username;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->display_name = $request->post('display_name', $username);
        $user->email = $email;
        $user->role = (int)$request->post('role', 1);
        $user->group_name = $request->post('group_name', 'default');
        $user->status = (int)$request->post('status', 1);
        $user->quota = (int)$request->post('quota', 0);
        $user->save();

        // 创建钱包
        Wallet::create([
            'user_id' => $user->id,
            'balance' => 0,
            'currency' => 'CNY',
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $user]);
    }

    /**
     * 用户详情
     */
    public function show(Request $request, $id)
    {
        $user = User::with('wallet')->find($id);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $user]);
    }

    /**
     * 更新用户信息
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        $fields = ['display_name', 'email', 'role', 'group_name', 'status', 'quota'];
        foreach ($fields as $field) {
            $value = $request->post($field);
            if ($value !== null) {
                $user->$field = $value;
            }
        }

        // 修改密码
        $password = $request->post('password');
        if ($password) {
            $user->password = password_hash($password, PASSWORD_DEFAULT);
        }

        // 检查邮箱唯一性
        if ($request->post('email') && $request->post('email') !== $user->getOriginal('email')) {
            if (User::where('email', $request->post('email'))->where('id', '!=', $id)->exists()) {
                return json(['code' => 400, 'msg' => '邮箱已被注册']);
            }
        }

        $user->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $user]);
    }

    /**
     * 删除用户
     */
    public function destroy(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        if ($user->role >= 100) {
            return json(['code' => 403, 'msg' => '不能删除超级管理员']);
        }

        $user->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 启用/禁用用户
     */
    public function updateStatus(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        $status = (int)$request->post('status', 0);
        if (!in_array($status, [1, 2])) {
            return json(['code' => 400, 'msg' => '状态值无效']);
        }

        $user->status = $status;
        $user->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $user]);
    }

    /**
     * 调整用户余额（单位：元）
     */
    public function updateQuota(Request $request, $id)
    {
        $user = User::find($id);
        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        // 前端传入的金额，单位：元
        $amount = $request->post('amount', 0);
        $amount = round((float)$amount, 4);
        if ($amount == 0) {
            return json(['code' => 400, 'msg' => '调整金额不能为0']);
        }

        // 获取或创建钱包
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            $wallet = Wallet::create([
                'user_id' => $user->id,
                'balance' => 0,
                'currency' => 'CNY',
            ]);
        }

        $balanceBefore = (string)($wallet->balance ?? '0');
        $balanceAfter = bcadd($balanceBefore, (string)$amount, 4);

        if (bccomp($balanceAfter, '0', 4) < 0) {
            return json(['code' => 400, 'msg' => '余额不足，无法扣减']);
        }

        $wallet->balance = $balanceAfter;
        if ($amount > 0) {
            $wallet->total_recharge = bcadd((string)($wallet->total_recharge ?? '0'), (string)$amount, 4);
        }
        $wallet->save();

        // 记录钱包交易
        WalletTransaction::create([
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => WalletTransaction::TYPE_ADJUST,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => $amount > 0 ? '管理员充值' : '管理员扣款',
        ]);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'balance' => $balanceAfter,
            'old_balance' => $balanceBefore,
            'change' => $amount,
        ]]);
    }
}
