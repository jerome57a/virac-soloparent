<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/ApplicationDraft.php';
require_once __DIR__ . '/DocumentValidationService.php';

/**
 * Login, sessions, lockout, password policy and password reset. This is
 * what includes/auth.php delegates to, so every existing caller shares one
 * implementation.
 *
 * Session rules: applicant, admin and staff portals each have their own cookie.
 * Each portal stores one identity; logging out affects only that portal. Its session data ends
 * on logout, after SESSION_IDLE_MINUTES without activity, or
 * SESSION_ABSOLUTE_HOURS after login. An account's sessions also end when
 * its password is changed, reset or replaced by an administrator
 * (users.auth_version moves on), except the session that changed it.
 * Ending a session wipes all its data,
 * so nothing carries over to the next person on that browser. (Application
 * and renewal drafts belong to the account, not the session -- see
 * ApplicationDraft.)
 */
class AuthService
{
    /** bcrypt hash of a random string, verified against when the username doesn't exist so both cases take the same time. */
    private const DUMMY_HASH = '$2y$10$31MBL39gLWJWynXE5ypA9uuebMudqMX1tX6U8zoSNoSWMKFlwjlka';

    private PDO $pdo;
    private User $userModel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->userModel = new User($pdo);
    }

    public function userModel(): User
    {
        return $this->userModel;
    }

    // ---- Current session ----

    /** The signed-in applicant, re-checked against the DB on every call so deactivation or a role change takes effect immediately. */
    public function currentUser(): ?array
    {
        if (AUTH_PORTAL !== 'applicant') {
            return null;
        }
        if (empty($_SESSION['user_id']) || $this->sessionExpired()) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, full_name, profile_photo, email, username, mobile, mobile_verified_at, role, must_change_password, auth_version FROM users WHERE id = ? AND role = 'applicant' AND is_active = 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        if ($this->signedOutElsewhere('user', $user)) {
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $user;
    }

    public function currentStaff(): ?array
    {
        if (!in_array(AUTH_PORTAL, ['admin', 'staff'], true)) {
            return null;
        }
        if (!empty($_SESSION['user_id'])) {
            unset($_SESSION['staff_id'], $_SESSION['staff_auth_version']);
            return null;
        }
        if (empty($_SESSION['staff_id']) || $this->sessionExpired()) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, full_name, email, username, mobile, role, must_change_password, last_login_at, auth_version FROM users WHERE id = ? AND role IN ('mswd_social_worker','admin') AND is_active = 1");
        $stmt->execute([$_SESSION['staff_id']]);
        $staff = $stmt->fetch();
        if (!$staff || $staff['role'] !== (AUTH_PORTAL === 'admin' ? 'admin' : 'mswd_social_worker')) {
            unset($_SESSION['staff_id']);
            return null;
        }
        if ($this->signedOutElsewhere('staff', $staff)) {
            return null;
        }
        $_SESSION['last_activity'] = time();
        return $staff;
    }

    /**
     * True (and the session is ended) if the account's password changed
     * since this session signed in -- e.g. the owner reset it after losing
     * their phone, or an administrator reset their access.
     */
    private function signedOutElsewhere(string $kind, array $account): bool
    {
        if ((int) ($_SESSION[$kind . '_auth_version'] ?? 0) === (int) $account['auth_version']) {
            return false;
        }
        $this->endSession();
        $_SESSION['auth_notice'] = 'Your password was changed, so you were signed out. Please log in with the new password.';
        return true;
    }

    /** Ends the session (with a notice for the login page) once it has been idle or open too long. */
    private function sessionExpired(): bool
    {
        $now = time();
        $idle = $now - (int) ($_SESSION['last_activity'] ?? $now);
        $age = $now - (int) ($_SESSION['auth_time'] ?? $now);
        if ($idle <= SESSION_IDLE_MINUTES * 60 && $age <= SESSION_ABSOLUTE_HOURS * 3600) {
            return false;
        }
        $this->endSession();
        $_SESSION['auth_notice'] = 'Your session expired for your security. Please log in again.';
        return true;
    }

    /** One-time message for the login page (e.g. "session expired"), or null. */
    public function takeAuthNotice(): ?string
    {
        $notice = $_SESSION['auth_notice'] ?? null;
        unset($_SESSION['auth_notice']);
        return $notice;
    }

    // ---- Login / logout ----

    /**
     * True if $password matches $account. Runs a full password check even
     * when the account doesn't exist, so response time doesn't reveal which
     * usernames are registered. Upgrades the stored hash if PHP's default
     * algorithm or cost has changed since it was created.
     */
    public function verifyCredentials(?array $account, string $password): bool
    {
        if (!$account) {
            password_verify($password, self::DUMMY_HASH);
            return false;
        }
        if (!password_verify($password, $account['password_hash'])) {
            return false;
        }
        if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
            $this->userModel->updatePasswordHash((int) $account['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        return true;
    }

    public function loginUser(array $user): void
    {
        // Capture the previous sign-in before recordLogin replaces its date.
        // Registration passes only the new user's ID, so load it when needed.
        $previousLogin = array_key_exists('last_login_at', $user)
            ? $user['last_login_at']
            : ($this->userModel->findById((int) $user['id'])['last_login_at'] ?? null);
        $this->startSession('user', (int) $user['id']);
        $_SESSION['applicant_first_login'] = empty($previousLogin);
        $this->userModel->recordLogin((int) $user['id']);
    }

    public function loginStaff(array $user): void
    {
        $this->startSession('staff', (int) $user['id']);
        $this->userModel->recordLogin((int) $user['id']);
    }

    public function logoutUser(): void
    {
        $this->endSession();
    }

    public function logoutStaff(): void
    {
        $this->endSession();
    }

    /**
     * Switches this request to the admin or staff portal's own session (and cookie) so the unified login page can sign
     * a staff member in after verifying their credentials. loginStaff() then wipes that session and issues a new ID.
     */
    public function enterPortalSession(string $portal): void
    {
        if (!in_array($portal, ['admin', 'staff'], true)) {
            throw new InvalidArgumentException('Unknown portal.');
        }
        session_write_close();
        session_name('ESPID_' . strtoupper($portal) . '_SESSION');
        session_start();
    }

    /** New session ID on every login (prevents session fixation). $kind: 'user' (applicant) or 'staff'. */
    private function startSession(string $kind, int $userId): void
    {
        $this->purgeAbandonedDrafts();
        // Preserve only pre-login context, never another account's identity or data.
        $context = array_intersect_key($_SESSION, array_flip(['eligibility_token', 'applicant_return']));
        if ($kind !== 'user') {
            unset($context['applicant_return']);
        }
        $_SESSION = $context;
        session_regenerate_id(true);
        $_SESSION[$kind . '_id'] = $userId;
        $_SESSION[$kind . '_auth_version'] = $this->userModel->authVersion($userId);
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();
    }

    private function endSession(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /**
     * Housekeeping on each login: temp upload folders older than the
     * longest possible session (left by sessions that simply expired, and
     * by the old session-based renewal drafts) are deleted, as are draft
     * folders whose saved draft no longer exists.
     */
    private function purgeAbandonedDrafts(): void
    {
        // Confined to the configured upload root (STORAGE_PATH): in testing that is the validated test root, and
        // the purge stands down if its marker or folder has gone. Only real, non-link, direct children of
        // <root>/tmp and <root>/drafts with the expected folder names are ever considered.
        if (!storage_root_still_safe()) {
            error_log('Draft cleanup skipped: the configured test storage root failed its safety check.');
            return;
        }
        $root = realpath(STORAGE_PATH);
        if ($root === false) {
            return;
        }
        $cutoff = time() - SESSION_ABSOLUTE_HOURS * 3600;
        $files = new DocumentValidationService($this->pdo);
        foreach ($this->purgeCandidates($root, 'tmp', '/\A[A-Za-z0-9_-]{1,80}\z/') as $dir) {
            if (filemtime($dir) < $cutoff) {
                $files->deleteDir($dir);
            }
        }

        // Draft folders whose draft row is gone (e.g. the account was deleted).
        // The hour's grace covers an upload whose draft row is written a moment later.
        $saved = (new ApplicationDraft($this->pdo))->folderNames();
        foreach ($this->purgeCandidates($root, 'drafts', '/\A(?:renewal_)?[0-9]{1,12}\z/') as $dir) {
            if (!isset($saved[basename($dir)]) && filemtime($dir) < time() - 3600) {
                $files->deleteDir($dir);
            }
        }
    }

    /**
     * Direct child folders of <root>/<kind> whose names match $namePattern, excluding links and anything whose
     * real path is not exactly <real root>/<kind>/<name>.
     *
     * @return string[]
     */
    private function purgeCandidates(string $root, string $kind, string $namePattern): array
    {
        $base = realpath($root . '/' . $kind);
        if ($base === false || is_link($root . '/' . $kind) || dirname($base) !== $root) {
            return [];
        }
        $candidates = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            $real = realpath($dir);
            if (preg_match($namePattern, $name) === 1 && !is_link($dir) && $real !== false && dirname($real) === $base && basename($real) === $name) {
                $candidates[] = $dir;
            }
        }
        return $candidates;
    }

    // ---- Password policy ----

    /** Shared rules for public and administrator-created Solo Parent accounts: mobile and optional Gmail. */
    public function applicantRegistrationError(array $form, string $password, string $confirmation): string
    {
        // Public sign-up always supplies a username (the form key is present); other callers, such as
        // administrator-created accounts, do not pass one and keep their existing rules.
        $usernameRequired = array_key_exists('username', $form);
        if ($form['fullName'] === '' || $form['mobile'] === '' || $password === '' || ($usernameRequired && trim((string) $form['username']) === '')) {
            return $usernameRequired ? 'Please fill in full name, username, mobile number, and password.' : 'Please fill in full name, mobile number, and password.';
        }
        if ($usernameRequired && ($usernameError = $this->usernameError((string) $form['username']))) {
            return $usernameError;
        }
        if (mb_strlen($form['fullName']) > 150) {
            return 'Full name is too long.';
        }
        if (!is_valid_ph_mobile($form['mobile'])) {
            return 'Enter a valid Philippine mobile number (e.g. 09171234567).';
        }
        if ($error = $this->passwordPolicyError($password, $confirmation)) {
            return $error;
        }
        $email = trim((string) ($form['email'] ?? ''));
        if ($email !== '' && (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strcasecmp(substr($email, -10), '@gmail.com') !== 0)) {
            return 'Enter a valid Gmail address ending in @gmail.com, or leave it blank.';
        }
        if ($email !== '' && $this->userModel->emailExists($email)) {
            return 'That Gmail address is already registered. Log in, or use Forgot password.';
        }
        if ($this->userModel->mobileExists($form['mobile'])) {
            return 'That mobile number is already registered. Log in, or use Forgot password if you can\'t remember it.';
        }
        return '';
    }

    public const USERNAME_MIN_LENGTH = 3;
    public const USERNAME_MAX_LENGTH = 30;

    /**
     * Rules for an applicant's username: 3-30 characters, letters, digits, dot, underscore or hyphen, starting with a
     * letter or digit, containing at least one letter (so it can never look like a mobile number), and unique across
     * every account (staff included). Characters such as "@" and "+" are refused so a username can never be mistaken
     * for an email address or a +63 mobile number. Null if acceptable.
     */
    public function usernameError(string $username): ?string
    {
        if (strlen($username) < self::USERNAME_MIN_LENGTH || strlen($username) > self::USERNAME_MAX_LENGTH
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $username) !== 1
            || preg_match('/[A-Za-z]/', $username) !== 1 || preg_match('/[._-]\z/', $username) === 1) {
            return 'Username must be ' . self::USERNAME_MIN_LENGTH . '-' . self::USERNAME_MAX_LENGTH . ' characters: letters, numbers, dots, underscores or hyphens, with at least one letter, starting with a letter or number and not ending with a symbol.';
        }
        if ($this->userModel->usernameExists($username)) {
            return 'That username is already taken. Please choose another.';
        }
        return null;
    }

    /**
     * An applicant whose account predates usernames chooses one (once). Returns [error, success].
     *
     * @return array{0: string, 1: string}
     */
    public function chooseUsername(array $account, string $username): array
    {
        $username = trim($username);
        if (($account['role'] ?? '') !== 'applicant') {
            return ['Only applicant accounts can choose a username here.', ''];
        }
        if (!empty($account['username'])) {
            return ['Your username is already set and cannot be changed.', ''];
        }
        if ($error = $this->usernameError($username)) {
            return [$error, ''];
        }
        try {
            $set = $this->userModel->setUsernameIfEmpty((int) $account['id'], $username);
        } catch (PDOException $e) {
            // SQLSTATE 23000 covers every integrity failure; report a duplicate only if the username now exists
            // (taken between the check and the update). Any other failure is rethrown unchanged.
            if ($e->getCode() === '23000' && $this->userModel->usernameExists($username)) {
                return ['That username is already taken. Please choose another.', ''];
            }
            throw $e;
        }
        if (!$set) {
            return ['Your username is already set and cannot be changed.', ''];
        }
        audit_log($this->pdo, (int) $account['id'], (string) $account['full_name'], 'applicant', 'Username Set', 'user', (int) $account['id'], 'Chose a username (account predated usernames)');
        return ['', 'Your username is saved. From now on, sign in with your username and password.'];
    }

    // ---- Mobile number verification at sign-up ----
    // The details are held in the session (the password only as a hash) while a 6-digit code is texted; the account is created
    // only when the code is entered correctly. The code itself is never stored, only its hash.

    /** Starts (or restarts) verification for these sign-up details and returns the plain code to text. */
    public function beginMobileVerification(array $form, string $password, ?int $now = null): string
    {
        $now ??= time();
        $code = (string) random_int(100000, 999999);
        $old = $_SESSION['pending_signup'] ?? null;
        $sameNumber = is_array($old) && ($old['form']['mobile'] ?? null) === ($form['mobile'] ?? '');
        $_SESSION['pending_signup'] = [
            'form' => [
                'fullName' => (string) $form['fullName'], 'username' => (string) $form['username'],
                'email' => (string) $form['email'], 'mobile' => (string) $form['mobile'],
            ],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires' => $now + MOBILE_CODE_MINUTES * 60,
            'attempts' => 0,
            'sent' => ($sameNumber ? (int) $old['sent'] : 0) + 1,
            'last_sent' => $now,
        ];
        return $code;
    }

    /** The sign-up waiting for its code, or null. */
    public function pendingSignup(): ?array
    {
        $pending = $_SESSION['pending_signup'] ?? null;
        return is_array($pending) && isset($pending['form'], $pending['code_hash'], $pending['password_hash']) ? $pending : null;
    }

    public function clearPendingSignup(): void
    {
        unset($_SESSION['pending_signup']);
    }

    /** Why another code cannot be sent now, or null if it can. */
    public function mobileCodeResendError(?int $now = null): ?string
    {
        $now ??= time();
        $pending = $this->pendingSignup();
        if (!$pending) {
            return 'Your sign-up session ended. Please fill in the form again.';
        }
        if ((int) $pending['sent'] >= MOBILE_CODE_MAX_SENDS) {
            return 'Too many codes were sent for this sign-up. Please start again later, or contact MSWDO.';
        }
        $wait = (int) $pending['last_sent'] + MOBILE_CODE_RESEND_SECONDS - $now;
        return $wait > 0 ? 'Please wait ' . $wait . ' second' . ($wait === 1 ? '' : 's') . ' before asking for another code.' : null;
    }

    /** A fresh code for the pending sign-up (the old one stops working). Returns the plain code to text. */
    public function renewMobileCode(?int $now = null): string
    {
        $now ??= time();
        $code = (string) random_int(100000, 999999);
        $_SESSION['pending_signup']['code_hash'] = password_hash($code, PASSWORD_DEFAULT);
        $_SESSION['pending_signup']['expires'] = $now + MOBILE_CODE_MINUTES * 60;
        $_SESSION['pending_signup']['attempts'] = 0;
        $_SESSION['pending_signup']['sent'] = (int) $_SESSION['pending_signup']['sent'] + 1;
        $_SESSION['pending_signup']['last_sent'] = $now;
        return $code;
    }

    /** 'ok', 'wrong', 'expired', 'locked' (too many wrong entries; start again) or 'none' (nothing pending). A wrong entry is counted. */
    public function checkMobileCode(string $code, ?int $now = null): string
    {
        $now ??= time();
        $pending = $this->pendingSignup();
        if (!$pending) {
            return 'none';
        }
        if ((int) $pending['attempts'] >= MOBILE_CODE_MAX_ATTEMPTS) {
            return 'locked';
        }
        if ($now > (int) $pending['expires']) {
            return 'expired';
        }
        if (preg_match('/\A\d{6}\z/', trim($code)) === 1 && password_verify(trim($code), (string) $pending['code_hash'])) {
            return 'ok';
        }
        $_SESSION['pending_signup']['attempts'] = (int) $pending['attempts'] + 1;
        return (int) $_SESSION['pending_signup']['attempts'] >= MOBILE_CODE_MAX_ATTEMPTS ? 'locked' : 'wrong';
    }

    public function passwordPolicyError(string $password, ?string $confirmation = null): ?string
    {
        if (strlen($password) < PASSWORD_MIN_LENGTH || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            return 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters and include at least one letter and one number.';
        }
        if (strlen($password) > PASSWORD_MAX_BYTES) {
            return 'Password is too long (at most ' . PASSWORD_MAX_BYTES . ' characters).';
        }
        if ($confirmation !== null && !hash_equals($password, $confirmation)) {
            return 'The two passwords do not match.';
        }
        return null;
    }

    /**
     * The signed-in user changes their own password (applicants on My
     * Profile, staff and admins on My Account). Clears a forced change.
     *
     * @return array{0: string, 1: string} [error, success]
     */
    public function changeOwnPassword(array $account, string $current, string $new, string $confirm): array
    {
        $stored = $this->userModel->findById((int) $account['id']);
        if (!$stored || !password_verify($current, $stored['password_hash'])) {
            return ['Current password is incorrect.', ''];
        }
        if ($error = $this->passwordPolicyError($new, $confirm)) {
            return [$error, ''];
        }
        if (password_verify($new, $stored['password_hash'])) {
            return ['Choose a password different from your current one.', ''];
        }
        $this->userModel->setPassword((int) $account['id'], password_hash($new, PASSWORD_DEFAULT));
        // Every other session of this account is now signed out; this one carries on, under a fresh session ID.
        $kind = $account['role'] === 'applicant' ? 'user' : 'staff';
        if ((int) ($_SESSION[$kind . '_id'] ?? 0) === (int) $account['id']) {
            session_regenerate_id(true);
            $_SESSION[$kind . '_auth_version'] = $this->userModel->authVersion((int) $account['id']);
        }
        audit_log($this->pdo, (int) $account['id'], $account['full_name'], $account['role'], 'Password Changed', 'user', (int) $account['id'],
            empty($stored['must_change_password']) ? 'Changed own password' : 'Replaced the temporary password issued by an administrator');
        return ['', 'Your password has been updated. Any other device signed in to this account has been signed out.'];
    }

    // ---- Lockout ----

    /** Null if not locked, otherwise the user-facing message. */
    public function lockoutMessage(array $account): ?string
    {
        if (!empty($account['locked_until']) && strtotime($account['locked_until']) > time()) {
            return 'Too many failed attempts. Please try again after ' . date('g:i A', strtotime($account['locked_until'])) . '.';
        }
        return null;
    }

    /** Counts a failed login; true if this attempt locked the account (which also alerts the administrators). */
    public function recordFailedLogin(array $account): bool
    {
        $attempts = (int) $account['failed_login_attempts'];
        $this->userModel->recordFailedLogin((int) $account['id'], $attempts);
        if ($attempts + 1 !== LOGIN_LOCKOUT_THRESHOLD) {
            return false;
        }
        require_once __DIR__ . '/NotificationService.php';
        (new NotificationService($this->pdo))->notifyAdmins('security_alert',
            'Account "' . $account['username'] . '" (' . ($account['role'] ?? 'user') . ') was locked for ' . LOGIN_LOCKOUT_MINUTES . ' minutes after ' . LOGIN_LOCKOUT_THRESHOLD . ' failed sign-in attempts.', 'admin/users.php');
        return true;
    }

    public function clearLockout(int $userId): void
    {
        $this->userModel->clearLockout($userId);
    }

    // ---- Password reset ----

    /** Generates a fresh 6-digit reset code and stores only its hash, returning the code so the caller can text it. */
    public function requestPasswordReset(array $account): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_CODE_MINUTES * 60);
        $this->userModel->setResetCode((int) $account['id'], password_hash($code, PASSWORD_DEFAULT), $expiresAt);
        return $code;
    }

    /** True while a texted reset code exists and has not expired (only then can a wrong guess be an attack on that code). */
    public function hasPendingResetCode(array $account): bool
    {
        return !empty($account['password_reset_code']) && !empty($account['password_reset_expires_at'])
            && strtotime($account['password_reset_expires_at']) >= time();
    }

    public function verifyResetCode(array $account, string $code): bool
    {
        if (empty($account['password_reset_code']) || empty($account['password_reset_expires_at'])) {
            return false;
        }
        if (strtotime($account['password_reset_expires_at']) < time()) {
            return false;
        }
        // Stored as a bcrypt hash: a database or backup leak doesn't reveal live codes.
        return password_verify(trim($code), (string) $account['password_reset_code']);
    }

    public function completePasswordReset(int $userId, string $newPassword): void
    {
        $this->userModel->setPassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /** True when $password is the account's current password (used to re-authenticate sensitive changes such as a new mobile number). */
    public function passwordMatches(array $account, string $password): bool
    {
        $stored = $this->userModel->findById((int) $account['id']);
        return $stored && $password !== '' && password_verify($password, (string) $stored['password_hash']);
    }
}
