<?php

namespace app\controller\user;

use support\Request;
use support\Response;
use app\model\Log;
use app\model\ModelPricing;
use app\model\Model_;
use app\model\User;
use app\model\Group;

class LogController
{
    /**
     * 日志列表（分页、按模型/时间筛选）
     */
    public function index(Request $request)
    {
        $userId = $request->user['id'];
        $perPage = (int)$request->get('per_page', 20);
        $perPage = min($perPage, 100);

        $query = Log::where('user_id', $userId);

        // 按模型筛选
        $modelName = $request->get('model_name');
        if ($modelName) {
            $query->where('model_name', $modelName);
        }

        // 按类型筛选
        $type = $request->get('type');
        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
        }

        // 按令牌筛选
        $tokenId = $request->get('token_id');
        if ($tokenId) {
            $query->where('token_id', (int)$tokenId);
        }

        // 按时间范围筛选
        $startDate = $request->get('start_date');
        $endDate = $request->get('end_date');
        if ($startDate) {
            $query->where('created_at', '>=', $startDate . ' 00:00:00');
        }
        if ($endDate) {
            $query->where('created_at', '<=', $endDate . ' 23:59:59');
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($perPage, ['id', 'token_id', 'type', 'model_name', 'prompt_tokens', 'completion_tokens', 'cost', 'token_name', 'use_time', 'duration', 'ttft', 'is_stream', 'ip', 'created_at']);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $logs]);
    }

    /**
     * 使用统计（按小时/按天/按模型的token用量和费用统计）
     */
    public function statistics(Request $request)
    {
        $userId = $request->user['id'];
        $groupBy = $request->get('group_by', 'hour'); // hour 或 day

        // 统计时间范围，默认最近7天
        $days = (int)$request->get('days', 7);
        $days = min($days, 90);
        $startDate = date('Y-m-d', strtotime("-{$days} days")) . ' 00:00:00';

        // 根据 group_by 参数选择聚合粒度
        if ($groupBy === 'day') {
            $timeFormat = "DATE_FORMAT(created_at, '%Y-%m-%d')";
        } else {
            $timeFormat = "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
        }

        // 按时间聚合统计
        $timeStats = Log::where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $startDate)
            ->selectRaw("{$timeFormat} as time, COUNT(*) as count, SUM(cost) as cost, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens")
            ->groupBy('time')
            ->orderBy('time')
            ->get();

        // 按模型统计
        $modelStats = Log::where('user_id', $userId)
            ->where('type', Log::TYPE_CONSUME)
            ->where('created_at', '>=', $startDate)
            ->selectRaw('model_name, COUNT(*) as request_count, SUM(prompt_tokens) as total_prompt_tokens, SUM(completion_tokens) as total_completion_tokens, SUM(cost) as total_cost')
            ->groupBy('model_name')
            ->orderByDesc('total_cost')
            ->get();

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'group_by' => $groupBy,
            'time_stats' => $timeStats,
            'models' => $modelStats,
        ]]);
    }

    /**
     * 计费详情 - 获取单条日志的计费明细
     */
    public function billingDetail(Request $request, $id): Response
    {
        $userId = $request->user['id'];
        $log = Log::where('id', $id)->where('user_id', $userId)->first();
        if (!$log) {
            return json(['code' => 404, 'message' => '日志不存在']);
        }

        // 查询模型及定价
        $model = Model_::where('model_name', $log->model_name)->first();
        $pricing = $model ? ModelPricing::where('model_id', $model->id)->where('status', 1)->first() : null;

        // 查询用户分组倍率
        $user = User::find($userId);
        $group = Group::where('name', $user->group_name ?? 'default')->first();
        $ratio = $group ? (float)$group->ratio : 1;

        // 构建计费详情
        $detail = [
            'model_name' => $log->model_name,
            'prompt_tokens' => $log->prompt_tokens,
            'completion_tokens' => $log->completion_tokens,
            'total_tokens' => $log->prompt_tokens + $log->completion_tokens,
            'pricing' => $pricing ? [
                'billing_type' => $pricing->billing_type == 1 ? 'per_token' : 'per_request',
                'input_price' => (float)$pricing->input_price,
                'output_price' => (float)$pricing->output_price,
                'fixed_price' => (float)$pricing->per_request_price,
            ] : null,
            'group_name' => $user->group_name ?? 'default',
            'group_ratio' => $ratio,
            'cost_breakdown' => [],
            'total_cost' => (float)$log->cost,
            'ip' => $log->ip,
            'duration' => $log->duration,
            'ttft' => $log->ttft,
        ];

        if ($pricing) {
            if ($pricing->billing_type == 1) {
                // 按量计费
                $inputCost = $log->prompt_tokens * ((float)$pricing->input_price / 1000000);
                $outputCost = $log->completion_tokens * ((float)$pricing->output_price / 1000000);
                $detail['cost_breakdown'] = [
                    'input_cost' => round($inputCost, 6),
                    'output_cost' => round($outputCost, 6),
                    'subtotal' => round($inputCost + $outputCost, 6),
                    'ratio' => $ratio,
                    'final_cost' => round(($inputCost + $outputCost) * $ratio, 6),
                ];
            } else {
                // 按次计费
                $detail['cost_breakdown'] = [
                    'fixed_price' => (float)$pricing->per_request_price,
                    'ratio' => $ratio,
                    'final_cost' => round((float)$pricing->per_request_price * $ratio, 6),
                ];
            }
        }

        return json(['code' => 0, 'data' => $detail]);
    }
}
