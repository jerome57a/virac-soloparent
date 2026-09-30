<?php
require_once __DIR__ . '/Model.php';

/**
 * Data access for the users table. Wraps the queries that used to live
 * inline in includes/auth.php, includes/portal/login.php, and
 * admin/login.php -- same columns, same ON conditions, just named and
 * in one place.
 */
class User extends Model
{
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Active applicant account by username, for login. */
    public function findActiveApplicantByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'applicant'");
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    /** Active staff account (MSWD or admin) by username, for login. */
    public function findStaffByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ? AND role IN ('mswd_social_worker','admin')");
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    /** By username or mobile, any role -- used by "forgot password" since the user might not remember which they signed up with. */
    public function findByUsernameOrMobile(string $usernameOrMobile): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = ? OR mobile = ? LIMIT 1');
        $stmt->execute([$usernameOrMobile, normalize_mobile($usernameOrMobile)]);
        return $stmt->fetch() ?: null;
    }

    public function usernameExists(string $username): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return (bool) $stmt->fetch();
    }

    public function createApplicant(string $fullName, string $email, string $username, string $passwordHash): int
    {
        $this->pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')
            ->execute([$fullName, $email, $username, $passwordHash, 'applicant']);
        return (int) $this->pdo->lastInsertId();
    }

    public const ROLE_LABELS = ['applicant' => 'Applicant', 'mswd_social_worker' => 'MSWD Social Worker', 'admin' => 'Administrator'];

    /** Count of users matching a fixed, code-supplied WHERE clause (never user input). */
    public function countWhere(string $where): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE $where")->fetchColumn();
    }

    /** Admin user list, newest first (max 300), optionally by role and name/username/email search. */
    public function listForAdmin(string $role, string $q): array
    {
        $sql = 'SELECT id, full_name, email, username, mobile, role, is_active, created_at FROM users WHERE 1=1';
        $params = [];
        if (isset(self::ROLE_LABELS[$role])) {
            $sql .= ' AND role = ?';
            $params[] = $role;
        }
        if ($q !== '') {
            $sql .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%");
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 300');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Every account for the CSV export, newest first. */
    public function allForExport(): array
    {
        return $this->pdo->query('SELECT username, full_name, email, mobile, role, is_active, created_at FROM users ORDER BY created_at DESC')->fetchAll();
    }

    public function createWithRole(string $fullName, string $email, string $username, string $passwordHash, string $role): int
    {
        $this->pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)')
            ->execute([$fullName, $email, $username, $passwordHash, $role]);
        return (int) $this->pdo->lastInsertId();
    }

    public function toggleActive(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$userId]);
    }

    public function setRole(int $userId, string $role): void
    {
        $this->pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $userId]);
    }

    /** Admin edit of contact details; a blank mobile is stored as NULL. */
    public function updateAccount(int $userId, string $fullName, string $email, ?string $mobile): void
    {
        $this->pdo->prepare('UPDATE users SET full_name = ?, email = ?, mobile = ? WHERE id = ?')
            ->execute([$fullName, $email, $mobile, $userId]);
    }

    public function recordFailedLogin(int $userId, int $currentAttempts): void
    {
        $attempts = $currentAttempts + 1;
        $lockedUntil = $attempts >= LOGIN_LOCKOUT_THRESHOLD
            ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60)
            : null;
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?')
            ->execute([$attempts, $lockedUntil, $userId]);
    }

    public function clearLockout(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$userId]);
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $userId]);
    }

    public function setResetCode(int $userId, string $code, string $expiresAt): void
    {
        $this->pdo->prepare('UPDATE users SET password_reset_code = ?, password_reset_expires_at = ? WHERE id = ?')
            ->execute([$code, $expiresAt, $userId]);
    }

    public function clearResetCode(int $userId): void
    {
        $this->pdo->prepare('UPDATE users SET password_reset_code = NULL, password_reset_expires_at = NULL WHERE id = ?')
            ->execute([$userId]);
    }
}
