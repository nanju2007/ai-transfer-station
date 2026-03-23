<?php

namespace app\controller\admin;

use support\Request;
use app\model\User;
use app\model\Channel;
use app\model\Log;
use app\model\Wallet;

class DashboardController
{
    /**
     * 管理端概览数据
     */
    public function index(Request $request)
    {
        $today = date('Y-m-d');

        // 用户总数
        $totalUsers = User::count();

        // 渠道数
        $totalChannels = Channel::where('status', 1)->count();

        // 今日请求数
        $todayRequests = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $today . ' 00:00:00')
            ->count();

        // 今日消费金额
        $todayCost = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $today . ' 00:00:00')
            ->sum('cost');

        // 今日token数
        $todayTokens = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $today . ' 00:00:00')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total')
            ->value('total') ?? 0;

        // 余额总计
        $totalBalance = Wallet::sum('balance');

        // 最近7天请求趋势
        $weekStart = date('Y-m-d', strtotime('-6 days'));
        $weeklyTrend = Log::where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $weekStart . ' 00:00:00')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(cost) as cost')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // 最近请求日志
        $recentLogs = Log::where('type', Log::TYPE_CONSUME)
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get(['id', 'username', 'model_name', 'token_name', 'prompt_tokens', 'completion_tokens', 'cost', 'duration', 'created_at']);

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'user_count' => $totalUsers,
            'channel_count' => $totalChannels,
            'today_requests' => $todayRequests,
            'today_cost' => sprintf('%.2f', $todayCost),
            'today_tokens' => (int)$todayTokens,
            'total_balance' => $totalBalance,
            'trend' => $weeklyTrend,
            'recent_logs' => $recentLogs,
        ]]);
    }
}
