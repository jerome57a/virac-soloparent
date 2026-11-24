<?php
/**
 * Removes leftovers that no one can use (November 2026). BACK UP THE DATABASE FIRST.
 *
 *   php database/cleanup_leftovers.php            show what would be removed (changes nothing)
 *   php database/cleanup_leftovers.php apply      remove it
 *   add --no-files to leave the upload folder alone (testing against a copy that shares the live uploads folder)
 *
 * What it removes:
 *   1. Applications whose Solo Parent account was deleted, with everything attached to them (children, documents, history, interview,
 *      ID release, text records, staff alerts); their uploaded files are MOVED to the quarantine folder, not deleted.
 *   2. Solo Parent profile rows (applicants) whose account was deleted.
 *   3. Test-mode SMS records (never sent to anyone) that no appointment, invitation or ID release points to, and that do not belong to a
 *      sample account (database/sample_data.php).
 *   4. The one junk staff note "hhjj" on VIRAC-SP-2026-0001 (matched exactly; any other note is left alone).
 *   5. Upload files that no document record points to (not draft files, not recent temp files), MOVED to the quarantine folder.
 * It never touches: accounts, applications of existing accounts, failed SMS records, drafts, audit logs, categories, settings.
 * Everything removed is listed in manifest.json inside the quarantine folder (C:\dev\backups\cleanup-<time>\), where moved files can
 * be put back by hand. Safe to run again: a second run finds nothing.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';

$apply = in_array('apply', $argv, true);
$touchFiles = !in_array('--no-files', $argv, true);
$would = $apply ? '' : '[would] ';
$say = fn(string $s) => print($s . "\n");
$one = fn(string $sql, array $args = []) => (function () use ($pdo, $sql, $args) { $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchColumn(); })();
$quarantine = (getenv('CLEANUP_QUARANTINE') ?: 'C:/dev/backups') . '/cleanup-' . date('Ymd-His');
$manifest = ['database' => $pdo->query('SELECT DATABASE()')->fetchColumn(), 'at' => date('c'), 'applied' => $apply, 'applications' => [], 'counts' => [], 'files_moved' => []];
$say('Database: ' . $manifest['database'] . ($apply ? '  (APPLY)' : '  (check only)') . ($touchFiles ? '' : '  (files untouched)') . "\n");

try {
    // ---- 1. applications whose account was deleted ----
    $orphans = $pdo->query('SELECT a.id, a.reference_no, a.full_name, a.status FROM applications a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL ORDER BY a.id')->fetchAll();
    $ids = array_map('intval', array_column($orphans, 'id'));
    $say('1. Applications whose account was deleted: ' . count($orphans));
    foreach ($orphans as $o) {
        $say('     ' . $o['reference_no'] . '  ' . str_pad(mb_substr($o['full_name'], 0, 28), 28) . ' ' . $o['status']);
        $manifest['applications'][] = ['reference_no' => $o['reference_no'], 'name' => $o['full_name'], 'status' => $o['status']];
    }
    $docFiles = [];
    if ($ids) {
        $in = implode(',', $ids);
        $docFiles = $pdo->query("SELECT stored_path FROM application_documents WHERE application_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['application_children', 'application_documents', 'application_document_checklists', 'application_status_history', 'appointments', 'id_release_schedules', 'sms_logs', 'notifications'] as $t) {
            $col = $t === 'notifications' ? 'related_application_id' : 'application_id';
            $manifest['counts'][$t . ' (of those applications)'] = (int) $one("SELECT COUNT(*) FROM `$t` WHERE $col IN ($in)");
        }
        $say('   attached: ' . implode(', ', array_map(fn($k, $v) => "$v " . explode(' (', $k)[0], array_keys($manifest['counts']), $manifest['counts'])) . '; ' . count($docFiles) . ' uploaded file(s)');
    }
    // ---- 2. profile rows of deleted accounts ----
    $profiles = (int) $one('SELECT COUNT(*) FROM applicants p LEFT JOIN users u ON u.id = p.user_id WHERE u.id IS NULL');
    $say("\n2. Solo Parent profile rows whose account was deleted: $profiles");
    // ---- 3. test-mode SMS records nobody points to ----
    $sampleApps = "SELECT a.id FROM applications a JOIN sample_records s ON s.kind = 'user' AND s.ref_id = a.user_id";
    $smsWhere = "s.test_mode = 1
        AND s.id NOT IN (SELECT interview_sms_log_id FROM appointments WHERE interview_sms_log_id IS NOT NULL)
        AND s.id NOT IN (SELECT confirmation_sms_log_id FROM appointments WHERE confirmation_sms_log_id IS NOT NULL)
        AND s.id NOT IN (SELECT sms_log_id FROM interview_invitations WHERE sms_log_id IS NOT NULL)
        AND s.id NOT IN (SELECT sms_log_id FROM id_release_schedules WHERE sms_log_id IS NOT NULL)
        AND (s.application_id IS NULL OR s.application_id NOT IN ($sampleApps))";
    $smsHas = (bool) $pdo->query("SHOW TABLES LIKE 'sample_records'")->fetchColumn();
    if (!$smsHas) { $smsWhere = str_replace("AND (s.application_id IS NULL OR s.application_id NOT IN ($sampleApps))", '', $smsWhere); }
    $smsTotalTest = (int) $one('SELECT COUNT(*) FROM sms_logs WHERE test_mode = 1');
    $smsCount = (int) $one("SELECT COUNT(*) FROM sms_logs s WHERE $smsWhere");
    $say("\n3. Test-mode SMS records nobody points to: $smsCount of $smsTotalTest test-mode records (the rest are kept: an interview, invitation, ID release or sample account uses them)");
    // ---- 4. the junk note ----
    $notes = (int) $one("SELECT COUNT(*) FROM application_notes n JOIN applications a ON a.id = n.application_id WHERE a.reference_no = 'VIRAC-SP-2026-0001' AND n.note = 'hhjj' AND n.author_name = 'Staff1'");
    $say("\n4. Junk staff note \"hhjj\": $notes");
    // ---- 5. upload files no record points to ----
    $stored = array_flip($pdo->query('SELECT stored_path FROM application_documents')->fetchAll(PDO::FETCH_COLUMN));
    $orphanFiles = [];
    if ($touchFiles && defined('STORAGE_PATH') && is_dir(STORAGE_PATH)) {
        $base = rtrim(str_replace('\\', '/', STORAGE_PATH), '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($base)), '/');
            if (isset($stored[$rel]) || preg_match('~(^|/)(\.htaccess|\.gitkeep|index\.html)$~', $rel) || str_starts_with($rel, 'drafts/')) continue;
            if (str_starts_with($rel, 'tmp/') && $f->getMTime() > time() - 2 * 86400) continue;   // a recent temp file may be in use
            $orphanFiles[] = $rel;
        }
    }
    $bytes = array_sum(array_map(fn($r) => (int) @filesize(STORAGE_PATH . '/' . $r), $orphanFiles));
    $say("\n5. Upload files no record points to: " . count($orphanFiles) . ' (' . round($bytes / 1048576, 1) . " MB) + " . count($docFiles) . ' file(s) of the applications in 1; moved to ' . $quarantine);
    foreach (array_slice($orphanFiles, 0, 8) as $r) $say('     ' . $r);

    $manifest['counts'] += ['applicants_rows' => $profiles, 'sms_test_records' => $smsCount, 'junk_notes' => $notes, 'orphan_files' => count($orphanFiles)];

    if (!$apply) {
        $say("\nNothing was changed. Run with \"apply\" to remove these (back up the database first).");
        exit(0);
    }

    // ---- apply: database first (one transaction), then move the files ----
    $pdo->beginTransaction();
    if ($ids) {
        $in = implode(',', $ids);
        $pdo->exec("DELETE FROM notifications WHERE related_application_id IN ($in)");
        $pdo->exec("UPDATE applications SET renewal_of_id = NULL WHERE renewal_of_id IN ($in)");
        $pdo->exec("DELETE FROM applications WHERE id IN ($in)");
        // nothing may still point at the removed applications
        $left = 0;
        foreach ($pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'applications'")->fetchAll() as $fk) {
            $left += (int) $pdo->query("SELECT COUNT(*) FROM `{$fk['TABLE_NAME']}` WHERE `{$fk['COLUMN_NAME']}` IN ($in)")->fetchColumn();
        }
        if ($left > 0) throw new RuntimeException("$left row(s) still point at the removed applications; nothing was changed.");
    }
    $pdo->exec('DELETE FROM applicants WHERE user_id NOT IN (SELECT id FROM users)');
    $pdo->exec("DELETE FROM sms_logs WHERE id IN (SELECT id FROM (SELECT s.id FROM sms_logs s WHERE $smsWhere) x)");
    if ($notes) {
        $pdo->exec("DELETE FROM application_notes WHERE note = 'hhjj' AND author_name = 'Staff1' AND application_id IN (SELECT id FROM (SELECT id FROM applications WHERE reference_no = 'VIRAC-SP-2026-0001') a)");
    }
    audit_log($pdo, null, 'Data cleanup tool', 'admin', 'Data Cleanup', 'system', 0,
        mb_substr('Removed ' . count($orphans) . ' applications without an account, ' . $profiles . ' profile rows, ' . $smsCount . ' test-mode SMS records, ' . $notes . ' junk note(s); ' . (count($orphanFiles) + count($docFiles)) . ' upload file(s) moved to quarantine.', 0, 500));
    $pdo->commit();
    $say("\nDatabase cleaned.");

    if ($touchFiles) {
        @mkdir($quarantine . '/files', 0777, true);
        $moved = 0;
        foreach (array_merge($orphanFiles, $docFiles) as $rel) {
            $from = STORAGE_PATH . '/' . $rel;
            if (!is_file($from)) continue;
            $to = $quarantine . '/files/' . $rel;
            @mkdir(dirname($to), 0777, true);
            if (@rename($from, $to) || (@copy($from, $to) && @unlink($from))) { $moved++; $manifest['files_moved'][] = $rel; }
        }
        $say("$moved file(s) moved to $quarantine/files (not deleted).");
    }
    @mkdir($quarantine, 0777, true);
    file_put_contents($quarantine . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $say('Manifest: ' . $quarantine . '/manifest.json');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'Stopped, the database was not changed: ' . $e->getMessage() . "\n");
    exit(1);
}
