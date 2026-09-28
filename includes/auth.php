<?php

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    // Always re-checked against the DB (not session-cached) so a deactivated
    // account loses access immediately, not just after its next login.
    $stmt = $pdo->prepare('SELECT id, full_name, email, username, mobile, role FROM users WHERE id = ? AND is_active = 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id'], $_SESSION['_user_cache']);
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
}

function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['apply_draft']);
    session_regenerate_id(true);
}

function current_staff(PDO $pdo): ?array
{
    if (empty($_SESSION['staff_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, full_name, email, username, role FROM users WHERE id = ? AND role IN ('mswd_social_worker','admin') AND is_active = 1");
    $stmt->execute([$_SESSION['staff_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['staff_id']);
        return null;
    }
    return $user;
}

function require_staff_login(PDO $pdo): array
{
    $staff = current_staff($pdo);
    if (!$staff) {
        redirect(base_url('admin/login.php'));
    }
    return $staff;
}

/** Stricter than require_staff_login(): only the 'admin' role may pass. MSWD workers are bounced back to their own dashboard. */
function require_admin_login(PDO $pdo): array
{
    $staff = require_staff_login($pdo);
    if ($staff['role'] !== 'admin') {
        redirect(base_url('admin/dashboard.php'));
    }
    return $staff;
}

function login_staff(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['staff_id'] = $user['id'];
}

function logout_staff(): void
{
    unset($_SESSION['staff_id']);
    session_regenerate_id(true);
}
