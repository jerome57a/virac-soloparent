<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $staff = current_staff($pdo);
    if ($staff) {
        audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Logout');
    }
    logout_staff();
}
redirect(base_url('admin/login.php'));
