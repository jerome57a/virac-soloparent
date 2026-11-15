<?php
/** Fail-closed guards for database test and schema tools; does not connect to MySQL. */

function database_tool_configuration_error(array $env): ?string
{
    foreach (['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'SMS_PROVIDER'] as $key) {
        if (!array_key_exists($key, $env) || !is_string($env[$key]) || trim($env[$key]) === '') {
            return "Missing explicit {$key} environment setting.";
        }
    }
    if ($env['APP_ENV'] !== 'testing') {
        return 'APP_ENV must be explicitly set to testing.';
    }
    if (strtolower($env['SMS_PROVIDER']) !== 'test') {
        return 'SMS_PROVIDER must be explicitly set to test.';
    }
    // Exact, case-sensitive match: values are never trimmed or normalized before comparison.
    if (!in_array($env['DB_HOST'], ['127.0.0.1', 'localhost'], true)) {
        return 'DB_HOST must be exactly 127.0.0.1 or localhost for database tools.';
    }
    if (strtolower(trim($env['DB_USER'])) === 'root') {
        return 'The root account is prohibited. Use a dedicated test account limited to the isolated test database.';
    }
    if ($env['DB_NAME'] === 'virac_solo_parent') {
        return 'The production database is prohibited for database tools.';
    }
    if ($env['DB_NAME'] !== 'virac_solo_parent_test') {
        return 'DB_NAME must explicitly select the isolated virac_solo_parent_test database.';
    }
    return null;
}

function require_isolated_database_tool_environment(): void
{
    $env = [];
    foreach (['APP_ENV', 'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'SMS_PROVIDER'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }
    $error = database_tool_configuration_error($env);
    if ($error !== null) {
        throw new RuntimeException($error . ' Configure process-level test settings; do not use config/local.php or production credentials for tests.');
    }
}

function validate_temporary_database_target(string $target, string $prefix): void
{
    // \z (not $) so a trailing newline is never tolerated.
    if (!preg_match('/\A[a-z][a-z0-9_]{0,47}\z/', $target)
        || !preg_match('/\A' . preg_quote($prefix, '/') . '(?:[a-f0-9]{12}|[a-f0-9]{16})\z/', $target)
        || in_array($target, ['virac_solo_parent', 'virac_solo_parent_test'], true)) {
        throw new RuntimeException('Refusing an invalid temporary database target. Use the script-generated isolated test name.');
    }
}

function require_temporary_database_opt_in(array $arguments): void
{
    if (!in_array('--allow-temporary-database', $arguments, true)) {
        throw new RuntimeException('This mode creates and drops a temporary database. Review the operation and pass --allow-temporary-database explicitly.');
    }
}

/**
 * Settings for tools that build their own disposable database and never select
 * an existing one. DB_NAME is deliberately neither required nor used. Pure check; no connection.
 */
function temporary_database_tool_configuration_error(array $env): ?string
{
    foreach (['APP_ENV', 'DB_HOST', 'DB_USER', 'DB_PASS', 'SMS_PROVIDER'] as $key) {
        if (!array_key_exists($key, $env) || !is_string($env[$key]) || trim($env[$key]) === '') {
            return "Missing explicit {$key} environment setting.";
        }
    }
    if ($env['APP_ENV'] !== 'testing') {
        return 'APP_ENV must be explicitly set to testing.';
    }
    if (strtolower($env['SMS_PROVIDER']) !== 'test') {
        return 'SMS_PROVIDER must be explicitly set to test.';
    }
    if (!in_array($env['DB_HOST'], ['localhost', '127.0.0.1'], true)) {
        return 'DB_HOST must be localhost or 127.0.0.1 for temporary database tools.';
    }
    if (strtolower(trim($env['DB_USER'])) === 'root') {
        return 'The root account is prohibited. Use a dedicated test account limited to solo_review_test_% databases.';
    }
    return null;
}

/** Reads the process environment (never config/local.php) and returns host, user and password. */
function require_isolated_temporary_database_environment(): array
{
    $env = [];
    foreach (['APP_ENV', 'DB_HOST', 'DB_USER', 'DB_PASS', 'SMS_PROVIDER'] as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $env[$key] = $value;
        }
    }
    $error = temporary_database_tool_configuration_error($env);
    if ($error !== null) {
        throw new RuntimeException($error . ' Configure process-level test settings; do not use config/local.php or application credentials for tests.');
    }
    return ['host' => $env['DB_HOST'], 'user' => $env['DB_USER'], 'pass' => $env['DB_PASS']];
}

/** CREATE DATABASE for the validated, script-generated name only. */
function create_temporary_database(PDO $server, string $target, string $prefix): void
{
    validate_temporary_database_target($target, $prefix);
    $server->exec('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

/** DROP DATABASE for the validated, script-generated name only; the name is re-validated here. */
function drop_temporary_database(PDO $server, string $target, string $prefix): void
{
    validate_temporary_database_target($target, $prefix);
    $server->exec('DROP DATABASE `' . $target . '`');
}

/**
 * Splits the project's SQL format (including DELIMITER-wrapped triggers) into statements.
 * Same rules as schema_statements() in sync_schema.php, which cannot be included without its side effects.
 */
function temporary_schema_statements(string $sql): array
{
    $statements = [];
    $delimiter = ';';
    $pending = '';
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^DELIMITER\s+(\S+)/i', trim($line), $match)) {
            if (trim($pending) !== '') {
                throw new RuntimeException('Unterminated statement before DELIMITER.');
            }
            $delimiter = $match[1];
            continue;
        }
        if (trim($line) === '' || str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $pending .= $line . "\n";
        if (str_ends_with(rtrim($pending), $delimiter)) {
            $statements[] = substr(rtrim($pending), 0, -strlen($delimiter));
            $pending = '';
        }
    }
    if (trim($pending) !== '') {
        throw new RuntimeException('Unterminated schema statement.');
    }
    return $statements;
}

/**
 * Statements of schema.sql that may run inside the temporary database. Database-level
 * DROP/CREATE/USE are skipped; anything other than table and trigger DDL aborts the setup.
 */
function temporary_schema_executable_statements(string $sql): array
{
    $executable = [];
    foreach (temporary_schema_statements($sql) as $statement) {
        if (preg_match('/^(DROP DATABASE|CREATE DATABASE|USE)\b/i', ltrim($statement))) {
            continue;
        }
        if (!preg_match('/^(CREATE TABLE|ALTER TABLE|CREATE TRIGGER)\b/i', ltrim($statement))) {
            throw new RuntimeException('Unexpected statement in schema.sql; stopping schema initialization.');
        }
        $executable[] = $statement;
    }
    return $executable;
}
