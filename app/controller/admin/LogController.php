<?php

namespace app\controller\admin;

use support\Request;
use app\model\Log;

class LogController
{
    /**
     * 日志列表
     */
    public function index(Request $request)
    {
        $perPage = (int)$request->input('per_page', 15);
        $userId = $request->input('user_id');
        $modelName = $request->input('model_name');
        $channelId = $request->input('channel_id');
        $type = $request->input('type');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $keyword = $request->input('keyword', '');

        $query = Log::query()->orderBy('id', 'desc');

        if ($userId !== null && $userId !== '') {
            $query->where('user_id', (int)$userId);
        }
        if ($modelName !== null && $modelName !== '') {
            $query->where('model_name', $modelName);
        }
        if ($channelId !== null && $channelId !== '') {
            $query->where('channel_id', (int)$channelId);
        }
        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
        }
        if ($startDate) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate . ' 23:59:59');
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('username', 'like', "%{$keyword}%")
                  ->orWhere('content', 'like', "%{$keyword}%")
                  ->orWhere('model_name', 'like', "%{$keyword}%");
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
     * 日志搜索
     */
    public function search(Request $request)
    {
        return $this->index($request);
    }

    /**
     * 统计数据
     */
    public function statistics(Request $request)
    {
        $startDate = $request->input('start_date', date('Y-m-d', strtotime('-30 days')));
        $endDate = $request->input('end_date', date('Y-m-d'));
        $groupBy = $request->input('group_by', 'hour'); // hour 或 day

        // 总请求数
        $totalRequests = Log::where('type', Log::TYPE_CONSUME)->count();

        // 总token数
        $totalTokens = Log::where('type', Log::TYPE_CONSUME)
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total')
            ->value('total') ?? 0;

        // 总费用
        $totalCost = Log::where('type', Log::TYPE_CONSUME)->sum('cost');

        // 根据 group_by 参数选择聚合粒度
        if ($groupBy === 'day') {
            $timeFormat = "DATE_FORMAT(created_at, '%Y-%m-%d')";
        } else {
            $timeFormat = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
        }

        // 按时间聚合统计
        $timeStats = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->selectRaw("{$timeFormat} as time, COUNT(*) as count, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost) as cost")
            ->groupBy('time')
            ->orderBy('time')
            ->get();

        // 按模型统计
        $modelStats = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $startDate)
            ->where('created_at', '<=', $endDate . ' 23:59:59')
            ->selectRaw('model_name, COUNT(*) as count, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost) as cost')
            ->groupBy('model_name')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'total_requests' => $totalRequests,
            'total_tokens' => (int)$totalTokens,
            'total_cost' => round((float)$totalCost, 4),
            'group_by' => $groupBy,
            'time_stats' => $timeStats,
            'model_stats' => $modelStats,
        ]]);
    }

    /**
     * 删除日志
     */
    public function destroy(Request $request, $id)
    {
        $log = Log::find($id);
        if (!$log) {
            return json(['code' => 404, 'msg' => '日志不存在']);
        }
        $log->delete();
        return json(['code' => 0, 'msg' => 'ok']);
    }

    /**
     * 清理过期日志
     */
    public function cleanup(Request $request)
    {
        $days = (int)$request->input('days', 30);
        if ($days < 1) {
            return json(['code' => 400, 'msg' => '天数必须大于0']);
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $deleted = Log::where('created_at', '<', $cutoff)->delete();

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['deleted' => $deleted]]);
    }
}
