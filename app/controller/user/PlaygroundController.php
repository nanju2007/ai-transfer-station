<?php

namespace app\controller\user;

use support\Request;
use app\model\Model_;
use app\model\Token;

class PlaygroundController
{
    /**
     * 获取当前用户可用的模型列表
     */
    public function models(Request $request)
    {
        $userId = $request->user['id'];

        // 获取系统中所有启用的模型
        $models = Model_::where('status', 1)
            ->orderBy('sort_order', 'desc')
            ->orderBy('id')
            ->get(['id', 'model_name', 'display_name', 'vendor', 'type', 'max_context', 'max_output']);

        // 检查用户令牌的模型限制
        $userTokens = Token::where('user_id', $userId)
            ->where('status', 1)
            ->get();

        // 收集用户令牌允许的模型（如果有限制的话）
        $hasLimitedToken = false;
        $allowedModels = [];
        foreach ($userTokens as $token) {
            if ($token->model_limits_enabled && !empty($token->model_limits)) {
                $hasLimitedToken = true;
                $limits = explode(',', $token->model_limits);
                $allowedModels = array_merge($allowedModels, $limits);
            }
        }

        // 如果存在模型限制的令牌，过滤模型列表
        if ($hasLimitedToken && !empty($allowedModels)) {
            $allowedModels = array_unique($allowedModels);
            $models = $models->filter(function ($model) use ($allowedModels) {
                return in_array($model->model_name, $allowedModels);
            })->values();
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $models]);
    }

    /**
     * 获取用户的第一个可用令牌 key（用于操练场调用 API）
     */
    public function token(Request $request)
    {
        $userId = $request->user['id'];

        $token = Token::where('user_id', $userId)
            ->where('status', 1)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$token) {
            return json(['code' => 404, 'msg' => '没有可用的令牌，请先创建令牌']);
        }

        // 检查是否过期
        if ($token->isExpired()) {
            return json(['code' => 400, 'msg' => '令牌已过期，请创建新令牌或更新过期时间']);
        }

        // 检查额度
        if (!$token->hasBudget()) {
            return json(['code' => 400, 'msg' => '令牌额度已用完']);
        }

        $token->makeVisible('key');

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'id' => $token->id,
                'name' => $token->name,
                'key' => $token->key,
            ]
        ]);
    }

    /**
     * 获取用户所有可用令牌列表（用于操练场选择令牌）
     */
    public function tokens(Request $request)
    {
        $userId = $request->user['id'];

        $tokens = Token::where('user_id', $userId)
            ->where('status', 1)
            ->orderBy('created_at', 'asc')
            ->get();

        $result = [];
        foreach ($tokens as $token) {
            if (!$token->isExpired() && $token->hasBudget()) {
                $token->makeVisible('key');
                $result[] = [
                    'id' => $token->id,
                    'name' => $token->name,
                    'key' => $token->key,
                ];
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $result]);
    }
}
