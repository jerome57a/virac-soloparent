<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/SMSService.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/RateLimiter.php';
require_once __DIR__ . '/../services/RequirementGuideService.php';

class AuthController extends Controller
{
    private AuthService $auth;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->auth = new AuthService($pdo);
    }

    /**
     * Handles the combined login/signup page (both applicant-facing).
     * GET just shows the form; POST processes whichever action was
     * submitted. Returns the data the view needs to render.
     */
    public function handleApplicantAuth(string $defaultMode = 'login'): array
    {
        if (array_key_exists('next', $_GET)) {
            unset($_SESSION['applicant_return']);
            if (applicant_return_path($_GET['next'])) {
                $_SESSION['applicant_return'] = $this->returnKey($_GET['next']);
            }
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($staff = $this->auth->currentStaff())) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($existingUser = $this->auth->currentUser()) {
            $this->redirectTo(take_applicant_return_path());
        }
        $authMode = ($_GET['mode'] ?? $defaultMode) === 'signup' ? 'signup' : 'login';
        $authError = '';
        $oldSignup = ['fullName' => '', 'username' => '', 'email' => '', 'mobile' => ''];
        $codeHint = null;       // the code itself, shown only while the SMS provider is the log-only test provider
        $authNoticeNow = null;  // a message for this request only (for example "A new code was sent.")
        $verifyMobile = '';
        $resendWait = 0;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = $_POST['action'] ?? '';

            if ($action === 'login') {
                $authMode = 'login';
                $username = trim((string) ($_POST['username'] ?? ''));
                // One form for every actor: usernames are unique across all accounts, so the stored role of the
                // matching account (never anything the visitor submits) decides which sign-in applies.
                $staffAccount = $username !== '' ? $this->auth->userModel()->findStaffByUsername($username) : null;
                $account = $staffAccount ?? $this->auth->userModel()->findApplicantForLogin($username);
                $authError = $staffAccount
                    ? $this->attemptLogin($account, $username, (string) ($_POST['password'] ?? ''), 'Staff sign-in', 'Please contact your system administrator.')
                    : $this->attemptLogin($account, $username, (string) ($_POST['password'] ?? ''), 'Solo Parent sign-in', 'Please contact MSWDO Virac for assistance.');
                if ($authError === '' && $staffAccount) {
                    // Staff and administrators keep their own session cookie; move to it before signing in.
                    $this->auth->enterPortalSession($account['role'] === 'admin' ? 'admin' : 'staff');
                    $this->auth->loginStaff($account);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login', 'user', (int) $account['id'], 'Staff sign-in');
                    $this->redirectTo($account['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
                }
                if ($authError === '') {
                    $this->auth->loginUser($account);
                    (new EligibilityService($this->pdo))->claimForUser((int) $account['id']);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login', 'user', (int) $account['id'],
                        empty($account['username']) ? 'Solo Parent sign-in (no username yet; signed in with email or mobile number)' : 'Solo Parent sign-in');
                    $this->redirectTo(take_applicant_return_path());
                }
            } elseif ($action === 'signup') {
                $authMode = 'signup';
                $oldSignup = [
                    'fullName' => trim((string) ($_POST['fullName'] ?? '')),
                    'username' => trim((string) ($_POST['username'] ?? '')),
                    'email' => strtolower(trim((string) ($_POST['email'] ?? ''))),
                    'mobile' => trim((string) ($_POST['mobile'] ?? '')),
                ];
                $password = (string) ($_POST['password'] ?? '');
                $authError = $this->registrationError($oldSignup, $password, (string) ($_POST['password_confirm'] ?? ''));
                $limiter = new RateLimiter($this->pdo);
                if ($authError === '' && $limiter->tooMany('register_ip', RateLimiter::clientIp())) {
                    $authError = 'Too many new accounts were created from this network recently. Please try again later.';
                }
                if ($authError === '') {
                    $limiter->hit('register_ip', RateLimiter::clientIp());
                    if (MOBILE_VERIFY_BY_SMS) {
                        // The account is created only after the texted code is entered (the 'verify_mobile' action below).
                        $authError = $this->sendSignupCode($oldSignup, $password, $codeHint);
                        $authMode = $authError === '' ? 'verify' : 'signup';
                    } else {
                        $authError = $this->createApplicantAccount($oldSignup, password_hash($password, PASSWORD_DEFAULT), false);
                    }
                }
            } elseif (in_array($action, ['verify_mobile', 'resend_code', 'cancel_verify'], true)) {
                $pending = $this->auth->pendingSignup();
                if (!$pending) {
                    $authMode = 'signup';
                    $authError = 'Your sign-up session ended. Please fill in the form again.';
                } elseif ($action === 'cancel_verify') {
                    $oldSignup = $pending['form'];
                    $this->auth->clearPendingSignup();
                    $authMode = 'signup';
                } elseif ($action === 'resend_code') {
                    $authMode = 'verify';
                    $limiter = new RateLimiter($this->pdo);
                    $authError = $this->auth->mobileCodeResendError() ?? '';
                    if ($authError === '' && $limiter->tooMany('verify_sms_ip', RateLimiter::clientIp())) {
                        $authError = 'Too many codes were requested from this network recently. Please try again later.';
                    }
                    if ($authError === '') {
                        $limiter->hit('verify_sms_ip', RateLimiter::clientIp());
                        $code = $this->auth->renewMobileCode();
                        $result = (new SMSService($this->pdo))->notifyMobileVerification($pending['form']['mobile'], $code);
                        if ($result['status'] === 'SENT') {
                            $authNoticeNow = 'A new code was sent.';
                            $codeHint = !empty($result['test_mode']) ? $code : null;
                        } else {
                            $authError = 'We could not send the code. Please try again in a moment.';
                        }
                    }
                } else { // verify_mobile
                    $authMode = 'verify';
                    $state = $this->auth->checkMobileCode((string) ($_POST['code'] ?? ''));
                    if ($state === 'ok') {
                        $authError = $this->createApplicantAccount($pending['form'], $pending['password_hash'], true);
                        if ($authError !== '') { // not created (for example the username was taken meanwhile): back to the form
                            $oldSignup = $pending['form'];
                            $this->auth->clearPendingSignup();
                            $authMode = 'signup';
                        }
                    } elseif ($state === 'locked') {
                        $oldSignup = $pending['form'];
                        $this->auth->clearPendingSignup();
                        $authMode = 'signup';
                        $authError = 'Too many wrong codes. Please fill in the form again to get a new code.';
                    } elseif ($state === 'expired') {
                        $authError = 'That code has expired. Request a new one.';
                    } else {
                        $authError = 'That code is not correct. Check the text message and try again.';
                    }
                }
            }
        }
        if ($authMode === 'verify') {
            $pendingNow = $this->auth->pendingSignup();
            $verifyMobile = $pendingNow ? $this->maskMobile((string) $pendingNow['form']['mobile']) : '';
            $resendWait = $pendingNow ? max(0, (int) $pendingNow['last_sent'] + MOBILE_CODE_RESEND_SECONDS - time()) : 0;
        }

        $authNotice = $this->auth->takeAuthNotice();
        if ($authNotice === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
            // Passed on from mswd-login.php when a staff or admin session ended (fixed codes only).
            $authNotice = [
                'expired' => 'Your session expired for your security. Please log in again.',
                'password_changed' => 'Your password was changed, so you were signed out. Please log in with the new password.',
            ][(string) ($_GET['notice'] ?? '')] ?? null;
        }
        $authNotice = $authNoticeNow ?? $authNotice;
        return compact('authMode', 'authError', 'oldSignup', 'authNotice', 'codeHint', 'verifyMobile', 'resendWait');
    }

    /**
     * Texts a verification code for these sign-up details and holds them in the session until it is entered. Returns '' when the
     * code was sent, else the message to show. $codeHint is set to the code only when the provider is the log-only test one
     * (nothing is delivered, so the tester could never see it otherwise).
     */
    private function sendSignupCode(array $form, string $password, ?string &$codeHint): string
    {
        // Like the password-reset code, this is a security message, not a notification: it is sent whatever the "Send SMS
        // notifications" setting says. If the SMS provider cannot send it, the sign-up stops with a message below.
        $limiter = new RateLimiter($this->pdo);
        if ($limiter->tooMany('verify_sms_ip', RateLimiter::clientIp())) {
            return 'Too many codes were requested from this network recently. Please try again later.';
        }
        $limiter->hit('verify_sms_ip', RateLimiter::clientIp());
        $code = $this->auth->beginMobileVerification($form, $password);
        $result = (new SMSService($this->pdo))->notifyMobileVerification($form['mobile'], $code);
        if ($result['status'] !== 'SENT') {
            $this->auth->clearPendingSignup();
            return 'We could not send a code to that number. Check the mobile number and try again.';
        }
        $codeHint = !empty($result['test_mode']) ? $code : null;
        return '';
    }

    /**
     * Creates the applicant account from checked sign-up details, signs it in and redirects. Returns a message only when the
     * account could not be created. $numberVerified: the mobile number was confirmed by a texted code; false is the earlier
     * behaviour (accepted without a code) used when MOBILE_VERIFY_BY_SMS is off.
     */
    private function createApplicantAccount(array $form, string $passwordHash, bool $numberVerified): string
    {
        $users = $this->auth->userModel();
        if ($numberVerified) {
            // Time has passed since the form was checked: make sure nobody registered the same number or Gmail meanwhile.
            if ($users->mobileExists($form['mobile'])) {
                return 'That mobile number is already registered. Log in, or use Forgot password if you can\'t remember it.';
            }
            if ($form['email'] !== '' && $users->emailExists($form['email'])) {
                return 'That Gmail address is already registered. Log in, or use Forgot password.';
            }
        }
        try {
            $newUserId = $users->createApplicant($form['fullName'], $form['email'], $form['username'], $passwordHash, $form['mobile']);
        } catch (PDOException $e) {
            // SQLSTATE 23000 covers every integrity failure; report a duplicate only if the username now exists
            // (taken between the check and the insert). Any other failure is rethrown unchanged.
            if ($e->getCode() !== '23000' || !$users->usernameExists($form['username'])) {
                throw $e;
            }
            return 'That username is already taken. Please choose another.';
        }
        // Without a texted code (MOBILE_VERIFY_BY_SMS off) the supplied number is simply marked usable, as before.
        $users->markMobileVerified($newUserId);
        $this->auth->clearPendingSignup();
        $this->auth->loginUser(['id' => $newUserId]);
        (new EligibilityService($this->pdo))->claimForUser($newUserId);
        audit_log($this->pdo, $newUserId, $form['fullName'], 'applicant', 'Registration', 'user', $newUserId,
            $numberVerified ? 'New Solo Parent account (mobile number confirmed by SMS code)' : 'New Solo Parent account');
        create_notification($this->pdo, $newUserId, 'registration', 'Welcome to e-SoloParentID! Your account has been created.');
        flash('Your account has been created. You can now use your dashboard and apply online.');
        $this->redirectTo(take_applicant_return_path());
        return '';
    }

    /** "09171234567" => "0917 *** 4567" (the full number is not repeated on screen). */
    private function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);
        return strlen($digits) >= 8 ? substr($digits, 0, 4) . ' *** ' . substr($digits, -4) : '***';
    }

    /** A "requirements:<doc_key>" destination is kept only while that document is still in the Requirements Guide; otherwise the guide's index. */
    private function returnKey(string $key): string
    {
        if (str_starts_with($key, 'requirements:') && !array_key_exists(substr($key, strlen('requirements:')), RequirementGuideService::catalog($this->pdo))) {
            return 'requirements';
        }
        return $key;
    }

    /**
     * Shared login checks for both portals: '' if the credentials are good,
     * otherwise the message to show. The same "Incorrect username or
     * password" is given whether the username (or mobile number) or the
     * password was wrong.
     * Every refused attempt is audit-logged ($portal says which sign-in
     * page); an attempt on an unknown username is logged against no account,
     * with the username as typed (never the password). Too many failures
     * from one IP (across any accounts -- password spraying) stops all
     * sign-in attempts from it for a while, without checking the password.
     */
    private function attemptLogin(?array $account, string $username, string $password, string $portal, string $deactivatedHelp): string
    {
        $limiter = new RateLimiter($this->pdo);
        if ($limiter->tooMany('login_ip', RateLimiter::clientIp())) {
            return 'Too many failed sign-in attempts from this network. Please try again in 15 minutes.';
        }
        $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;
        if ($lockoutMsg) {
            $this->auditFailedLogin($account, $username, $portal, 'account is locked until ' . date('g:i A', strtotime($account['locked_until'])));
            return $lockoutMsg;
        }
        if (!$this->auth->verifyCredentials($account, $password)) {
            if ($account) {
                $locked = $this->auth->recordFailedLogin($account);
                $this->auditFailedLogin($account, $username, $portal, 'wrong password (attempt ' . ((int) $account['failed_login_attempts'] + 1) . ' of ' . LOGIN_LOCKOUT_THRESHOLD . ')');
                if ($locked) {
                    $this->auditLocked($account);
                }
            } else {
                $this->auditFailedLogin(null, $username, $portal, 'no matching account');
            }
            // Logged once, when the limit is reached; further attempts are refused without a log entry each.
            if ($limiter->hit('login_ip', RateLimiter::clientIp()) === RateLimiter::RULES['login_ip'][0]) {
                audit_log($this->pdo, null, 'Unknown user', 'guest', 'Sign-in Blocked', null, null,
                    "$portal: " . RateLimiter::RULES['login_ip'][0] . ' failed sign-ins from IP ' . RateLimiter::clientIp() . ' within 15 minutes; further attempts refused for 15 minutes');
            }
            return 'Incorrect username or password.';
        }
        if (!$account['is_active']) {
            $this->auditFailedLogin($account, $username, $portal, 'account is deactivated');
            return 'This account has been deactivated. ' . $deactivatedHelp;
        }
        $this->auth->clearLockout((int) $account['id']);
        return '';
    }

    private function auditLocked(array $account): void
    {
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Account Locked', 'user', (int) $account['id'],
            $account['username'] . ': ' . LOGIN_LOCKOUT_THRESHOLD . ' failed attempts, locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes');
    }

    private function auditFailedLogin(?array $account, string $username, string $portal, string $reason): void
    {
        if ($account) {
            audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login Failed', 'user', (int) $account['id'], "$portal: $reason");
        } else {
            $typed = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $username) ?? '', 0, 50);
            audit_log($this->pdo, null, 'Unknown user', 'guest', 'Login Failed', null, null, "$portal: $reason, username \"$typed\"");
        }
    }

    /** '' if the sign-up form is valid, otherwise the first problem found. */
    private function registrationError(array $form, string $password, string $confirmation): string
    {
        return $this->auth->applicantRegistrationError($form, $password, $confirmation);
    }

    public function logoutApplicant(): void
    {
        if ($staff = $this->auth->currentStaff()) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($user = $this->auth->currentUser()) {
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Logout', 'user', (int) $user['id'], 'Solo Parent sign-out');
            }
            $this->auth->logoutUser();
        }
        $this->redirectTo('login.php');
    }

    public function logoutStaff(): void
    {
        if ($this->auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($staff = $this->auth->currentStaff()) {
                audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Logout', 'user', (int) $staff['id'], 'Staff sign-out');
            }
            $this->auth->logoutStaff();
        }
        $this->redirectTo('login.php');
    }

    public function forgotPassword(): array
    {
        $error = '';
        $success = '';
        $smsReset = PASSWORD_RESET_BY_SMS;
        if (!$smsReset) {
            // Resets are handled by an administrator for now: no code is generated or texted, and nothing is looked up.
            return compact('error', 'success', 'smsReset');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            $limiter = new RateLimiter($this->pdo);
            if ($limiter->tooMany('reset_ip', RateLimiter::clientIp())) {
                return ['error' => 'Too many reset requests from this network. Please try again in an hour.', 'success' => ''];
            }
            $limiter->hit('reset_ip', RateLimiter::clientIp());

            // Same message whether or not the account exists, so this
            // can't be used to enumerate valid usernames/mobiles.
            $success = 'If that account exists and has a mobile number on file, a reset code has been sent by SMS.';

            // At most a few texts per account per code lifetime (no SMS bombing); past that, the last code
            // sent still works, and the response is the same so it doesn't reveal the account exists.
            if ($account && !empty($account['mobile']) && $account['is_active'] && !$limiter->tooMany('reset_account', (string) $account['id'])) {
                $limiter->hit('reset_account', (string) $account['id']);
                $code = $this->auth->requestPasswordReset($account);
                (new SMSService($this->pdo))->notifyPasswordReset($account['mobile'], $code);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Requested', 'user', (int) $account['id'],
                    'Reset code sent by SMS to the number ending ' . substr(preg_replace('/\D/', '', $account['mobile']), -4));
            }
        }

        return compact('error', 'success', 'smsReset');
    }

    public function resetPassword(): array
    {
        if (!PASSWORD_RESET_BY_SMS) {
            $this->redirectTo('forgot-password.php');
        }
        $error = '';
        $success = '';
        $loginUrl = 'login.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $policyError = $this->auth->passwordPolicyError($newPassword, (string) ($_POST['new_password_confirm'] ?? ''));
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            // Wrong codes count toward the same lockout as wrong passwords,
            // so the 6-digit code can't be brute-forced.
            $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;

            if ($lockoutMsg) {
                $error = $lockoutMsg;
            } elseif (!$account || !$this->auth->verifyResetCode($account, $code)) {
                // Only an account with a live code is at risk of guessing, so only then does a wrong code count toward
                // the lockout; otherwise anyone could lock any account (an administrator's included) just by typing its username.
                if ($account && $this->auth->hasPendingResetCode($account)) {
                    $locked = $this->auth->recordFailedLogin($account);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Failed', 'user', (int) $account['id'],
                        'Invalid or expired reset code (attempt ' . ((int) $account['failed_login_attempts'] + 1) . ' of ' . LOGIN_LOCKOUT_THRESHOLD . ')');
                    if ($locked) {
                        $this->auditLocked($account);
                    }
                }
                $error = 'That code is invalid or has expired. Please request a new one.';
            } elseif ($policyError) {
                $error = $policyError;
            } else {
                $this->auth->completePasswordReset((int) $account['id'], $newPassword);
                $this->auth->clearLockout((int) $account['id']);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Completed', 'user', (int) $account['id'], 'New password set with an SMS reset code; lockout cleared');
                $success = 'Your password has been reset. You can now log in with your new password.';
            }
        }

        return compact('error', 'success', 'loginUrl');
    }
}
