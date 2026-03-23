<?php

namespace app\controller\user;

use support\Request;
use support\Response;
use app\model\Ticket;
use app\model\TicketReply;

class TicketController
{
    /**
     * 我的工单列表
     */
    public function index(Request $request): Response
    {
        $userId = $request->user['id'];
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 15);

        $query = Ticket::where('user_id', $userId);

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        $query->orderByDesc('id');

        $total = $query->count();
        $list = $query->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return json(['code' => 0, 'data' => ['list' => $list, 'total' => $total]]);
    }

    /**
     * 工单详情
     */
    public function detail(Request $request): Response
    {
        $userId = $request->user['id'];
        $id = $request->get('id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少工单ID']);
        }

        $ticket = Ticket::where('id', $id)->where('user_id', $userId)->first();
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
     * 创建工单
     */
    public function create(Request $request): Response
    {
        $userId = $request->user['id'];
        $title = trim($request->post('title', ''));
        $content = trim($request->post('content', ''));
        $category = $request->post('category', Ticket::CATEGORY_GENERAL);

        // 验证
        if (empty($title)) {
            return json(['code' => 400, 'msg' => '标题不能为空']);
        }
        if (mb_strlen($title) > 100) {
            return json(['code' => 400, 'msg' => '标题不能超过100字']);
        }
        if (empty($content)) {
            return json(['code' => 400, 'msg' => '内容不能为空']);
        }
        if (mb_strlen($content) > 2000) {
            return json(['code' => 400, 'msg' => '内容不能超过2000字']);
        }

        $allowedCategories = [
            Ticket::CATEGORY_GENERAL, Ticket::CATEGORY_BILLING,
            Ticket::CATEGORY_TECHNICAL, Ticket::CATEGORY_SUGGESTION,
        ];
        if (!in_array($category, $allowedCategories)) {
            $category = Ticket::CATEGORY_GENERAL;
        }

        // 创建工单
        $ticket = Ticket::create([
            'user_id' => $userId,
            'title' => $title,
            'category' => $category,
            'status' => Ticket::STATUS_PENDING,
            'priority' => Ticket::PRIORITY_NORMAL,
            'last_reply_at' => date('Y-m-d H:i:s'),
        ]);

        // 创建第一条回复（用户描述内容）
        TicketReply::create([
            'ticket_id' => $ticket->id,
            'user_id' => $userId,
            'is_admin' => 0,
            'content' => $content,
        ]);

        return json(['code' => 0, 'msg' => '工单创建成功', 'data' => $ticket]);
    }

    /**
     * 用户回复工单
     */
    public function reply(Request $request): Response
    {
        $userId = $request->user['id'];
        $ticketId = $request->post('ticket_id');
        $content = trim($request->post('content', ''));

        if (!$ticketId || empty($content)) {
            return json(['code' => 400, 'msg' => '缺少必要参数']);
        }

        if (mb_strlen($content) > 2000) {
            return json(['code' => 400, 'msg' => '回复内容不能超过2000字']);
        }

        $ticket = Ticket::where('id', $ticketId)->where('user_id', $userId)->first();
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        if ($ticket->status === Ticket::STATUS_CLOSED) {
            return json(['code' => 400, 'msg' => '工单已关闭，无法回复']);
        }

        // 创建回复
        $reply = TicketReply::create([
            'ticket_id' => $ticketId,
            'user_id' => $userId,
            'is_admin' => 0,
            'content' => $content,
        ]);

        // 如果工单状态是 resolved，用户追加回复后状态变回 processing
        if ($ticket->status === Ticket::STATUS_RESOLVED) {
            $ticket->status = Ticket::STATUS_PROCESSING;
        }
        $ticket->last_reply_at = date('Y-m-d H:i:s');
        $ticket->save();

        return json(['code' => 0, 'msg' => '回复成功', 'data' => $reply]);
    }

    /**
     * 用户关闭工单
     */
    public function close(Request $request): Response
    {
        $userId = $request->user['id'];
        $id = $request->post('id');

        if (!$id) {
            return json(['code' => 400, 'msg' => '缺少工单ID']);
        }

        $ticket = Ticket::where('id', $id)->where('user_id', $userId)->first();
        if (!$ticket) {
            return json(['code' => 404, 'msg' => '工单不存在']);
        }

        if ($ticket->status === Ticket::STATUS_CLOSED) {
            return json(['code' => 400, 'msg' => '工单已关闭']);
        }

        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->closed_at = date('Y-m-d H:i:s');
        $ticket->save();

        return json(['code' => 0, 'msg' => '工单已关闭', 'data' => $ticket]);
    }
}
