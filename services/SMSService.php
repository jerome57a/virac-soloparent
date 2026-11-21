<?php
/**
 * Server-side SMS service. Every message goes through send(), which:
 *   1. rejects an invalid Philippine mobile number without calling any gateway;
 *   2. logs the attempt in sms_logs as PENDING before anything is sent;
 *   3. hands it to the configured provider (SMS_PROVIDER, see config/app.php);
 *   4. updates the log to SENT or FAILED with the provider's answer.
 * Sending never throws for a gateway problem -- the failure is recorded and an
 * administrator can retry it (up to SMS_MAX_ATTEMPTS attempts in total).
 *
 * Providers (all settings come from the environment / config/local.php, none are hard-coded):
 *   test       test mode (default): logged as SENT with test_mode = 1, never sent anywhere.
 *   iprog      IPROG SMS HTTP API (SMS_API_KEY = your IPROG API token, SMS_API_URL, SMS_TIMEOUT).
 *
 * Message text comes from the admin-editable sms_templates table (see
 * TEMPLATES for the keys, placeholders and the built-in fallback text).
 */
class SMSService
{
    /** key => [label, fallback text]. Placeholders in braces are filled in by the notify* methods. */
    public const TEMPLATES = [
        'submitted' => ['Application Submitted', 'MSWDO Virac: Your Solo Parent ID application {ref} has been received and is Pending Review. We will text you when its status changes.'],
        'status_change' => ['Status Change (approval/rejection/correction/etc.)', 'MSWDO Virac: Application {ref} is now {status}. Note: {remarks}'],
        'interview_scheduled' => ['Interview/Appointment Scheduled', 'Hello {name}, your Solo Parent ID application interview is scheduled on {when}. Location: {location}. Please arrive on time. For inquiries, contact the MSWDO Virac office.'],
        'renewal_submitted' => ['Renewal Submitted', 'MSWDO Virac: Your Solo Parent ID renewal request {ref} has been received and is Pending Review. We will text you when its status changes.'],
        'renewal_reminder' => ['Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.'],
        'renewal_expired' => ['Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO to keep your benefits.'],
        'appointment_reminder' => ['Appointment Reminder (auto)', 'MSWDO Virac: Reminder -- your appointment for application {ref} is on {when} at {location}. Please arrive on time and bring a valid ID.'],
        'appointment_cancelled' => ['Appointment Cancelled', 'MSWDO Virac: Your appointment for application {ref} on {when} was cancelled. Reason: {reason}. Please contact MSWDO for a new schedule.'],
        'appointment_missed' => ['Appointment Missed', 'MSWDO Virac: You missed your appointment for application {ref} on {when}. Please contact MSWDO for a new schedule.'],
        'interview_date_assigned' => ['Interview Date Scheduled by MSWDO Staff', 'MSWDO Virac: Your interview for application {ref} is scheduled on {when}, {hours} at {location}. You may come on that date, or log in to your e-SoloParentID account and pick another date.'],
        'interview_date_booked' => ['Interview Date Booked', 'MSWDO Virac: Your interview for application {ref} is booked for {when} at {location}. Please come during office hours on that date and bring a valid ID.'],
        'document_correction' => ['Document Needs Correction', 'MSWDO Virac: Your document "{document}" for application {ref} needs correction. Please log in and open Supporting Documents to see what to fix and upload a replacement.'],
        'password_reset' => ['Password Reset Code', "MSWDO Virac: Your password reset code is {code}. It expires in {minutes} minutes. If you didn't request this, ignore this message."],
        'mobile_verification' => ['Sign-up Verification Code', "MSWDO Virac: Your e-SoloParentID verification code is {code}. It expires in {minutes} minutes. If you didn't sign up, ignore this message."],
    ];
    /**
     * Shortened wording that fits one 160-character text (so one credit) once the gateway's own header is added. It is used
     * only while SMS_COMPACT_TEXTS is on (a small test token); the full wording above is the default for deployment.
     * database/sms_wording.php compact|full switches the stored template rows to match.
     */
    public const COMPACT_TEMPLATES = [
        'submitted' => ['Application Submitted', 'MSWDO Virac: Application {ref} received and Pending Review. We will text you when its status changes.'],
        'renewal_submitted' => ['Renewal Submitted', 'MSWDO Virac: Renewal request {ref} received and Pending Review. We will text you when its status changes.'],
        'renewal_reminder' => ['Renewal Reminder (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please renew online or visit MSWDO.'],
        'renewal_expired' => ['Renewal Reminder after Expiry (auto)', 'MSWDO Virac: Your Solo Parent ID ({ref}) expired on {when}. Please renew online or visit MSWDO.'],
        'appointment_reminder' => ['Appointment Reminder (auto)', 'MSWDO Virac: Reminder: your interview for {ref} is on {when}. Please bring a valid ID.'],
        'interview_date_assigned' => ['Interview Date Scheduled by MSWDO Staff', 'MSWDO Virac: Interview for {ref} is on {when}, {hours}. Come then, or log in to e-SoloParentID to pick another date.'],
        'interview_date_booked' => ['Interview Date Booked', 'MSWDO Virac: Interview for {ref} is booked for {when}. Come during office hours with a valid ID.'],
    ];
    /** Placeholders each template can use (anything else would be sent literally); the first is required. */
    public const PLACEHOLDERS = [
        'submitted' => ['{ref}'],
        'status_change' => ['{ref}', '{status}', '{remarks}'],
        'interview_scheduled' => ['{name}', '{ref}', '{when}', '{location}'],
        'renewal_submitted' => ['{ref}'],
        'renewal_reminder' => ['{ref}', '{when}'],
        'renewal_expired' => ['{ref}', '{when}'],
        'appointment_reminder' => ['{ref}', '{when}', '{location}'],
        'appointment_cancelled' => ['{ref}', '{when}', '{reason}'],
        'appointment_missed' => ['{ref}', '{when}'],
        'interview_date_booked' => ['{ref}', '{when}', '{location}'],
        'interview_date_assigned' => ['{ref}', '{when}', '{hours}', '{location}'],
        'document_correction' => ['{ref}', '{document}'],
        'password_reset' => ['{code}', '{minutes}'],
        'mobile_verification' => ['{code}', '{minutes}'],
    ];
    public const PROVIDERS = ['test', 'iprog'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** True while the shortened wording is on (SMS_COMPACT_TEXTS): fewer characters, so fewer gateway credits. */
    public static function compact(): bool
    {
        return defined('SMS_COMPACT_TEXTS') && SMS_COMPACT_TEXTS;
    }

    /** The built-in text for $key in the wording that is switched on (a stored row, when present, still wins). */
    public static function defaultBody(string $key, ?bool $compact = null): string
    {
        $compact = $compact ?? self::compact();
        return ($compact ? (self::COMPACT_TEMPLATES[$key] ?? null) : null) !== null ? self::COMPACT_TEMPLATES[$key][1] : (self::TEMPLATES[$key][1] ?? '');
    }

    /**
     * What to tell staff about one SMS result (the array send()/sendTemplate() return): sent, failed or not sent at all.
     *
     * @return array{state: string, text: string, log_id: ?int}
     */
    public static function outcomeNotice(array $result, string $what): array
    {
        $logId = !empty($result['id']) ? (int) $result['id'] : null;
        $status = (string) ($result['status'] ?? '');
        if ($status === 'SENT') {
            return ['state' => 'sent', 'log_id' => $logId, 'text' => $what . ': the SMS to the Solo Parent was accepted by the SMS gateway. Only the Solo Parent\'s phone can confirm it arrived.'];
        }
        if ($status === 'DISABLED' || ($result['provider_status'] ?? '') === 'disabled') {
            return ['state' => 'skipped', 'log_id' => $logId, 'text' => $what . ': NO SMS was sent, because SMS notifications are switched off in Notification Settings. Switch them on, then use Retry SMS.'];
        }
        $help = self::failureHelp((string) ($result['error'] ?? ''));
        return ['state' => 'failed', 'log_id' => $logId, 'text' => $what . ': the SMS to the Solo Parent FAILED. Cause: ' . $help['cause'] . ' What to do: ' . $help['action'] . ' (Details: ' . (($result['error'] ?? '') ?: 'provider error') . '). Use Retry SMS to send it again.'];
    }

    /**
     * The real cause of a failed text, in plain words, and what staff can do about it. $error is the message stored with the failed
     * SMS (sms_logs.error). Pure: it reads nothing but that text. ['cause' => ..., 'action' => ..., 'kind' => a short code].
     */
    public static function failureHelp(?string $error): array
    {
        $e = strtolower((string) $error);
        $has = function (string ...$needles) use ($e): bool {
            foreach ($needles as $n) {
                if (str_contains($e, $n)) {
                    return true;
                }
            }
            return false;
        };
        if ($has('allowlist')) {
            return ['kind' => 'allowlist', 'cause' => 'This number is not on the SMS test allowlist, so nothing was sent.', 'action' => 'Add the number to SMS_ALLOWED_NUMBERS, or empty that setting in the local settings file to text everyone, then Retry SMS.'];
        }
        if ($has('daily sms limit')) {
            return ['kind' => 'daily-limit', 'cause' => 'The daily SMS limit was reached, so nothing was sent.', 'action' => 'Try again tomorrow, or raise SMS_DAILY_LIMIT in the local settings file.'];
        }
        if ($has('same text was already sent')) {
            return ['kind' => 'duplicate', 'cause' => 'The same text was just sent to this number.', 'action' => 'No action needed; the Solo Parent already received it.'];
        }
        if ($has('invalid mobile number')) {
            return ['kind' => 'bad-number', 'cause' => 'The Solo Parent\'s mobile number is not a valid Philippine number.', 'action' => 'Correct the number on the Solo Parent\'s record, then press Retry SMS.'];
        }
        if ($has('could not resolve host', 'could not reach', 'failed to connect', 'connection refused', 'network is unreachable', 'ssl connect', 'recv failure', 'empty reply')) {
            if ($has('timed out', 'timeout')) {
                return ['kind' => 'timeout', 'cause' => 'The SMS service did not answer in time (slow or unstable internet).', 'action' => 'Check the internet connection, then press Retry SMS.'];
            }
            return ['kind' => 'no-internet', 'cause' => 'There is no internet connection, or the SMS service cannot be reached.', 'action' => 'Check the internet connection, then press Retry SMS. The schedule itself is already saved.'];
        }
        if ($has('timed out', 'timeout')) {
            return ['kind' => 'timeout', 'cause' => 'The SMS service did not answer in time (slow or unstable internet).', 'action' => 'Check the internet connection, then press Retry SMS.'];
        }
        if ($has('invalid api token', 'no load balance', 'load balance', 'insufficient', 'out of credit', 'no credit left', 'unauthorized')) {
            return ['kind' => 'token-or-credit', 'cause' => 'The SMS token is invalid, or it has no SMS credit left.', 'action' => 'Put a token that still has credit in the local settings file (SMS_API_KEY), then press Retry SMS.'];
        }
        if ($has('sms_api_key', 'curl extension', 'not set')) {
            return ['kind' => 'not-configured', 'cause' => 'SMS sending is not set up on this computer.', 'action' => 'Ask the MSWDO admin or the system administrator to set the SMS token (SMS_API_KEY in the local settings file).'];
        }
        if ($has('disabled in settings', 'switched off')) {
            return ['kind' => 'disabled', 'cause' => 'SMS notifications are switched off in Notification Settings.', 'action' => 'Switch them on (MSWDO admin), then press Retry SMS.'];
        }
        if ($has('gateway rejected', 'http ')) {
            return ['kind' => 'rejected', 'cause' => 'The SMS service refused the message.', 'action' => 'Read the details below. If it keeps happening, contact the SMS provider (IPROG).'];
        }
        return ['kind' => 'unknown', 'cause' => 'The SMS could not be sent.', 'action' => 'Press Retry SMS. If it fails again, read the details below or contact the system administrator.'];
    }

    /** 'test' or 'iprog' -- an unknown SMS_PROVIDER value is treated as test mode so nothing is sent by accident. */
    public static function provider(): string
    {
        return in_array(SMS_PROVIDER, self::PROVIDERS, true) ? SMS_PROVIDER : 'test';
    }

    public static function testMode(): bool
    {
        return self::provider() === 'test';
    }

    /** A Philippine mobile number in 09XXXXXXXXX form, or null if it isn't one. */
    public static function normalizeNumber(string $mobile): ?string
    {
        $digits = preg_replace('/[\s().-]/', '', $mobile);
        if (preg_match('/^(?:\+?63|0)?(9\d{9})$/', $digits, $m)) {
            return '0' . $m[1];
        }
        return null;
    }

    /**
     * Sends one SMS and logs it. $logMessage replaces the text stored in
     * sms_logs when the real text holds a secret (e.g. a reset code).
     * Returns the log row's ['id', 'status' (SENT|FAILED), 'test_mode', 'error'].
     */
    public function send(?int $applicationId, string $mobile, string $message, ?string $templateKey = null, ?string $logMessage = null): array
    {
        $message = fit_text($message, 500);
        $number = self::normalizeNumber($mobile);
        $provider = self::provider();
        $stmt = $this->pdo->prepare(
            "INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status)
             VALUES (?, ?, ?, 'PENDING', ?, ?, ?, 'pending')"
        );
        $stmt->execute([$applicationId, mb_substr($number ?? $mobile, 0, 20), fit_text($logMessage ?? $message, 500), (int) ($provider === 'test'), $templateKey, $provider]);
        $logId = (int) $this->pdo->lastInsertId();

        $blocked = $number !== null ? $this->blockReason($provider, $number, $message) : null;
        $result = $number === null
            ? ['ok' => false, 'provider_status' => 'not-sent', 'message_id' => null, 'error' => 'Invalid mobile number.']
            : ($blocked !== null
                ? ['ok' => false, 'provider_status' => 'blocked', 'message_id' => null, 'error' => $blocked]
                : $this->dispatch($provider, $number, $message));
        return $this->record($logId, $result, $provider === 'test');
    }

    /**
     * Retries a FAILED message (administrators, from the SMS log). Only
     * possible while attempts remain and the message wasn't masked. Returns
     * [error or null, result].
     */
    public function retry(int $logId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sms_logs WHERE id = ?');
        $stmt->execute([$logId]);
        $log = $stmt->fetch();
        if (!$log || $log['status'] !== 'FAILED') {
            return ['Only a failed message can be retried.', null];
        }
        if ((int) $log['attempts'] >= SMS_MAX_ATTEMPTS) {
            return ['This message has already been tried ' . SMS_MAX_ATTEMPTS . ' times.', null];
        }
        if ($log['template_key'] === 'document_correction') {
            // A correction SMS is only ever sent through staff preview-and-confirm, which checks the batch is still
            // current and not already sent. A blind re-send of the stored text could duplicate it or contradict the record.
            return ['Correction SMS cannot be retried from the SMS log. MSWDO staff must confirm it again from the application review page.', null];
        }
        if ($log['template_key'] === 'password_reset') {
            return ['Password reset codes cannot be re-sent from the log; the Solo Parent should request a new code.', null];
        }
        if ($log['template_key'] === 'mobile_verification') {
            return ['Sign-up verification codes cannot be re-sent from the log; the Solo Parent should request a new code.', null];
        }
        if (in_array($log['template_key'], ['interview_scheduled', 'interview_custom', 'id_release_scheduled', 'id_release_custom'], true) && !$this->templateEnabled('interview_scheduled')) {
            return ['Appointment SMS notifications are disabled in Notification Settings.', null];
        }
        $number = self::normalizeNumber($log['mobile']);
        $provider = self::provider();
        $claim = $this->pdo->prepare("UPDATE sms_logs SET status = 'PENDING', attempts = attempts + 1, provider = ?, test_mode = ?, error = NULL WHERE id = ? AND status = 'FAILED' AND attempts < ?");
        $claim->execute([$provider, (int) ($provider === 'test'), $logId, SMS_MAX_ATTEMPTS]);
        if ($claim->rowCount() !== 1) {
            return ['This SMS has already been retried or has reached its retry limit.', null];
        }
        $blocked = $number !== null ? $this->blockReason($provider, $number, (string) $log['message']) : null;
        $result = $number === null
            ? ['ok' => false, 'provider_status' => 'not-sent', 'message_id' => null, 'error' => 'Invalid mobile number.']
            : ($blocked !== null
                ? ['ok' => false, 'provider_status' => 'blocked', 'message_id' => null, 'error' => $blocked]
                : $this->dispatch($provider, $number, (string) $log['message']));
        return [null, $this->record($logId, $result, $provider === 'test')];
    }

    /**
     * Why a REAL text must not be sent now (null = go ahead). These guards exist to save gateway credits and never apply
     * in test mode: an allowlist (SMS_ALLOWED_NUMBERS), a daily cap (SMS_DAILY_LIMIT) and a duplicate check (the same
     * text to the same number already accepted in the last 10 minutes, e.g. a double click).
     */
    private function blockReason(string $provider, string $number, string $message): ?string
    {
        if ($provider === 'test') {
            return null;
        }
        $allowed = array_filter(array_map(fn ($n) => self::normalizeNumber(trim($n)), explode(',', SMS_ALLOWED_NUMBERS)));
        if ($allowed && !in_array($number, $allowed, true)) {
            return 'Not sent: this number is not on the SMS test allowlist (SMS_ALLOWED_NUMBERS), so no credit was used.';
        }
        if (SMS_DAILY_LIMIT > 0) {
            $today = $this->pdo->query("SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0 AND status = 'SENT' AND sent_at >= CURDATE()")->fetchColumn();
            if ((int) $today >= SMS_DAILY_LIMIT) {
                return 'Not sent: the daily SMS limit (' . SMS_DAILY_LIMIT . ') was reached, so no credit was used.';
            }
        }
        $dup = $this->pdo->prepare("SELECT COUNT(*) FROM sms_logs WHERE test_mode = 0 AND status = 'SENT' AND mobile = ? AND message = ? AND sent_at >= (NOW() - INTERVAL 10 MINUTE)");
        $dup->execute([$number, fit_text($message, 500)]);
        if ((int) $dup->fetchColumn() > 0) {
            return 'Not sent: the same text was already sent to this number a moment ago, so no credit was used.';
        }
        return null;
    }

    private function record(int $logId, array $result, bool $testMode): array
    {
        $status = $result['ok'] ? 'SENT' : 'FAILED';
        $this->pdo->prepare('UPDATE sms_logs SET status = ?, provider_status = ?, provider_message_id = ?, error = ? WHERE id = ?')
            ->execute([$status, mb_substr($result['provider_status'], 0, 30), $result['message_id'] !== null ? mb_substr((string) $result['message_id'], 0, 100) : null,
                $result['error'] !== null ? mb_substr($result['error'], 0, 255) : null, $logId]);
        return ['id' => $logId, 'status' => $status, 'test_mode' => $testMode, 'error' => $result['error']];
    }

    /** ['ok', 'provider_status', 'message_id', 'error'] from the provider. Never throws. */
    private function dispatch(string $provider, string $number, string $message): array
    {
        if ($provider === 'test') {
            return ['ok' => true, 'provider_status' => 'test-mode', 'message_id' => null, 'error' => null];
        }
        try {
            return $this->sendViaIprog($number, $message);
        } catch (Throwable $e) {
            error_log('SMS gateway error: ' . $e->getMessage());
            return ['ok' => false, 'provider_status' => 'error', 'message_id' => null, 'error' => 'Gateway error.'];
        }
    }

    /**
     * IPROG SMS: POST JSON {api_token, phone_number (63XXXXXXXXXX), message}. Success is an HTTP 2xx whose JSON has no
     * error status; anything else is a failure. The token (SMS_API_KEY) is never written to the log.
     */
    private function sendViaIprog(string $number, string $message): array
    {
        if (SMS_API_KEY === '') {
            return ['ok' => false, 'provider_status' => 'not-configured', 'message_id' => null, 'error' => 'SMS_API_KEY (the IPROG API token) is not set.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'provider_status' => 'not-configured', 'message_id' => null, 'error' => 'The PHP curl extension is not enabled.'];
        }
        $ch = curl_init(SMS_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, SMS_TIMEOUT),
            CURLOPT_TIMEOUT => SMS_TIMEOUT,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'api_token' => SMS_API_KEY,
                'phone_number' => '63' . substr($number, 1),
                'message' => $message,
            ]),
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        if ($body === false) {
            return ['ok' => false, 'provider_status' => 'unreachable', 'message_id' => null, 'error' => 'Could not reach the SMS gateway: ' . $curlError];
        }
        $data = json_decode((string) $body, true);
        $status = is_array($data) && isset($data['status']) ? (string) $data['status'] : '';
        $statusOk = $status === '' || $status === '200' || in_array(strtolower($status), ['success', 'sent', 'queued'], true);
        $id = is_array($data) ? ($data['message_id'] ?? $data['id'] ?? null) : null;
        // Accepted only with IPROG's message id: a bare 200 page (maintenance, proxy) must not be recorded as sent.
        if ($httpCode >= 200 && $httpCode < 300 && $statusOk && $id !== null && $id !== '') {
            return ['ok' => true, 'provider_status' => 'accepted', 'message_id' => $id !== null ? (string) $id : null, 'error' => null];
        }
        return ['ok' => false, 'provider_status' => 'http-' . $httpCode, 'message_id' => null,
            'error' => 'Gateway rejected the message (HTTP ' . $httpCode . '): ' . self::describeError($data, (string) $body)];
    }

    /** A short, key-free description of a gateway error response. */
    private static function describeError(mixed $data, string $body): string
    {
        if (is_array($data)) {
            $parts = [];
            array_walk_recursive($data, function ($v, $k) use (&$parts) {
                if (is_string($v) && $k !== 'apikey') {
                    $parts[] = (is_string($k) ? $k . ': ' : '') . $v;
                }
            });
            $text = implode('; ', $parts);
        } else {
            $text = trim(strip_tags($body));
        }
        return mb_substr(str_ireplace(SMS_API_KEY !== '' ? SMS_API_KEY : "\0", '[key]', $text !== '' ? $text : 'no details'), 0, 180);
    }

    /** The admin-edited text for $key (or its built-in fallback), with {placeholders} filled in. */
    public function render(string $key, array $placeholders): string
    {
        $stmt = $this->pdo->prepare('SELECT body FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        $body = trim((string) $stmt->fetchColumn());
        if ($body === '') {
            $body = self::defaultBody($key);
        }
        // An optional placeholder left empty (e.g. no staff remarks) drops the sentence it sits in, so the text
        // never reads "Note: none" or "Note: ." -- unless that sentence also carries other details, in which
        // case only the placeholder goes (an administrator may have reworded the template).
        $filled = array_keys(array_filter($placeholders, fn ($value) => $value !== ''));
        foreach ($placeholders as $placeholder => $value) {
            if ($value === '' && strpos($body, $placeholder) !== false) {
                $body = implode(' ', array_filter(preg_split('/(?<=[.!?])\s+/u', $body), function ($sentence) use ($placeholder, $filled) {
                    if (strpos($sentence, $placeholder) === false) {
                        return true;
                    }
                    foreach ($filled as $other) {
                        if (strpos($sentence, $other) !== false) {
                            return true;
                        }
                    }
                    return false;
                }));
            }
        }
        return trim(strtr($body, $placeholders));
    }

    private function sendTemplate(string $key, array $placeholders, ?int $applicationId, string $mobile, string $preparation = ''): array
    {
        if (!$this->templateEnabled($key)) {
            // Switched off in Notification Settings: nothing is sent or logged.
            return ['id' => null, 'status' => 'DISABLED', 'test_mode' => false, 'error' => null];
        }
        $message = $this->render($key, $placeholders);
        if (in_array($key, ['appointment_cancelled', 'appointment_missed'], true)) {
            $message = str_replace(['Please book a new appointment online.', 'Please log in and book a new appointment.'], 'Please contact MSWDO for a new schedule.', $message);
        }
        if ($preparation !== '') {
            // Keep preparation instructions even when an existing admin template is long.
            $message = mb_substr($message, 0, 499 - mb_strlen($preparation)) . ' ' . $preparation;
        }
        return $this->send($applicationId, $applicationId !== null ? $this->recipientFor($applicationId, $mobile) : $mobile, $message, $key);
    }

    /** The current unchecked required documents, excluding accepted physical evidence. */
    public function uncheckedRequirementLabels(int $applicationId): array
    {
        require_once __DIR__ . '/../models/Document.php';
        $query = $this->pdo->prepare('SELECT id, category_id, type FROM applications WHERE id = ?');
        $query->execute([$applicationId]);
        $app = $query->fetch();
        if (!$app) {
            return [];
        }
        $interview = $this->pdo->prepare('SELECT id FROM appointments WHERE application_id = ? LIMIT 1');
        $interview->execute([$applicationId]);
        if ($interview->fetchColumn()) {
            return array_column((new Document($this->pdo))->outstandingRequired($app), 'label');
        }
        $rows = (new Document($this->pdo))->checklistForReview($applicationId,
            $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal');
        return array_values(array_column(array_filter($rows, [Document::class, 'needsPreparation']), 'label'));
    }

    /** Include whole document names; explicitly point to the full list of required uploads when the SMS limit is reached. */
    public static function preparationText(array $labels, int $maxLength = 300): string
    {
        if (!$labels) {
            return '';
        }
        $labels = array_values(array_map(fn($label) => trim(preg_replace('/\s+/u', ' ', $label)), $labels));
        $prefix = 'After your interview, submit missing documents before ID release: ';
        for ($count = count($labels); $count > 0; $count--) {
            $remaining = count($labels) - $count;
            $text = $prefix . implode('; ', array_slice($labels, 0, $count)) . '.'
                . ($remaining ? ' Plus ' . $remaining . ' more; see all required uploads online.' : '');
            if (mb_strlen($text) <= $maxLength) {
                return $text;
            }
        }
        return 'After your interview, submit ' . count($labels) . ' missing documents before ID release. See all required uploads online.';
    }

    public function preparationReminder(int $applicationId): string
    {
        return self::preparationText($this->uncheckedRequirementLabels($applicationId));
    }

    /**
     * Where texts about an application go: the applicant's confirmed account
     * number, so a number re-confirmed on My Profile after submission is
     * used. $fallback (the number on the application) is used only while
     * the account has no confirmed number.
     */
    private function recipientFor(int $applicationId, string $fallback): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.mobile, u.mobile_verified_at FROM applications a JOIN users u ON u.id = a.user_id WHERE a.id = ?'
        );
        $stmt->execute([$applicationId]);
        $account = $stmt->fetch();
        return ($account ? self::confirmedNumber($account['mobile'], $account['mobile_verified_at']) : null) ?? $fallback;
    }

    /**
     * An account's mobile number if it is confirmed, else null. The one rule
     * for "is there a confirmed number" — recipientFor() and the staff
     * screens (views/partials/mobile-numbers.php) both use it.
     */
    public static function confirmedNumber(?string $mobile, ?string $verifiedAt): ?string
    {
        return $mobile !== null && $mobile !== '' && $verifiedAt !== null ? $mobile : null;
    }

    /** False when SMS notifications, or this message, are switched off (Notification Settings). */
    public function templateEnabled(string $key): bool
    {
        if (setting('sms_enabled') !== '1') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT is_enabled FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        $enabled = $stmt->fetchColumn();
        return $enabled === false || (int) $enabled === 1;
    }

    public function notifySubmitted(int $applicationId, string $mobile, string $refNumber): array
    {
        return $this->sendTemplate('submitted', ['{ref}' => $refNumber], $applicationId, $mobile,
            trim('Await your MSWDO interview schedule. ' . $this->preparationReminder($applicationId)));
    }

    public function notifyStatusChange(int $applicationId, string $mobile, string $refNumber, string $status, string $remarks = ''): array
    {
        return $this->sendTemplate('status_change', ['{ref}' => $refNumber, '{status}' => $status, '{remarks}' => $remarks], $applicationId, $mobile);
    }

    /** Sends the selected custom release message to the applicant's current confirmed number (or application fallback). */
    public function notifyIdReleaseScheduled(int $applicationId, string $mobile, string $message, bool $custom): array
    {
        $key = $custom ? 'id_release_custom' : 'id_release_scheduled';
        if (!$this->templateEnabled('interview_scheduled')) {
            $recipient = $this->recipientFor($applicationId, $mobile);
            $stmt = $this->pdo->prepare("INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status, error) VALUES (?, ?, ?, 'FAILED', 0, ?, ?, 'disabled', 'Appointment SMS notifications are disabled in settings.')");
            $stmt->execute([$applicationId, mb_substr($recipient, 0, 20), fit_text($message, 500), $key, self::provider()]);
            return ['id' => (int) $this->pdo->lastInsertId(), 'status' => 'FAILED', 'test_mode' => false, 'error' => 'Appointment SMS notifications are disabled in settings.', 'provider_status' => 'disabled'];
        }
        return $this->send($applicationId, $this->recipientFor($applicationId, $mobile), $message, $key);
    }

    public function notifyRenewalSubmitted(int $applicationId, string $mobile, string $refNumber): array
    {
        return $this->sendTemplate('renewal_submitted', ['{ref}' => $refNumber], $applicationId, $mobile, $this->preparationReminder($applicationId));
    }

    public function notifyRenewalReminder(int $applicationId, string $mobile, string $refNumber, string $expiresAt): array
    {
        return $this->sendTemplate($expiresAt < date('Y-m-d') ? 'renewal_expired' : 'renewal_reminder',
            ['{ref}' => $refNumber, '{when}' => date('F j, Y', strtotime($expiresAt))], $applicationId, $mobile);
    }

    /**
     * $dateOnly (a date-only appointment has no time of day): {when} is the date alone, and the appended "Please arrive on
     * time." sentence is left out; the admin-editable template text is used as it is. false (the default) is the timed behaviour, unchanged.
     */
    public function notifyAppointmentReminder(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $location, bool $dateOnly = false): array
    {
        return $this->sendTemplate('appointment_reminder', ['{ref}' => $refNumber, '{when}' => date($dateOnly ? 'F j, Y' : 'F j, Y g:i A', strtotime($scheduledAt)), '{location}' => $location], $applicationId, $mobile,
            trim(($dateOnly ? '' : 'Please arrive on time. ') . $this->preparationReminder($applicationId)));
    }

    /** $dateOnly: {when} is the date alone (no misleading 12:00 AM); false (the default) is the timed behaviour, unchanged. */
    public function notifyAppointmentCancelled(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, string $reason, bool $dateOnly = false): array
    {
        return $this->sendTemplate('appointment_cancelled', ['{ref}' => $refNumber, '{when}' => date($dateOnly ? 'F j, Y' : 'F j, Y g:i A', strtotime($scheduledAt)), '{reason}' => $reason], $applicationId, $mobile);
    }

    /** Office hours for an SMS: the fixed opening time to the closing-time setting ("7:00 AM to 5:00 PM"; shortened to "7AM-5PM" in compact wording). */
    public function officeHours(): string
    {
        $closes = get_setting($this->pdo, 'appointment_day_end', '17:00');
        $closes = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $closes) === 1 ? $closes : '17:00';
        return self::compact() ? '7AM-' . str_replace(':00', '', date('g:iA', strtotime($closes))) : '7:00 AM to ' . date('g:i A', strtotime($closes));
    }

    /** A time of day (HH:MM) as SMS text: "9:00 AM", or "9AM" / "9:30AM" in compact wording. */
    public static function timeText(string $time): string
    {
        return self::compact() ? str_replace(':00', '', date('g:iA', strtotime($time))) : date('g:i A', strtotime($time));
    }

    /** An interview date as SMS text ($date is Y-m-d): "Monday, November 23, 2026", or "Mon, Nov 23, 2026" in compact wording. */
    public static function dateText(string $date): string
    {
        return date(self::compact() ? 'D, M j, Y' : 'l, F j, Y', strtotime($date));
    }

    /** The automatic text for a date staff scheduled (what the applicant gets when staff choose Automatic Message). $date is Y-m-d; null keeps a literal {when} for the staff screen's live preview. */
    public function interviewDateAssignedMessage(string $refNumber, ?string $date, string $location): string
    {
        return $this->render('interview_date_assigned', ['{ref}' => $refNumber, '{when}' => $date === null ? '{when}' : self::dateText($date), '{hours}' => $this->officeHours(), '{location}' => $location]);
    }

    /**
     * Staff scheduled an interview DATE for the applicant (interview_date_assigned): come on that date, or log in and pick another.
     * A custom text uses the same on/off switch, logged under its own key.
     */
    public function notifyInterviewDateAssigned(int $applicationId, string $mobile, string $refNumber, string $date, string $location, ?string $customMessage = null, ?string $time = null): array
    {
        if ($customMessage === null) {
            // With a time, it replaces the office-hours range in the text ("..., 9:00 AM at <place>").
            return $this->sendTemplate('interview_date_assigned', ['{ref}' => $refNumber, '{when}' => self::dateText($date), '{hours}' => $time !== null ? self::timeText($time) : $this->officeHours(), '{location}' => $location], $applicationId, $mobile);
        }
        if (!$this->templateEnabled('interview_date_assigned')) {
            return ['id' => null, 'status' => 'DISABLED', 'test_mode' => false, 'error' => null];
        }
        return $this->send($applicationId, $this->recipientFor($applicationId, $mobile), trim($customMessage), 'interview_date_assigned_custom');
    }

    /** The applicant booked (or replaced) an interview DATE (interview_date_booked). $date is Y-m-d; {when} is the date with its weekday, never a time. */
    public function notifyInterviewDateBooked(int $applicationId, string $mobile, string $refNumber, string $date, string $location): array
    {
        return $this->sendTemplate('interview_date_booked', ['{ref}' => $refNumber, '{when}' => date('l, F j, Y', strtotime($date)), '{location}' => $location], $applicationId, $mobile);
    }

    /** $dateOnly: {when} is the date alone (no misleading 12:00 AM); false (the default) is the timed behaviour, unchanged. */
    public function notifyAppointmentMissed(int $applicationId, string $mobile, string $refNumber, string $scheduledAt, bool $dateOnly = false): array
    {
        return $this->sendTemplate('appointment_missed', ['{ref}' => $refNumber, '{when}' => date($dateOnly ? 'F j, Y' : 'F j, Y g:i A', strtotime($scheduledAt))], $applicationId, $mobile);
    }

    /**
     * The approved correction SMS wording. It is deliberately a source constant, NOT read from sms_templates:
     * render() prefers an administrator-edited or older stored 'document_correction' row over the built-in text,
     * which would silently replace this wording (and could drop the applicant name or the document names).
     * The staff preview and the confirmed send both use correctionMessage(), so neither can differ from this.
     * The stored row still controls only whether this SMS is switched on (templateEnabled()).
     */
    /** Shortened correction text (one credit): used only while SMS_COMPACT_TEXTS is on. A count replaces the names when they would not fit. */
    public const CORRECTION_BODY_COMPACT = 'MSWDO Virac: Your "{document}" needs correction. Log in, open Documents and upload a new file.';
    public const CORRECTION_COUNT_COMPACT = 'MSWDO Virac: {count} {documents} correction. Log in and open Documents to see why and upload new files.';
    public const CORRECTION_BODY = 'Hello {applicant_name}, your document "{document}" for application {ref} needs correction. Please log in to your Solo Parent ID account, open Supporting Documents, and upload a replacement document. Please check the instructions provided by MSWDO in your account. Thank you.';

    /**
     * The exact correction SMS for a batch: the approved wording with the applicant's name, EVERY affected
     * document name (one message however many) and the application reference filled in. Nothing is shortened
     * or dropped here; callers must check the length (CORRECTION_MAX_LENGTH) and refuse to send rather than
     * truncate. Staff remarks are not a parameter and cannot appear. strtr() substitutes in a single pass, so a
     * name or label that happens to contain "{ref}" or similar is never expanded again.
     */
    public function correctionMessage(string $applicantName, string $refNumber, array $documentLabels, ?bool $compact = null): string
    {
        $clean = static fn($text) => trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        $names = [];
        foreach ($documentLabels as $label) {
            $label = $clean($label);
            if ($label !== '') {
                $names[] = $label;
            }
        }
        $applicant = $clean($applicantName);
        if ($compact ?? self::compact()) {
            $short = strtr(self::CORRECTION_BODY_COMPACT, ['{document}' => $names ? implode('", "', $names) : 'Supporting document']);
            if (mb_strlen($short) <= 146 || !$names) {
                return $short; // 146 + the gateway header still fits one 160-character text
            }
            return strtr(self::CORRECTION_COUNT_COMPACT, ['{count}' => (string) count($names), '{documents}' => count($names) === 1 ? 'document needs' : 'documents need']);
        }
        return strtr(self::CORRECTION_BODY, [
            '{applicant_name}' => $applicant !== '' ? $applicant : 'Solo Parent',
            '{document}' => $names ? implode(', ', $names) : 'Supporting document',
            '{ref}' => $refNumber,
        ]);
    }

    /** Wording of the automatic ID-release text with {name}, {when} and {location} still to fill (the staff screen previews it live). */
    public static function idReleaseTemplate(?bool $compact = null): string
    {
        return ($compact ?? self::compact())
            ? 'MSWDO Virac: ID release on {when} at {location}. Bring a valid ID.'
            : 'Hello {name}, your Solo Parent ID is scheduled for release on {when}. Location: {location}. Please visit the MSWDO Virac office at your scheduled time. For inquiries, contact the MSWDO office.';
    }

    /** The date and time of an ID release as SMS text: "November 27, 2026 at 9:00 AM", or "Fri, Nov 27, 2026 9:00 AM" in compact wording. */
    public static function idReleaseWhen(int $timestamp, ?bool $compact = null): string
    {
        return ($compact ?? self::compact()) ? date('D, M j, Y g:i A', $timestamp) : date('F j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp);
    }

    /** The automatic ID-release text for an applicant. */
    public static function idReleaseMessage(string $name, int $timestamp, string $location, ?bool $compact = null): string
    {
        return strtr(self::idReleaseTemplate($compact), ['{name}' => $name, '{when}' => self::idReleaseWhen($timestamp, $compact), '{location}' => $location]);
    }

    /** The longest SMS text the log (and therefore a confirmed send) can hold without cutting it. */
    public const CORRECTION_MAX_LENGTH = 500;

    /** The number texts about an application go to (confirmed account number, else $fallback), as send() would use it. */
    public function recipientForApplication(int $applicationId, string $fallback): string
    {
        return $this->recipientFor($applicationId, $fallback);
    }

    /**
     * Step one of a two-step send: records a PENDING log row for the exact $message without contacting the
     * provider, so the caller can commit the reservation before dispatching. Returns the log id.
     */
    public function reserveLog(int $applicationId, string $number, string $message, string $templateKey): int
    {
        $provider = self::provider();
        $this->pdo->prepare(
            "INSERT INTO sms_logs (application_id, mobile, message, status, test_mode, template_key, provider, provider_status)
             VALUES (?, ?, ?, 'PENDING', ?, ?, ?, 'pending')"
        )->execute([$applicationId, mb_substr($number, 0, 20), $message, (int) ($provider === 'test'), $templateKey, $provider]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Step two: sends the reserved message and records the outcome on its log row. Same return shape as send(). */
    public function dispatchReserved(int $logId, string $number, string $message): array
    {
        $provider = self::provider();
        return $this->record($logId, $this->dispatch($provider, $number, $message), $provider === 'test');
    }

    /** Sign-up verification: like the reset code, the number is texted but the log keeps the code masked. */
    public function notifyMobileVerification(string $mobile, string $code): array
    {
        $vars = ['{minutes}' => (string) MOBILE_CODE_MINUTES];
        return $this->send(null, $mobile, $this->render('mobile_verification', $vars + ['{code}' => $code]), 'mobile_verification',
            $this->render('mobile_verification', $vars + ['{code}' => '******']));
    }

    /** The code is sent but never stored: the log keeps the text with the code masked. */
    public function notifyPasswordReset(string $mobile, string $code): array
    {
        $vars = ['{minutes}' => (string) PASSWORD_RESET_CODE_MINUTES];
        return $this->send(null, $mobile, $this->render('password_reset', $vars + ['{code}' => $code]), 'password_reset',
            $this->render('password_reset', $vars + ['{code}' => '******']));
    }
}
