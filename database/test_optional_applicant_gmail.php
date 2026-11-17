<?php
/** CLI only. Temporary tables isolate every write from real accounts; no SMS is sent. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
require __DIR__ . '/../config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/services/AuthService.php';
require APP_ROOT . '/services/UserManagementService.php';
function gmail_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
try {
    foreach (['users', 'applicants', 'audit_logs'] as $table) {
        $sql = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $sql = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $sql);
        $sql = preg_replace('/,\s*CONSTRAINT\s+[^\n]+/', '', $sql);
        $pdo->exec($sql);
    }
    $auth = new AuthService($pdo);
    $users = $auth->userModel();
    $form = ['fullName' => 'Test Parent', 'mobile' => '09171234567', 'email' => ''];
    $password = 'FixtureOnly123';
    $validate = fn(array $changes) => $auth->applicantRegistrationError(array_replace($form, $changes), $password, $password);
    gmail_check($validate([]) === '', 'Signup without Gmail or username was rejected.');
    foreach (['not-email', 'name@yahoo.com', 'name@gmail.com.example.org', 'name@@gmail.com', str_repeat('a', 150) . '@gmail.com'] as $email) {
        gmail_check($validate(['email' => $email]) !== '', 'Invalid Gmail was accepted: ' . $email);
    }
    gmail_check($validate(['email' => 'Parent@GMAIL.COM']) === '', 'Valid mixed-case Gmail rejected.');
    foreach (['mobile' => '', 'fullName' => ''] as $key => $value) {
        gmail_check($validate([$key => $value]) !== '', 'Missing required field was accepted.');
    }
    gmail_check($auth->applicantRegistrationError($form, $password, 'Different123') !== '', 'Password mismatch accepted.');
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $id = $users->createApplicant($form['fullName'], '', null, $hash, $form['mobile']);
    $users->markMobileVerified($id);
    $secondId = $users->createApplicant('Second Parent', '', null, $hash, '09181234567');
    $users->markMobileVerified($secondId);
    gmail_check($users->findById($id)['username'] === null && $users->findById($id)['email'] === '', 'Blank signup fields stored incorrectly.');
    gmail_check($users->findApplicantForLogin('+639171234567')['id'] === $id, 'Mobile login failed without Gmail.');
    gmail_check($users->findByUsernameOrMobile('09181234567')['id'] === $secondId, 'Mobile password recovery failed.');
    gmail_check($validate(['mobile' => '+639171234567']) !== '', 'Duplicate mobile accepted.');
    $gmailId = $users->createApplicant('Gmail Parent', 'parent@gmail.com', null, $hash, '09191234567');
    $users->markMobileVerified($gmailId);
    gmail_check($users->findApplicantForLogin('PARENT@GMAIL.COM')['id'] === $gmailId, 'Gmail login failed.');
    gmail_check($users->findByUsernameOrMobile('parent@gmail.com')['id'] === $gmailId, 'Gmail recovery failed.');
    gmail_check($validate(['mobile' => '09201234567', 'email' => 'PARENT@gmail.com']) !== '', 'Duplicate Gmail accepted.');
    gmail_check($auth->verifyCredentials($users->findById($gmailId), $password), 'Stored password does not verify.');
    $legacyId = $users->createApplicant('Legacy Parent', '', 'legacy.parent', $hash, '09211234567');
    gmail_check($users->findApplicantForLogin('legacy.parent')['id'] === $legacyId, 'Existing username login broken.');
    $users->createWithRole('Staff', 'staff@gmail.com', 'legacy.staff', $hash, 'mswd_social_worker');
    gmail_check($users->findApplicantForLogin('staff@gmail.com') === null, 'Staff account accepted by applicant login.');
    gmail_check($users->findStaffByUsername('legacy.staff') !== null, 'Staff username login broken.');
    gmail_check($users->findApplicantForLogin('') === null && $users->findByUsernameOrMobile('') === null, 'Empty email matched an account.');
    $admin = ['id' => 0, 'full_name' => 'Test administrator', 'role' => 'admin'];
    $management = new UserManagementService($pdo);
    [$error] = $management->setActive($admin, $users->findById($id), false);
    gmail_check($error === '', 'Cannot manage an applicant without username.');
    [$error] = $management->updateAccount($admin, $users->findById($secondId), ['full_name' => 'Second Parent', 'mobile' => '09181234567', 'email' => 'parent@gmail.com']);
    gmail_check($error !== '', 'Admin edit allowed a duplicate login email.');
    // Simulate historical shared contact emails: refuse ambiguous email sign-in/recovery.
    $users->createApplicant('Historical duplicate', 'parent@gmail.com', null, $hash, '09221234567');
    gmail_check($users->findApplicantForLogin('parent@gmail.com') === null && $users->findByUsernameOrMobile('parent@gmail.com') === null, 'Ambiguous Gmail matched an arbitrary account.');
    gmail_check((int) $pdo->query('SELECT COUNT(*) FROM applicants')->fetchColumn() === 5, 'Applicant profiles missing.');
    echo "PASS: optional Gmail, multiple username-free accounts, validation, duplicate contacts, mobile/Gmail login and recovery, legacy/staff login and account management. No live accounts created.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users, applicants, audit_logs');
}
