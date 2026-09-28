<?php

function current_user(PDO $pdo): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    if (isset($_SESSION['_user_cache']) && $_SESSION['_user_cache']['id'] === $_SESSION['user_id']) {
        return $_SESSION['_user_cache'];
    }
    $stmt = $pdo->prepare('SELECT id, full_name, email, username, mobile, role FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        unset($_SESSION['user_id']);
        return null;
    }
    $_SESSION['_user_cache'] = $user;
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['_user_cache'] = $user;
}

function logout_user(): void
{
    unset($_SESSION['user_id'], $_SESSION['_user_cache'], $_SESSION['apply_draft']);
    session_regenerate_id(true);
}

function current_staff(PDO $pdo): ?array
{
    if (empty($_SESSION['staff_id'])) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id, full_name, email, username, role FROM users WHERE id = ? AND role IN ('staff','admin')");
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
