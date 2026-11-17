<?php
/**
 * OFFLINE login-identifier checks. In-memory SQLite only: no MariaDB/MySQL, no config/app.php, no config/database.php,
 * no storage, no network, no HTTP, no SMS. Safe to run anywhere; it cannot reach any real database.
 *
 *   php database/test_login_identifiers_offline.php
 *
 * Policy under test (all three actors sign in with username + password):
 *   - Administrator and MSWD staff: username (staff login form, User::findStaffByUsername).
 *   - Applicant: username (applicant login form, User::findApplicantForLogin). Email and mobile number are contact
 *     details, not login identifiers.
 *   - Transitional rule: an applicant account created before usernames existed (username still empty) may sign in
 *     once with its Gmail address or confirmed mobile number, and must then choose a username
 *     (AuthService::chooseUsername). An account that has a username is never found by email or mobile.
 *   - Registration requires a valid, unique username (AuthService::usernameError / applicantRegistrationError).
 *
 * Also covers AuthController::attemptLogin (shared by both forms: correct/wrong password, unknown, inactive, locked,
 * counters, audit rows) and AuthController::handleStaffLogin rejection paths for each portal.
 *
 * The passwords below are throwaway constants used only inside this file's in-memory database.
 * The sign-in SUCCESS path ends in a redirect and session start, which cannot run in the CLI; it is covered through
 * attemptLogin() returning '' plus the lookup and the portal-role rule. The gated redirect of requireUsername() also
 * ends in redirect(); only its non-redirecting cases are exercised here.
 * SQLite compares text case-sensitively whereas MariaDB's utf8mb4_unicode_ci does not, so case variants are not tested.
 *
 * The file runs itself twice more (as a child process) because AUTH_PORTAL is a constant: once as 'staff', once as 'admin'.
 */
const TEST_PASSWORD = 'Fixture-Pass-1';
const OTHER_PASSWORD = 'Another-Pass-2';

$mode = $argv[1] ?? 'parent';
$portal = $argv[2] ?? '';

require_once __DIR__ . '/../config/auth.php';          // constants only
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AuthService.php';

/** PDO over SQLite that understands the few MySQL spellings the login code path uses (NOW(), INTERVAL). */
class OfflinePdo extends PDO
{
    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        $query = preg_replace('/NOW\(\)\s*-\s*INTERVAL\s+1\s+DAY/i', "datetime('now','-1 day')", $query);
        $query = preg_replace('/NOW\(\)\s*-\s*INTERVAL\s+\?\s+SECOND/i', "datetime('now', '-' || ? || ' seconds')", $query);
        $query = preg_replace('/NOW\(\)/i', "datetime('now')", $query);
        $query = preg_replace('/^\s*INSERT\s+IGNORE\s+INTO/i', 'INSERT OR IGNORE INTO', $query);
        return parent::prepare($query, $options);
    }
}

/**
 * OfflinePdo that simulates a race: just before the next INSERT INTO users / UPDATE users SET username, another request
 * "wins" by inserting a row with $raceUsername. The statement then hits the real UNIQUE index (SQLSTATE 23000 with the
 * username now existing).
 */
class RacePdo extends OfflinePdo
{
    public ?string $raceUsername = null;

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = [])
    {
        if ($this->raceUsername !== null && preg_match('/^\s*(INSERT\s+INTO\s+users|UPDATE\s+users\s+SET\s+username)/i', $query)) {
            $name = $this->raceUsername;
            $this->raceUsername = null;
            $this->exec("INSERT INTO users (full_name, username, password_hash) VALUES ('Racer', " . $this->quote($name) . ", 'x')");
        }
        return parent::prepare($query, $options);
    }
}

/** The username 'check.fail' is refused by a CHECK constraint: an integrity error (SQLSTATE 23000) that is NOT a duplicate username. */
function offline_pdo(string $class = 'OfflinePdo'): PDO
{
    $pdo = new $class('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY, full_name TEXT NOT NULL, email TEXT NOT NULL DEFAULT '', username TEXT UNIQUE,
        password_hash TEXT NOT NULL, mobile TEXT, mobile_verified_at TEXT, role TEXT NOT NULL DEFAULT 'applicant',
        is_active INTEGER NOT NULL DEFAULT 1, failed_login_attempts INTEGER NOT NULL DEFAULT 0, locked_until TEXT,
        auth_version INTEGER NOT NULL DEFAULT 0, must_change_password INTEGER NOT NULL DEFAULT 0, last_login_at TEXT,
        CHECK (username IS NULL OR username <> 'check.fail'))");
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, user_id INTEGER, actor_name TEXT, actor_role TEXT, action TEXT,
        entity_type TEXT, entity_id INTEGER, details TEXT, ip_address TEXT, user_agent TEXT)');
    $pdo->exec('CREATE TABLE applicants (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL UNIQUE)');
    $pdo->exec('CREATE TABLE rate_limit_hits (id INTEGER PRIMARY KEY, bucket TEXT NOT NULL, hit_at TEXT NOT NULL)');
    $hash = password_hash(TEST_PASSWORD, PASSWORD_DEFAULT);
    $verified = date('Y-m-d H:i:s');
    $ins = $pdo->prepare('INSERT INTO users (id, full_name, email, username, password_hash, mobile, mobile_verified_at, role, is_active, locked_until) VALUES (?,?,?,?,?,?,?,?,?,?)');
    foreach ([
        [1, 'Test Administrator', 'test.admin@example.test', 'test.admin', $hash, null, null, 'admin', 1, null],
        [2, 'Test Staff Reviewer', 'test.staff@example.test', 'test.staff', $hash, '09000000002', $verified, 'mswd_social_worker', 1, null],
        // Applicants created before usernames existed (as in the seed): username empty, signed in by email / mobile.
        [3, 'Fixture Applicant One', '', null, $hash, '09000000003', $verified, 'applicant', 1, null],
        [4, 'Fixture Applicant Two', 'fixture.two@example.test', null, $hash, '09000000004', $verified, 'applicant', 1, null],
        [5, 'Inactive Staff', 'inactive.staff@example.test', 'inactive.staff', $hash, null, null, 'mswd_social_worker', 0, null],
        [6, 'Inactive Applicant', 'inactive.applicant@example.test', null, $hash, '09000000006', $verified, 'applicant', 0, null],
        [7, 'Locked Staff', 'locked.staff@example.test', 'locked.staff', $hash, null, null, 'mswd_social_worker', 1, date('Y-m-d H:i:s', time() + 600)],
        [8, 'Legacy Applicant', 'legacy@example.test', 'legacy.parent', $hash, '09000000008', null, 'applicant', 1, null],
        [9, 'Locked Admin', 'locked.admin@example.test', 'locked.admin', $hash, null, null, 'admin', 1, date('Y-m-d H:i:s', time() + 600)],
        // New-style applicant: has a username, so email and mobile are contact details only.
        [10, 'New Parent', 'new.parent@example.test', 'new.parent', $hash, '09000000010', $verified, 'applicant', 1, null],
        // Pre-username applicant whose mobile was never confirmed.
        [12, 'Unconfirmed Legacy', '', null, $hash, '09000000012', null, 'applicant', 1, null],
        // Inactive applicant who has a username.
        [13, 'Inactive Named Applicant', 'inactive.named@example.test', 'inactive.named', $hash, '09000000013', $verified, 'applicant', 0, null],
    ] as $row) {
        $ins->execute($row);
    }
    return $pdo;
}

$pass = 0; $fail = 0;
function check(bool $ok, string $name): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    if (!$ok) { echo "FAIL: $name\n"; }
}

// ------------------------------------------------------------------------------------------------------------
if ($mode === 'parent') {
    $pdo = offline_pdo();
    $users = new User($pdo);
    $auth = new AuthService($pdo);
    $id = static fn(?array $r): ?int => $r === null ? null : (int) $r['id'];

    // Administrator and MSWD staff sign in by username (staff login form).
    check($id($users->findStaffByUsername('test.admin')) === 1 && $users->findStaffByUsername('test.admin')['role'] === 'admin', 'administrator found by username');
    check($id($users->findStaffByUsername('test.staff')) === 2 && $users->findStaffByUsername('test.staff')['role'] === 'mswd_social_worker', 'MSWD staff found by username');
    check($users->findStaffByUsername('nobody') === null, 'unknown staff username rejected');
    check($users->findStaffByUsername('') === null, 'empty staff username rejected');
    check($users->findStaffByUsername('legacy.parent') === null && $users->findStaffByUsername('new.parent') === null, 'applicant usernames are not staff identifiers');
    check($users->findStaffByUsername('test.admin@example.test') === null && $users->findStaffByUsername('test.staff@example.test') === null, 'staff email is not a staff identifier');
    check($users->findStaffByUsername('09000000002') === null, 'staff mobile is not a staff identifier');
    check($users->findStaffByUsername('09000000003') === null && $users->findStaffByUsername('fixture.two@example.test') === null, 'applicant email/mobile never match the staff form');

    // Applicants sign in by username; email and mobile are no longer login identifiers.
    check($id($users->findApplicantForLogin('new.parent')) === 10, 'applicant found by username');
    check($id($users->findApplicantForLogin('legacy.parent')) === 8, 'applicant (older account) found by username');
    check($users->findApplicantForLogin('new.parent@example.test') === null, 'applicant with a username is not found by email');
    check($users->findApplicantForLogin('09000000010') === null && $users->findApplicantForLogin('+639000000010') === null, 'applicant with a username is not found by mobile');
    check($users->findApplicantForLogin('legacy@example.test') === null && $users->findApplicantForLogin('09000000008') === null, 'older account with a username is not found by email or mobile');
    check($users->findApplicantForLogin('') === null, 'empty applicant identifier rejected (an empty email must not match)');
    check($users->findApplicantForLogin('nobody') === null && $users->findApplicantForLogin('nobody@example.test') === null, 'unknown applicant identifier rejected');

    // Transitional rule: only accounts whose username is still empty may use email / confirmed mobile.
    check($id($users->findApplicantForLogin('fixture.two@example.test')) === 4, 'username-less applicant found by email (transitional)');
    check($id($users->findApplicantForLogin('09000000003')) === 3 && $id($users->findApplicantForLogin('+639000000003')) === 3, 'username-less applicant found by confirmed mobile, both forms (transitional)');
    check($id($users->findApplicantForLogin('0900 000 0004')) === 4, 'username-less applicant mobile with spaces normalised (transitional)');
    check($users->findApplicantForLogin('09000000012') === null, 'username-less applicant with an unconfirmed mobile is not found');

    // Role separation between the two forms.
    check($users->findApplicantForLogin('test.admin') === null && $users->findApplicantForLogin('test.staff') === null, 'staff usernames do not sign in through the applicant form');
    check($users->findApplicantForLogin('test.admin@example.test') === null && $users->findApplicantForLogin('test.staff@example.test') === null, 'staff emails do not sign in through the applicant form');
    check($users->findApplicantForLogin('09000000002') === null, 'staff mobile does not sign in through the applicant form');

    // Username rule used at registration and when choosing one.
    foreach (['juan.delacruz', 'abc', 'a1b', 'Maria_Santos-1', 'user123'] as $ok) {
        check($auth->usernameError($ok) === null, "username accepted: $ok");
    }
    foreach (['ab', str_repeat('a', 31), '09171234567', '12345', '+639171234567', 'bad name', 'bad@name', 'name!', '.abc', '-abc', 'abc.', 'abc-', 'ünïcode', ''] as $bad) {
        check($auth->usernameError($bad) !== null, 'username rejected: ' . ($bad === '' ? '(empty)' : $bad));
    }
    check($auth->usernameError(str_repeat('a', 30)) === null, 'username of the maximum length accepted');
    check(str_contains((string) $auth->usernameError('test.admin'), 'already taken') && str_contains((string) $auth->usernameError('new.parent'), 'already taken'), 'taken usernames (staff and applicant) rejected');

    // Registration: username required and validated for public sign-up; unchanged when absent (administrator-created accounts).
    $form = ['fullName' => 'New Person', 'username' => 'new.person', 'email' => '', 'mobile' => '09171234567'];
    check($auth->applicantRegistrationError($form, 'Valid-Pass-123', 'Valid-Pass-123') === '', 'registration with a valid unique username accepted');
    check($auth->applicantRegistrationError(['username' => ''] + $form, 'Valid-Pass-123', 'Valid-Pass-123') !== '', 'registration without a username refused');
    check($auth->applicantRegistrationError(['username' => 'bad name'] + $form, 'Valid-Pass-123', 'Valid-Pass-123') !== '', 'registration with a malformed username refused');
    check($auth->applicantRegistrationError(['username' => 'new.parent'] + $form, 'Valid-Pass-123', 'Valid-Pass-123') !== '', 'registration with a taken username refused');
    check($auth->applicantRegistrationError(['username' => '09171234567'] + $form, 'Valid-Pass-123', 'Valid-Pass-123') !== '', 'registration with a mobile-number-like username refused');
    $adminForm = ['fullName' => 'Admin Made', 'email' => '', 'mobile' => '09171234568'];
    check($auth->applicantRegistrationError($adminForm, 'Valid-Pass-123', 'Valid-Pass-123') === '', 'administrator-created account rules unchanged (no username key)');
    $userId = $users->createApplicant('New Person', '', 'new.person', password_hash('Valid-Pass-123', PASSWORD_DEFAULT), '09171234567');
    check($id($users->findApplicantForLogin('new.person')) === $userId, 'newly registered applicant signs in by username');
    check($users->findApplicantForLogin('09171234567') === null, 'newly registered applicant is not found by mobile');
    check($auth->applicantRegistrationError($form, 'Valid-Pass-123', 'Valid-Pass-123') !== '', 'second registration with the same username refused');
    $duplicate = false;
    try { $users->createApplicant('Race', '', 'new.person', password_hash('x', PASSWORD_DEFAULT), '09171234569'); } catch (PDOException $e) { $duplicate = $e->getCode() === '23000'; }
    check($duplicate, 'database unique key refuses a duplicate username (registration race)');

    // Choosing a username (accounts that predate usernames).
    $legacy = $users->findById(3);
    check($auth->chooseUsername($legacy, 'bad name')[0] !== '' && $users->findById(3)['username'] === null, 'invalid username not saved');
    check(str_contains($auth->chooseUsername($legacy, 'test.admin')[0], 'already taken') && $users->findById(3)['username'] === null, 'taken username not saved');
    [$err, $ok] = $auth->chooseUsername($legacy, 'applicant.one');
    check($err === '' && $ok !== '' && $users->findById(3)['username'] === 'applicant.one', 'username-less applicant can choose a username');
    check($id($users->findApplicantForLogin('applicant.one')) === 3, 'after choosing, the applicant signs in by username');
    check($users->findApplicantForLogin('09000000003') === null, 'after choosing, the mobile number no longer signs in');
    check($auth->chooseUsername($users->findById(3), 'another.name')[0] !== '' && $users->findById(3)['username'] === 'applicant.one', 'a username cannot be changed once set');
    check($auth->chooseUsername($users->findById(2), 'staff.try')[0] !== '' && $users->findById(2)['username'] === 'test.staff', 'staff accounts cannot use the applicant username step');
    check($users->setUsernameIfEmpty(1, 'admin.try') === false && $users->findById(1)['username'] === 'test.admin', 'model refuses to set a username on a non-applicant');
    check($id($users->findApplicantForLogin('fixture.two@example.test')) === 4, 'other username-less applicants are unaffected');

    // Administrator-created applicants (UserManagementService::create): a username is now required and validated.
    require_once __DIR__ . '/../services/UserManagementService.php';
    $adminPdo = offline_pdo();
    $manager = new UserManagementService($adminPdo);
    $adminUsers = new User($adminPdo);
    $actor = ['id' => 1, 'full_name' => 'Test Administrator', 'role' => 'admin'];
    $applicantIn = ['role' => 'applicant', 'fullName' => 'Admin Made Parent', 'username' => 'admin.made', 'email' => '', 'mobile' => '09171230001',
        'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123'];
    foreach ([['username' => ''], ['username' => '  '], ['username' => 'ab'], ['username' => 'bad name'], ['username' => 'bad@name'], ['username' => '09171230001'],
              ['username' => '12345'], ['username' => 'ends.with.dot.'], ['username' => str_repeat('a', 31)], ['username' => 'test.admin'], ['username' => 'new.parent']] as $changes) {
        [$e, , $newId] = $manager->create($actor, array_replace($applicantIn, $changes));
        check($e !== '' && $newId === null, 'admin-created applicant refused: ' . json_encode($changes));
    }
    unset($applicantIn['username']);
    [$e, , $newId] = $manager->create($actor, $applicantIn);
    check($e !== '' && $newId === null, 'admin-created applicant without a username key refused');
    $applicantIn['username'] = 'admin.made';
    [$e, $msg, $newId] = $manager->create($actor, $applicantIn);
    $made = $newId === null ? null : $adminUsers->findById($newId);
    check($e === '' && $made !== null && $made['username'] === 'admin.made' && $made['role'] === 'applicant' && $made['mobile_verified_at'] !== null && (int) $made['must_change_password'] === 0, 'admin-created applicant stored with its username');
    check(str_contains($msg, 'admin.made') && str_contains($msg, 'username'), 'success message names the username');
    check($adminUsers->findApplicantForLogin('admin.made')['id'] === $newId, 'admin-created applicant signs in by username');
    check($adminUsers->findApplicantForLogin('09171230001') === null && $adminUsers->findApplicantForLogin('+639171230001') === null, 'admin-created applicant is not found by mobile');
    check(password_verify('FixtureOnly123', $made['password_hash']), 'admin-created applicant password hashed');
    [$e] = $manager->create($actor, array_replace($applicantIn, ['mobile' => '09171230002']));
    check($e !== '', 'admin-created applicant refused: username already taken by another applicant');
    [$e] = $manager->create($actor, array_replace($applicantIn, ['username' => 'good.name', 'mobile' => '+639171230001']));
    check($e !== '', 'admin-created applicant refused: mobile already used');
    [$e, , $gmailMade] = $manager->create($actor, array_replace($applicantIn, ['username' => 'with.gmail', 'mobile' => '09171230003', 'email' => ' Parent@GMAIL.com ']));
    check($e === '' && $gmailMade !== null && $adminUsers->findById($gmailMade)['email'] === 'parent@gmail.com' && $adminUsers->findApplicantForLogin('parent@gmail.com') === null, 'admin-created applicant with Gmail: stored, normalised, not a sign-in identifier');
    // Personnel creation is unchanged, and usernames stay unique across roles in both directions.
    $staffIn = ['role' => 'mswd_social_worker', 'fullName' => 'New Staff', 'username' => 'new.staff', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123'];
    [$e, , $staffId] = $manager->create($actor, $staffIn);
    check($e === '' && $staffId !== null && $adminUsers->findStaffByUsername('new.staff')['role'] === 'mswd_social_worker' && $adminUsers->findById($staffId)['mobile'] === null, 'personnel creation unchanged');
    [$e] = $manager->create($actor, array_replace($staffIn, ['username' => '']));
    check($e !== '', 'personnel without a username still refused');
    [$e] = $manager->create($actor, array_replace($staffIn, ['username' => 'ab']));
    check($e !== '', 'personnel with a too-short username still refused');
    [$e] = $manager->create($actor, array_replace($staffIn, ['username' => 'admin.made']));
    check($e !== '', 'personnel cannot take an applicant username');
    [$e] = $manager->create($actor, array_replace($applicantIn, ['username' => 'new.staff', 'mobile' => '09171230004']));
    check($e !== '', 'applicant cannot take a personnel username');
    check(UserManagementService::APPLICANT_USERNAME_PATTERN_HTML !== '' && preg_match('/\A' . str_replace('/', '\/', UserManagementService::APPLICANT_USERNAME_PATTERN_HTML) . '\z/', 'valid.name') === 1
        && preg_match('/\A' . UserManagementService::APPLICANT_USERNAME_PATTERN_HTML . '\z/', '12345') !== 1 && preg_match('/\A' . UserManagementService::APPLICANT_USERNAME_PATTERN_HTML . '\z/', 'ab') !== 1
        && preg_match('/\A' . UserManagementService::APPLICANT_USERNAME_PATTERN_HTML . '\z/', 'ends.with.dot.') !== 1 && preg_match('/\A' . UserManagementService::APPLICANT_USERNAME_PATTERN_HTML . '\z/', str_repeat('a', 31)) !== 1
        && preg_match('/\A' . UserManagementService::APPLICANT_USERNAME_PATTERN_HTML . '\z/', str_repeat('a', 30)) === 1, 'the create form\'s HTML pattern matches the server rule');

    // Duplicate-key handling: SQLSTATE 23000 is reported as "username taken" ONLY when the username now exists;
    // any other integrity failure is rethrown unchanged. (UserManagementService and AuthService here; AuthController in the child run.)
    $sqlstate = static function (callable $fn): ?string { try { $fn(); } catch (PDOException $e) { return (string) $e->getCode(); } return null; };
    $racePdo = offline_pdo(RacePdo::class);
    $raceManager = new UserManagementService($racePdo);
    $racers = static fn(PDO $p, string $name): int => (int) $p->query("SELECT COUNT(*) FROM users WHERE username = " . $p->quote($name))->fetchColumn();
    $raceIn = ['role' => 'applicant', 'fullName' => 'Race Parent', 'username' => 'race.name', 'email' => '', 'mobile' => '09175550001', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123'];
    $racePdo->raceUsername = 'race.name';
    [$e, , $newId] = $raceManager->create($actor, $raceIn);
    check($e === 'That username is already taken.' && $newId === null && $racers($racePdo, 'race.name') === 1, 'admin create: a username taken at insert time is reported as taken (one row remains)');
    $code = $sqlstate(fn() => $raceManager->create($actor, array_replace($raceIn, ['username' => 'check.fail', 'mobile' => '09175550002'])));
    check($code === '23000' && $racers($racePdo, 'check.fail') === 0, 'admin create: a non-duplicate integrity failure is rethrown, not reported as a taken username');
    $racePdo->raceUsername = 'race.staff';
    [$e, , $newId] = $raceManager->create($actor, ['role' => 'mswd_social_worker', 'fullName' => 'Race Staff', 'username' => 'race.staff', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123']);
    check($e === 'That username is already taken.' && $newId === null, 'admin create (personnel): a username taken at insert time is reported as taken');
    $code = $sqlstate(fn() => $raceManager->create($actor, ['role' => 'admin', 'fullName' => 'Check Fail', 'username' => 'check.fail', 'password' => 'FixtureOnly123', 'password_confirm' => 'FixtureOnly123']));
    check($code === '23000', 'admin create (personnel): a non-duplicate integrity failure is rethrown');
    $raceAuth = new AuthService($racePdo);
    $racePdo->raceUsername = 'race.pick';
    [$e, $ok] = $raceAuth->chooseUsername($raceAuth->userModel()->findById(3) ?? ['id' => 3, 'role' => 'applicant', 'username' => null, 'full_name' => 'x'], 'race.pick');
    check($e === 'That username is already taken. Please choose another.' && $ok === '', 'choose username: a username taken at update time is reported as taken');
    $racePdo->exec("INSERT INTO users (id, full_name, username, password_hash, role) VALUES (950, 'Pick Parent', NULL, 'x', 'applicant')");
    $code = $sqlstate(fn() => $raceAuth->chooseUsername(['id' => 950, 'role' => 'applicant', 'username' => null, 'full_name' => 'Pick Parent'], 'check.fail'));
    check($code === '23000', 'choose username: a non-duplicate integrity failure is rethrown');
    check($raceAuth->userModel()->findById(950)['username'] === null, 'choose username: the failed update left the account without a username');

    // requireUsername(): only its non-redirecting cases can run offline (the gated case ends in redirect()).
    require_once __DIR__ . '/../controllers/Controller.php';
    $probe = new class($pdo) extends Controller {
        public function gate(array $account, bool $onPage): string { $this->requireUsername($account, 'applicant/dashboard.php?section=profile#username', $onPage); return 'passed'; }
    };
    check($probe->gate(['role' => 'applicant', 'username' => 'has.name'], false) === 'passed', 'applicant with a username is not gated');
    check($probe->gate(['role' => 'applicant', 'username' => null], true) === 'passed', 'username-less applicant may reach the profile page');
    check($probe->gate(['role' => 'mswd_social_worker', 'username' => null], false) === 'passed' && $probe->gate(['role' => 'admin', 'username' => ''], false) === 'passed', 'non-applicants are never gated');

    // Controller-level checks run once per portal.
    $failures = 0; $passes = 0;
    foreach (['staff', 'admin'] as $p) {
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . $p . ' 2>&1', $out, $rc);
        $line = (string) end($out);
        foreach ($out as $l) { if (str_starts_with($l, 'FAIL') || str_contains($l, 'Fatal') || str_contains($l, 'Warning') || str_contains($l, 'Deprecated')) { echo "[$p] $l\n"; } }
        if (!preg_match('/CHILD (\w+): (\d+) passed, (\d+) failed/', $line, $m) || (int) $m[3] !== 0 || $rc !== 0) { $failures++; echo "FAIL: child run for the $p portal did not finish cleanly ($line)\n"; }
        else { $passes += (int) $m[2]; echo "child $p portal: {$m[2]} checks passed\n"; }
    }
    $pass += $passes; $fail += $failures;
    echo "\nTOTAL: " . ($pass + $fail) . " checks, $pass passed, $fail failed\n";
    exit($fail === 0 ? 0 : 1);
}

// ------------------------------------------------------------------------------------------------------------
// child: one portal
define('AUTH_PORTAL', $portal === 'admin' ? 'admin' : 'staff');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SESSION = [];
require_once __DIR__ . '/../controllers/AuthController.php';

$pdo = offline_pdo();
$controller = new AuthController($pdo);
$attempt = new ReflectionMethod($controller, 'attemptLogin');
$users = new User($pdo);
$message = 'Incorrect username or password.';
$login = static fn(?array $a, string $typed, string $pw, string $portalLabel, string $help): string => $attempt->invoke($controller, $a, $typed, $pw, $portalLabel, $help);
$counter = static function (int $id) use ($pdo): int { return (int) $pdo->query("SELECT failed_login_attempts FROM users WHERE id = $id")->fetchColumn(); };
$auditCount = static function (string $action) use ($pdo): int { return (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action = '$action'")->fetchColumn(); };

$expectedRole = AUTH_PORTAL === 'admin' ? 'admin' : 'mswd_social_worker';
$goodUser = AUTH_PORTAL === 'admin' ? 'test.admin' : 'test.staff';
$goodId = AUTH_PORTAL === 'admin' ? 1 : 2;
$otherPortalUser = AUTH_PORTAL === 'admin' ? 'test.staff' : 'test.admin';

// Success decision (the redirect/session step after it cannot run in the CLI).
$account = $users->findStaffByUsername($goodUser);
check($account !== null && $account['role'] === $expectedRole, AUTH_PORTAL . ': username resolves to this portal\'s role');
check($login($account, $goodUser, TEST_PASSWORD, 'Staff sign-in', 'help') === '', AUTH_PORTAL . ': correct username + password accepted');
check($counter($goodId) === 0, AUTH_PORTAL . ': counter stays 0 after success');

// Wrong password: generic message, counter and audit row.
$before = $auditCount('Login Failed');
check($login($account, $goodUser, OTHER_PASSWORD, 'Staff sign-in', 'help') === $message, AUTH_PORTAL . ': wrong password rejected with the generic message');
check($counter($goodId) === 1, AUTH_PORTAL . ': failed-login counter incremented');
check($auditCount('Login Failed') === $before + 1, AUTH_PORTAL . ': failed attempt audit-logged');
check($login($users->findStaffByUsername($goodUser), $goodUser, TEST_PASSWORD, 'Staff sign-in', 'help') === '' && $counter($goodId) === 0, AUTH_PORTAL . ': correct password afterwards clears the counter');

// Unknown account: same message as a wrong password (no account enumeration), logged without an account.
$before = $auditCount('Login Failed');
check($login(null, 'no.such.user', TEST_PASSWORD, 'Staff sign-in', 'help') === $message, AUTH_PORTAL . ': unknown username gets the same generic message');
check($auditCount('Login Failed') === $before + 1, AUTH_PORTAL . ': unknown-username attempt audit-logged');
check((int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='Login Failed' AND user_id IS NULL")->fetchColumn() >= 1, AUTH_PORTAL . ': unknown-username audit row has no account');

// Inactive: rejected only after a correct password; a wrong password still gets the generic message.
$inactive = $users->findStaffByUsername('inactive.staff');
check($inactive !== null && (int) $inactive['is_active'] === 0, AUTH_PORTAL . ': inactive staff account is found (rejected later, not hidden)');
check(str_starts_with($login($inactive, 'inactive.staff', TEST_PASSWORD, 'Staff sign-in', 'Please contact your system administrator.'), 'This account has been deactivated.'), AUTH_PORTAL . ': inactive account rejected with correct password');
check($login($inactive, 'inactive.staff', OTHER_PASSWORD, 'Staff sign-in', 'help') === $message, AUTH_PORTAL . ': inactive account with wrong password gets the generic message');

// Locked: refused before the password is checked.
$lockedUser = AUTH_PORTAL === 'admin' ? 'locked.admin' : 'locked.staff';
$locked = $users->findStaffByUsername($lockedUser);
$lockedId = (int) $locked['id'];
check(str_starts_with($login($locked, $lockedUser, TEST_PASSWORD, 'Staff sign-in', 'help'), 'Too many failed attempts.'), AUTH_PORTAL . ': locked account refused even with the correct password');
check($counter($lockedId) === 0, AUTH_PORTAL . ': locked account counter not touched');

// Role authorization: the portal accepts only its own role, through the real handleStaffLogin().
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SESSION['csrf_token'] = str_repeat('a', 64);
$post = static function (string $user, string $pw) use ($controller): string {
    $_POST = ['csrf_token' => str_repeat('a', 64), 'username' => $user, 'password' => $pw];
    return $controller->handleStaffLogin();
};
check($post($otherPortalUser, TEST_PASSWORD) === $message, AUTH_PORTAL . ': the other role cannot sign in at this portal (correct password)');
check(empty($_SESSION['staff_id']), AUTH_PORTAL . ': no staff session created for the wrong role');
check($post('legacy.parent', TEST_PASSWORD) === $message && $post('new.parent', TEST_PASSWORD) === $message, AUTH_PORTAL . ': applicant usernames cannot sign in at the staff portal');
check($post('fixture.two@example.test', TEST_PASSWORD) === $message && $post('09000000003', TEST_PASSWORD) === $message, AUTH_PORTAL . ': applicant email/mobile cannot sign in at the staff portal');
check($post('no.such.user', 'whatever') === $message, AUTH_PORTAL . ': unknown username rejected through handleStaffLogin');
check($post($goodUser, OTHER_PASSWORD) === $message, AUTH_PORTAL . ': wrong password rejected through handleStaffLogin');
check(str_starts_with($post('inactive.staff', TEST_PASSWORD), AUTH_PORTAL === 'staff' ? 'This account has been deactivated.' : $message), AUTH_PORTAL . ': inactive staff through handleStaffLogin');
check(str_starts_with($post($lockedUser, TEST_PASSWORD), 'Too many failed attempts.'), AUTH_PORTAL . ': locked account through handleStaffLogin');

// Applicant sign-in (shared attemptLogin): by username, with the same generic message as staff.
$byName = $users->findApplicantForLogin('new.parent');
check($login($byName, 'new.parent', TEST_PASSWORD, 'Applicant sign-in', 'help') === '', AUTH_PORTAL . ': applicant username + password accepted');
check($login($byName, 'new.parent', OTHER_PASSWORD, 'Applicant sign-in', 'help') === $message, AUTH_PORTAL . ': applicant wrong password gets the generic message');
check($login(null, 'nobody', TEST_PASSWORD, 'Applicant sign-in', 'help') === $message, AUTH_PORTAL . ': applicant unknown username gets the generic message');
check($users->findApplicantForLogin('new.parent@example.test') === null && $login($users->findApplicantForLogin('new.parent@example.test'), 'new.parent@example.test', TEST_PASSWORD, 'Applicant sign-in', 'help') === $message, AUTH_PORTAL . ': applicant email + correct password refused when the account has a username');
check($login($users->findApplicantForLogin('09000000010'), '09000000010', TEST_PASSWORD, 'Applicant sign-in', 'help') === $message, AUTH_PORTAL . ': applicant mobile + correct password refused when the account has a username');
// Transitional (username-less) accounts still get in once with email / mobile.
check($login($users->findApplicantForLogin('fixture.two@example.test'), 'fixture.two@example.test', TEST_PASSWORD, 'Applicant sign-in', 'help') === '', AUTH_PORTAL . ': username-less applicant email + password accepted (transitional)');
check($login($users->findApplicantForLogin('09000000003'), '09000000003', TEST_PASSWORD, 'Applicant sign-in', 'help') === '', AUTH_PORTAL . ': username-less applicant mobile + password accepted (transitional)');
check($login($users->findApplicantForLogin('09000000003'), '09000000003', OTHER_PASSWORD, 'Applicant sign-in', 'help') === $message, AUTH_PORTAL . ': username-less applicant wrong password rejected');
// Inactive applicants (with and without a username).
check(str_starts_with($login($users->findApplicantForLogin('inactive.applicant@example.test'), 'inactive.applicant@example.test', TEST_PASSWORD, 'Applicant sign-in', 'Please contact MSWDO Virac for assistance.'), 'This account has been deactivated.'), AUTH_PORTAL . ': inactive username-less applicant rejected');
check(str_starts_with($login($users->findApplicantForLogin('inactive.named'), 'inactive.named', TEST_PASSWORD, 'Applicant sign-in', 'Please contact MSWDO Virac for assistance.'), 'This account has been deactivated.'), AUTH_PORTAL . ': inactive applicant with a username rejected');

// Public sign-up duplicate-key handling through the real handleApplicantAuth(): taken-at-insert is reported as taken;
// any other integrity failure (here a CHECK constraint, SQLSTATE 23000) is rethrown, never shown as a taken username.
$racePdo = offline_pdo(RacePdo::class);
$raceController = new AuthController($racePdo);
$signup = static function (string $username) use ($raceController): array {
    $_POST = ['csrf_token' => str_repeat('a', 64), 'action' => 'signup', 'fullName' => 'Race Parent', 'username' => $username, 'email' => '',
        'mobile' => '09175550009', 'password' => 'Valid-Pass-123', 'password_confirm' => 'Valid-Pass-123'];
    return $raceController->handleApplicantAuth('login');
};
$racePdo->raceUsername = 'race.signup';
$result = $signup('race.signup');
check($result['authError'] === 'That username is already taken. Please choose another.', AUTH_PORTAL . ': sign-up: a username taken at insert time is reported as taken');
check((int) $racePdo->query("SELECT COUNT(*) FROM users WHERE username = 'race.signup'")->fetchColumn() === 1, AUTH_PORTAL . ': sign-up: only the winning account exists');
$thrown = null;
try { $signup('check.fail'); } catch (PDOException $e) { $thrown = (string) $e->getCode(); }
check($thrown === '23000', AUTH_PORTAL . ': sign-up: a non-duplicate integrity failure is rethrown, not reported as a taken username');
check((int) $racePdo->query("SELECT COUNT(*) FROM users WHERE username = 'check.fail'")->fetchColumn() === 0, AUTH_PORTAL . ': sign-up: nothing was stored for the failed insert');

echo "CHILD {$portal}: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
