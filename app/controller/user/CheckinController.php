<?php

namespace app\controller\user;

use support\Request;
use support\Response;
use app\model\Checkin;
use app\model\Wallet;
use app\model\WalletTransaction;
use app\model\Option;
use support\Db;

class CheckinController
{
    /**
     * POST /api/user/checkin - 签到
     */
    public function checkin(Request $request): Response
    {
        $userId = $request->user['id'];

        // 检查签到功能是否启用
        $enabled = Option::getOption('checkin_enabled', '0');
        if (!$enabled || $enabled === '0') {
            return json(['code' => 400, 'message' => '签到功能未启用']);
        }

        // 检查今天是否已签到
        $today = date('Y-m-d');
        $exists = Checkin::where('user_id', $userId)->where('checkin_date', $today)->exists();
        if ($exists) {
            return json(['code' => 400, 'message' => '今天已经签到过了']);
        }

        // 获取签到金额范围
        $minAmount = (float)(Option::getOption('checkin_min_amount', '0.01'));
        $maxAmount = (float)(Option::getOption('checkin_max_amount', '0.1'));

        // 生成随机金额（4位小数）
        $amount = round($minAmount + mt_rand() / mt_getrandmax() * ($maxAmount - $minAmount), 4);

        try {
            Db::beginTransaction();

            // 记录签到
            Checkin::create([
                'user_id' => $userId,
                'amount' => $amount,
                'checkin_date' => $today,
            ]);

            // 增加钱包余额
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if (!$wallet) {
                $wallet = Wallet::create(['user_id' => $userId, 'balance' => 0, 'currency' => 'CNY']);
                $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            }

            $balanceBefore = (float)$wallet->balance;
            $balanceAfter = round($balanceBefore + $amount, 4);

            Wallet::where('id', $wallet->id)->update([
                'balance' => $balanceAfter,
                'total_recharge' => Db::raw("total_recharge + " . (string)$amount),
            ]);

            // 记录钱包交易
            WalletTransaction::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'type' => WalletTransaction::TYPE_RECHARGE,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => '每日签到奖励',
                'related_id' => 0,
                'related_type' => 'checkin',
            ]);

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            return json(['code' => 500, 'message' => '签到失败，请稍后重试']);
        }

        return json(['code' => 0, 'message' => '签到成功', 'data' => [
            'amount' => $amount,
            'balance' => $balanceAfter,
        ]]);
    }

    /**
     * GET /api/user/checkin/status - 获取签到状态
     */
    public function status(Request $request): Response
    {
        $userId = $request->user['id'];
        $today = date('Y-m-d');

        $enabled = Option::getOption('checkin_enabled', '0');
        $checkedIn = Checkin::where('user_id', $userId)->where('checkin_date', $today)->exists();

        // 本月签到天数
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $monthCount = Checkin::where('user_id', $userId)
            ->whereBetween('checkin_date', [$monthStart, $monthEnd])
            ->count();

        // 本月签到总额
        $monthTotal = Checkin::where('user_id', $userId)
            ->whereBetween('checkin_date', [$monthStart, $monthEnd])
            ->sum('amount');

        return json(['code' => 0, 'data' => [
            'enabled' => $enabled && $enabled !== '0',
            'checked_in_today' => $checkedIn,
            'month_count' => $monthCount,
            'month_total' => round((float)$monthTotal, 4),
        ]]);
    }
}
