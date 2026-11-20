<?php
/**
 * One-time helper to create the first MSWDO staff/admin account in the isolated test database.
 * Run from the CLI only (php seed_admin.php) with process-level test settings: APP_ENV=testing,
 * DB_HOST=localhost|127.0.0.1, DB_NAME=virac_solo_parent_test, a non-root DB_USER, DB_PASS and
 * SMS_PROVIDER=test. It refuses to run otherwise; config/local.php is not used for this check.
 */
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
require __DIR__ . '/../config/database.php';

$username = 'admin';
$password = 'Admin@12345';
$fullName = 'MSWDO Virac Administrator';
$email    = 'mswdo.virac@example.gov.ph';

$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    echo "An account with username '$username' already exists. Nothing to do.\n";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $pdo->prepare('INSERT INTO users (full_name, email, username, password_hash, role) VALUES (?, ?, ?, ?, ?)');
$stmt->execute([$fullName, $email, $username, $hash, 'admin']);

echo "Admin account created.\n";
echo "Username: $username\n";
echo "Password: $password\n";
echo "Please log in at /login.php and change this password, then delete database/seed_admin.php.\n";
