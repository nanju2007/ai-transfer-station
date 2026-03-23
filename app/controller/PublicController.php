<?php
namespace app\controller;

use support\Request;
use support\Response;
use app\model\Model_;
use app\model\ModelPricing;
use app\model\Provider;
use app\model\ChannelModel;
use app\model\Channel;
use app\model\Option;
use app\model\Announcement;
use app\model\ModelCategory;
use app\model\CategoryChannel;

class PublicController
{
    /**
     * 渠道类型到端点的映射
     */
    private static $endpointMap = [
        1 => [ // OpenAI
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
        2 => [ // Anthropic
            ['path' => '/v1/messages', 'method' => 'POST', 'type' => 'anthropic'],
        ],
        3 => [ // Google Gemini
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
        4 => [ // DeepSeek
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
        5 => [ // Ollama
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
        6 => [ // OpenRouter
            ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'openai'],
        ],
    ];

    /**
     * 默认端点（自定义渠道）
     */
    private static $defaultEndpoints = [
        ['path' => '/v1/chat/completions', 'method' => 'POST', 'type' => 'custom'],
    ];

    /**
     * 根据模型ID批量推导端点
     * @param array $modelIds
     * @return array [model_id => endpoints[]]
     */
    private static function deriveEndpointsForModels(array $modelIds): array
    {
        if (empty($modelIds)) {
            return [];
        }

        // 查询所有模型关联的启用渠道及其类型
        $channelModels = ChannelModel::whereIn('model_id', $modelIds)
            ->where('status', 1)
            ->get();

        $channelIds = $channelModels->pluck('channel_id')->unique()->toArray();
        $channels = Channel::whereIn('id', $channelIds)
            ->where('status', 1)
            ->get()
            ->keyBy('id');

        // 按模型分组推导端点
        $result = [];
        foreach ($channelModels as $cm) {
            $channel = $channels->get($cm->channel_id);
            if (!$channel) {
                continue;
            }
            $type = $channel->type;
            $endpoints = self::$endpointMap[$type] ?? self::$defaultEndpoints;
            if (!isset($result[$cm->model_id])) {
                $result[$cm->model_id] = [];
            }
            foreach ($endpoints as $ep) {
                $result[$cm->model_id][$ep['type']] = $ep;
            }
        }

        // 转为数组值
        foreach ($result as $modelId => $eps) {
            $result[$modelId] = array_values($eps);
        }

        return $result;
    }

    /**
     * 为单个模型数据附加端点信息
     * 如果模型自身 endpoints 有值则优先使用，否则自动推导
     */
    private static function attachEndpoints(array &$data, array $derivedEndpoints): void
    {
        $endpoints = $data['endpoints'] ?? [];
        if (empty($endpoints)) {
            $data['endpoints'] = $derivedEndpoints[$data['id']] ?? [];
        }
    }

    // GET /api/public/providers - 公开厂商列表（含品牌色和图标）
    public function providerList(Request $request): Response
    {
        $providers = Provider::where('status', 1)->orderBy('sort')->get();
        return json(['code' => 0, 'data' => $providers]);
    }

    // GET /api/public/models - 公开模型列表（用于前台模型广场）
    public function models(Request $request): Response
    {
        $query = Model_::where('status', 1);
        
        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }
        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }
        if ($keyword = $request->get('keyword')) {
            $query->where(function($q) use ($keyword) {
                $q->where('model_name', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }
        // 标签筛选
        if ($tag = $request->get('tag')) {
            $query->where('tags', 'like', "%\"{$tag}\"%");
        }
        
        $models = $query->orderBy('model_name')->get();
        
        // 批量获取定价信息
        $modelIds = $models->pluck('id')->toArray();
        $pricings = ModelPricing::whereIn('model_id', $modelIds)->get()->groupBy('model_id');

        // 批量推导端点
        $derivedEndpoints = self::deriveEndpointsForModels($modelIds);

        // 端点类型筛选（在推导后进行）
        $endpointFilter = $request->get('endpoint');
        
        $list = $models->map(function($model) use ($pricings, $derivedEndpoints) {
            $data = $model->toArray();
            $data['pricing'] = $pricings->get($model->id, collect())->toArray();
            self::attachEndpoints($data, $derivedEndpoints);
            return $data;
        });

        // 如果有端点类型筛选，在推导后过滤
        if ($endpointFilter) {
            $list = $list->filter(function($item) use ($endpointFilter) {
                $endpoints = $item['endpoints'] ?? [];
                foreach ($endpoints as $ep) {
                    if (isset($ep['type']) && $ep['type'] === $endpointFilter) {
                        return true;
                    }
                }
                return false;
            })->values();
        }
        
        return json(['code' => 0, 'data' => ['list' => $list, 'total' => count($list)]]);
    }
    
    // GET /api/public/models/providers - 公开供应商列表
    public function providers(Request $request): Response
    {
        $providers = Model_::where('status', 1)
            ->where('provider', '!=', '')
            ->distinct()
            ->pluck('provider')
            ->toArray();
        return json(['code' => 0, 'data' => $providers]);
    }
    
    // GET /api/public/models/tags - 公开标签列表
    public function tags(Request $request): Response
    {
        $models = Model_::where('status', 1)
            ->whereNotNull('tags')
            ->where('tags', '!=', '[]')
            ->pluck('tags')
            ->toArray();
        $allTags = [];
        foreach ($models as $tags) {
            if (is_array($tags)) {
                $allTags = array_merge($allTags, $tags);
            }
        }
        $allTags = array_values(array_unique($allTags));
        return json(['code' => 0, 'data' => $allTags]);
    }
    
    // GET /api/public/models/endpoints - 公开端点类型列表
    public function endpoints(Request $request): Response
    {
        // 获取所有启用模型
        $models = Model_::where('status', 1)->get();
        $modelIds = $models->pluck('id')->toArray();

        // 批量推导端点
        $derivedEndpoints = self::deriveEndpointsForModels($modelIds);

        $types = [];
        foreach ($models as $model) {
            $endpoints = $model->endpoints;
            if (empty($endpoints)) {
                $endpoints = $derivedEndpoints[$model->id] ?? [];
            }
            foreach ($endpoints as $ep) {
                if (isset($ep['type'])) {
                    $types[] = $ep['type'];
                }
            }
        }
        $types = array_values(array_unique($types));
        return json(['code' => 0, 'data' => $types]);
    }
    
    // GET /api/public/models/{id} - 公开模型详情
    public function modelDetail(Request $request, $id): Response
    {
        $model = Model_::where('id', $id)->where('status', 1)->first();
        if (!$model) {
            return json(['code' => 404, 'message' => '模型不存在']);
        }
        $pricing = ModelPricing::where('model_id', $id)->get();
        $data = $model->toArray();
        $data['pricing'] = $pricing;

        // 自动推导端点
        $derivedEndpoints = self::deriveEndpointsForModels([$model->id]);
        self::attachEndpoints($data, $derivedEndpoints);

        return json(['code' => 0, 'data' => $data]);
    }

    /**
     * GET /api/public/settings - 获取公开设置（充值链接等）
     */
    public function settings(Request $request): Response
    {
        $keys = [
            'site_name',
            'site_logo',
            'site_footer',
            'recharge_url',
            'checkin_enabled',
            'register_enabled',
            'register_email_verify',
            'privacy_policy',
            'terms_of_service',
            'pay_address',
            'pay_channels',
        ];
        $settings = Option::getOptions($keys);
        // 根据 pay_address 是否配置来计算支付是否启用
        $settings['payment_enabled'] = !empty($settings['pay_address']);
        // 解析通道 JSON（存储为 JSON 字符串，格式为 [{name,label}] 对象数组）
        $channelsRaw = $settings['pay_channels'] ?? '';
        $channels = [];
        if (!empty($channelsRaw)) {
            $decoded = json_decode($channelsRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $ch) {
                    if (is_string($ch)) {
                        $name = trim($ch);
                        if ($name !== '') {
                            $channels[] = ['name' => $name, 'label' => $name];
                        }
                    } elseif (is_array($ch)) {
                        $name = trim($ch['name'] ?? '');
                        if ($name !== '') {
                            $label = trim($ch['label'] ?? '');
                            $channels[] = ['name' => $name, 'label' => $label !== '' ? $label : $name];
                        }
                    }
                }
            }
        }
        $settings['pay_channels'] = $channels;
        // 不暴露敏感的支付地址给前端
        unset($settings['pay_address']);
        return json(['code' => 0, 'data' => $settings]);
    }

    /**
     * GET /api/public/announcements - 获取公开公告列表
     */
    public function announcements(Request $request): Response
    {
        $list = Announcement::where('status', 1)->orderByDesc('sort')->orderByDesc('id')->get();
        return json(['code' => 0, 'data' => $list]);
    }

    /**
     * GET /api/categories - 获取所有启用的模型分类列表（含渠道数量统计）
     */
    public function categories(Request $request): Response
    {
        $categories = ModelCategory::where('status', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $categoryIds = $categories->pluck('id')->toArray();
        $channelCounts = CategoryChannel::whereIn('category_id', $categoryIds)
            ->where('status', 1)
            ->selectRaw('category_id, count(*) as count')
            ->groupBy('category_id')
            ->pluck('count', 'category_id');

        $list = $categories->map(function ($item) use ($channelCounts) {
            $data = $item->toArray();
            $data['channel_count'] = $channelCounts->get($item->id, 0);
            return $data;
        });

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    /**
     * GET /api/categories/{id} - 获取分类详情（含渠道绑定和价格信息）
     */
    public function categoryDetail(Request $request, $id): Response
    {
        $category = ModelCategory::where('id', $id)->where('status', 1)->first();
        if (!$category) {
            return json(['code' => 404, 'msg' => '分类不存在']);
        }

        // 获取该分类下所有启用的渠道绑定
        $bindings = CategoryChannel::where('category_id', $id)
            ->where('status', 1)
            ->get();

        // 批量获取渠道名称（仅暴露名称，不暴露敏感信息）
        $channelIds = $bindings->pluck('channel_id')->unique()->toArray();
        $channelNames = Channel::whereIn('id', $channelIds)
            ->where('status', 1)
            ->pluck('name', 'id');

        // 收集所有模型名称，批量查找默认价格
        $modelNames = $bindings->pluck('model_name')->unique()->toArray();
        $defaultPricings = ModelPricing::whereHas('model', function ($q) use ($modelNames) {
            $q->whereIn('model_name', $modelNames)->where('status', 1);
        })->get();

        // 构建 model_name => 默认价格 的映射
        $modelIdToName = Model_::whereIn('model_name', $modelNames)
            ->where('status', 1)
            ->pluck('model_name', 'id');
        $defaultPriceMap = [];
        foreach ($defaultPricings as $dp) {
            $name = $modelIdToName->get($dp->model_id);
            if ($name) {
                $defaultPriceMap[$name] = [
                    'input_price' => $dp->input_price,
                    'output_price' => $dp->output_price,
                ];
            }
        }

        // 组装绑定列表
        $items = $bindings->map(function ($bind) use ($channelNames, $defaultPriceMap) {
            $channelName = $channelNames->get($bind->channel_id, '未知渠道');

            // 优先使用自定义价格，否则使用默认价格
            $inputPrice = $bind->custom_input_price;
            $outputPrice = $bind->custom_output_price;
            if ($inputPrice === null || $inputPrice == 0) {
                $inputPrice = $defaultPriceMap[$bind->model_name]['input_price'] ?? null;
            }
            if ($outputPrice === null || $outputPrice == 0) {
                $outputPrice = $defaultPriceMap[$bind->model_name]['output_price'] ?? null;
            }

            return [
                'id' => $bind->id,
                'channel_name' => $channelName,
                'model_name' => $bind->model_name,
                'input_price' => $inputPrice,
                'output_price' => $outputPrice,
                'has_custom_price' => ($bind->custom_input_price !== null && $bind->custom_input_price != 0)
                    || ($bind->custom_output_price !== null && $bind->custom_output_price != 0),
            ];
        });

        // 按模型名称分组汇总
        $grouped = [];
        foreach ($items as $item) {
            $name = $item['model_name'];
            if (!isset($grouped[$name])) {
                $grouped[$name] = [
                    'model_name' => $name,
                    'channels' => [],
                    'min_input_price' => null,
                    'max_input_price' => null,
                    'min_output_price' => null,
                    'max_output_price' => null,
                ];
            }
            $grouped[$name]['channels'][] = $item;

            // 计算价格范围
            if ($item['input_price'] !== null) {
                $ip = (float)$item['input_price'];
                if ($grouped[$name]['min_input_price'] === null || $ip < $grouped[$name]['min_input_price']) {
                    $grouped[$name]['min_input_price'] = $ip;
                }
                if ($grouped[$name]['max_input_price'] === null || $ip > $grouped[$name]['max_input_price']) {
                    $grouped[$name]['max_input_price'] = $ip;
                }
            }
            if ($item['output_price'] !== null) {
                $op = (float)$item['output_price'];
                if ($grouped[$name]['min_output_price'] === null || $op < $grouped[$name]['min_output_price']) {
                    $grouped[$name]['min_output_price'] = $op;
                }
                if ($grouped[$name]['max_output_price'] === null || $op > $grouped[$name]['max_output_price']) {
                    $grouped[$name]['max_output_price'] = $op;
                }
            }
        }

        return json([
            'code' => 0,
            'msg' => 'success',
            'data' => [
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'description' => $category->description,
                    'icon' => $category->icon,
                ],
                'models' => array_values($grouped),
            ],
        ]);
    }
}
