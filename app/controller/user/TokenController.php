<?php

namespace app\controller\user;

use support\Request;
use app\model\Token;

class TokenController
{
    /**
     * 令牌列表（当前用户的所有令牌）
     */
    public function index(Request $request)
    {
        $userId = $request->user['id'];

        $tokens = Token::with('category')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->get();

        // 令牌key只显示前8位
        $tokens->each(function ($token) {
            $token->makeVisible('key');
            $token->key_preview = substr($token->key, 0, 8) . '...';
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => $tokens]);
    }

    /**
     * 创建令牌
     */
    public function store(Request $request)
    {
        $userId = $request->user['id'];
        $name = $request->post('name', '');

        if (empty($name)) {
            return json(['code' => 400, 'msg' => '令牌名称不能为空']);
        }

        $token = new Token();
        $token->user_id = $userId;
        $token->name = $name;
        $token->key = 'sk-' . bin2hex(random_bytes(24));
        $token->status = 1;
        $token->max_budget = (float)$request->post('max_budget', 0); // 0表示无限制
        $token->category_id = (int)$request->post('category_id', 0);
        $token->used_amount = 0;

        // 过期时间
        $expiresAt = $request->post('expires_at');
        if ($expiresAt) {
            $token->expired_at = $expiresAt;
        }

        // 模型限制
        $modelsLimit = $request->post('models_limit');
        if ($modelsLimit) {
            $token->model_limits_enabled = 1;
            $token->model_limits = is_array($modelsLimit) ? implode(',', $modelsLimit) : $modelsLimit;
        } else {
            $token->model_limits_enabled = 0;
            $token->model_limits = '';
        }

        // IP白名单
        $ipLimit = $request->post('ip_limit');
        if ($ipLimit) {
            $token->allow_ips = is_array($ipLimit) ? implode(',', $ipLimit) : $ipLimit;
        } else {
            $token->allow_ips = '';
        }

        $token->save();

        // 返回时显示完整key（仅创建时）
        $token->makeVisible('key');

        return json(['code' => 0, 'msg' => '创建成功', 'data' => $token]);
    }

    /**
     * 令牌详情
     */
    public function show(Request $request, $id)
    {
        $userId = $request->user['id'];
        $token = Token::where('user_id', $userId)->where('id', $id)->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '令牌不存在']);
        }

        $token->makeVisible('key');
        $token->key_preview = substr($token->key, 0, 8) . '...';

        return json(['code' => 0, 'msg' => 'ok', 'data' => $token]);
    }

    /**
     * 更新令牌
     */
    public function update(Request $request, $id)
    {
        $userId = $request->user['id'];
        $token = Token::where('user_id', $userId)->where('id', $id)->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '令牌不存在']);
        }

        $name = $request->post('name');
        if ($name !== null) {
            $token->name = $name;
        }

        $categoryId = $request->post('category_id');
        if ($categoryId !== null) {
            $token->category_id = (int)$categoryId;
        }

        $maxBudget = $request->post('max_budget');
        if ($maxBudget !== null) {
            $token->max_budget = (float)$maxBudget;
        }

        $expiresAt = $request->post('expires_at');
        if ($expiresAt !== null) {
            $token->expired_at = $expiresAt ?: null;
        }

        $modelsLimit = $request->post('models_limit');
        if ($modelsLimit !== null) {
            if ($modelsLimit) {
                $token->model_limits_enabled = 1;
                $token->model_limits = is_array($modelsLimit) ? implode(',', $modelsLimit) : $modelsLimit;
            } else {
                $token->model_limits_enabled = 0;
                $token->model_limits = '';
            }
        }

        $ipLimit = $request->post('ip_limit');
        if ($ipLimit !== null) {
            $token->allow_ips = is_array($ipLimit) ? implode(',', $ipLimit) : $ipLimit;
        }

        $token->save();

        return json(['code' => 0, 'msg' => '更新成功', 'data' => $token]);
    }

    /**
     * 删除令牌
     */
    public function destroy(Request $request, $id)
    {
        $userId = $request->user['id'];
        $token = Token::where('user_id', $userId)->where('id', $id)->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '令牌不存在']);
        }

        $token->delete();

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 启用/禁用令牌
     */
    public function toggleStatus(Request $request, $id)
    {
        $userId = $request->user['id'];
        $token = Token::where('user_id', $userId)->where('id', $id)->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '令牌不存在']);
        }

        $token->status = $token->status === 1 ? 2 : 1;
        $token->save();

        return json(['code' => 0, 'msg' => ($token->status === 1 ? '已启用' : '已禁用'), 'data' => ['status' => $token->status]]);
    }

    /**
     * 重置令牌key
     */
    public function resetKey(Request $request, $id)
    {
        $userId = $request->user['id'];
        $token = Token::where('user_id', $userId)->where('id', $id)->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '令牌不存在']);
        }

        $token->key = 'sk-' . bin2hex(random_bytes(24));
        $token->save();

        $token->makeVisible('key');

        return json(['code' => 0, 'msg' => '重置成功', 'data' => ['key' => $token->key]]);
    }
}
