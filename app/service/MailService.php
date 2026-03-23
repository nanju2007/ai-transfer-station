<?php

namespace app\service;

use app\model\Option;
use support\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class MailService
{
    /**
     * 通过Redis队列异步发送邮件
     */
    public static function sendAsync(string $to, string $subject, string $body, int $delay = 0): bool
    {
        try {
            \Webman\RedisQueue\Redis::send('send-mail', [
                'to'      => $to,
                'subject' => $subject,
                'body'    => $body,
            ], $delay);
            return true;
        } catch (\Throwable $e) {
            Log::error('MailService::sendAsync 投递失败', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 同步发送邮件（由队列消费者调用）
     */
    public function send(string $to, string $subject, string $body): bool
    {
        $config = $this->getSmtpConfig();

        if (empty($config['smtp_host'])) {
            Log::warning('MailService: SMTP未配置，跳过发送');
            return false;
        }

        try {
            return $this->sendViaPHPMailer($to, $subject, $body, $config);
        } catch (\Throwable $e) {
            Log::error('MailService: 发送失败', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 使用 PHPMailer 发送邮件
     */
    protected function sendViaPHPMailer(string $to, string $subject, string $body, array $config): bool
    {
        $mail = new PHPMailer(true);
        
        try {
            // SMTP 配置
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_user'];
            $mail->Password = $config['smtp_pass'];
            $mail->SMTPSecure = $config['smtp_encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)($config['smtp_port'] ?? 465);
            $mail->CharSet = 'UTF-8';

            // 发件人
            $mail->setFrom($config['smtp_from'] ?: $config['smtp_user'], $config['smtp_from_name'] ?? 'AI中转站');
            
            // 收件人
            $mail->addAddress($to);
            
            // 内容
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            
            $mail->send();
            return true;
        } catch (Exception $e) {
            throw new \RuntimeException("邮件发送失败: {$mail->ErrorInfo}");
        }
    }

    /**
     * 从options表读取SMTP配置
     */
    protected function getSmtpConfig(): array
    {
        $keys = [
            'smtp_server', 'smtp_port', 'smtp_account', 'smtp_token',
            'smtp_from', 'smtp_encryption', 'site_name',
        ];
        $config = Option::getOptions($keys);
        
        // 映射到统一的键名
        return [
            'smtp_host' => $config['smtp_server'] ?? '',
            'smtp_port' => $config['smtp_port'] ?? 465,
            'smtp_user' => $config['smtp_account'] ?? '',
            'smtp_pass' => $config['smtp_token'] ?? '',
            'smtp_from' => $config['smtp_from'] ?? '',
            'smtp_from_name' => $config['site_name'] ?? 'AI中转站',
            'smtp_encryption' => $config['smtp_encryption'] ?? 'ssl',
        ];
    }
}
