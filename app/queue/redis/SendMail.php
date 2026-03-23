<?php

namespace app\queue\redis;

use Webman\RedisQueue\Consumer;
use app\service\MailService;
use support\Log;

class SendMail implements Consumer
{
    /**
     * 要消费的队列名
     */
    public $queue = 'send-mail';

    /**
     * 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接
     */
    public $connection = 'default';

    /**
     * 消费
     */
    public function consume($data)
    {
        $to      = $data['to'] ?? '';
        $subject = $data['subject'] ?? '';
        $body    = $data['body'] ?? '';

        if (empty($to) || empty($subject)) {
            Log::warning('SendMail: 缺少必要参数', $data);
            return;
        }

        $mailService = new MailService();
        $result = $mailService->send($to, $subject, $body);

        if ($result) {
            Log::info("SendMail: 邮件发送成功 -> {$to}");
        } else {
            throw new \RuntimeException("SendMail: 邮件发送失败 -> {$to}");
        }
    }

    /**
     * 消费失败回调
     */
    public function onConsumeFailure(\Throwable $e, $package)
    {
        Log::error('SendMail: 消费失败', [
            'error'    => $e->getMessage(),
            'queue'    => $package['queue'] ?? '',
            'attempts' => $package['attempts'] ?? 0,
            'data'     => $package['data'] ?? [],
        ]);
    }
}
