<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/csrf.php';
require __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $user = current_user($pdo);
    if ($user) {
        audit_log($pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Logout');
    }
    logout_user();
}
redirect(base_url('index.php'));
