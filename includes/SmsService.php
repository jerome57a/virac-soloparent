<?php
/**
 * Thin wrapper around an SMS gateway (Semaphore PH / Vonage).
 *
 * Every call is recorded in sms_logs regardless of whether a provider is
 * configured, so MSWDO staff can see what notifications were (or should
 * have been) sent. Without an API key, messages are simply logged with
 * provider_status = 'skipped-no-api-key' -- wire up the real HTTP call in
 * send() once credentials are available.
 */
class SmsService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function send(int $applicationId, string $mobile, string $message): void
    {
        $status = 'skipped-no-api-key';

        if (SMS_API_KEY !== '') {
            $status = $this->sendViaSemaphore($mobile, $message) ? 'sent' : 'failed';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO sms_logs (application_id, mobile, message, provider, provider_status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$applicationId, $mobile, $message, SMS_PROVIDER, $status]);
    }

    private function sendViaSemaphore(string $mobile, string $message): bool
    {
        $ch = curl_init('https://api.semaphore.co/api/v4/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query([
                'apikey' => SMS_API_KEY,
                'number' => normalize_mobile($mobile),
                'message' => $message,
                'sendername' => SMS_SENDER_NAME,
            ]),
        ]);
        $result = curl_exec($ch);
        $ok = $result !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        return $ok;
    }

    public function notifySubmitted(int $applicationId, string $mobile, string $refNumber): void
    {
        $this->send($applicationId, $mobile, "MSWDO Virac: Your Solo Parent ID application $refNumber has been received and is Pending Review. We will text you when its status changes.");
    }

    public function notifyStatusChange(int $applicationId, string $mobile, string $refNumber, string $status, string $remarks = ''): void
    {
        $msg = "MSWDO Virac: Application $refNumber is now $status.";
        if ($remarks !== '') {
            $msg .= " Note: $remarks";
        }
        $this->send($applicationId, $mobile, $msg);
    }
}
