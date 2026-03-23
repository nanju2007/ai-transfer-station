<?php

namespace app\controller\admin;

use support\Request;
use app\model\Channel;
use app\service\ChannelRateLimiter;

class ChannelController
{
    /**
     * 渠道列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $keyword = $request->input('keyword', '');
        $status = $request->input('status');
        $type = $request->input('type');

        $query = Channel::query()->orderBy('id', 'desc');

        if ($keyword !== '') {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }
        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
        }

        $paginator = $query->paginate($perPage);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'list' => $paginator->items(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ]]);
    }

    /**
     * 创建渠道
     */
    public function store(Request $request)
    {
        $name = $request->post('name', '');
        if (!$name) {
            return json(['code' => 400, 'msg' => '渠道名称不能为空']);
        }

        $type = (int)$request->post('type', 1);
        $key = $request->post('key', '');
        if (!$key) {
            return json(['code' => 400, 'msg' => 'API密钥不能为空']);
        }

        $channel = new Channel();
        $channel->name = $name;
        $channel->type = $type;
        $channel->key = $key;
        $channel->base_url = $request->post('base_url', '');
        $channel->status = (int)$request->post('status', 1);
        $channel->weight = (int)$request->post('weight', 1);
        $channel->priority = (int)$request->post('priority', 0);
        $channel->pass_through = (int)$request->post('pass_through', 0);
        $channel->models = $request->post('models', '');
        $channel->model_mapping = $request->post('model_mapping', '');
        $channel->test_model = $request->post('test_model', '');
        $channel->max_input_tokens = (int)$request->post('max_input_tokens', 0);
        $channel->auto_ban = (int)$request->post('auto_ban', 1);
        $channel->remark = $request->post('remark', '');
        $channel->rate_limit = (int)$request->post('rate_limit', 0);
        $channel->rate_limit_window = (int)$request->post('rate_limit_window', 60);
        $channel->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $channel]);
    }

    /**
     * 渠道详情
     */
    public function show(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $channel]);
    }

    /**
     * 更新渠道
     */
    public function update(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $fields = ['name', 'type', 'key', 'base_url', 'status', 'weight', 'priority',
            'pass_through', 'models', 'model_mapping', 'test_model',
            'max_input_tokens', 'auto_ban', 'remark', 'rate_limit', 'rate_limit_window'];

        foreach ($fields as $field) {
            $value = $request->post($field);
            if ($value !== null) {
                $channel->$field = $value;
            }
        }
        $channel->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $channel]);
    }

    /**
     * 删除渠道（软删除）
     */
    public function destroy(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }
        $channel->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 启用/禁用渠道
     */
    public function updateStatus(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $status = (int)$request->post('status', 0);
        if (!in_array($status, [1, 2])) {
            return json(['code' => 400, 'msg' => '状态值无效']);
        }

        $channel->status = $status;
        $channel->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $channel]);
    }

    /**
     * 测试渠道连通性
     */
    public function test(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $testModel = $channel->test_model ?: 'gpt-3.5-turbo';
        $baseUrl = $channel->base_url ?: 'https://api.openai.com';
        $keys = explode("\n", $channel->key);
        $apiKey = trim($keys[0]);

        $startTime = microtime(true);

        try {
            if ($channel->type == 2) {
                // Anthropic
                $url = rtrim($baseUrl, '/') . '/v1/messages';
                $headers = [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: 2023-06-01',
                ];
                $body = json_encode([
                    'model' => $testModel,
                    'max_tokens' => 5,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ]);
            } else {
                // OpenAI兼容
                $url = rtrim($baseUrl, '/') . '/v1/chat/completions';
                $headers = [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                ];
                $body = json_encode([
                    'model' => $testModel,
                    'max_tokens' => 5,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ]);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $elapsed = round((microtime(true) - $startTime) * 1000);

            if ($error) {
                return json(['code' => 500, 'msg' => '连接失败: ' . $error]);
            }

            $channel->test_time = date('Y-m-d H:i:s');
            $channel->response_time = $elapsed;
            $channel->save();

            $success = $httpCode >= 200 && $httpCode < 300;

            return json(['code' => $success ? 0 : 500, 'msg' => $success ? '测试成功' : '测试失败', 'data' => [
                'http_code' => $httpCode,
                'response_time' => $elapsed,
                'response' => json_decode($response, true),
            ]]);
        } catch (\Throwable $e) {
            return json(['code' => 500, 'msg' => '测试异常: ' . $e->getMessage()]);
        }
    }

    /**
     * 查询上游余额
     */
    public function balance(Request $request, $id)
    {
        $channel = Channel::find($id);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'balance' => $channel->balance,
            'balance_updated_at' => $channel->balance_updated_at,
        ]]);
    }

    /**
     * 获取渠道实时限流状态
     */
    public function rateLimitStatus(Request $request)
    {
        $channelId = (int)$request->input('channel_id', 0);
        if (!$channelId) {
            return json(['code' => 400, 'msg' => '缺少 channel_id 参数']);
        }

        $channel = Channel::find($channelId);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $rateLimit = (int)$channel->rate_limit;
        $window = (int)$channel->rate_limit_window ?: 60;
        $currentCount = ChannelRateLimiter::getCount($channelId, $window);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'channel_id' => $channelId,
            'current_count' => $currentCount,
            'rate_limit' => $rateLimit,
            'rate_limit_window' => $window,
            'is_limited' => $rateLimit > 0 && $currentCount >= $rateLimit,
        ]]);
    }
}
