<?php

namespace app\controller\admin;

use support\Request;
use support\Response;
use app\model\Group;

class GroupController
{
    // GET /api/admin/groups - 分组列表
    public function index(Request $request): Response
    {
        $query = Group::orderBy('sort')->orderBy('id');
        if ($keyword = $request->get('keyword')) {
            $query->where(function($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('display_name', 'like', "%{$keyword}%");
            });
        }
        $groups = $query->get();
        return json(['code' => 0, 'data' => ['list' => $groups, 'total' => $groups->count()]]);
    }

    // POST /api/admin/groups - 新建分组
    public function store(Request $request): Response
    {
        $name = $request->post('name');
        $displayName = $request->post('display_name');
        if (!$name || !$displayName) {
            return json(['code' => 400, 'message' => '分组标识和显示名称不能为空']);
        }
        if (Group::where('name', $name)->exists()) {
            return json(['code' => 400, 'message' => '分组标识已存在']);
        }
        $ratio = $request->post('ratio', 1);
        if ($ratio <= 0) {
            return json(['code' => 400, 'message' => '倍率必须大于0']);
        }
        $group = Group::create([
            'name' => $name,
            'display_name' => $displayName,
            'ratio' => $ratio,
            'description' => $request->post('description', ''),
            'sort' => $request->post('sort', 0),
            'status' => $request->post('status', 1),
        ]);
        return json(['code' => 0, 'message' => '创建成功', 'data' => $group]);
    }

    // PUT /api/admin/groups/{id} - 更新分组
    public function update(Request $request, $id): Response
    {
        $group = Group::find($id);
        if (!$group) {
            return json(['code' => 404, 'message' => '分组不存在']);
        }
        $fields = ['name', 'display_name', 'ratio', 'description', 'sort', 'status'];
        foreach ($fields as $field) {
            if ($request->post($field) !== null) {
                $group->$field = $request->post($field);
            }
        }
        if ($group->ratio <= 0) {
            return json(['code' => 400, 'message' => '倍率必须大于0']);
        }
        $group->save();
        return json(['code' => 0, 'message' => '更新成功', 'data' => $group]);
    }

    // DELETE /api/admin/groups/{id} - 删除分组
    public function destroy(Request $request, $id): Response
    {
        $group = Group::find($id);
        if (!$group) {
            return json(['code' => 404, 'message' => '分组不存在']);
        }
        if ($group->name === 'default') {
            return json(['code' => 400, 'message' => '默认分组不能删除']);
        }
        $group->delete();
        return json(['code' => 0, 'message' => '删除成功']);
    }
}
