<?php

namespace app\controller\user;

use support\Request;
use app\model\Log;
use app\model\Wallet;
use Illuminate\Support\Facades\DB;

class DashboardController
{
    /**
     * 概览数据
     */
    public function index(Request $request)
    {
        $userId = $request->user['id'];
        $today = date('Y-m-d');
        $todayStart = $today . ' 00:00:00';
        $todayEnd = $today . ' 23:59:59';

        // 今日请求
        $todayRequests = Log::where('user_id', $userId)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->count();

        // 今日消费（元）
        $todayConsumption = Log::where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->sum('cost');

        // 总消费（元）
        $totalConsumption = Log::where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->sum('cost');

        // 余额
        $wallet = Wallet::where('user_id', $userId)->first();
        $balance = $wallet ? $wallet->balance : 0;

        // 本月请求趋势图数据（最近30天每天的请求数）
        $thirtyDaysAgo = date('Y-m-d', strtotime('-29 days'));
        $trendRaw = Log::where('user_id', $userId)
            ->where('created_at', '>=', $thirtyDaysAgo . ' 00:00:00')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trend = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $trend[] = [
                'date' => $date,
                'count' => (int)($trendRaw[$date]['count'] ?? 0),
            ];
        }

        // 模型使用占比数据
        $modelUsage = Log::where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->selectRaw('model_name, COUNT(*) as count, SUM(cost) as total_cost')
            ->groupBy('model_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'today_requests' => $todayRequests,
            'today_consumption' => $todayConsumption,
            'total_consumption' => $totalConsumption,
            'balance' => $balance,
            'trend' => $trend,
            'model_usage' => $modelUsage,
        ]]);
    }

    /**
     * 最近使用记录（最近10条）
     */
    public function recentLogs(Request $request)
    {
        $userId = $request->user['id'];

        $logs = Log::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'model_name', 'prompt_tokens', 'completion_tokens', 'cost', 'token_name', 'use_time', 'is_stream', 'created_at']);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $logs]);
    }
}
