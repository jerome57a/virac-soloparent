<?php
/**
 * Moves the card dates of ONE released Solo Parent ID, through the system's own "Correct dates" step (IdReleaseService::correctDates:
 * a history line, an audit entry with the reason, and the Solo Parent's in-app notice; no SMS). Used to make an account ready to renew
 * (renewal opens on the day the ID expires), for example for a demonstration. It never touches anything else.
 *
 *   php database/set_id_expiry.php <username> <expires: today|YYYY-MM-DD>            show what would change (changes nothing)
 *   php database/set_id_expiry.php <username> <expires: today|YYYY-MM-DD> apply      change it
 *
 * The start date becomes one year before the expiry date. The change is credited to the deactivated account sample.encoder, and the
 * reason says it was set on request. Back the database up first.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';
require_once 'config/permissions.php';
require_once 'services/IdReleaseService.php';
require_once 'models/Application.php';
$_SESSION = $_SESSION ?? [];

[$username, $when] = [$argv[1] ?? '', $argv[2] ?? ''];
$apply = in_array('apply', $argv, true);
$expires = $when === 'today' ? date('Y-m-d') : $when;
if ($username === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)) {
    fwrite(STDERR, "Usage: php database/set_id_expiry.php <username> <today|YYYY-MM-DD> [apply]\n");
    exit(2);
}
$from = date('Y-m-d', strtotime($expires . ' -1 year'));
$user = $pdo->prepare('SELECT id, full_name, username FROM users WHERE username = ? AND role = \'applicant\'');
$user->execute([$username]);
$user = $user->fetch();
if (!$user) { fwrite(STDERR, "No Solo Parent account with the username $username.\n"); exit(1); }
$apps = $pdo->prepare("SELECT id FROM applications WHERE user_id = ? AND status = 'ID Released' ORDER BY id DESC");
$apps->execute([(int) $user['id']]);
$ids = $apps->fetchAll(PDO::FETCH_COLUMN);
if (count($ids) !== 1) { fwrite(STDERR, $user['full_name'] . ' has ' . count($ids) . " released ID(s); this tool changes exactly one.\n"); exit(1); }
$app = (new Application($pdo))->findForReview((int) $ids[0]);
echo 'Database: ', $pdo->query('SELECT DATABASE()')->fetchColumn(), $apply ? '  (APPLY)' : '  (check only)', "\n";
echo $user['full_name'], ' (', $user['username'], ')  ', $app['reference_no'], "\n";
echo '  now:  valid ', $app['id_valid_from'], ' to ', $app['id_expires_at'], "\n";
echo '  new:  valid ', $from, ' to ', $expires, ($expires === date('Y-m-d') ? '  (expires today: renewal is open now)' : ($expires < date('Y-m-d') ? '  (expired: renewal is open)' : '  (renewal opens on that day)')), "\n";
if ($app['id_valid_from'] === $from && $app['id_expires_at'] === $expires) { echo "Already set.\n"; exit(0); }
if (!$apply) { echo "Nothing was changed. Run with \"apply\" to change it (back up the database first).\n"; exit(0); }
$enc = $pdo->query("SELECT id, full_name FROM users WHERE username = 'sample.encoder'")->fetch();
if (!$enc) { fwrite(STDERR, "The account sample.encoder does not exist.\n"); exit(1); }
$staff = ['id' => (int) $enc['id'], 'full_name' => $enc['full_name'], 'role' => 'mswd_social_worker'];
$error = (new IdReleaseService($pdo))->correctDates($staff, $app, $from, $expires, 'Card dates set on request so this Solo Parent can renew now (renewal opens on the expiry date). Not the dates printed on a physical card.');
if ($error !== null) { fwrite(STDERR, "Not changed: $error\n"); exit(1); }
$after = (new Application($pdo))->findForReview((int) $app['id']);
echo '  done: valid ', $after['id_valid_from'], ' to ', $after['id_expires_at'], "\n";
