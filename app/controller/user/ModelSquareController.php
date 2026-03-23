<?php

namespace app\controller\user;

use support\Request;
use support\Response;
use app\model\Model_;
use app\model\ChannelModel;
use app\model\Channel;

class ModelSquareController
{
    /**
     * 渠道类型到端点的映射
     */
    private static $endpointMap = [
        1 => [ // OpenA
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
        2 => [ // Anthropic
            ['path' => '/v1/messages', 'method' => 'POST', 'type' => 'anthropic'],
        ],
    ];

    private static $defaultEndpoints = [
        ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'custom'],
    ];

    /**
     * 根据模型ID批量推导端点
     */
    private static function deriveEndpointsForModels(array $modelIds): array
    {
        if (empty($modelIds)) {
            return [];
        }

        $channelModels = ChannelModel::whereIn('model_id', $modelIds)
            ->where('status', 1)
            ->get();

        $channelIds = $channelModels->pluck('channel_id')->unique()->toArray();
        $channels = Channel::whereIn('id', $channelIds)
            ->where('status', 1)
            ->get()
            ->keyBy('id');

        $result = [];
        foreach ($channelModels as $cm) {
            $channel = $channels->get($cm->channel_id);
            if (!$channel) {
                continue;
            }
            $endpoints = self::$endpointMap[$channel->type] ?? self::$defaultEndpoints;
            if (!isset($result[$cm->model_id])) {
                $result[$cm->model_id] = [];
            }
            foreach ($endpoints as $ep) {
                $result[$cm->model_id][$ep['type']] = $ep;
            }
        }

        foreach ($result as $modelId => $eps) {
            $result[$modelId] = array_values($eps);
        }

        return $result;
    }

    /**
     * 获取模型广场列表
     * GET /api/user/model-square
     */
    public function index(Request $request): Response
    {
        $type = $request->input('type');
        $provider = $request->input('provider');
        $keyword = $request->input('keyword', '');

        $query = Model_::query()
            ->where('status', 1)
            ->with('pricing')
            ->orderBy('sort_order', 'desc')
            ->orderBy('id', 'desc');

        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
        }
        if ($provider !== null && $provider !== '') {
            $query->where('provider', $provider);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('model_name', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }

        $models = $query->get();

        // 批量推导端点
        $modelIds = $models->pluck('id')->toArray();
        $derivedEndpoints = self::deriveEndpointsForModels($modelIds);

        $list = $models->map(function($model) use ($derivedEndpoints) {
            $data = $model->toArray();
            $endpoints = $data['endpoints'] ?? [];
            if (empty($endpoints)) {
                $data['endpoints'] = $derivedEndpoints[$model->id] ?? [];
            }
            return $data;
        });

        return json(['code' => 0, 'msg' => 'ok', 'data' => $list]);
    }

    /**
     * 获取所有供应商列表（用于前端筛选标签）
     * GET /api/user/model-square/providers
     */
    public function providers(Request $request): Response
    {
        $providers = Model_::where('status', 1)
            ->where('provider', '!=', '')
            ->distinct()
            ->pluck('provider')
            ->values();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $providers]);
    }

    /**
     * 获取模型详情
     * GET /api/user/model-square/{id}
     */
    public function show(Request $request, $id): Response
    {
        $model = Model_::query()
            ->where('status', 1)
            ->with('pricing')
            ->find($id);

        if (!$model) {
            return json(['code' => 404, 'msg' => '模型不存在或未启用'], 404);
        }

        // 自动推导端点
        $endpoints = $model->endpoints ?? [];
        if (empty($endpoints)) {
            $derivedEndpoints = self::deriveEndpointsForModels([$model->id]);
            $endpoints = $derivedEndpoints[$model->id] ?? [];
        }

        return json([
            'code' => 0,
            'msg' => 'ok',
            'data' => [
                'id' => $model->id,
                'name' => $model->model_name,
                'display_name' => $model->display_name,
                'type' => $model->type,
                'provider' => $model->provider,
                'description' => $model->description,
                'tags' => $model->tags ?? [],
                'endpoints' => $endpoints,
                'pricing' => $model->pricing,
            ],
        ]);
    }
}
