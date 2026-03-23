<?php
namespace app\controller\admin;

use support\Request;
use support\Response;
use app\model\Provider;

class ProviderController
{
    // GET /api/admin/providers - 厂商列表
    public function index(Request $request): Response
    {
        $query = Provider::orderBy('sort')->orderBy('id');
        if ($keyword = $request->get('keyword')) {
            $query->where(function($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }
        $providers = $query->get();
        return json(['code' => 0, 'data' => ['list' => $providers, 'total' => $providers->count()]]);
    }

    // POST /api/admin/providers - 新建厂商
    public function store(Request $request): Response
    {
        $name = $request->post('name');
        $displayName = $request->post('display_name');
        if (!$name || !$displayName) {
            return json(['code' => 400, 'message' => '厂商标识和显示名称不能为空']);
        }
        if (Provider::where('name', $name)->exists()) {
            return json(['code' => 400, 'message' => '厂商标识已存在']);
        }
        $provider = Provider::create([
            'name' => $name,
            'display_name' => $displayName,
            'icon' => $request->post('icon', ''),
            'color' => $request->post('color', '#0052d9'),
            'sort' => $request->post('sort', 0),
            'status' => $request->post('status', 1),
        ]);
        return json(['code' => 0, 'message' => '创建成功', 'data' => $provider]);
    }

    // PUT /api/admin/providers/{id} - 更新厂商
    public function update(Request $request, $id): Response
    {
        $provider = Provider::find($id);
        if (!$provider) {
            return json(['code' => 404, 'message' => '厂商不存在']);
        }
        $fields = ['name', 'display_name', 'icon', 'color', 'sort', 'status'];
        foreach ($fields as $field) {
            if ($request->post($field) !== null) {
                $provider->$field = $request->post($field);
            }
        }
        $provider->save();
        return json(['code' => 0, 'message' => '更新成功', 'data' => $provider]);
    }

    // DELETE /api/admin/providers/{id} - 删除厂商
    public function destroy(Request $request, $id): Response
    {
        $provider = Provider::find($id);
        if (!$provider) {
            return json(['code' => 404, 'message' => '厂商不存在']);
        }
        $provider->delete();
        return json(['code' => 0, 'message' => '删除成功']);
    }
}
