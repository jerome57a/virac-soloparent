<?php
/** CLI only; all writes use connection-local temporary tables. No SMS or live accounts. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
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
    // Admin-created applicants now need a username (their sign-in identifier), exactly like public sign-up.
    $base = ['fullName' => 'Test Parent', 'username' => 'test.parent', 'email' => '', 'mobile' => '09171234567', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123', 'role' => 'applicant'];
    foreach ([['mobile' => ''], ['mobile' => '123'], ['email' => 'invalid'], ['email' => 'parent@example.test'], ['email' => 'parent@gmail.com.example.test'], ['password_confirm' => 'different'], ['role' => 'unknown'],
              ['username' => ''], ['username' => 'ab'], ['username' => 'bad name'], ['username' => 'bad@name'], ['username' => '09171234567'], ['username' => '12345'], ['username' => 'ends.with.dot.'], ['username' => str_repeat('a', 31)]] as $changes) {
        [$error, , $id] = $service->create($admin, array_replace($base, $changes));
        account_check($error !== '' && $id === null, 'Invalid creation was accepted: ' . json_encode($changes));
        if (isset($changes['username'])) {
            // A bad username must be refused for the username, not for some other reason or as a "taken" name.
            account_check(stripos($error, 'username') !== false && !str_contains($error, 'already taken'), 'Invalid username refused with the wrong message: ' . json_encode($changes) . ' -> ' . $error);
        }
    }
    [$error, $success, $id] = $service->create($admin, $base + ['mustChange' => '1']);
    account_check($error === '' && $id !== null, 'Applicant creation failed: ' . $error);
    $applicant = $users->findById($id);
    account_check($applicant['role'] === 'applicant' && $applicant['email'] === '' && $applicant['username'] === 'test.parent' && $applicant['mobile_verified_at'] && !$applicant['must_change_password'], 'Applicant registration state is incorrect.');
    account_check(str_contains($success, 'test.parent') && str_contains($success, 'username'), 'Success message does not name the username.');
    account_check($users->findApplicantForLogin('test.parent')['id'] === $id, 'Admin-created applicant cannot sign in by username.');
    account_check($users->findApplicantForLogin('+639171234567') === null && $users->findApplicantForLogin('09171234567') === null, 'Admin-created applicant with a username must not sign in by mobile.');
    account_check(password_verify($base['password'], $applicant['password_hash']), 'Password was not hashed correctly.');
    account_check((int) $pdo->query('SELECT COUNT(*) FROM applicants')->fetchColumn() === 1, 'Applicant profile missing.');
    [$error] = $service->create($admin, array_replace($base, ['username' => 'other.parent', 'mobile' => '+639171234567']));
    account_check(str_contains($error, 'mobile number') && str_contains($error, 'already'), 'Duplicate normalized mobile not refused for the mobile number: ' . $error);
    [$error] = $service->create($admin, array_replace($base, ['mobile' => '09211234567']));
    account_check(str_contains($error, 'username is already taken'), 'Duplicate username not refused as taken: ' . $error);
    [$error] = $service->create($admin, array_replace($base, ['username' => 'TEST.PARENT', 'mobile' => '09211234567']));
    account_check(str_contains($error, 'username is already taken'), 'Username differing only by case not refused as taken (the unique index is case-insensitive): ' . $error);
    [$error, , $gmailId] = $service->create($admin, array_replace($base, ['username' => 'gmail.parent', 'mobile' => '09181234567', 'email' => '  Parent@GMAIL.COM  ']));
    account_check($error === '' && $gmailId !== null, 'Applicant with Gmail could not be created: ' . $error);
    account_check($users->findById($gmailId)['email'] === 'parent@gmail.com', 'Gmail was not normalized and stored.');
    account_check($users->findApplicantForLogin('gmail.parent')['id'] === $gmailId, 'Admin-created Gmail applicant cannot sign in by username.');
    account_check($users->findApplicantForLogin('PARENT@gmail.com') === null, 'Gmail address must not be a sign-in identifier for an account with a username.');
    account_check($users->findByUsernameOrMobile('parent@gmail.com')['id'] === $gmailId, 'Admin-created Gmail password recovery failed.');
    [$error] = $service->create($admin, array_replace($base, ['username' => 'dup.gmail', 'mobile' => '09191234567', 'email' => 'PARENT@gmail.com']));
    account_check(str_contains($error, 'Gmail address is already registered'), 'Duplicate Gmail not refused for the Gmail address: ' . $error);
    [$error, , $blankId] = $service->create($admin, array_replace($base, ['username' => 'blank.parent', 'mobile' => '09201234567']));
    account_check($error === '' && $blankId !== null, 'Multiple accounts without Gmail were rejected.');
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
        [$error] = $service->create($admin, $input);
        account_check(str_contains($error, 'username is already taken'), 'Duplicate personnel username not refused as taken: ' . $error);
        [$error] = $service->create($admin, array_replace($input, ['username' => '']));
        account_check(str_contains($error, 'Username must be'), 'Personnel account without a username not refused for the username: ' . $error);
    }
    // Usernames stay unique across all roles, in both directions.
    [$error] = $service->create($admin, array_replace($base, ['username' => 'staff-0', 'mobile' => '09221234567']));
    account_check(str_contains($error, 'username is already taken'), 'Applicant taking a personnel username not refused as taken: ' . $error);
    [$error] = $service->create($admin, ['fullName' => 'Staff Clash', 'username' => 'test.parent', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123', 'role' => 'mswd_social_worker']);
    account_check(str_contains($error, 'username is already taken'), 'Personnel taking an applicant username not refused as taken: ' . $error);
    account_check((int) $pdo->query('SELECT COUNT(*) FROM applicants')->fetchColumn() === 3, 'Personnel received an applicant profile.');
    account_check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 5, 'Unexpected account count.');
    echo "PASS: all three roles, applicant usernames (required, validated, unique across roles and case-insensitive), shared signup rules, confirmation, duplicate contacts, profiles, password hashing and first-sign-in state. No live accounts created.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users, applicants, audit_logs');
}
