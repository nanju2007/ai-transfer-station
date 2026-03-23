<?php

namespace app\controller\admin;

use support\Request;
use app\model\RedemptionCode;

class RedemptionCodeController
{
    /**
     * 兑换码列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $status = $request->input('status');
        $keyword = $request->input('keyword', '');

        $query = RedemptionCode::query()->orderBy('id', 'desc');

        if ($status !== null && $status !== '') {
            $query->where('status', (int)$status);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                  ->orWhere('key', 'like', "%{$keyword}%");
            });
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
     * 批量生成兑换码
     */
    public function store(Request $request)
    {
        $quota = (int)$request->post('quota', 0);
        if ($quota <= 0) {
            return json(['code' => 400, 'msg' => '兑换额度必须大于0']);
        }

        $count = (int)$request->post('count', 1);
        if ($count < 1 || $count > 100) {
            return json(['code' => 400, 'msg' => '生成数量需在1-100之间']);
        }

        $prefix = $request->post('prefix', '');
        $name = $request->post('name', '批量生成');
        $expiresAt = $request->post('expires_at');
        $userId = $request->user['id'] ?? 0;

        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $prefix . strtolower(md5(uniqid((string)mt_rand(), true)));
            $key = substr($key, 0, 32);

            $code = new RedemptionCode();
            $code->name = $name;
            $code->key = $key;
            $code->status = RedemptionCode::STATUS_UNUSED;
            $code->quota = $quota;
            $code->user_id = $userId;
            $code->expired_at = $expiresAt ?: null;
            $code->created_at = date('Y-m-d H:i:s');
            $code->save();

            $codes[] = $code;
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'count' => count($codes),
            'codes' => $codes,
        ]]);
    }

    /**
     * 删除兑换码
     */
    public function destroy(Request $request, $id)
    {
        $code = RedemptionCode::find($id);
        if (!$code) {
            return json(['code' => 404, 'msg' => '兑换码不存在']);
        }
        $code->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 批量删除
     */
    public function batchDestroy(Request $request)
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return json(['code' => 400, 'msg' => '请选择要删除的兑换码']);
        }

        $deleted = RedemptionCode::whereIn('id', $ids)->delete();

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => $deleted]]);
    }

    /**
     * 启用/禁用
     */
    public function updateStatus(Request $request, $id)
    {
        $code = RedemptionCode::find($id);
        if (!$code) {
            return json(['code' => 404, 'msg' => '兑换码不存在']);
        }

        if ($code->status == RedemptionCode::STATUS_USED) {
            return json(['code' => 400, 'msg' => '已使用的兑换码不能修改状态']);
        }

        $status = (int)$request->post('status', 0);
        if (!in_array($status, [RedemptionCode::STATUS_UNUSED, RedemptionCode::STATUS_DISABLED])) {
            return json(['code' => 400, 'msg' => '状态值无效']);
        }

        $code->status = $status;
        $code->save();

        return json(['code' => 0, 'msg' => 'ok', 'data' => $code]);
    }
}
