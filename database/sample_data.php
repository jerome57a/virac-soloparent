<?php
/**
 * Sample Solo Parents for demonstration and training (database/sample_data/solo-parents.csv). Every record is created through the
 * system's own functions: accounts as an administrator creates them, applications by the Solo Parent through the real web form
 * (scripts/sample-data/apply.cjs uploads the pictures through the real upload checks), and every staff step by the MSWDO functions,
 * recorded under the "Sample Data Encoder" staff account (created deactivated, so nobody can sign in with it).
 * Everything added is listed in sample_records, so `remove` takes it all out again.
 *
 *   php database/sample_data.php check              read the CSV, show what each row becomes; changes nothing
 *   php database/sample_data.php accounts           create the encoder and the Solo Parent accounts   (needs SAMPLE_PASSWORD)
 *   php database/sample_data.php staff              after the web form: the staff steps that bring each row to its stage
 *   php database/sample_data.php report             where each row is now, and the staff dashboard counts
 *   php database/sample_data.php remove             delete every sample record listed in sample_records (and its files)
 *
 * The CSV columns: key, group, full_name, username, mobile (TEAM = a team member's number, given as SAMPLE_TEAM_MOBILE), no_text (1 = never texted),
 * sex, birthdate, civil_status, barangay, address, category_id, children ("Name:YYYY-MM-DD[:pwd];..."), stage, card_valid_from,
 * card_expires_at. Stages: released, released_renewal_filed, pending_review, documents_to_verify, for_revision, reuploaded,
 * ready_to_schedule, waiting_for_date, interview_today, needs_result, missed, release_scheduled, release_overdue, rejected.
 * This tool changes the database named in config/local.php (or DB_NAME). Back the database up first.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';
require 'includes/data.php';
require_once 'config/permissions.php';
require_once 'services/SampleRecords.php';
require_once 'services/UserManagementService.php';
require_once 'services/ApplicationReviewService.php';
require_once 'services/InterviewBookingService.php';
require_once 'services/AppointmentService.php';
require_once 'services/IdReleaseService.php';
require_once 'models/Application.php';
require_once 'models/Document.php';
require_once 'models/Appointment.php';
$_SESSION = $_SESSION ?? [];

const SAMPLE_CSV = __DIR__ . '/sample_data/solo-parents.csv';
const ENCODER_USERNAME = 'sample.encoder';
const ENCODER_NAME = 'Sample Data Encoder';
const STAGES = ['released', 'released_renewal_filed', 'pending_review', 'documents_to_verify', 'for_revision', 'reuploaded', 'ready_to_schedule',
    'waiting_for_date', 'interview_today', 'needs_result', 'missed', 'release_scheduled', 'release_overdue', 'rejected',
    // A renewal row: the first ID (card dates in the CSV, already expired) is released, the Solo Parent files the renewal on the web form,
    // and the renewal goes to the stage after "renewal_". The new ID of renewal_released is valid from today for one year.
    'renewal_pending_review', 'renewal_documents_to_verify', 'renewal_for_revision', 'renewal_ready_to_schedule', 'renewal_interview_today',
    'renewal_needs_result', 'renewal_release_today', 'renewal_release_overdue', 'renewal_released', 'renewal_rejected'];

$command = $argv[1] ?? 'check';
$say = fn(string $s) => print($s . "\n");

/** @return array<int, array<string, mixed>> */
function sample_rows(): array
{
    $fh = fopen(SAMPLE_CSV, 'r');
    $head = fgetcsv($fh);
    $rows = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($head)) {
            continue;
        }
        $row = array_combine($head, array_map('trim', $r));
        // TEAM: a team member's mobile number, given at run time (SAMPLE_TEAM_MOBILE), so real numbers are never stored in the CSV.
        if ($row['mobile'] === 'TEAM' && (string) getenv('SAMPLE_TEAM_MOBILE') !== '') {
            $row['mobile'] = (string) getenv('SAMPLE_TEAM_MOBILE');
        }
        $row['children_list'] = [];
        foreach (array_filter(explode(';', $row['children'])) as $c) {
            $p = explode(':', $c);
            $row['children_list'][] = ['name' => trim($p[0]), 'birthdate' => trim($p[1] ?? ''), 'disability' => isset($p[2]) && trim($p[2]) === 'pwd'];
        }
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function sample_problems(array $row): array
{
    $p = [];
    if (!in_array($row['stage'], STAGES, true)) $p[] = 'unknown stage ' . $row['stage'];
    if (!in_array($row['civil_status'], CIVIL_STATUS_OPTIONS, true)) $p[] = 'civil status';
    if ($row['mobile'] !== 'TEAM' && !is_valid_ph_mobile(normalize_mobile($row['mobile']))) $p[] = 'mobile';
    if ((str_starts_with($row['stage'], 'released') || str_starts_with($row['stage'], 'renewal_')) && (!$row['card_valid_from'] || !$row['card_expires_at'])) $p[] = 'card dates';
    if (str_starts_with($row['stage'], 'renewal_') && $row['card_expires_at'] && $row['card_expires_at'] > date('Y-m-d')) $p[] = 'a renewal needs an ID that has expired';
    if (!$row['children_list']) $p[] = 'no child';
    return $p;
}

/**
 * Every mobile number already in the database, accounts and applications alike, as its last 10 digits (09... and +639... are the
 * same number) => who uses it. A sample account must never share a number with a real Solo Parent.
 */
function numbers_in_use(PDO $pdo, array $skipUserIds = []): array
{
    $used = [];
    foreach ($pdo->query("SELECT id, full_name, mobile FROM users WHERE mobile IS NOT NULL AND mobile <> ''") as $u) {
        if (!in_array((int) $u['id'], $skipUserIds, true)) $used[SampleRecords::digits($u['mobile'])][] = 'account ' . $u['full_name'];
    }
    foreach ($pdo->query('SELECT reference_no, user_id, mobile FROM applications') as $a) {
        if (!in_array((int) $a['user_id'], $skipUserIds, true)) $used[SampleRecords::digits($a['mobile'])][] = 'application ' . $a['reference_no'];
    }
    return $used;
}

/** Problems with the sample numbers: one already used in the database, or the same number twice in the list. */
function number_problems(PDO $pdo, array $rows): array
{
    $existing = array_map(fn($r) => sample_user($pdo, $r['username']), $rows);
    $own = array_values(array_filter(array_map(fn($u) => $u ? (int) $u['id'] : null, $existing)));   // a re-run: our own accounts
    $used = numbers_in_use($pdo, $own);
    $seen = [];
    $problems = [];
    foreach ($rows as $r) {
        if ($r['mobile'] === 'TEAM') continue;
        $k = SampleRecords::digits($r['mobile']);
        if (isset($used[$k])) $problems[] = $r['key'] . ' ' . $r['mobile'] . ' is already used by ' . implode(', ', array_unique($used[$k]));
        if (isset($seen[$k])) $problems[] = $r['key'] . ' ' . $r['mobile'] . ' is also used by ' . $seen[$k] . ' in the list';
        $seen[$k] = $r['key'];
    }
    return $problems;
}

function sample_user(PDO $pdo, string $username): ?array
{
    $s = $pdo->prepare('SELECT * FROM users WHERE username = ?');
    $s->execute([$username]);
    return $s->fetch() ?: null;
}

/** The newest application of a sample user (new or renewal). */
function sample_app(PDO $pdo, int $userId, ?string $type = null): ?array
{
    $s = $pdo->prepare('SELECT id FROM applications WHERE user_id = ?' . ($type ? ' AND type = ?' : '') . ' ORDER BY id DESC LIMIT 1');
    $s->execute($type ? [$userId, $type] : [$userId]);
    $id = $s->fetchColumn();
    return $id ? (new Application($pdo))->findForReview((int) $id) : null;
}

function sample_encoder(PDO $pdo): array
{
    $u = sample_user($pdo, ENCODER_USERNAME);
    if (!$u) {
        throw new RuntimeException('Run "accounts" first: the Sample Data Encoder account does not exist.');
    }
    return ['id' => (int) $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'], 'role' => 'mswd_social_worker'];
}

function must(?string $error, string $what): void
{
    if ($error !== null && $error !== '') {
        throw new RuntimeException($what . ': ' . $error);
    }
}

try {
    $rows = sample_rows();

    if ($command === 'check') {
        $say('Database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . '   rows: ' . count($rows));
        foreach ($rows as $r) {
            $u = sample_user($pdo, $r['username']);
            $problems = sample_problems($r);
            $say(sprintf('%-4s %-24s %-24s %-12s %-24s %s%s', $r['key'], $r['full_name'], $r['username'], $r['mobile'] === 'TEAM' ? 'TEAM number' : ($r['no_text'] === '1' ? 'never text' : 'real texts'),
                $r['stage'], $u ? 'account exists' : 'new', $problems ? '  PROBLEM: ' . implode(', ', $problems) : ''));
        }
        $numbers = number_problems($pdo, $rows);
        $say($numbers ? "
MOBILE NUMBERS ALREADY IN USE:
  " . implode("
  ", $numbers) : "
Mobile numbers: none is used by another account or application, none twice.");
        exit($numbers ? 1 : 0);
    }

    if ($command === 'accounts') {
        $password = (string) getenv('SAMPLE_PASSWORD');
        if ($password === '') {
            throw new RuntimeException('Set SAMPLE_PASSWORD (the demo password) in the environment first. It is never printed or stored in plain text.');
        }
        foreach ($rows as $r) {
            if ($p = sample_problems($r)) throw new RuntimeException($r['key'] . ': ' . implode(', ', $p));
            if ($r['mobile'] === 'TEAM') throw new RuntimeException($r['key'] . ': fill in a team mobile number in the CSV first.');
        }
        if ($numbers = number_problems($pdo, $rows)) {
            throw new RuntimeException("mobile numbers already in use, nothing was created:
  " . implode("
  ", $numbers));
        }
        SampleRecords::ensureTable($pdo);
        $users = new UserManagementService($pdo);
        if (!$enc = sample_user($pdo, ENCODER_USERNAME)) {
            // The encoder is the staff account every sample record is credited to. It is created deactivated with a random password
            // nobody knows, so nobody can sign in with it; the audit trail credits its own creation to itself.
            require_once 'models/User.php';
            $id = (new User($pdo))->createWithRole(ENCODER_NAME, '', ENCODER_USERNAME, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), 'mswd_social_worker');
            $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
            SampleRecords::register($pdo, 'staff', (int) $id, 'ENCODER');
            audit_log($pdo, (int) $id, ENCODER_NAME, 'mswd_social_worker', 'User Creation', 'user', (int) $id, ENCODER_USERNAME . ' (sample data encoder, deactivated)');
            $say('created ' . ENCODER_USERNAME . ' (deactivated)');
        }
        $creator = sample_encoder($pdo);
        foreach ($rows as $r) {
            if ($u = sample_user($pdo, $r['username'])) {
                $say($r['key'] . ' ' . $r['username'] . ': exists, skipped');
                continue;
            }
            [$err, , $id] = $users->create($creator, ['fullName' => $r['full_name'], 'username' => $r['username'], 'email' => '', 'mobile' => $r['mobile'],
                'password' => $password, 'password_confirm' => $password, 'role' => 'applicant']);
            must($err, $r['key'] . ' account');
            SampleRecords::register($pdo, 'user', (int) $id, $r['key'], $r['mobile'], $r['no_text'] === '1');
            $say($r['key'] . ' ' . $r['username'] . ': created' . ($r['no_text'] === '1' ? ' (never texted)' : ''));
        }
        exit(0);
    }

    if ($command === 'staff') {
        $staff = sample_encoder($pdo);
        $review = new ApplicationReviewService($pdo);
        $booking = new InterviewBookingService($pdo);
        $release = new IdReleaseService($pdo);
        $appointments = new Appointment($pdo);
        $today = date('Y-m-d');
        $day = fn(int $n) => date('Y-m-d', strtotime($today . ' ' . ($n >= 0 ? '+' : '') . $n . ' days'));
        $fresh = fn(int $id) => (new Application($pdo))->findForReview($id);
        $docs = function (int $appId) use ($pdo): array {
            $s = $pdo->prepare('SELECT * FROM application_documents WHERE application_id = ? ORDER BY id');
            $s->execute([$appId]);
            return $s->fetchAll();
        };
        // Save Verification: every document VERIFIED, except $correct (doc_key => remark) which is marked Needs Correction.
        $verify = function (array $app, array $correct = []) use ($review, $staff, $docs, $fresh): void {
            $rows = [];
            foreach ($docs((int) $app['id']) as $d) {
                $key = $d['doc_key'];
                $want = isset($correct[$key]) ? 'NEEDS_CORRECTION' : 'VERIFIED';
                if ($d['verification_status'] === $want) continue;
                $rows[(int) $d['id']] = ['status' => $want, 'remarks' => $correct[$key] ?? '',
                    'expected' => $d['verification_status'], 'expected_remarks' => (string) ($d['staff_remarks'] ?? ''), 'expected_version' => Document::versionToken($d)];
            }
            if ($rows) {
                $res = $review->saveVerification($staff, $fresh((int) $app['id']), $rows);
                if ($res['errors']) throw new RuntimeException('verification: ' . json_encode($res['errors']));
            }
        };
        $schedule = function (array $app, string $date) use ($booking, $staff): void {
            $sms = null;
            must($booking->scheduleForApplicant($staff, (int) $app['id'], $date, null, $sms, null), 'schedule interview');
        };
        // Schedules on the first future day with a free seat (a day holds only so many interviews).
        $scheduleFree = function (array $app, int $from = 1) use ($booking, $staff, $day): void {
            $last = '';
            for ($n = $from; $n <= 45; $n++) {
                $sms = null;
                $last = (string) $booking->scheduleForApplicant($staff, (int) $app['id'], $day($n), null, $sms, null);
                if ($last === '') return;
                if (!str_contains($last, 'full')) break;
            }
            throw new RuntimeException('schedule interview: ' . $last);
        };
        // Moves this application's interview to a past day (a past date cannot be scheduled; the history says so).
        $backdate = function (array $app, string $date) use ($pdo, $staff): void {
            $pdo->prepare("UPDATE appointments SET scheduled_at = ?, date_only = 1 WHERE application_id = ?")->execute([$date . ' 00:00:00', (int) $app['id']]);
            (new Application($pdo))->addHistory((int) $app['id'], $app['status'], 'Sample data: the interview day was set to ' . date('F j, Y', strtotime($date)) . ' to show this stage.', $staff['full_name'], (int) $staff['id']);
        };
        $interviewPassed = function (array $app) use ($review, $staff, $fresh): void {
            must($review->recordInterviewOutcome($staff, (int) $app['id'], 'Successful', 'Interviewed at the MSWDO office. Statements match the documents.',
                array_keys(ApplicationReviewService::RENEWAL_RECHECK)), 'interview result');
        };
        $approve = fn(array $app) => must($review->updateStatus($staff, $fresh((int) $app['id']), 'Approved', 'All requirements verified; interview successful.', 'Interview Scheduled'), 'approve');
        $scheduleRelease = fn(array $app, string $date) => must($release->schedule($staff, $fresh((int) $app['id']), $date, $date === $today ? date('H:i', strtotime('+10 minutes')) : '09:00', get_setting($pdo, 'appointment_location', 'MSWDO Office, Virac'), 'automatic', ''), 'schedule release');

        foreach ($rows as $r) {
            // A renewal row has two applications: its first ID is released first, then (once the Solo Parent has filed the renewal on the web form)
            // the renewal goes to its stage.
            $jobs = str_starts_with($r['stage'], 'renewal_') ? [['new', 'released'], ['renewal', substr($r['stage'], 8)]] : [['new', $r['stage']]];
            foreach ($jobs as [$type, $stage]) {
            $u = sample_user($pdo, $r['username']);
            $app = $u ? sample_app($pdo, (int) $u['id'], $type) : null;
            if (!$app) {
                $say($r['key'] . ': ' . ($type === 'renewal' ? 'the renewal is not filed yet (run the web form renewal first)' : 'no application yet (run the web form first)') . ', skipped');
                continue;
            }
            SampleRecords::register($pdo, 'application', (int) $app['id'], $r['key']);
            $releaseStage = in_array($stage, ['release_scheduled', 'release_today', 'release_overdue', 'released', 'released_renewal_filed'], true);
            $resumeRelease = $releaseStage && $app['status'] === 'Approved' && !$release->scheduleFor((int) $app['id']);
            if (!$resumeRelease && ($stage === 'pending_review' || $app['status'] !== 'Pending Review' && $app['status'] !== 'Under Verification')) {
                $say($r['key'] . ': ' . $app['reference_no'] . ' is ' . $app['status'] . ($stage === 'pending_review' ? ' (left for staff)' : ' (already done, skipped)'));
                continue;
            }
            if (!$resumeRelease) {
                $review->startReviewOnOpen($staff, $app);           // what opening the application does
            }
            $app = $fresh((int) $app['id']);
            switch ($stage) {
                case 'documents_to_verify':
                    break;
                case 'reuploaded':
                    $key = $docs((int) $app['id'])[0]['doc_key'];
                    $verify($app, [$key => 'The picture is blurry. Please take it again in good light so every word can be read.']);
                    $preview = $review->correctionSmsPreview($fresh((int) $app['id']));
                    $review->sendCorrectionSms($staff, $fresh((int) $app['id']), (string) ($preview['token'] ?? ''));
                    break;
                case 'for_revision':
                    $key = $docs((int) $app['id'])[0]['doc_key'];
                    $verify($app);
                    $scheduleFree($fresh((int) $app['id']));
                    $backdate($fresh((int) $app['id']), $day(-1));
                    $interviewPassed($app);
                    $verify($fresh((int) $app['id']), [$key => 'The name on this document does not match. Please upload the correct copy.']);
                    must($review->updateStatus($staff, $fresh((int) $app['id']), 'For Revision', 'Please replace the document marked Needs Correction, then we can decide.', 'Interview Scheduled'), 'request corrections');
                    break;
                case 'ready_to_schedule':
                    $verify($app);
                    break;
                case 'waiting_for_date':
                    $verify($app);
                    $scheduleFree($fresh((int) $app['id']), 3); // far enough ahead that the Solo Parent can still cancel online
                    $user = ['id' => (int) $u['id'], 'full_name' => $u['full_name'], 'role' => 'applicant', 'mobile' => $u['mobile']];
                    must((new AppointmentService($pdo))->cancelByApplicant($user, $fresh((int) $app['id'])), 'Solo Parent cancels to pick another date');
                    break;
                case 'interview_today':
                    $verify($app);
                    $schedule($fresh((int) $app['id']), $today);
                    break;
                case 'needs_result':
                    $verify($app);
                    $scheduleFree($fresh((int) $app['id']));
                    $backdate($fresh((int) $app['id']), $day(-3));
                    break;
                case 'missed':
                    $verify($app);
                    $scheduleFree($fresh((int) $app['id']));
                    $backdate($fresh((int) $app['id']), $day(-2));
                    $appt = $appointments->interviewFor((int) $app['id']);
                    $sms = null;
                    must((new AppointmentService($pdo))->staffSetOutcome($staff, (int) $appt['id'], 'MISSED', '', $sms), 'mark missed');
                    // "Missed and Not Rebooked" lists a miss once it is a week old with no new date: place it 10 days back, marked 8 days ago.
                    $pdo->prepare('UPDATE appointments SET scheduled_at = ?, updated_at = NOW() - INTERVAL 8 DAY WHERE id = ?')->execute([$day(-10) . ' 00:00:00', (int) $appt['id']]);
                    (new Application($pdo))->addHistory((int) $app['id'], $fresh((int) $app['id'])['status'], 'Sample data: the missed interview was placed on ' . date('F j, Y', strtotime($day(-10))) . ' to show a Solo Parent who has not rebooked for a week.', $staff['full_name'], (int) $staff['id']);
                    break;
                case 'rejected':
                    $verify($app);
                    must($review->updateStatus($staff, $fresh((int) $app['id']), 'Rejected', 'The child listed is already supported by the other parent, so the applicant is not the sole caregiver (RA 11861).', 'Under Verification'), 'reject');
                    break;
                case 'release_scheduled':
                case 'release_today':
                case 'release_overdue':
                case 'released':
                case 'released_renewal_filed':
                    if (!$resumeRelease) {
                        $verify($app);
                        $scheduleFree($fresh((int) $app['id']));
                        $backdate($fresh((int) $app['id']), $day(-5));
                        $interviewPassed($app);
                        $approve($fresh((int) $app['id']));
                    }
                    if ($stage === 'release_scheduled') {
                        $scheduleRelease($app, $day(7));
                        break;
                    }
                    if ($stage === 'release_today') {
                        $scheduleRelease($app, $today);   // later today: the Solo Parent's release-day card and the counter search show it
                        break;
                    }
                    if ($stage === 'release_overdue') {
                        $scheduleRelease($app, $day(1));
                        $pdo->prepare('UPDATE id_release_schedules SET scheduled_at = ? WHERE application_id = ?')->execute([$day(-3) . ' 09:00:00', (int) $app['id']]);
                        (new Application($pdo))->addHistory((int) $app['id'], 'Approved', 'Sample data: the release date was set to ' . date('F j, Y', strtotime($day(-3))) . ' to show an ID not yet collected.', $staff['full_name'], (int) $staff['id']);
                        break;
                    }
                    $scheduleRelease($app, $today);
                    // Handover: the card dates when the card is still valid; otherwise valid dates first, then corrected to the card.
                    $from = $type === 'renewal' ? $today : $r['card_valid_from'];
                    $to = $type === 'renewal' ? $day(365) : $r['card_expires_at'];
                    $handoverOk = IdReleaseService::datesError($from, $to, true) === null;
                    must($release->confirmRelease($staff, $fresh((int) $app['id']), ['valid_from' => $handoverOk ? $from : $today, 'expires_at' => $handoverOk ? $to : $day(365), 'identity_checked' => true, 'override_reason' => '']), 'confirm release');
                    if (!$handoverOk) {
                        must($release->correctDates($staff, $fresh((int) $app['id']), $from, $to, 'Encoded from MSWDO records: the dates printed on the Solo Parent\'s physical card.'), 'card dates');
                    }
                    break;
            }
            $now = $fresh((int) $app['id']);
            $say($r['key'] . ': ' . $now['reference_no'] . ' -> ' . $now['status'] . ' (' . ($type === 'renewal' ? 'renewal ' : '') . $stage . ')');
            }
        }
        exit(0);
    }

    if ($command === 'report') {
        $say('Database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn());
        foreach ($rows as $r) {
            $u = sample_user($pdo, $r['username']);
            $line = sprintf('%-4s %-24s %-24s', $r['key'], $r['full_name'], $r['stage']);
            if (!$u) { $say($line . ' no account'); continue; }
            $apps = $pdo->prepare('SELECT reference_no, type, status, id_valid_from, id_expires_at FROM applications WHERE user_id = ? ORDER BY id');
            $apps->execute([(int) $u['id']]);
            $parts = array_map(fn($a) => $a['reference_no'] . ' ' . $a['type'] . ' ' . $a['status'] . ($a['id_expires_at'] ? ' ' . $a['id_valid_from'] . '..' . $a['id_expires_at'] : ''), $apps->fetchAll());
            $say($line . ' ' . ($parts ? implode(' | ', $parts) : 'no application'));
        }
        exit(0);
    }

    if ($command === 'remove') {
        SampleRecords::ensureTable($pdo);
        $userIds = $pdo->query("SELECT ref_id FROM sample_records WHERE kind IN ('user','staff')")->fetchAll(PDO::FETCH_COLUMN);
        if (!$userIds) { $say('No sample records.'); exit(0); }
        $in = implode(',', array_map('intval', $userIds));
        $files = $pdo->query("SELECT d.stored_path FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE a.user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $apps = $pdo->query("SELECT id FROM applications WHERE user_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($apps) {
            $ain = implode(',', array_map('intval', $apps));
            $pdo->exec("DELETE FROM notifications WHERE related_application_id IN ($ain)");   // the staff alerts about these applications
            $pdo->exec("UPDATE applications SET renewal_of_id = NULL WHERE renewal_of_id IN ($ain)");
            $pdo->exec("DELETE FROM applications WHERE id IN ($ain)");   // children, documents, history, appointments... cascade
        }
        foreach (['application_drafts' => 'user_id', 'notifications' => 'user_id', 'applicants' => 'user_id'] as $t => $c) {
            $pdo->exec("DELETE FROM $t WHERE $c IN ($in)");
        }
        $pdo->exec("UPDATE appointments SET scheduled_by = NULL WHERE scheduled_by IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        $pdo->exec('DELETE FROM sample_records');
        $pdo->commit();
        $gone = 0;
        foreach ($files as $f) { $p = STORAGE_PATH . '/' . $f; if (is_file($p) && @unlink($p)) $gone++; }
        $say('Removed ' . count($userIds) . ' accounts, ' . count($apps) . ' applications and ' . $gone . ' uploaded files.');
        exit(0);
    }

    throw new RuntimeException('Unknown command. Use check, accounts, staff, report or remove.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Stopped: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n");
    exit(1);
}
