<?php
/**
 * Fills the START date of released IDs that have none (released before staff had to type the dates printed on the card).
 *
 *   php database/fill_missing_card_dates.php            show what would be filled (changes nothing)
 *   php database/fill_missing_card_dates.php apply      fill them
 *
 * The start date is the date the ID was released (the release record, else the "ID Released" history line), and only when the stored
 * expiry date is exactly the ID validity (Settings, default 1 year) after it: that proves the system set the expiry itself at release,
 * and nobody typed the dates from the card. An ID where that does not hold is listed and left for staff. These dates are NOT read from
 * the physical card: each correction says so in its reason, so staff can check the card and use "Correct dates" when it differs.
 * It uses the system's own correction (IdReleaseService::correctDates: history line, audit entry, the Solo Parent's in-app notice; no SMS)
 * credited to the deactivated account sample.encoder. Back the database up first. Safe to run again.
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

$apply = in_array('apply', $argv, true);
$say = fn(string $s) => print($s . "\n");
$years = IdReleaseService::validityYears($pdo);
$say('Database: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . ($apply ? '  (APPLY)' : '  (check only)') . "   ID validity: $years year(s)\n");

$enc = $pdo->query("SELECT id, full_name FROM users WHERE username = 'sample.encoder'")->fetch();
if ($apply && !$enc) {
    fwrite(STDERR, "The account sample.encoder does not exist (run database/sample_data.php accounts first).\n");
    exit(1);
}
$staff = ['id' => (int) ($enc['id'] ?? 0), 'full_name' => (string) ($enc['full_name'] ?? 'Records update'), 'role' => 'mswd_social_worker'];
$reason = 'Start date set to the release date: the ID was released before card dates were recorded, and its expiry is exactly one year after release. NOT read from the physical card; check the card and use Correct dates if it differs.';

$rows = $pdo->query("SELECT a.id, a.reference_no, a.full_name, a.id_expires_at,
        (SELECT DATE(r.released_at) FROM id_release_schedules r WHERE r.application_id = a.id AND r.released_at IS NOT NULL) rel,
        (SELECT DATE(h.changed_at) FROM application_status_history h WHERE h.application_id = a.id AND h.status = 'ID Released' ORDER BY h.id DESC LIMIT 1) hist
    FROM applications a WHERE a.status = 'ID Released' AND a.id_valid_from IS NULL AND a.id_expires_at IS NOT NULL ORDER BY a.id")->fetchAll();
if (!$rows) {
    $say('Every released ID already has a start date. Nothing to do.');
    exit(0);
}
$service = new IdReleaseService($pdo);
$apps = new Application($pdo);
$done = $left = 0;
foreach ($rows as $r) {
    $from = $r['rel'] ?: $r['hist'];
    $label = sprintf('%-20s %-26s expires %s', $r['reference_no'], mb_substr($r['full_name'], 0, 26), $r['id_expires_at']);
    if (!$from || date('Y-m-d', strtotime($from . " +$years year")) !== $r['id_expires_at']) {
        $say($label . '  LEFT FOR STAFF: ' . ($from ? "the expiry is not release + $years year(s)" : 'no release date on record'));
        $left++;
        continue;
    }
    if (!$apply) {
        $say($label . "  [would] start date $from");
        $done++;
        continue;
    }
    $error = $service->correctDates($staff, $apps->findForReview((int) $r['id']), $from, $r['id_expires_at'], $reason);
    $say($label . ($error === null ? "  start date $from" : '  NOT CHANGED: ' . $error));
    $error === null ? $done++ : $left++;
}
$say("\n" . ($apply ? 'Filled' : 'Would fill') . ": $done   left for staff: $left");
$say($apply ? 'Each ID has a history line and an audit entry with the reason; the dates are the release dates, not the card dates.' : 'Nothing was changed. Run with "apply" to fill them (back up the database first).');
