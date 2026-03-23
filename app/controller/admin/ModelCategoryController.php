<?php

namespace app\controller\admin;

use support\Request;
use support\Response;
use app\model\ModelCategory;
use app\model\CategoryChannel;
use app\model\Token;

class ModelCategoryController
{
    // GET /admin/api/model-categories/list - 分类列表（分页、搜索、状态筛选）
    public function index(Request $request): Response
    {
        $query = ModelCategory::orderBy('sort_order')->orderBy('id');

        if ($keyword = $request->get('keyword')) {
            $query->where('name', 'like', "%{$keyword}%");
        }
        if (($status = $request->get('status')) !== null && $status !== '') {
            $query->where('status', (int)$status);
        }

        $page = max(1, (int)$request->get('page', 1));
        $pageSize = max(1, min(100, (int)$request->get('page_size', 20)));
        $total = $query->count();
        $list = $query->offset(($page - 1) * $pageSize)->limit($pageSize)->get();

        // 附加每个分类下的渠道绑定数量
        $categoryIds = $list->pluck('id')->toArray();
        $channelCounts = CategoryChannel::whereIn('category_id', $categoryIds)
            ->selectRaw('category_id, count(*) as count')
            ->groupBy('category_id')
            ->pluck('count', 'category_id');

        $list->each(function ($item) use ($channelCounts) {
            $item->channel_count = $channelCounts->get($item->id, 0);
        });

        return json(['code' => 0, 'msg' => 'success', 'data' => [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]]);
    }

    // POST /admin/api/model-categories/save - 创建/更新分类
    public function save(Request $request): Response
    {
        $id = $request->post('id');
        $name = trim($request->post('name', ''));

        if ($name === '') {
            return json(['code' => 400, 'msg' => '分类名称不能为空']);
        }

        // 检查名称唯一性
        $exists = ModelCategory::where('name', $name)
            ->when($id, function ($q) use ($id) {
                $q->where('id', '!=', $id);
            })->exists();
        if ($exists) {
            return json(['code' => 400, 'msg' => '分类名称已存在']);
        }

        $data = [
            'name' => $name,
            'description' => $request->post('description', ''),
            'icon' => $request->post('icon', ''),
            'sort_order' => (int)$request->post('sort_order', 0),
            'status' => (int)$request->post('status', 1),
        ];

        if ($id) {
            $category = ModelCategory::find($id);
            if (!$category) {
                return json(['code' => 404, 'msg' => '分类不存在']);
            }
            $category->update($data);
            return json(['code' => 0, 'msg' => '更新成功', 'data' => $category]);
        }

        $category = ModelCategory::create($data);
        return json(['code' => 0, 'msg' => '创建成功', 'data' => $category]);
    }

    // POST /admin/api/model-categories/delete - 删除分类
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少分类ID']);
        }

        $category = ModelCategory::find($id);
        if (!$category) {
            return json(['code' => 404, 'msg' => '分类不存在']);
        }

        // 检查是否有绑定的渠道
        $channelCount = CategoryChannel::where('category_id', $id)->count();
        if ($channelCount > 0) {
            return json(['code' => 400, 'msg' => "该分类下还有 {$channelCount} 个渠道绑定，请先解除绑定"]);
        }

        // 检查是否有关联的令牌在使用
        $tokenCount = Token::where('category_id', $id)->count();
        if ($tokenCount > 0) {
            return json(['code' => 400, 'msg' => "该分类下还有 {$tokenCount} 个令牌在使用，请先修改令牌配置"]);
        }

        $category->delete();
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    // GET /admin/api/model-categories/all - 获取所有启用的分类（不分页，用于下拉选择）
    public function all(Request $request): Response
    {
        $list = ModelCategory::where('status', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'description', 'icon']);

        return json(['code' => 0, 'msg' => 'success', 'data' => $list]);
    }

    // GET /admin/api/model-categories/channels - 获取指定分类下的渠道绑定列表
    public function channels(Request $request): Response
    {
        $categoryId = $request->get('category_id');
        if (!$categoryId) {
            return json(['code' => 400, 'msg' => '缺少分类ID']);
        }

        $category = ModelCategory::find($categoryId);
        if (!$category) {
            return json(['code' => 404, 'msg' => '分类不存在']);
        }

        $list = CategoryChannel::where('category_id', $categoryId)
            ->with('channel:id,name,type,status')
            ->orderBy('priority', 'desc')
            ->orderBy('weight', 'desc')
            ->get();

        return json(['code' => 0, 'msg' => 'success', 'data' => [
            'list' => $list,
            'total' => $list->count(),
        ]]);
    }

    // POST /admin/api/model-categories/save-channel - 添加/更新分类-渠道绑定
    public function saveChannel(Request $request): Response
    {
        $id = $request->post('id');
        $categoryId = $request->post('category_id');
        $channelId = $request->post('channel_id');

        if (!$categoryId) {
            return json(['code' => 400, 'msg' => '缺少分类ID']);
        }
        if (!$channelId && !$id) {
            return json(['code' => 400, 'msg' => '缺少渠道ID']);
        }

        if (!ModelCategory::where('id', $categoryId)->exists()) {
            return json(['code' => 404, 'msg' => '分类不存在']);
        }

        $data = [
            'category_id' => (int)$categoryId,
            'channel_id' => (int)$channelId,
            'model_name' => $request->post('model_name', ''),
            'priority' => (int)$request->post('priority', 0),
            'weight' => (int)$request->post('weight', 1),
            'custom_input_price' => $request->post('custom_input_price'),
            'custom_output_price' => $request->post('custom_output_price'),
            'status' => (int)$request->post('status', 1),
        ];

        if ($id) {
            $binding = CategoryChannel::find($id);
            if (!$binding) {
                return json(['code' => 404, 'msg' => '绑定记录不存在']);
            }
            $binding->update($data);
            return json(['code' => 0, 'msg' => '更新成功', 'data' => $binding]);
        }

        // 检查是否已存在相同绑定
        $exists = CategoryChannel::where('category_id', $categoryId)
            ->where('channel_id', $channelId)
            ->where('model_name', $data['model_name'])
            ->exists();
        if ($exists) {
            return json(['code' => 400, 'msg' => '该渠道在此分类下已存在相同模型名称的绑定']);
        }

        $binding = CategoryChannel::create($data);
        return json(['code' => 0, 'msg' => '创建成功', 'data' => $binding]);
    }

    // POST /admin/api/model-categories/delete-channel - 删除分类-渠道绑定
    public function deleteChannel(Request $request): Response
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少绑定ID']);
        }

        $binding = CategoryChannel::find($id);
        if (!$binding) {
            return json(['code' => 404, 'msg' => '绑定记录不存在']);
        }

        $binding->delete();
        return json(['code' => 0, 'msg' => '删除成功']);
    }

    // POST /admin/api/model-categories/update-status - 切换分类状态
    public function updateStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');

        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少分类ID']);
        }
        if ($status === null || $status === '') {
            return json(['code' => 400, 'msg' => '缺少状态值']);
        }

        $category = ModelCategory::find($id);
        if (!$category) {
            return json(['code' => 404, 'msg' => '分类不存在']);
        }

        $category->status = (int)$status;
        $category->save();

        return json(['code' => 0, 'msg' => '状态更新成功', 'data' => $category]);
    }
}
