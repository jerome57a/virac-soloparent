<?php
/**
 * OFFLINE checks of the staff SMS outcome notice (SMSService::outcomeNotice, set_sms_notice / take_sms_notice). No database, no
 * SMS, no network.
 *
 *   php database/test_sms_notice_offline.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/SMSService.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $message\n"); }
}

$sent = SMSService::outcomeNotice(['id' => 7, 'status' => 'SENT', 'error' => null], 'Interview scheduled');
check($sent['state'] === 'sent' && $sent['log_id'] === 7 && str_contains($sent['text'], 'accepted by the SMS gateway'), 'a sent text is reported as accepted by the gateway');
check(str_contains($sent['text'], 'Interview scheduled') && str_contains($sent['text'], 'phone'), 'the notice names what happened and says only the phone confirms arrival');

$failed = SMSService::outcomeNotice(['id' => 8, 'status' => 'FAILED', 'error' => 'Gateway rejected the message (HTTP 401): invalid token'], 'Interview scheduled');
check($failed['state'] === 'failed' && $failed['log_id'] === 8, 'a failed text is reported as failed, with its log id for the Retry button');
check(str_contains($failed['text'], 'FAILED') && str_contains($failed['text'], 'invalid token') && str_contains($failed['text'], 'Retry SMS'), 'the failure shows the reason and says to retry');
check(SMSService::outcomeNotice(['id' => 9, 'status' => 'FAILED', 'error' => ''], 'x')['text'] !== '', 'a failure without a reason still gets a message');

$off = SMSService::outcomeNotice(['id' => null, 'status' => 'DISABLED', 'error' => null], 'Status changed');
check($off['state'] === 'skipped' && $off['log_id'] === null && str_contains($off['text'], 'NO SMS was sent'), 'SMS switched off is "not sent", not "sent"');
$offLogged = SMSService::outcomeNotice(['id' => 11, 'status' => 'FAILED', 'provider_status' => 'disabled', 'error' => 'disabled in settings'], 'ID release');
check($offLogged['state'] === 'skipped' && $offLogged['log_id'] === 11, 'a disabled-provider failure that was logged is "not sent" and keeps its log id');
check(SMSService::outcomeNotice([], 'x')['state'] === 'failed', 'an empty or unknown result is treated as a failure, never as success');

// The notice is shown once, and a failure is not hidden by a later success.
$_SESSION = [];
check(take_sms_notice() === null, 'no notice when nothing was set');
set_sms_notice($sent);
check(take_sms_notice()['state'] === 'sent' && take_sms_notice() === null, 'a notice is shown once');
set_sms_notice($failed);
set_sms_notice($sent);
check(take_sms_notice()['state'] === 'failed', 'a failure is not replaced by a later success in the same request');
set_sms_notice($sent);
set_sms_notice($failed);
check(take_sms_notice()['state'] === 'failed', 'a failure replaces an earlier success');
set_sms_notice($off);
set_sms_notice($sent);
check(take_sms_notice()['state'] === 'sent', 'a skipped notice may be replaced by a later success');

// Templates that may not be retried from a log.
require_once __DIR__ . '/../models/SmsLog.php';
check(in_array('password_reset', SmsLog::NOT_RETRYABLE, true) && in_array('mobile_verification', SmsLog::NOT_RETRYABLE, true) && in_array('document_correction', SmsLog::NOT_RETRYABLE, true), 'codes and corrections are never retryable from a log');
check(!in_array('interview_date_assigned', SmsLog::NOT_RETRYABLE, true) && !in_array('status_change', SmsLog::NOT_RETRYABLE, true), 'schedule and status texts are retryable');

// The real cause of a failed text, in plain words.
$kind = fn(string $e) => SMSService::failureHelp($e)['kind'];
check($kind('Could not reach the SMS gateway: Could not resolve host: sms.example') === 'no-internet', 'no internet is named');
check($kind('Could not reach the SMS gateway: Operation timed out after 5000 milliseconds') === 'timeout', 'a slow connection is named');
check($kind('Gateway rejected the message (HTTP 200): message: Invalid api token or no load balance') === 'token-or-credit', 'a bad token or no credit is named');
check($kind('SMS_API_KEY (the IPROG API token) is not set.') === 'not-configured', 'a missing token is named');
check($kind('Invalid mobile number.') === 'bad-number', 'a bad number is named');
check($kind('Not sent: this number is not on the SMS test allowlist (SMS_ALLOWED_NUMBERS), so no credit was used.') === 'allowlist', 'the allowlist is not mistaken for no credit');
check($kind('Not sent: the daily SMS limit (50) was reached, so no credit was used.') === 'daily-limit', 'the daily limit is named');
check($kind('Gateway rejected the message (HTTP 500): maintenance') === 'rejected', 'another refusal is named');
check($kind('') === 'unknown' && SMSService::failureHelp('Invalid api token or no load balance')['action'] !== '', 'unknown text still gets advice');
$notice = SMSService::outcomeNotice(['status' => 'FAILED', 'error' => 'Gateway rejected the message (HTTP 200): message: Invalid api token or no load balance'], 'Interview scheduled');
check(str_contains($notice['text'], 'Cause: The SMS token is invalid') && str_contains($notice['text'], 'Invalid api token or no load balance') && str_contains($notice['text'], 'Retry SMS'), 'the notice shows the cause, the advice and the original message');
echo $failures ? "FAILED: $failures of $checks checks.\n" : "PASS: $checks SMS notice checks. No database, no SMS, no network.\n";
exit($failures ? 1 : 0);
