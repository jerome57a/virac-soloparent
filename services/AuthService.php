<?php
require_once __DIR__ . '/../models/User.php';

/**
 * Real home for login/session/lockout/password-reset logic. This is what
 * includes/auth.php now delegates to (see that file's comment) so every
 * existing caller across the app keeps working unchanged while there's
 * only one actual implementation.
 */
class AuthService
{
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

    /** Always re-checked against the DB (not session-cached) so a deactivated account loses access immediately. */
    public function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, full_name, profile_photo, email, username, mobile, role FROM users WHERE id = ? AND is_active = 1');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        return $user;
    }

    public function currentStaff(): ?array
    {
        if (empty($_SESSION['staff_id'])) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT id, full_name, email, username, role FROM users WHERE id = ? AND role IN ('mswd_social_worker','admin') AND is_active = 1");
        $stmt->execute([$_SESSION['staff_id']]);
        $staff = $stmt->fetch();
        if (!$staff) {
            unset($_SESSION['staff_id']);
            return null;
        }
        return $staff;
    }

    public function loginUser(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
    }

    public function logoutUser(): void
    {
        unset($_SESSION['user_id'], $_SESSION['apply_draft']);
        session_regenerate_id(true);
    }

    public function loginStaff(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['staff_id'] = $user['id'];
    }

    public function logoutStaff(): void
    {
        unset($_SESSION['staff_id']);
        session_regenerate_id(true);
    }

    /** Null if not locked, otherwise the user-facing message. */
    public function lockoutMessage(array $account): ?string
    {
        if (!empty($account['locked_until']) && strtotime($account['locked_until']) > time()) {
            return 'Too many failed attempts. Please try again after ' . date('g:i A', strtotime($account['locked_until'])) . '.';
        }
        return null;
    }

    public function recordFailedLogin(int $userId, int $currentAttempts): void
    {
        $this->userModel->recordFailedLogin($userId, $currentAttempts);
    }

    public function clearLockout(int $userId): void
    {
        $this->userModel->clearLockout($userId);
    }

    /** Generates and stores a fresh 6-digit reset code, returning it so the caller can text it. */
    public function requestPasswordReset(array $account): string
    {
        $code = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', time() + PASSWORD_RESET_CODE_MINUTES * 60);
        $this->userModel->setResetCode((int) $account['id'], $code, $expiresAt);
        return $code;
    }

    public function verifyResetCode(array $account, string $code): bool
    {
        if (empty($account['password_reset_code']) || empty($account['password_reset_expires_at'])) {
            return false;
        }
        if (strtotime($account['password_reset_expires_at']) < time()) {
            return false;
        }
        return hash_equals((string) $account['password_reset_code'], trim($code));
    }

    public function completePasswordReset(int $userId, string $newPassword): void
    {
        $this->userModel->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->userModel->clearResetCode($userId);
    }
}
