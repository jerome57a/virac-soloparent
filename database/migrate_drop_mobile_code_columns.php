<?php
/**
 * PROPOSED, NOT EXECUTED. Phase 2 database pruning: remove the obsolete SMS
 * mobile-verification columns from `users`:
 *
 *   users.mobile_code, users.mobile_code_expires_at, users.mobile_code_attempts
 *
 * Preserved untouched: users.mobile_verified_at, users.password_reset_code,
 * users.password_reset_expires_at (and every other column, index and constraint).
 *
 * SAFETY MODEL
 *   * Command line only. Uses the same fail-closed guard as the other database tools
 *     (tool_safety.php): process-level APP_ENV=testing, SMS_PROVIDER=test, a local
 *     host, a non-root account and DB_NAME=virac_solo_parent_test. It therefore
 *     refuses to touch the production database; run it against a restored COPY
 *     of the data loaded into virac_solo_parent_test.
 *   * Default mode is --check: read-only information_schema queries plus one
 *     aggregate COUNT on the three obsolete columns. No applicant row is selected.
 *   * --apply additionally requires --backup-confirmed (the operator states a
 *     dump of the copy exists) and refuses to run if any row still holds a
 *     value in the obsolete columns unless --discard-pending-codes is given.
 *   * The ALTER is a single statement. DDL in MariaDB/MySQL commits implicitly,
 *     so recovery from a bad run is "restore the copy from its dump"; the
 *     structure alone can be re-added with rollback_restore_mobile_code_columns.sql
 *     (values cannot be recovered that way).
 *   * After --apply the tool re-reads the schema and verifies that the columns
 *     are gone, that the preserved columns have identical definitions, and that
 *     the users row count did not change. It exits non-zero on any mismatch.
 *
 * Usage (PowerShell, against a COPY only; values are examples):
 *   $env:APP_ENV='testing'; $env:SMS_PROVIDER='test'; $env:DB_HOST='127.0.0.1'
 *   $env:DB_NAME='virac_solo_parent_test'; $env:DB_USER='<dedicated test account>'; $env:DB_PASS='<password>'
 *   php database/migrate_drop_mobile_code_columns.php --check
 *   php database/migrate_drop_mobile_code_columns.php --apply --backup-confirmed
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}

const OBSOLETE_COLUMNS = [
    // name => expected [COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT]
    'mobile_code'            => ['varchar(255)', 'YES', null],
    'mobile_code_expires_at' => ['datetime', 'YES', null],
    'mobile_code_attempts'   => ['tinyint(3) unsigned', 'NO', '0'],
];
const PRESERVED_COLUMNS = ['mobile', 'mobile_verified_at', 'password_reset_code', 'password_reset_expires_at'];

$args = array_slice($argv, 1);
$allowed = ['--check', '--apply', '--backup-confirmed', '--discard-pending-codes'];
if (array_diff($args, $allowed)
    || (in_array('--check', $args, true) === in_array('--apply', $args, true))) {
    fwrite(STDERR, "Usage: php database/migrate_drop_mobile_code_columns.php --check\n"
        . "       php database/migrate_drop_mobile_code_columns.php --apply --backup-confirmed [--discard-pending-codes]\n");
    exit(2);
}
$apply = in_array('--apply', $args, true);

require __DIR__ . '/tool_safety.php';
try {
    require_isolated_database_tool_environment();
    if ($apply && !in_array('--backup-confirmed', $args, true)) {
        throw new RuntimeException('--apply needs --backup-confirmed: dump the database copy first (see docs/database-pruning-report.md).');
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

require __DIR__ . '/../config/database.php';
if (DB_NAME !== 'virac_solo_parent_test' || getenv('DB_NAME') !== DB_NAME) {
    fwrite(STDERR, "Refusing to run: the connected database is not the isolated virac_solo_parent_test copy.\n");
    exit(1);
}

/** Column metadata of users, keyed by column name (structure only, no row data). */
function users_columns(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, ORDINAL_POSITION
                           FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $stmt->execute(['users']);
    $columns = [];
    foreach ($stmt->fetchAll() as $row) {
        $columns[$row['COLUMN_NAME']] = $row;
    }
    return $columns;
}

/** Everything about a column except its position, so a preserved column can be compared before and after. */
function column_fingerprint(array $row): string
{
    unset($row['ORDINAL_POSITION']);
    return json_encode($row);
}

/** @return string[] problems that must block the change (empty = safe to proceed) */
function precondition_problems(PDO $pdo, array $columns): array
{
    $problems = [];
    foreach (OBSOLETE_COLUMNS as $name => [$type, $nullable, $default]) {
        if (!isset($columns[$name])) {
            $problems[] = "users.$name is missing (already removed or an unexpected schema).";
            continue;
        }
        $actual = $columns[$name];
        // MariaDB reports a NULL default as the string 'NULL' on 10.2.7+, and a numeric default as '0'.
        $actualDefault = $actual['COLUMN_DEFAULT'] === 'NULL' ? null : $actual['COLUMN_DEFAULT'];
        if (strtolower($actual['COLUMN_TYPE']) !== $type || $actual['IS_NULLABLE'] !== $nullable || $actualDefault !== $default) {
            $problems[] = "users.$name differs from the expected definition ($type, nullable=$nullable, default=" . var_export($default, true) . ').';
        }
    }
    foreach (PRESERVED_COLUMNS as $name) {
        if (!isset($columns[$name])) {
            $problems[] = "users.$name must exist and is missing; refusing to continue.";
        }
    }
    $names = array_map(fn($c) => "'" . $c . "'", array_keys(OBSOLETE_COLUMNS));
    $in = implode(',', $names);
    $checks = [
        'an index or key uses an obsolete column' => "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ($in)",
        'a foreign key uses an obsolete column' => "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_NAME = 'users' AND COLUMN_NAME IN ($in)) OR (REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME IN ($in)))",
        'a trigger mentions an obsolete column' => "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND (ACTION_STATEMENT LIKE '%mobile\\_code%')",
        'a view mentions an obsolete column' => "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND VIEW_DEFINITION LIKE '%mobile\\_code%'",
        'a check constraint mentions an obsolete column' => "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CHECK_CLAUSE LIKE '%mobile\\_code%'",
    ];
    foreach ($checks as $message => $sql) {
        try {
            if ((int) $pdo->query($sql)->fetchColumn() > 0) {
                $problems[] = ucfirst($message) . '; resolve it before dropping the columns.';
            }
        } catch (PDOException $e) {
            // CHECK_CONSTRAINTS does not exist on older MariaDB; any other failure must not be silent.
            if (!str_contains($sql, 'CHECK_CONSTRAINTS')) {
                throw $e;
            }
            echo "Note: CHECK_CONSTRAINTS is not available on this server; check constraints were not inspected.\n";
        }
    }
    return $problems;
}

$exitCode = 0;
try {
    $pdo->exec('SET SESSION lock_wait_timeout = 10');
    $columns = users_columns($pdo);
    echo 'Database: ' . DB_NAME . ' (isolated copy); mode: ' . ($apply ? 'APPLY' : 'check only') . PHP_EOL;

    $problems = precondition_problems($pdo, $columns);
    if ($problems) {
        foreach ($problems as $problem) {
            echo 'BLOCKED: ' . $problem . PHP_EOL;
        }
        echo "No changes applied.\n";
        exit(1);
    }

    // Aggregate only: how many accounts still hold a value in the obsolete columns. No row is selected.
    $pending = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE mobile_code IS NOT NULL OR mobile_code_expires_at IS NOT NULL OR mobile_code_attempts <> 0')->fetchColumn();
    $rowsBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "Preconditions met: the three columns match the expected definitions; no index, foreign key, trigger or view uses them.\n";
    echo "Accounts holding a value in an obsolete column: $pending of $rowsBefore.\n";

    if (!$apply) {
        echo "Check only: nothing was changed. Statement that --apply would run:\n";
        echo "  ALTER TABLE users DROP COLUMN mobile_code, DROP COLUMN mobile_code_expires_at, DROP COLUMN mobile_code_attempts;\n";
        exit(0);
    }
    if ($pending > 0 && !in_array('--discard-pending-codes', $args, true)) {
        echo "BLOCKED: $pending account(s) still hold a value in the obsolete columns. Nothing in the application reads them,\n"
            . "but dropping them discards that data. Re-run with --discard-pending-codes to confirm, after the dump is verified.\n";
        exit(1);
    }

    $before = [];
    foreach (PRESERVED_COLUMNS as $name) {
        $before[$name] = column_fingerprint($columns[$name]);
    }
    $pdo->exec('ALTER TABLE users DROP COLUMN mobile_code, DROP COLUMN mobile_code_expires_at, DROP COLUMN mobile_code_attempts');

    $after = users_columns($pdo);
    $failures = [];
    foreach (array_keys(OBSOLETE_COLUMNS) as $name) {
        if (isset($after[$name])) {
            $failures[] = "users.$name still exists.";
        }
    }
    foreach (PRESERVED_COLUMNS as $name) {
        if (!isset($after[$name]) || column_fingerprint($after[$name]) !== $before[$name]) {
            $failures[] = "users.$name changed or disappeared.";
        }
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== $rowsBefore) {
        $failures[] = 'The users row count changed.';
    }
    if ($failures) {
        foreach ($failures as $failure) {
            echo 'VERIFY FAILED: ' . $failure . PHP_EOL;
        }
        echo "Restore the copy from its dump before continuing.\n";
        exit(1);
    }
    echo "Applied and verified: obsolete columns removed; preserved columns identical; users row count unchanged ($rowsBefore).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL . "If a change may have been made, restore the copy from its dump.\n");
    $exitCode = 1;
}
exit($exitCode);
