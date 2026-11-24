<?php
/**
 * Brings a database's data in line with the current code (November 2026). BACK UP THE DATABASE FIRST (mysqldump).
 *
 *   php database/migrate_data_alignment.php          show what would change (changes nothing)
 *   php database/migrate_data_alignment.php apply    make the changes
 *
 * What it does (details in services/DataAlignment.php):
 *   1. The two 1x1 ID pictures are no longer uploaded (the Solo Parent brings them to the interview): the requirement is removed for
 *      new applications and renewals, and a renewal's saved checklist row for it becomes not required. Uploaded pictures are KEPT.
 *   2. Settings that were never saved are saved with the default the code already used (no change in behaviour).
 *   3. The ID-expiry SMS templates get the "expires today" wording (a text an administrator edited by hand is left alone).
 *   4. The unused users.mobile_code, mobile_code_expires_at and mobile_code_attempts columns are dropped, only when all are empty.
 *   Report only, nothing changed: children over 22 without a disability recorded, and applications whose account no longer exists.
 *
 * Safe to run again: a second run finds nothing left to change. This tool changes the database named in config/local.php (or the
 * DB_NAME environment variable) when you use "apply".
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';
require_once 'services/DataAlignment.php';

$apply = ($argv[1] ?? '') === 'apply';
echo 'Database: ', $pdo->query('SELECT DATABASE()')->fetchColumn(), $apply ? '  (APPLY)' : '  (check only)', "\n\n";
try {
    DataAlignment::run($pdo, $apply, function (string $line) { echo $line, "\n"; });
} catch (Throwable $e) {
    fwrite(STDERR, "\n" . $e->getMessage() . "\n");
    exit(1);
}
echo $apply ? "\nDone.\n" : "\nNothing was changed. Run with \"apply\" to make these changes (back up the database first).\n";
