<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use app\model\Token;
use app\model\User;
use app\model\Wallet;
use support\Redis;

class ApiAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 从请求头中提取API密钥，支持两种方式：
        // 1. OpenAI格式: Authorization: Bearer sk-xxx
        // 2. Anthropic格式: x-api-key: sk-xxx
        $key = '';

        // 优先检查 Authorization: Bearer 头
        $authorization = $request->header('authorization', '');
        if (preg_match('/^Bearer\s+(sk-.+)$/i', $authorization, $matches)) {
            $key = $matches[1];
        }

        // 如果没有从 Authorization 头获取到，尝试 x-api-key 头（Anthropic格式）
        if (empty($key)) {
            $xApiKey = $request->header('x-api-key', '');
            if (!empty($xApiKey) && str_starts_with($xApiKey, 'sk-')) {
                $key = $xApiKey;
            }
        }

        if (empty($key)) {
            return $this->errorResponse('无效的API密钥，请提供 Authorization: Bearer sk-xxx 或 x-api-key: sk-xxx', 'invalid_api_key', 401);
        }

        // 尝试从Redis缓存获取令牌信息
        $tokenData = $this->getTokenFromCache($key);

        if (!$tokenData) {
            // 从数据库查询
            $token = Token::where('key', $key)->first();
            if (!$token) {
                return $this->errorResponse('无效的API密钥', 'invalid_api_key', 401);
            }
            $tokenData = $token->toArray();
            // 缓存到Redis（5分钟）
            $this->cacheToken($key, $tokenData);
        }

        // 检查令牌状态
        if ($tokenData['status'] !== 1) {
            return $this->errorResponse('API密钥已被禁用', 'api_key_disabled', 403);
        }

        // 检查过期时间
        if (!empty($tokenData['expired_at']) && strtotime($tokenData['expired_at']) < time()) {
            return $this->errorResponse('API密钥已过期', 'api_key_expired', 403);
        }

        // 检查令牌消费额度
        $maxBudget = (float)($tokenData['max_budget'] ?? 0);
        $usedAmount = (float)($tokenData['used_amount'] ?? 0);
        if ($maxBudget > 0 && $usedAmount >= $maxBudget) {
            return $this->errorResponse('令牌消费已达上限', 'token_budget_exceeded', 429);
        }

        // 检查IP白名单
        if (!empty($tokenData['allow_ips'])) {
            $allowIps = array_filter(explode("\n", $tokenData['allow_ips']));
            $clientIp = $request->getRealIp();
            if (!empty($allowIps) && !in_array($clientIp, $allowIps)) {
                return $this->errorResponse('IP地址不在白名单中', 'ip_not_allowed', 403);
            }
        }

        // 获取用户信息
        $user = User::find($tokenData['user_id']);
        if (!$user || $user->status !== 1) {
            return $this->errorResponse('用户账号异常', 'user_disabled', 403);
        }

        // 判断是否为只读请求（GET请求如模型列表不需要检查余额和模型权限）
        $isReadOnly = $request->method() === 'GET';

        // 检查钱包余额（仅对非只读请求检查，模型列表等GET请求不需要余额）
        if (!$isReadOnly) {
            $wallet = Wallet::where('user_id', $tokenData['user_id'])->first();
            if (!$wallet || (float)$wallet->balance <= 0) {
                return $this->errorResponse('余额不足，请充值后再试', 'insufficient_balance', 402);
            }
        }

        // 检查模型访问权限（仅对非只读请求检查）
        $requestModel = $this->extractModelFromRequest($request);
        if (!$isReadOnly && $tokenData['model_limits_enabled'] && $requestModel) {
            $modelLimits = json_decode($tokenData['model_limits'] ?? '[]', true) ?: [];
            if (!empty($modelLimits) && !in_array($requestModel, $modelLimits)) {
                return $this->errorResponse('该API密钥无权访问模型: ' . $requestModel, 'model_not_allowed', 403);
            }
        }

        // 检测客户端请求格式（openai 或 anthropic）
        // 判断依据：请求路径为 /v1/messages 且有 anthropic-version 头
        $clientFormat = 'openai';
        $path = $request->path();
        $anthropicVersion = $request->header('anthropic-version', '');
        if (str_contains($path, '/v1/messages') || !empty($anthropicVersion)) {
            $clientFormat = 'anthropic';
        }

        // 将信息注入请求对象
        $request->tokenData = $tokenData;
        $request->user = $user->toArray();
        $request->apiModel = $requestModel;
        $request->clientFormat = $clientFormat;
        $request->categoryId = (int)($tokenData['category_id'] ?? 0);

        return $handler($request);
    }

    /**
     * 从Redis缓存获取令牌
     */
    protected function getTokenFromCache(string $key): ?array
    {
        try {
            $cached = Redis::get('token:' . $key);
            return $cached ? json_decode($cached, true) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 缓存令牌到Redis
     */
    protected function cacheToken(string $key, array $data): void
    {
        try {
            Redis::setex('token:' . $key, 300, json_encode($data));
        } catch (\Throwable $e) {
            // 缓存失败不影响业务
        }
    }

    /**
     * 从请求体中提取模型名称
     */
    protected function extractModelFromRequest(Request $request): string
    {
        $body = json_decode($request->rawBody(), true);
        return $body['model'] ?? '';
    }

    /**
     * 返回错误响应（正确设置HTTP状态码）
     */
    protected function errorResponse(string $message, string $code, int $httpStatus): Response
    {
        return new Response($httpStatus, ['Content-Type' => 'application/json'], json_encode([
            'error' => [
                'message' => $message,
                'type' => 'authentication_error',
                'code' => $code,
            ]
        ], JSON_UNESCAPED_UNICODE));
    }
}
