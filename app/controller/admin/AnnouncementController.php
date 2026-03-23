<?php

namespace app\controller\admin;

use support\Request;
use support\Response;
use app\model\Announcement;

class AnnouncementController
{
    // GET /api/admin/announcements - 公告列表
    public function index(Request $request): Response
    {
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 15);

        $query = Announcement::orderByDesc('sort')->orderByDesc('id');

        if ($keyword = $request->get('keyword')) {
            $query->where(function($q) use ($keyword) {
                $q->where('title', 'like', "%{$keyword}%")
                  ->orWhere('content', 'like', "%{$keyword}%");
            });
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    // POST /api/admin/announcements - 新建公告
    public function store(Request $request): Response
    {
        $title = $request->post('title');
        if (!$title) {
            return json(['code' => 400, 'message' => '公告标题不能为空']);
        }

        $announcement = Announcement::create([
            'title'   => $title,
            'content' => $request->post('content', ''),
            'sort'    => $request->post('sort', 0),
            'status'  => $request->post('status', 1),
            'popup'   => $request->post('popup', 0),
        ]);

        return json(['code' => 0, 'message' => '创建成功', 'data' => $announcement]);
    }

    // PUT /api/admin/announcements/{id} - 更新公告
    public function update(Request $request, $id): Response
    {
        $announcement = Announcement::find($id);
        if (!$announcement) {
            return json(['code' => 404, 'message' => '公告不存在']);
        }

        $fields = ['title', 'content', 'sort', 'status', 'popup'];
        foreach ($fields as $field) {
            if ($request->post($field) !== null) {
                $announcement->$field = $request->post($field);
            }
        }
        $announcement->save();

        return json(['code' => 0, 'message' => '更新成功', 'data' => $announcement]);
    }

    // DELETE /api/admin/announcements/{id} - 删除公告
    public function destroy(Request $request, $id): Response
    {
        $announcement = Announcement::find($id);
        if (!$announcement) {
            return json(['code' => 404, 'message' => '公告不存在']);
        }

        $announcement->delete();
        return json(['code' => 0, 'message' => '删除成功']);
    }
}
