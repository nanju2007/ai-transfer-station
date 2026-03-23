<?php

namespace app\controller\user;

use support\Request;
use support\Db;
use app\model\Wallet;
use app\model\WalletTransaction;
use app\model\RedemptionCode;
use app\model\Option;

class WalletController
{
    /**
     * 钱包信息（余额、总充值、总消费）
     */
    public function index(Request $request)
    {
        $userId = $request->user['id'];
        $wallet = Wallet::where('user_id', $userId)->first();

        if (!$wallet) {
            return json(['code' => 0, 'msg' => 'ok', 'data' => [
                'balance' => '0.0000',
                'frozen_balance' => '0.0000',
                'total_recharge' => '0.0000',
                'total_consumption' => '0.0000',
                'currency' => 'CNY',
            ]]);
        }

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'balance' => $wallet->balance,
            'frozen_balance' => $wallet->frozen_balance,
            'total_recharge' => $wallet->total_recharge,
            'total_consumption' => $wallet->total_consumption,
            'currency' => $wallet->currency,
        ]]);
    }

    /**
     * 交易记录列表（分页、按类型筛选）
     */
    public function transactions(Request $request)
    {
        $userId = $request->user['id'];
        $perPage = (int)$request->get('per_page', 20);
        $perPage = min($perPage, 100);

        $query = WalletTransaction::where('user_id', $userId);

        // 按类型筛选：充值/消费/退款/兑换/调整
        $type = $request->get('type');
        if ($type !== null && $type !== '') {
            $query->where('type', (int)$type);
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

        $transactions = $query->orderByDesc('created_at')->paginate($perPage);

        return json(['code' => 0, 'msg' => 'ok', 'data' => $transactions]);
    }

    /**
     * 兑换码充值
     */
    public function redeem(Request $request)
    {
        $userId = $request->user['id'];
        $code = $request->post('code', '');

        if (empty($code)) {
            return json(['code' => 400, 'msg' => '请输入兑换码']);
        }

        // 使用数据库事务保证原子性
        Db::connection()->beginTransaction();
        try {
            // 锁定兑换码记录防止并发
            $redemption = RedemptionCode::where('key', $code)
                ->lockForUpdate()
                ->first();

            if (!$redemption) {
                Db::connection()->rollBack();
                return json(['code' => 404, 'msg' => '兑换码不存在']);
            }

            if ($redemption->status === RedemptionCode::STATUS_USED) {
                Db::connection()->rollBack();
                return json(['code' => 400, 'msg' => '兑换码已被使用']);
            }

            if ($redemption->status === RedemptionCode::STATUS_DISABLED) {
                Db::connection()->rollBack();
                return json(['code' => 400, 'msg' => '兑换码已被禁用']);
            }

            // 检查是否过期
            if ($redemption->expired_at && strtotime($redemption->expired_at) < time()) {
                Db::connection()->rollBack();
                return json(['code' => 400, 'msg' => '兑换码已过期']);
            }

            // 获取或创建钱包（加锁）
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id' => $userId,
                    'balance' => 0,
                    'currency' => 'CNY',
                ]);
                // 重新加锁获取
                $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            }

            $amount = bcdiv((string)$redemption->quota, '1000', 4);
            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($balanceBefore, $amount, 4);

            // 更新钱包余额
            $wallet->balance = $balanceAfter;
            $wallet->total_recharge = bcadd($wallet->total_recharge, $amount, 4);
            $wallet->save();

            // 创建交易记录
            WalletTransaction::create([
                'user_id' => $userId,
                'wallet_id' => $wallet->id,
                'type' => WalletTransaction::TYPE_REDEEM,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'description' => '兑换码充值: ' . $redemption->name,
                'related_id' => $redemption->id,
                'related_type' => 'redemption_code',
            ]);

            // 标记兑换码已使用
            $redemption->status = RedemptionCode::STATUS_USED;
            $redemption->used_user_id = $userId;
            $redemption->redeemed_at = date('Y-m-d H:i:s');
            $redemption->save();

            Db::connection()->commit();

            return json(['code' => 0, 'msg' => '兑换成功', 'data' => [
                'amount' => $amount,
                'balance' => $balanceAfter,
            ]]);
        } catch (\Throwable $e) {
            Db::connection()->rollBack();
            return json(['code' => 500, 'msg' => '兑换失败：' . $e->getMessage()]);
        }
    }

    /**
     * 发起在线充值（彩虹易支付）
     */
    public function pay(Request $request)
    {
        $userId = $request->user['id'];
        $amount = (float)$request->post('amount', 0);
        $type   = trim($request->post('type', ''));  // 支付通道：alipay/wxpay/qqpay，空则由平台显示收银台

        if ($amount <= 0) {
            return json(['code' => 400, 'msg' => '充值金额必须大于0']);
        }
        if ($amount > 10000) {
            return json(['code' => 400, 'msg' => '单次充值金额不能超过10000元']);
        }

        $options = Option::getOptions(['pay_address', 'pay_id', 'pay_key', 'pay_channels']);
        $payAddress  = trim($options['pay_address'] ?? '');
        $payId       = trim($options['pay_id'] ?? '');
        $payKey      = trim($options['pay_key'] ?? '');
        $channelsRaw = $options['pay_channels'] ?? '';

        if (empty($payAddress) || empty($payId) || empty($payKey)) {
            return json(['code' => 400, 'msg' => '支付功能未配置，请联系管理员']);
        }

        // 校验通道是否已开启（格式 ["alipay","wxpay"] 字符串数组）
        if (!empty($type)) {
            $channelNames = [];
            if (!empty($channelsRaw)) {
                $decoded = json_decode($channelsRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $ch) {
                        $name = is_string($ch) ? trim($ch) : (isset($ch['name']) ? trim($ch['name']) : '');
                        if ($name !== '') {
                            $channelNames[] = $name;
                        }
                    }
                }
            }
            if (!empty($channelNames) && !in_array($type, $channelNames, true)) {
                return json(['code' => 400, 'msg' => '所选支付通道未开启']);
            }
        }

        // 生成唯一订单号
        $outTradeNo = 'PAY' . $userId . date('YmdHis') . rand(1000, 9999);

        // 构建回调基础 URL（从请求头获取，支持反向代理）
        $proto   = $request->header('x-forwarded-proto');
        $scheme  = ($proto === 'https') ? 'https' : 'http';
        $host    = $request->header('x-forwarded-host') ?: $request->host();
        $baseUrl = $scheme . '://' . $host;

        // 构建易支付参数
        $params = [
            'pid'          => $payId,
            'out_trade_no' => $outTradeNo,
            'notify_url'   => $baseUrl . '/api/user/wallet/pay/notify',
            'return_url'   => $baseUrl . '/user/wallet',
            'name'         => '账户充值 ' . number_format($amount, 2) . ' 元',
            'money'        => number_format($amount, 2, '.', ''),
        ];
        // 如果指定了支付通道则传入 type 参数
        if (!empty($type)) {
            $params['type'] = $type;
        }

        // 按参数名字母排序后签名
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '') {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&');
        $params['sign']      = md5($signStr . $payKey);
        $params['sign_type'] = 'MD5';

        $payUrl = rtrim($payAddress, '/') . '/submit.php?' . http_build_query($params);

        return json(['code' => 0, 'msg' => 'ok', 'data' => ['pay_url' => $payUrl, 'out_trade_no' => $outTradeNo]]);
    }

    /**
     * 易支付异步通知（服务端回调）
     */
    public function payNotify(Request $request)
    {
        $params = $request->get();

        $options = Option::getOptions(['pay_key']);
        $payKey  = trim($options['pay_key'] ?? '');

        if (empty($payKey)) {
            return response('fail');
        }

        // 验签
        $sign = $params['sign'] ?? '';
        unset($params['sign'], $params['sign_type']);
        ksort($params);
        $signStr = '';
        foreach ($params as $k => $v) {
            if ($v !== '') {
                $signStr .= $k . '=' . $v . '&';
            }
        }
        $signStr = rtrim($signStr, '&');
        if (md5($signStr . $payKey) !== $sign) {
            return response('fail');
        }

        // 仅处理交易成功状态
        if (($params['trade_status'] ?? '') !== 'TRADE_SUCCESS') {
            return response('success');
        }

        $outTradeNo = $params['out_trade_no'] ?? '';
        $money      = (float)($params['money'] ?? 0);

        if ($money <= 0 || empty($outTradeNo)) {
            return response('fail');
        }

        // 从订单号解析 userId（格式：PAY{userId}{date}{rand}）
        if (!preg_match('/^PAY(\d+)\d{14}\d{4}$/', $outTradeNo, $m)) {
            return response('fail');
        }
        $userId = (int)$m[1];

        // 检查是否已处理（幂等）
        $exists = WalletTransaction::where('description', 'like', '%' . $outTradeNo . '%')
            ->where('type', WalletTransaction::TYPE_RECHARGE)
            ->where('user_id', $userId)
            ->exists();
        if ($exists) {
            return response('success');
        }

        Db::connection()->beginTransaction();
        try {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            if (!$wallet) {
                $wallet = Wallet::create([
                    'user_id'  => $userId,
                    'balance'  => 0,
                    'currency' => 'CNY',
                ]);
                $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->first();
            }

            $amount        = number_format($money, 4, '.', '');
            $balanceBefore = $wallet->balance;
            $balanceAfter  = bcadd($balanceBefore, $amount, 4);

            $wallet->balance        = $balanceAfter;
            $wallet->total_recharge = bcadd($wallet->total_recharge, $amount, 4);
            $wallet->save();

            WalletTransaction::create([
                'user_id'       => $userId,
                'wallet_id'     => $wallet->id,
                'type'          => WalletTransaction::TYPE_RECHARGE,
                'amount'        => $amount,
                'balance_before'=> $balanceBefore,
                'balance_after' => $balanceAfter,
                'description'   => '在线充值: ' . $outTradeNo,
                'related_id'    => null,
                'related_type'  => 'epay',
            ]);

            Db::connection()->commit();
        } catch (\Throwable $e) {
            Db::connection()->rollBack();
            return response('fail');
        }

        return response('success');
    }

    /**
     * 支付完成跳转（同步回调，直接重定向到钱包页面）
     */
    public function payReturn(Request $request)
    {
        return redirect('/user/wallet');
    }
}
