<?php

namespace app\controller\user;

use support\Request;
use app\service\AuthService;
use app\model\User;
use app\model\Wallet;
use app\model\Option;
use Webman\Captcha\CaptchaBuilder;
use Webman\Captcha\PhraseBuilder;

class AuthController
{
    protected AuthService $authService;

    public function __construct()
    {
        $this->authService = new AuthService();
    }

    /**
     * GET /api/captcha - 获取验证码图片
     */
    public function captcha(Request $request)
    {
        // 从 options 获取验证码难度设置
        $length = (int)(Option::where('key', 'captcha_length')->value('value') ?: 4);
        $chars = Option::where('key', 'captcha_chars')->value('value') ?: 'abcdefghjkmnpqrstuvwxyz23456789';

        $phraseBuilder = new PhraseBuilder($length, $chars);
        $builder = new CaptchaBuilder(null, $phraseBuilder);
        $builder->build();

        $request->session()->set('captcha', strtolower($builder->getPhrase()));

        $img_content = $builder->get();
        return response($img_content, 200, ['Content-Type' => 'image/jpeg']);
    }

    /**
     * 用户登录（用户名/邮箱+密码）
     */
    public function login(Request $request)
    {
        $username = $request->post('username', '');
        $password = $request->post('password', '');
        $captcha = $request->post('captcha', '');

        if (empty($username) || empty($password)) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        // 验证码校验
        if (empty($captcha) || strtolower($captcha) !== $request->session()->get('captcha')) {
            return json(['code' => 400, 'msg' => '验证码错误']);
        }
        // 验证通过后清除验证码（防止重复使用）
        $request->session()->delete('captcha');

        // 支持邮箱登录
        $user = User::where('username', $username)->orWhere('email', $username)->first();
        if (!$user || !password_verify($password, $user->password)) {
            return json(['code' => 401, 'msg' => '用户名或密码错误']);
        }

        if ($user->status !== 1) {
            return json(['code' => 403, 'msg' => '账号已被禁用']);
        }

        // 更新登录信息
        $user->last_login_at = date('Y-m-d H:i:s');
        $user->last_login_ip = $request->getRealIp();
        $user->save();

        // 设置Session
        $sessionData = [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
        ];
        $request->session()->set('user', $sessionData);

        return json(['code' => 0, 'msg' => '登录成功', 'data' => $sessionData]);
    }

    /**
     * 用户注册
     */
    public function register(Request $request)
    {
        $username = $request->post('username', '');
        $email = $request->post('email', '');
        $password = $request->post('password', '');
        $captcha = $request->post('captcha', '');
        $emailCode = $request->post('email_code', '');

        if (empty($username) || empty($password)) {
            return json(['code' => 400, 'msg' => '用户名和密码不能为空']);
        }

        if (strlen($username) < 3 || strlen($username) > 20) {
            return json(['code' => 400, 'msg' => '用户名长度需在3-20个字符之间']);
        }

        if (strlen($password) < 6) {
            return json(['code' => 400, 'msg' => '密码长度不能少于6个字符']);
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '邮箱格式不正确']);
        }

        // 检查是否需要邮箱验证
        $emailVerifyRequired = Option::where('key', 'register_email_verify')->value('value') === '1';

        if ($emailVerifyRequired) {
            // 开启邮箱验证时，图形验证码已在发送邮箱验证码时校验过，此处只验证邮箱验证码
            if (empty($email)) {
                return json(['code' => 400, 'msg' => '注册需要提供邮箱']);
            }
            if (empty($emailCode)) {
                return json(['code' => 400, 'msg' => '请输入邮箱验证码']);
            }
            // 验证邮箱验证码
            $redis = \support\Redis::connection();
            $storedCode = $redis->get("register_email:{$email}");
            if (!$storedCode || $storedCode !== $emailCode) {
                return json(['code' => 400, 'msg' => '邮箱验证码错误或已过期']);
            }
            // 验证通过后删除
            $redis->del("register_email:{$email}");
        } else {
            // 未开启邮箱验证时，校验图形验证码
            if (empty($captcha) || strtolower($captcha) !== $request->session()->get('captcha')) {
                return json(['code' => 400, 'msg' => '验证码错误']);
            }
            $request->session()->delete('captcha');
        }

        $result = $this->authService->register([
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'display_name' => $request->post('display_name', ''),
            'aff_code' => $request->post('aff_code', ''),
        ], $request);

        if ($result['code'] !== 0) {
            return json(['code' => $result['code'], 'msg' => $result['message']]);
        }

        return json(['code' => 0, 'msg' => '注册成功', 'data' => $result['data']]);
    }

    /**
     * POST /api/user/auth/send-email-code - 发送邮箱验证码（注册用）
     */
    public function sendEmailCode(Request $request)
    {
        $email = $request->post('email', '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json(['code' => 400, 'msg' => '请输入正确的邮箱地址']);
        }

        // 检查邮箱是否已注册
        if (User::where('email', $email)->exists()) {
            return json(['code' => 400, 'msg' => '该邮箱已被注册']);
        }

        // 频率限制
        $redis = \support\Redis::connection();

        // 1. 同一邮箱60秒内只能发一次
        $rateLimitKey = "register_email_limit:{$email}";
        if ($redis->exists($rateLimitKey)) {
            return json(['code' => 429, 'msg' => '发送过于频繁，请60秒后再试']);
        }

        // 2. 同一IP地址1小时内最多发送10次
        $ip = $request->getRealIp();
        $ipLimitKey = "register_email_ip_limit:{$ip}";
        $ipCount = (int)$redis->get($ipLimitKey);
        if ($ipCount >= 10) {
            return json(['code' => 429, 'msg' => '当前IP发送验证码过于频繁，请1小时后再试']);
        }

        // 生成6位验证码
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 存入 Redis，10分钟有效
        $redis->setex("register_email:{$email}", 600, $code);
        // 设置邮箱频率限制（60秒）
        $redis->setex($rateLimitKey, 60, '1');
        // 设置IP频率限制（1小时内累计计数）
        if ($redis->exists($ipLimitKey)) {
            $redis->incr($ipLimitKey);
        } else {
            $redis->setex($ipLimitKey, 3600, '1');
        }

        // 通过队列发送邮件
        \Webman\RedisQueue\Client::send('send-mail', [
            'to' => $email,
            'subject' => '注册邮箱验证码',
            'body' => "您的注册验证码是：{$code}，有效期10分钟。如非本人操作请忽略。",
        ]);

        return json(['code' => 0, 'msg' => '验证码已发送到您的邮箱']);
    }

    /**
     * 退出登录
     */
    public function logout(Request $request)
    {
        $request->session()->delete('user');
        return json(['code' => 0, 'msg' => '退出成功']);
    }

    /**
     * 获取当前用户信息（含钱包余额）
     */
    public function info(Request $request)
    {
        $userId = $request->user['id'];
        $user = User::find($userId);

        if (!$user) {
            return json(['code' => 404, 'msg' => '用户不存在']);
        }

        $wallet = Wallet::where('user_id', $userId)->first();

        return json(['code' => 0, 'msg' => 'ok', 'data' => [
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'aff_code' => $user->aff_code,
            'aff_count' => $user->aff_count,
            'created_at' => $user->created_at?->toDateTimeString(),
            'wallet' => $wallet ? [
                'balance' => $wallet->balance,
                'total_recharge' => $wallet->total_recharge,
                'total_consumption' => $wallet->total_consumption,
                'currency' => $wallet->currency,
            ] : null,
        ]]);
    }

    /**
     * 发送重置密码验证码
     */
    public function forgotPassword(Request $request)
    {
        $email = $request->post('email');
        if (!$email) {
            return json(['code' => 400, 'msg' => '请输入邮箱']);
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            return json(['code' => 400, 'msg' => '该邮箱未注册']);
        }

        // 生成6位验证码
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // 存入 Redis，10分钟有效
        $redis = \support\Redis::connection();
        $redis->setex("reset_password:{$email}", 600, $code);

        // 发送邮件（通过 Redis 队列异步发送）
        \Webman\RedisQueue\Client::send('send-mail', [
            'to' => $email,
            'subject' => '重置密码验证码',
            'body' => "您的重置密码验证码是：{$code}，有效期10分钟。如非本人操作请忽略。",
        ]);

        return json(['code' => 0, 'msg' => '验证码已发送到您的邮箱']);
    }

    /**
     * 重置密码
     */
    public function resetPassword(Request $request)
    {
        $email = $request->post('email');
        $code = $request->post('code');
        $password = $request->post('password');

        if (!$email || !$code || !$password) {
            return json(['code' => 400, 'msg' => '参数不完整']);
        }

        if (strlen($password) < 6) {
            return json(['code' => 400, 'msg' => '密码至少6位']);
        }

        // 验证验证码
        $redis = \support\Redis::connection();
        $storedCode = $redis->get("reset_password:{$email}");
        if (!$storedCode || $storedCode !== $code) {
            return json(['code' => 400, 'msg' => '验证码错误或已过期']);
        }

        // 更新密码
        $user = User::where('email', $email)->first();
        if (!$user) {
            return json(['code' => 400, 'msg' => '用户不存在']);
        }

        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->save();

        // 删除验证码
        $redis->del("reset_password:{$email}");

        return json(['code' => 0, 'msg' => '密码重置成功，请重新登录']);
    }
}
