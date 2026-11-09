<?php
/** CLI only; all writes use connection-local temporary tables. No SMS or live accounts. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/services/UserManagementService.php';
function account_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$tables = ['users', 'applicants', 'audit_logs'];
try {
    foreach ($tables as $table) {
        $sql = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $sql = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $sql);
        $sql = preg_replace('/,\s*CONSTRAINT\s+[^\n]+/', '', $sql);
        $pdo->exec($sql);
    }
    $service = new UserManagementService($pdo);
    $auth = new AuthService($pdo);
    $users = new User($pdo);
    $admin = ['id' => 0, 'full_name' => 'Test administrator', 'role' => 'admin'];
    $base = ['fullName' => 'Test Parent', 'username' => 'test.parent', 'mobile' => '09171234567', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123', 'role' => 'applicant'];
    foreach ([['mobile' => ''], ['mobile' => '123'], ['username' => '123456'], ['username' => '123_45'], ['username' => 'ab'], ['username' => 'not-allowed'], ['password_confirm' => 'different'], ['role' => 'unknown']] as $changes) {
        [$error, , $id] = $service->create($admin, array_replace($base, $changes));
        account_check($error !== '' && $id === null, 'Invalid creation was accepted: ' . json_encode($changes));
    }
    account_check($auth->applicantRegistrationError(array_replace($base, ['username' => '123_45']), $base['password'], $base['password_confirm']) !== '', 'Public registration accepted username without a letter.');
    [$error, , $id] = $service->create($admin, $base + ['mustChange' => '1', 'email' => 'ignored@example.test']);
    account_check($error === '' && $id !== null, 'Applicant creation failed: ' . $error);
    $applicant = $users->findById($id);
    account_check($applicant['role'] === 'applicant' && $applicant['email'] === '' && !$applicant['mobile_verified_at'] && !$applicant['must_change_password'], 'Applicant registration state is incorrect.');
    account_check(password_verify($base['password'], $applicant['password_hash']), 'Password was not hashed correctly.');
    account_check((int) $pdo->query('SELECT COUNT(*) FROM applicants')->fetchColumn() === 1, 'Applicant profile missing.');
    [$error] = $service->create($admin, array_replace($base, ['username' => 'second.parent', 'mobile' => '+639171234567']));
    account_check($error !== '', 'Duplicate normalized mobile accepted.');
    [$error] = $service->create($admin, array_replace($base, ['mobile' => '09181234567']));
    account_check($error !== '', 'Duplicate username accepted.');
    [$error] = $service->updateAccount($admin, $applicant, ['full_name' => 'Updated Parent', 'email' => '', 'mobile' => $applicant['mobile']]);
    account_check($error === '', 'Applicant without email cannot be edited.');
    foreach (['mswd_social_worker', 'admin'] as $index => $role) {
        $input = array_replace($base, ['role' => $role, 'username' => 'staff-' . $index, 'email' => '', 'mobile' => '', 'mustChange' => '1']);
        unset($input['email'], $input['mobile']);
        [$error, , $newId] = $service->create($admin, $input);
        account_check($error === '' && $newId !== null, 'Personnel creation failed: ' . $error);
        $personnel = $users->findById($newId);
        account_check($personnel['role'] === $role && $personnel['must_change_password'] && $personnel['mobile'] === null && $personnel['email'] === '', 'Personnel account state incorrect.');
        [$error] = $service->updateAccount($admin, $personnel, ['full_name' => 'Updated Personnel', 'email' => '', 'mobile' => '']);
        account_check($error === '', 'Personnel without contacts cannot be edited.');
    }
    account_check((int) $pdo->query('SELECT COUNT(*) FROM applicants')->fetchColumn() === 1, 'Personnel received an applicant profile.');
    account_check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'Unexpected account count.');
    echo "PASS: all three roles, shared signup rules, confirmation, duplicate contacts, profiles, password hashing and first-sign-in state. No live accounts created.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users, applicants, audit_logs');
}
