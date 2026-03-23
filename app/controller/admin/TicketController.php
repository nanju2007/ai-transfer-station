<?php

namespace app\controller\admin;

use support\Request;
use support\Response;
use app\model\Ticket;
use app\model\TicketReply;

class TicketController
{
    /**
     * 工单列表
     */
    public function index(Request $request): Response
    {
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 15);

        $query = Ticket::with('user:id,username,display_name');

        // 状态筛选
        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        // 分类筛选
        if ($category = $request->get('category')) {
            $query->where('category', $category);
        }

        // 搜索标题
        if ($keyword = $request->get('keyword')) {
            $query->where('title', 'like', "%{$keyword}%");
        }

        // 优先级筛选
        if ($priority = $request->get('priority')) {
            $query->where('priority', $priority);
        }

        // 排序
        $sortBy = $request->get('sort_by', 'id');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['id', 'priority', 'status', 'created_at', 'last_reply_at'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderByDesc('id');
        }

        $total = $query->count();
        $list = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    /**
     * 工单详情
     */
    public function detail(Request $request): Response
    {
        $id = $request->get('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少工单ID']);
        }

        $ticket = Ticket::with('user:id,username,display_name')->find($id);
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        $replies = TicketReply::with('user:id,username,display_name')
            ->where('ticket_id', $id)
            ->orderBy('created_at', 'asc')
            ->get();

        return json(['code' => 0, 'data' => ['ticket' => $ticket, 'replies' => $replies]]);
    }

    /**
     * 管理员回复工单
     */
    public function reply(Request $request): Response
    {
        $ticketId = $request->post('ticket_id');
        $content = $request->post('content');

        if (!$ticketId || !$content) {
            return json(['code' => 400, 'msg' => '缺少必要参数']);
        }

        $ticket = Ticket::find($ticketId);
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        if ($ticket->status === Ticket::STATUS_CLOSED) {
            return json(['code' => 400, 'msg' => '工单已关闭，无法回复']);
        }

        // 创建回复
        $reply = TicketReply::create([
            'ticket_id' => $ticketId,
            'user_id' => $request->user['id'],
            'is_admin' => 1,
            'content' => $content,
        ]);

        // 如果工单状态是 pending，自动变为 processing
        if ($ticket->status === Ticket::STATUS_PENDING) {
            $ticket->status = Ticket::STATUS_PROCESSING;
        }
        $ticket->last_reply_at = date('Y-m-d H:i:s');
        $ticket->save();

        return json(['code' => 0, 'msg' => '回复成功', 'data' => $reply]);
    }

    /**
     * 更新工单状态
     */
    public function updateStatus(Request $request): Response
    {
        $id = $request->post('id');
        $status = $request->post('status');

        if (!$id || !$status) {
            return json(['code' => 400, 'msg' => '缺少必要参数']);
        }

        $allowedStatuses = [Ticket::STATUS_PROCESSING, Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED];
        if (!in_array($status, $allowedStatuses)) {
            return json(['code' => 400, 'msg' => '无效的状态值']);
        }

        $ticket = Ticket::find($id);
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        $ticket->status = $status;
        if ($status === Ticket::STATUS_CLOSED) {
            $ticket->closed_at = date('Y-m-d H:i:s');
        }
        $ticket->save();

        return json(['code' => 0, 'msg' => '状态更新成功', 'data' => $ticket]);
    }

    /**
     * 更新工单优先级
     */
    public function updatePriority(Request $request): Response
    {
        $id = $request->post('id');
        $priority = $request->post('priority');

        if (!$id || !$priority) {
            return json(['code' => 400, 'msg' => '缺少必要参数']);
        }

        $allowedPriorities = [Ticket::PRIORITY_LOW, Ticket::PRIORITY_NORMAL, Ticket::PRIORITY_HIGH, Ticket::PRIORITY_URGENT];
        if (!in_array($priority, $allowedPriorities)) {
            return json(['code' => 400, 'msg' => '无效的优先级']);
        }

        $ticket = Ticket::find($id);
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        $ticket->priority = $priority;
        $ticket->save();

        return json(['code' => 0, 'msg' => '优先级更新成功', 'data' => $ticket]);
    }

    /**
     * 删除工单
     */
    public function delete(Request $request): Response
    {
        $id = $request->post('id');
        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少工单ID']);
        }

        $ticket = Ticket::find($id);
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        // 删除所有回复
        TicketReply::where('ticket_id', $id)->delete();
        $ticket->delete();

        return json(['code' => 0, 'msg' => '删除成功']);
    }

    /**
     * 工单统计
     */
    public function stats(Request $request): Response
    {
        $stats = [
            'total' => Ticket::count(),
            'pending' => Ticket::where('status', Ticket::STATUS_PENDING)->count(),
            'processing' => Ticket::where('status', Ticket::STATUS_PROCESSING)->count(),
            'resolved' => Ticket::where('status', Ticket::STATUS_RESOLVED)->count(),
            'closed' => Ticket::where('status', Ticket::STATUS_CLOSED)->count(),
        ];

        return json(['code' => 0, 'data' => $stats]);
    }
}
