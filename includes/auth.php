<?php
/**
 * Thin delegation layer: the real logic now lives in services/AuthService.php
 * and models/User.php. These functions keep their exact original
 * signatures/behavior so the ~50 existing pages across admin/, staff/,
 * applicant/, and shared pages that already call them keep working
 * completely unchanged. New code should use AuthService directly instead
 * of these functions.
 */
require_once __DIR__ . '/../services/AuthService.php';

function current_user(PDO $pdo): ?array
{
    return (new AuthService($pdo))->currentUser();
}

function login_user(array $user): void
{
    // No $pdo needed for a pure session write, but kept as a standalone
    // service call for a single source of truth.
    $service = new AuthService($GLOBALS['pdo']);
    $service->loginUser($user);
}

function logout_user(): void
{
    (new AuthService($GLOBALS['pdo']))->logoutUser();
}

function current_staff(PDO $pdo): ?array
{
    return (new AuthService($pdo))->currentStaff();
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
        redirect(base_url('staff/dashboard.php'));
    }
    return $staff;
}

function login_staff(array $user): void
{
    (new AuthService($GLOBALS['pdo']))->loginStaff($user);
}

function logout_staff(): void
{
    (new AuthService($GLOBALS['pdo']))->logoutStaff();
}
