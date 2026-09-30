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

    public function send(?int $applicationId, string $mobile, string $message): void
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

    /**
     * Renders an admin-editable sms_templates row, substituting {placeholder}
     * tokens. Falls back to $fallback (with the same substitution applied)
     * if the template row is missing or was emptied out, so a bad edit in
     * the admin UI can never silently break notifications.
     */
    private function template(string $key, array $placeholders, string $fallback): string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        $body = trim((string) $stmt->fetchColumn());
        if ($body === '') {
            $body = $fallback;
        }
        return strtr($body, $placeholders);
    }

    public function notifySubmitted(int $applicationId, string $mobile, string $refNumber): void
    {
        $msg = $this->template(
            'submitted',
            ['{ref}' => $refNumber],
            "MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes."
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyStatusChange(int $applicationId, string $mobile, string $refNumber, string $status, string $remarks = ''): void
    {
        $msg = $this->template(
            'status_change',
            ['{ref}' => $refNumber, '{status}' => $status, '{remarks}' => $remarks !== '' ? $remarks : 'none'],
            "MSWDO Virac: Application {ref} is now {status}. Note: {remarks}"
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyInterviewScheduled(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $location): void
    {
        $when = date('F j, Y g:i A', strtotime($scheduledAt));
        $msg = $this->template(
            'interview_scheduled',
            ['{ref}' => $refNumber, '{when}' => $when, '{location}' => $location],
            "MSWDO Virac: An interview for application {ref} has been scheduled on {when} at {location}. Please arrive on time and bring a valid ID."
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyRenewalSubmitted(int $applicationId, string $mobile, string $refNumber): void
    {
        $msg = $this->template(
            'renewal_submitted',
            ['{ref}' => $refNumber],
            "MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes."
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyRenewalReminder(int $applicationId, string $mobile, string $refNumber, string $expiresAt): void
    {
        $when = date('F j, Y', strtotime($expiresAt));
        $msg = $this->template(
            'renewal_reminder',
            ['{ref}' => $refNumber, '{when}' => $when],
            "MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption."
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyAppointmentReminder(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $location): void
    {
        $when = date('F j, Y g:i A', strtotime($scheduledAt));
        $msg = $this->template(
            'appointment_reminder',
            ['{ref}' => $refNumber, '{when}' => $when, '{location}' => $location],
            "MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID."
        );
        $this->send($applicationId, $mobile, $msg);
    }

    public function notifyPasswordReset(string $mobile, string $code): void
    {
        $msg = $this->template(
            'password_reset',
            ['{code}' => $code, '{minutes}' => (string) PASSWORD_RESET_CODE_MINUTES],
            "MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn't request this, ignore this message."
        );
        $this->send(null, $mobile, $msg);
    }
}
