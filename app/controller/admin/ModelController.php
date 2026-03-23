<?php

namespace app\controller\admin;

use support\Request;
use app\model\Model_;
use app\model\Channel;
use app\model\ChannelModel;
use app\model\CategoryChannel;
use Illuminate\Database\Capsule\Manager as DB;

class ModelController
{
    protected function normalizeJsonArrayField($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }

            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * 模型列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $keyword = $request->input('keyword', '');
        $status = $request->input('status');
        $vendor = $request->input('vendor');
        $provider = $request->input('provider');

        $query = Model_::query()->orderBy('sort_order', 'desc')->orderBy('id', 'desc');

        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('model_name', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }
        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }
        if ($vendor !== null && $vendor !== '') {
            $query->where('vendor', $vendor);
        }
        if ($provider !== null && $provider !== '') {
            $query->where('provider', $provider);
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
     * 创建模型
     */
    public function store(Request $request)
    {
        $modelName = $request->post('model_name', '');
        if (!$modelName) {
            return json(['code' => 400, 'msg' => '模型标识名不能为空']);
        }

        if (Model_::where('model_name', $modelName)->exists()) {
            return json(['code' => 400, 'msg' => '模型标识名已存在']);
        }

        $model = new Model_();
        $model->model_name = $modelName;
        $model->display_name = $request->post('display_name', $modelName);
        $model->vendor = $request->post('vendor', 'openai');
        $model->type = (int)$request->post('type', 1);
        $model->provider = $request->post('provider', '');
        $model->description = $request->post('description');
        $model->tags = $this->normalizeJsonArrayField($request->post('tags', []));
        $model->endpoints = $this->normalizeJsonArrayField($request->post('endpoints', []));
        $model->max_context = (int)$request->post('max_context', 0);
        $model->max_output = (int)$request->post('max_output', 0);
        $model->status = (int)$request->post('status', 1);
        $model->sort_order = (int)$request->post('sort_order', 0);
        $model->save();

        // 关联渠道
        $channelIds = $request->post('channel_ids', []);
        if (!empty($channelIds) && is_array($channelIds)) {
            foreach ($channelIds as $channelId) {
                ChannelModel::create([
                    'channel_id' => (int)$channelId,
                    'model_id' => $model->id,
                    'status' => 1,
                ]);
            }
        }

        // 关联分类
        $categoryId = $request->post('category_id');
        if ($categoryId && !empty($channelIds) && is_array($channelIds)) {
            foreach ($channelIds as $channelId) {
                CategoryChannel::firstOrCreate([
                    'category_id' => (int)$categoryId,
                    'channel_id' => (int)$channelId,
                    'model_name' => $model->model_name,
                ], [
                    'priority' => 0,
                    'weight' => 1,
                    'status' => 1,
                ]);
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $model]);
    }

    /**
     * 模型详情
     */
    public function show(Request $request, $id)
    {
        $model = Model_::with('channelModels')->find($id);
        if (!$model) {
            return json(['code' => 404, 'msg' => '模型不存在']);
        }
        return json(['code' => 0, 'msg' => 'ok', 'data' => $model]);
    }

    /**
     * 更新模型
     */
    public function update(Request $request, $id)
    {
        $model = Model_::find($id);
        if (!$model) {
            return json(['code' => 404, 'msg' => '模型不存在']);
        }

        $fields = ['model_name', 'display_name', 'vendor', 'type', 'provider',
            'description', 'tags', 'endpoints', 'max_context', 'max_output', 'status', 'sort_order'];

        foreach ($fields as $field) {
            $value = $request->post($field);
            if ($value !== null) {
                if (in_array($field, ['tags', 'endpoints'], true)) {
                    $value = $this->normalizeJsonArrayField($value);
                }
                $model->$field = $value;
            }
        }

        // 检查model_name唯一性
        if ($request->post('model_name') && $request->post('model_name') !== $model->getOriginal('model_name')) {
            if (Model_::where('model_name', $request->post('model_name'))->where('id', '!=', $id)->exists()) {
                return json(['code' => 400, 'msg' => '模型标识名已存在']);
            }
        }

        $model->save();

        // 更新渠道关联
        $channelIds = $request->post('channel_ids');
        if ($channelIds !== null && is_array($channelIds)) {
            ChannelModel::where('model_id', $model->id)->delete();
            foreach ($channelIds as $channelId) {
                ChannelModel::create([
                    'channel_id' => (int)$channelId,
                    'model_id' => $model->id,
                    'status' => 1,
                ]);
            }
        }

        // 更新分类关联
        $categoryId = $request->post('category_id');
        if ($categoryId !== null) {
            // 清除旧的分类关联
            CategoryChannel::where('model_name', $model->model_name)->delete();
            // 创建新的分类关联
            if ($categoryId && $channelIds !== null && is_array($channelIds)) {
                foreach ($channelIds as $channelId) {
                    CategoryChannel::firstOrCreate([
                        'category_id' => (int)$categoryId,
                        'channel_id' => (int)$channelId,
                        'model_name' => $model->model_name,
                    ], [
                        'priority' => 0,
                        'weight' => 1,
                        'status' => 1,
                    ]);
                }
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => $model]);
    }

    /**
     * 删除模型
     */
    public function destroy(Request $request, $id)
    {
        $model = Model_::find($id);
        if (!$model) {
            return json(['code' => 404, 'msg' => '模型不存在']);
        }

        ChannelModel::where('model_id', $model->id)->delete();
        $model->delete();

        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 从渠道批量添加模型（旧接口保留兼容）
     */
    public function batchAdd(Request $request)
    {
        $channelId = (int)$request->post('channel_id', 0);
        $channel = Channel::find($channelId);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $modelNames = $request->post('model_names', []);
        if (empty($modelNames) || !is_array($modelNames)) {
            $modelNames = array_filter(array_map('trim', explode(',', $channel->models ?? '')));
        }

        if (empty($modelNames)) {
            return json(['code' => 400, 'msg' => '没有可添加的模型']);
        }

        $added = 0;
        foreach ($modelNames as $name) {
            $model = Model_::where('model_name', $name)->first();
            if (!$model) {
                $model = Model_::create([
                    'model_name' => $name,
                    'display_name' => $name,
                    'vendor' => $channel->type == 2 ? 'anthropic' : 'openai',
                    'type' => 1,
                    'status' => 1,
                ]);
            }

            $exists = ChannelModel::where('channel_id', $channelId)
                ->where('model_id', $model->id)->exists();
            if (!$exists) {
                ChannelModel::create([
                    'channel_id' => $channelId,
                    'model_id' => $model->id,
                    'status' => 1,
                ]);
                $added++;
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['added' => $added]]);
    }

    /**
     * 批量创建模型（支持绑定渠道和分类）
     */
    public function batchCreate(Request $request)
    {
        $modelNamesStr = $request->post('model_names', '');
        if (!$modelNamesStr) {
            return json(['code' => 400, 'msg' => '模型名称不能为空']);
        }

        $modelNames = array_filter(array_map('trim', explode(',', $modelNamesStr)));
        if (empty($modelNames)) {
            return json(['code' => 400, 'msg' => '模型名称不能为空']);
        }

        $channelIds = $request->post('channel_ids', []);
        if (!is_array($channelIds)) {
            $channelIds = [];
        }

        $categoryId = $request->post('category_id');
        $provider = $request->post('provider', '');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        DB::connection()->beginTransaction();
        try {
            foreach ($modelNames as $name) {
                // 创建或获取模型
                $model = Model_::where('model_name', $name)->first();
                if ($model) {
                    $skipped++;
                } else {
                    $model = Model_::create([
                        'model_name' => $name,
                        'display_name' => $name,
                        'vendor' => 'openai',
                        'provider' => $provider,
                        'type' => 1,
                        'status' => 1,
                    ]);
                    if (!$model) {
                        $failed++;
                        continue;
                    }
                    $created++;
                }

                // 绑定渠道
                foreach ($channelIds as $channelId) {
                    ChannelModel::firstOrCreate([
                        'channel_id' => (int)$channelId,
                        'model_id' => $model->id,
                    ], [
                        'status' => 1,
                    ]);

                    // 绑定分类
                    if ($categoryId) {
                        CategoryChannel::firstOrCreate([
                            'category_id' => (int)$categoryId,
                            'channel_id' => (int)$channelId,
                            'model_name' => $model->model_name,
                        ], [
                            'priority' => 0,
                            'weight' => 1,
                            'status' => 1,
                        ]);
                    }
                }
            }

            DB::connection()->commit();
        } catch (\Exception $e) {
            DB::connection()->rollBack();
            return json(['code' => 500, 'msg' => '批量创建失败: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
        ]]);
    }

    /**
     * 从渠道API导入模型列表
     */
    public function importFromChannel(Request $request)
    {
        $channelId = (int)$request->post('channel_id', 0);
        if (!$channelId) {
            return json(['code' => 400, 'msg' => '渠道ID不能为空']);
        }

        $channel = Channel::find($channelId);
        if (!$channel) {
            return json(['code' => 404, 'msg' => '渠道不存在']);
        }

        $baseUrl = rtrim($channel->base_url ?: 'https://api.openai.com', '/');
        $apiKey = $channel->key;

        if (!$apiKey) {
            return json(['code' => 400, 'msg' => '渠道未配置API密钥']);
        }

        // 调用 /v1/models 接口
        $url = $baseUrl . '/v1/models';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$apiKey}\r\nContent-Type: application/json\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return json(['code' => 500, 'msg' => '请求渠道API失败，请检查渠道配置']);
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['data']) || !is_array($data['data'])) {
            return json(['code' => 500, 'msg' => '解析模型列表失败，返回格式不符合预期']);
        }

        $categoryId = $request->post('category_id');
        $created = 0;
        $skipped = 0;

        DB::connection()->beginTransaction();
        try {
            foreach ($data['data'] as $item) {
                $modelId = $item['id'] ?? '';
                if (!$modelId) {
                    continue;
                }

                // 创建或获取模型
                $model = Model_::where('model_name', $modelId)->first();
                if ($model) {
                    $skipped++;
                } else {
                    $model = Model_::create([
                        'model_name' => $modelId,
                        'display_name' => $modelId,
                        'vendor' => $item['owned_by'] ?? 'openai',
                        'type' => 1,
                        'status' => 1,
                    ]);
                    $created++;
                }

                // 绑定渠道
                ChannelModel::firstOrCreate([
                    'channel_id' => $channelId,
                    'model_id' => $model->id,
                ], [
                    'status' => 1,
                ]);

                // 绑定分类
                if ($categoryId) {
                    CategoryChannel::firstOrCreate([
                        'category_id' => (int)$categoryId,
                        'channel_id' => $channelId,
                        'model_name' => $model->model_name,
                    ], [
                        'priority' => 0,
                        'weight' => 1,
                        'status' => 1,
                    ]);
                }
            }

            DB::connection()->commit();
        } catch (\Exception $e) {
            DB::connection()->rollBack();
            return json(['code' => 500, 'msg' => '导入失败: ' . $e->getMessage()]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'total' => count($data['data']),
            'created' => $created,
            'skipped' => $skipped,
        ]]);
    }

    /**
     * 获取指定渠道的可用模型列表
     */
    public function channelModels(Request $request)
    {
        $channelId = (int)$request->input('channel_id', 0);
        if (!$channelId) {
            return json(['code' => 400, 'msg' => '渠道ID不能为空']);
        }

        $models = ChannelModel::where('channel_id', $channelId)
            ->with('model')
            ->get();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $models]);
    }
}
