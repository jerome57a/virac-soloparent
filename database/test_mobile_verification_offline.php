<?php
/**
 * OFFLINE checks of the sign-up mobile verification state (AuthService::beginMobileVerification and friends). It lives in the
 * session array, so no database, no SMS and no network are involved: an in-memory SQLite handle satisfies the constructor and
 * is never queried.
 *
 *   php database/test_mobile_verification_offline.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AuthService.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $message\n"); }
}

$auth = new AuthService(new PDO('sqlite::memory:'));
$form = ['fullName' => 'Maria Santos', 'username' => 'maria.s', 'email' => '', 'mobile' => '09171234567'];
$t = 1_000_000;
$_SESSION = [];

check(is_bool(MOBILE_VERIFY_BY_SMS), 'the sign-up verification switch exists (config/auth.php); the checks below do not depend on its value');
check($auth->pendingSignup() === null && $auth->checkMobileCode('123456', $t) === 'none', 'nothing pending at the start');
check($auth->mobileCodeResendError($t) !== null, 'no code can be re-sent when nothing is pending');

// A normal sign-up.
$code = $auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
check(preg_match('/\A\d{6}\z/', $code) === 1, 'the code is six digits');
$pending = $auth->pendingSignup();
check($pending !== null && $pending['form']['username'] === 'maria.s', 'the sign-up details are held');
check(!str_contains(json_encode($_SESSION), $code) && !str_contains(json_encode($_SESSION), 'Secret-Pass-1'), 'neither the code nor the password is stored in plain text');
check(password_verify('Secret-Pass-1', $pending['password_hash']), 'the password is kept as a hash');
check($auth->checkMobileCode($code, $t + 30) === 'ok', 'the right code is accepted');
check($auth->checkMobileCode(' ' . $code . ' ', $t + 30) === 'ok', 'spaces around the code are ignored');

// Wrong entries are counted, then the sign-up is locked.
$wrong = $code === '000000' ? '111111' : '000000';
for ($i = 1; $i < MOBILE_CODE_MAX_ATTEMPTS; $i++) {
    check($auth->checkMobileCode($wrong, $t + 30) === 'wrong', "wrong entry $i is reported as wrong");
}
check($auth->checkMobileCode($wrong, $t + 30) === 'locked', 'the last allowed wrong entry locks the sign-up');
check($auth->checkMobileCode($code, $t + 30) === 'locked', 'even the right code is refused once locked');

// Non-numeric and short input never match.
$_SESSION = [];
$code = $auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
check($auth->checkMobileCode('12345', $t) === 'wrong' && $auth->checkMobileCode('abcdef', $t) === 'wrong' && $auth->checkMobileCode('', $t) === 'wrong', 'short, non-numeric and empty entries are wrong');

// Expiry.
$_SESSION = [];
$code = $auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
check($auth->checkMobileCode($code, $t + MOBILE_CODE_MINUTES * 60) === 'ok', 'a code still works at its last second');
check($auth->checkMobileCode($code, $t + MOBILE_CODE_MINUTES * 60 + 1) === 'expired', 'a code expires after its lifetime');

// Re-sending.
$_SESSION = [];
$first = $auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
check(str_contains((string) $auth->mobileCodeResendError($t + 10), 'wait'), 'a new code cannot be asked for straight away');
check($auth->mobileCodeResendError($t + MOBILE_CODE_RESEND_SECONDS) === null, 'a new code may be asked for after the wait');
$second = $auth->renewMobileCode($t + MOBILE_CODE_RESEND_SECONDS);
check($auth->checkMobileCode($first, $t + MOBILE_CODE_RESEND_SECONDS + 1) === ($first === $second ? 'ok' : 'wrong'), 'the old code stops working after a new one is sent');
check($auth->checkMobileCode($second, $t + MOBILE_CODE_RESEND_SECONDS + 2) === 'ok', 'the new code works');
$_SESSION = [];
$auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
$clock = $t;
for ($i = 1; $i < MOBILE_CODE_MAX_SENDS; $i++) {
    $clock += MOBILE_CODE_RESEND_SECONDS;
    check($auth->mobileCodeResendError($clock) === null, "resend $i is allowed");
    $auth->renewMobileCode($clock);
}
check(str_contains((string) $auth->mobileCodeResendError($clock + 3600), 'Too many codes'), 'the number of codes per sign-up is capped');

// Restarting with the same number keeps the count (no SMS bombing by resubmitting the form); a different number starts afresh.
$_SESSION = [];
$auth->beginMobileVerification($form, 'Secret-Pass-1', $t);
$auth->beginMobileVerification($form, 'Secret-Pass-1', $t + 100);
check($_SESSION['pending_signup']['sent'] === 2, 'resubmitting for the same number counts as another send');
$auth->beginMobileVerification(['mobile' => '09181234567'] + $form, 'Secret-Pass-1', $t + 200);
check($_SESSION['pending_signup']['sent'] === 1, 'a different number starts a new count (the per-IP limit still applies)');

// Clearing.
$auth->clearPendingSignup();
check($auth->pendingSignup() === null, 'clearing removes the pending sign-up');

// The SMS template exists and cannot be re-sent from the log.
require_once __DIR__ . '/../services/SMSService.php';
check(isset(SMSService::TEMPLATES['mobile_verification'], SMSService::PLACEHOLDERS['mobile_verification']), 'the verification SMS template is defined');
check(str_contains(SMSService::TEMPLATES['mobile_verification'][1], '{code}') && str_contains(SMSService::TEMPLATES['mobile_verification'][1], '{minutes}'), 'the template carries the code and its lifetime');
check(mb_strlen(SMSService::TEMPLATES['mobile_verification'][1]) <= 160, 'the verification text fits one SMS');

echo $failures ? "FAILED: $failures of $checks checks.\n" : "PASS: $checks sign-up mobile verification checks. No database, no SMS, no network.\n";
exit($failures ? 1 : 0);
