<?php
/** Pure safety-guard checks. No database connection or SQL is used. */
require __DIR__ . '/tool_safety.php';

function safety_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$valid = [
    'APP_ENV' => 'testing', 'DB_HOST' => 'localhost', 'DB_NAME' => 'virac_solo_parent_test',
    'DB_USER' => 'soloparent_test', 'DB_PASS' => 'test-only-secret', 'SMS_PROVIDER' => 'test',
];
safety_check(database_tool_configuration_error($valid) === null, 'Valid isolated test configuration was rejected.');

foreach ([
    [[], 'Missing environment configuration was accepted.'],
    [array_replace($valid, ['DB_NAME' => 'virac_solo_parent']), 'Production database was accepted.'],
    [array_replace($valid, ['DB_NAME' => 'another_database']), 'Ambiguous database name was accepted.'],
    [array_replace($valid, ['APP_ENV' => 'local']), 'Non-testing environment was accepted.'],
    [array_replace($valid, ['SMS_PROVIDER' => 'semaphore']), 'Non-test SMS provider was accepted.'],
] as [$configuration, $message]) {
    safety_check(database_tool_configuration_error($configuration) !== null, $message);
}

// Isolated-database guard: DB_HOST allowlist (exact, case-sensitive loopback values only).
foreach (['localhost', '127.0.0.1'] as $host) {
    safety_check(database_tool_configuration_error(array_replace($valid, ['DB_HOST' => $host])) === null, "Approved loopback DB_HOST '{$host}' was rejected.");
}
foreach (['db.example.com', '192.168.1.10', '8.8.8.8', '::1', 'LOCALHOST', 'localhost.', '127.0.0.1:3306'] as $host) {
    safety_check(database_tool_configuration_error(array_replace($valid, ['DB_HOST' => $host])) !== null, "Non-approved DB_HOST '{$host}' was accepted.");
}

// Isolated-database guard: root account rejection (case-insensitive, whitespace-trimmed); similar names stay allowed.
foreach (['root', 'ROOT', 'Root', "root\t", "root\n"] as $user) {
    safety_check(database_tool_configuration_error(array_replace($valid, ['DB_USER' => $user])) !== null, 'Root DB_USER variant was accepted: ' . json_encode($user));
}
safety_check(database_tool_configuration_error(array_replace($valid, ['DB_USER' => 'rooter'])) === null, "Non-root DB_USER 'rooter' was rejected.");

validate_temporary_database_target('solo_review_test_0123456789ab', 'solo_review_test_');
validate_temporary_database_target('sp_schema_check_0123456789abcdef', 'sp_schema_check_');
foreach (['virac_solo_parent', 'virac_solo_parent_test', 'solo_review_test_../bad'] as $target) {
    try {
        validate_temporary_database_target($target, 'solo_review_test_');
        throw new RuntimeException('Unsafe temporary database target was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage() === 'Unsafe temporary database target was accepted.') {
            throw $e;
        }
    }
}

try {
    require_temporary_database_opt_in([]);
    throw new RuntimeException('Temporary database operation did not require opt-in.');
} catch (RuntimeException $e) {
    safety_check($e->getMessage() !== 'Temporary database operation did not require opt-in.', 'Temporary database opt-in guard failed.');
}
require_temporary_database_opt_in(['--allow-temporary-database']);
function expect_rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        return;
    }
    throw new RuntimeException($message);
}

// Strict database-name validation (no database connection).
foreach (['solo_review_test_0123456789ab', 'solo_review_test_0123456789abcdef'] as $name) {
    validate_temporary_database_target($name, 'solo_review_test_');
}
foreach ([
    'wrong_prefix_0123456789ab' => 'Invalid prefix was accepted.',
    'solo_review_test_0123456789a' => 'Too-short name was accepted.',
    'solo_review_test_0123456789abc' => 'Name of incorrect length was accepted.',
    'solo_review_test_0123456789abcdef0' => 'Over-long name was accepted.',
    'solo_review_test_0123456789AB' => 'Uppercase hex was accepted.',
    'Solo_review_test_0123456789ab' => 'Uppercase prefix was accepted.',
    "solo_review_test_0123456789ab\n" => 'Trailing newline was accepted.',
    "solo_review_test_0123456789abcdef\n" => 'Trailing newline (16 hex) was accepted.',
    'solo_review_test_0123456789ab`' => 'Backtick was accepted.',
    'virac_solo_parent' => 'Production database name was accepted.',
    'virac_solo_parent_test' => 'Isolated test database name was accepted as temporary.',
] as $name => $message) {
    expect_rejected(fn() => validate_temporary_database_target((string) $name, 'solo_review_test_'), $message);
}

// Temporary-database configuration: host restriction, root rejection, explicit settings.
$temporary = ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_USER' => 'solo_review_tester', 'DB_PASS' => 'test-only-secret', 'SMS_PROVIDER' => 'test'];
safety_check(temporary_database_tool_configuration_error($temporary) === null, 'Valid temporary configuration was rejected.');
safety_check(temporary_database_tool_configuration_error(array_replace($temporary, ['DB_HOST' => 'localhost'])) === null, 'localhost was rejected.');
foreach ([
    [[], 'Empty temporary configuration was accepted.'],
    [array_replace($temporary, ['DB_HOST' => 'db.example.com']), 'Remote host was accepted.'],
    [array_replace($temporary, ['DB_HOST' => '192.168.1.10']), 'LAN host was accepted.'],
    [array_replace($temporary, ['DB_USER' => 'root']), 'Root account was accepted.'],
    [array_replace($temporary, ['DB_USER' => ' ROOT ']), 'Root account with different case/whitespace was accepted.'],
    [array_replace($temporary, ['APP_ENV' => 'production']), 'Non-testing APP_ENV was accepted.'],
    [array_replace($temporary, ['SMS_PROVIDER' => 'semaphore']), 'Non-test SMS provider was accepted.'],
    [array_diff_key($temporary, ['DB_PASS' => 1]), 'Missing DB_PASS was accepted.'],
] as [$configuration, $message]) {
    safety_check(temporary_database_tool_configuration_error($configuration) !== null, $message);
}

// Statement splitting and DELIMITER-based trigger parsing.
$sampleSchema = "-- comment\nCREATE TABLE t (id INT);\n\nALTER TABLE t ADD COLUMN c INT;\n"
    . "DELIMITER //\nCREATE TRIGGER trg BEFORE UPDATE ON t FOR EACH ROW BEGIN\n  SET NEW.c = 1;\n  SET NEW.id = 2;\nEND//\nDELIMITER ;\n"
    . "DROP DATABASE IF EXISTS some_db;\nCREATE DATABASE some_db;\nUSE some_db;\n";
$split = temporary_schema_statements($sampleSchema);
safety_check(count($split) === 6, 'Unexpected statement count from the splitter.');
safety_check(str_starts_with($split[0], 'CREATE TABLE t') && !str_contains($split[0], ';'), 'Plain statement was not split cleanly.');
safety_check(str_starts_with($split[2], 'CREATE TRIGGER trg') && substr_count($split[2], ';') === 2 && str_ends_with($split[2], 'END'), 'Trigger body was split at an inner semicolon or kept its delimiter.');
$executable = temporary_schema_executable_statements($sampleSchema);
safety_check(count($executable) === 3, 'Database-level statements were not skipped.');
foreach ($executable as $statement) {
    safety_check(!preg_match('/^(DROP DATABASE|CREATE DATABASE|USE)\b/i', $statement), 'A database-level statement would be executed.');
}
expect_rejected(fn() => temporary_schema_statements("CREATE TABLE t (id INT)\n"), 'Unterminated statement was accepted.');
expect_rejected(fn() => temporary_schema_statements("CREATE TABLE t (id INT\nDELIMITER //\n"), 'DELIMITER inside a pending statement was accepted.');

// Unsupported statements are rejected.
foreach (['DROP TABLE users;', 'INSERT INTO users (id) VALUES (1);', 'GRANT ALL ON *.* TO x;', 'DELETE FROM users;', 'TRUNCATE users;', 'SET GLOBAL sql_mode = "";'] as $unsupported) {
    expect_rejected(fn() => temporary_schema_executable_statements($unsupported . "\n"), 'Unsupported statement was accepted: ' . $unsupported);
}

// Static checks of the dedicated Save Verification test (never executed).
$saveVerificationSource = file_get_contents(__DIR__ . '/test_save_verification.php');
$saveVerificationCode = '';
foreach (token_get_all($saveVerificationSource) as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $saveVerificationCode .= is_array($token) ? $token[1] : $token;
}
$svGuardAt = strpos($saveVerificationCode, 'require_isolated_temporary_database_environment();');
$svConfigAt = strpos($saveVerificationCode, "require __DIR__ . '/../config/config.php';");
safety_check($svGuardAt !== false && $svConfigAt !== false && $svGuardAt < $svConfigAt,
    'test_save_verification.php must call require_isolated_temporary_database_environment() before loading config/config.php.');
safety_check(!preg_match('~\brequire(?:_once)?\b[^;]*config/database\.php~', $saveVerificationCode),
    'test_save_verification.php must never require config/database.php.');
safety_check(str_contains($saveVerificationCode, 'PDO::MYSQL_ATTR_MULTI_STATEMENTS => false'),
    'test_save_verification.php must disable PDO multi-statements.');

foreach (glob(__DIR__ . '/test_*.php') as $path) {
    if (basename($path) === 'test_save_verification.php') {
        continue; // Covered by the dedicated static check above; it uses its own temporary-database guard and never loads the connector.
    }
    $source = file_get_contents($path);
    $guardAt = strpos($source, 'require_isolated_database_tool_environment();');
    $connectorAt = strpos($source, "require __DIR__ . '/../config/database.php';");
    safety_check($guardAt !== false && $connectorAt !== false && $guardAt < $connectorAt,
        basename($path) . ' does not guard before loading the database connector.');
}
$syncSource = file_get_contents(__DIR__ . '/sync_schema.php');
$syncGuardAt = strpos($syncSource, 'require_isolated_database_tool_environment();');
$syncConnectorAt = strpos($syncSource, "require __DIR__ . '/../config/database.php';");
safety_check($syncGuardAt !== false && $syncConnectorAt !== false && $syncGuardAt < $syncConnectorAt,
    'Schema sync does not guard before loading the database connector.');

safety_check(!preg_match('/SELECT\s+1\s+FROM\b/i', $syncSource) && !preg_match('/\bLIMIT\s+1\b/i', $syncSource),
    'Schema sync contains a table-row data probe.');

// Fixtures and seed helper must guard before loading the database connector (static text only).
foreach (['scripts/fixtures/staff-workspace.php', 'scripts/fixtures/offline-layout.php', 'scripts/fixtures/admin-workspace.php', 'database/seed_admin.php'] as $relative) {
    $toolSource = file_get_contents(__DIR__ . '/../' . $relative);
    safety_check($toolSource !== false, $relative . ' could not be read.');
    $toolGuardAt = strpos($toolSource, 'require_isolated_database_tool_environment();');
    $toolConnectorAt = strpos($toolSource, "/config/database.php';");
    safety_check($toolGuardAt !== false && $toolConnectorAt !== false && $toolGuardAt < $toolConnectorAt,
        $relative . ' does not guard before loading the database connector.');
}

foreach (['apply-with-temp-db', 'confirm-target', 'BackupService'] as $removed) {
    safety_check(!str_contains($syncSource, $removed), 'Schema sync still references removed apply/backup support: ' . $removed);
}
safety_check(!preg_match('/\$pdo->exec\(\s*\$statements?/', $syncSource), 'Schema sync executes a planned statement.');

echo "PASS: missing, ambiguous and production configurations rejected; test config, opt-ins, and pre-connector guards validated without a database connection.\n";
