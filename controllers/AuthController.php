<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../includes/SmsService.php';

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
        if ($this->auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        $authMode = ($_GET['mode'] ?? $defaultMode) === 'signup' ? 'signup' : 'login';
        $authError = '';
        $oldSignup = ['fullName' => '', 'email' => '', 'username' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = $_POST['action'] ?? '';

            if ($action === 'login') {
                $authMode = 'login';
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $account = $this->auth->userModel()->findActiveApplicantByUsername($username);
                $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;

                if ($lockoutMsg) {
                    $authError = $lockoutMsg;
                } elseif (!$account || !password_verify($password, $account['password_hash'])) {
                    if ($account) {
                        $this->auth->recordFailedLogin((int) $account['id'], (int) $account['failed_login_attempts']);
                    }
                    $authError = 'Incorrect username or password.';
                } elseif (!$account['is_active']) {
                    $authError = 'This account has been deactivated. Please contact MSWDO Virac for assistance.';
                } else {
                    $this->auth->clearLockout((int) $account['id']);
                    $this->auth->loginUser($account);
                    audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login');
                    $this->redirectTo('applicant/dashboard.php');
                }
            } elseif ($action === 'signup') {
                $authMode = 'signup';
                $fullName = trim((string) ($_POST['fullName'] ?? ''));
                $email = trim((string) ($_POST['email'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $oldSignup = compact('fullName', 'email', 'username');

                if ($fullName === '' || $username === '' || $password === '') {
                    $authError = 'Please fill in full name, username, and password.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $authError = 'Enter a valid email address.';
                } elseif (strlen($password) < 6) {
                    $authError = 'Password must be at least 6 characters.';
                } elseif ($this->auth->userModel()->usernameExists($username)) {
                    $authError = 'That username is already taken.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $newUserId = $this->auth->userModel()->createApplicant($fullName, $email, $username, $hash);
                    $newUser = ['id' => $newUserId, 'full_name' => $fullName, 'email' => $email, 'username' => $username, 'mobile' => null, 'role' => 'applicant'];
                    $this->auth->loginUser($newUser);
                    audit_log($this->pdo, $newUserId, $fullName, 'applicant', 'Registration');
                    create_notification($this->pdo, $newUserId, 'registration', 'Welcome to e-SoloParentID! Your account has been created.');
                    $this->redirectTo('portal.php?tab=apply');
                }
            }
        }

        return compact('authMode', 'authError', 'oldSignup');
    }

    /** MSWD staff + admin login (admin/login.php). Returns the error message to show, if any. */
    public function handleStaffLogin(): string
    {
        if ($existing = $this->auth->currentStaff()) {
            $this->redirectTo($existing['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return '';
        }

        csrf_require();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $account = $this->auth->userModel()->findStaffByUsername($username);
        $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;

        if ($lockoutMsg) {
            return $lockoutMsg;
        }
        if (!$account || !password_verify($password, $account['password_hash'])) {
            if ($account) {
                $this->auth->recordFailedLogin((int) $account['id'], (int) $account['failed_login_attempts']);
            }
            return 'Incorrect username or password.';
        }
        if (!$account['is_active']) {
            return 'This account has been deactivated. Please contact your system administrator.';
        }

        $this->auth->clearLockout((int) $account['id']);
        $this->auth->loginStaff($account);
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Login');
        $this->redirectTo($account['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        return '';
    }

    public function logoutApplicant(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($user = $this->auth->currentUser()) {
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Logout');
            }
            $this->auth->logoutUser();
        }
        $this->redirectTo('index.php');
    }

    public function logoutStaff(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if ($staff = $this->auth->currentStaff()) {
                audit_log($this->pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Logout');
            }
            $this->auth->logoutStaff();
        }
        $this->redirectTo('admin/login.php');
    }

    public function forgotPassword(): array
    {
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            // Same message whether or not the account exists, so this
            // can't be used to enumerate valid usernames/mobiles.
            $success = 'If that account exists and has a mobile number on file, a reset code has been sent by SMS.';

            if ($account && !empty($account['mobile']) && $account['is_active']) {
                $code = $this->auth->requestPasswordReset($account);
                (new SmsService($this->pdo))->notifyPasswordReset($account['mobile'], $code);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Requested');
            }
        }

        return compact('error', 'success');
    }

    public function resetPassword(): array
    {
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $identifier = trim((string) ($_POST['identifier'] ?? ''));
            $code = trim((string) ($_POST['code'] ?? ''));
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $account = $identifier !== '' ? $this->auth->userModel()->findByUsernameOrMobile($identifier) : null;

            // Wrong codes count toward the same lockout as wrong passwords,
            // so the 6-digit code can't be brute-forced.
            $lockoutMsg = $account ? $this->auth->lockoutMessage($account) : null;

            if ($lockoutMsg) {
                $error = $lockoutMsg;
            } elseif (!$account || !$this->auth->verifyResetCode($account, $code)) {
                if ($account) {
                    $this->auth->recordFailedLogin((int) $account['id'], (int) $account['failed_login_attempts']);
                }
                $error = 'That code is invalid or has expired. Please request a new one.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'New password must be at least 6 characters.';
            } else {
                $this->auth->completePasswordReset((int) $account['id'], $newPassword);
                $this->auth->clearLockout((int) $account['id']);
                audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Reset Completed');
                $success = 'Your password has been reset. You can now log in with your new password.';
            }
        }

        return compact('error', 'success');
    }
}
