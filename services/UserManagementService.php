<?php
require_once __DIR__ . '/../models/User.php';

/**
 * Admin account management. Admins can't deactivate or re-role themselves,
 * so the system can never be left without an active administrator by
 * accident. Each method returns [error, success] messages (one is '').
 */
class UserManagementService
{
    private PDO $pdo;
    private User $users;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->users = new User($pdo);
    }

    /** @return array{0: string, 1: string} */
    public function create(array $admin, string $fullName, string $email, string $username, string $password, string $role): array
    {
        if ($fullName === '' || $username === '' || $password === '') {
            return ['Please fill in full name, username, and password.', ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['Enter a valid email address.', ''];
        }
        if (strlen($password) < 6) {
            return ['Password must be at least 6 characters.', ''];
        }
        if (!isset(User::ROLE_LABELS[$role])) {
            return ['Select a valid role.', ''];
        }
        if ($this->users->usernameExists($username)) {
            return ['That username is already taken.', ''];
        }
        $newId = $this->users->createWithRole($fullName, $email, $username, password_hash($password, PASSWORD_DEFAULT), $role);
        $this->audit($admin, 'User Creation', $newId, $username . ' (' . User::ROLE_LABELS[$role] . ')');
        return ['', 'Account created for ' . $username . '.'];
    }

    /** @return array{0: string, 1: string} */
    public function toggleActive(array $admin, int $targetId): array
    {
        if ($targetId === (int) $admin['id']) {
            return ['You cannot deactivate your own account.', ''];
        }
        $target = $this->users->findById($targetId);
        $this->users->toggleActive($targetId);
        if ($target) {
            $this->audit($admin, 'Account ' . ($target['is_active'] ? 'Deactivated' : 'Activated'), $targetId, $target['username']);
        }
        return ['', 'Account status updated.'];
    }

    /** Sets a random temporary password and shows it once to the admin to pass on. @return array{0: string, 1: string} */
    public function resetPassword(array $admin, int $targetId): array
    {
        $target = $this->users->findById($targetId);
        if (!$target) {
            return ['Account not found.', ''];
        }
        $tempPassword = bin2hex(random_bytes(5));
        $this->users->updatePasswordHash($targetId, password_hash($tempPassword, PASSWORD_DEFAULT));
        $this->audit($admin, 'Password Reset', $targetId, $target['username']);
        return ['', 'Password for ' . $target['username'] . ' reset to: ' . $tempPassword . ' (share this with them securely; they should change it after logging in).'];
    }

    /** @return array{0: string, 1: string} */
    public function changeRole(array $admin, int $targetId, string $newRole): array
    {
        if ($targetId === (int) $admin['id']) {
            return ['You cannot change your own role.', ''];
        }
        if (!isset(User::ROLE_LABELS[$newRole])) {
            return ['Select a valid role.', ''];
        }
        $target = $this->users->findById($targetId);
        $this->users->setRole($targetId, $newRole);
        if ($target) {
            $this->audit($admin, 'User Role Change', $targetId, $target['username'] . ': ' . User::ROLE_LABELS[$target['role']] . ' → ' . User::ROLE_LABELS[$newRole]);
        }
        return ['', 'Role updated.'];
    }

    /** Edits contact details; mobile may be blank. @return array{0: string, 1: string} */
    public function updateAccount(array $admin, array $target, string $fullName, string $email, string $mobile): array
    {
        if ($fullName === '') {
            return ['Enter a full name.', ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['Enter a valid email address.', ''];
        }
        if ($mobile !== '' && !is_valid_ph_mobile($mobile)) {
            return ['Enter a valid Philippine mobile number (e.g. 09171234567), or leave it blank.', ''];
        }
        $this->users->updateAccount((int) $target['id'], $fullName, $email, $mobile !== '' ? $mobile : null);
        $this->audit($admin, 'User Edited', (int) $target['id'], $target['username']);
        return ['', 'Account updated.'];
    }

    private function audit(array $admin, string $action, int $userId, string $details): void
    {
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], $action, 'user', $userId, $details);
    }
}
