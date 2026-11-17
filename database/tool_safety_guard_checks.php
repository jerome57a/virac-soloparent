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
    'APP_ENV' => 'testing', 'DB_HOST' => 'localhost', 'DB_PORT' => '3307', 'DB_NAME' => 'virac_solo_parent_test',
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

// Temporary guard uses the same loopback policy: localhost/127.0.0.1 accepted, ::1 and non-loopback rejected.
foreach (['::1', 'LOCALHOST', '10.0.0.5'] as $host) {
    safety_check(temporary_database_tool_configuration_error(array_replace(['APP_ENV' => 'testing', 'DB_HOST' => $host, 'DB_PORT' => '3307', 'DB_USER' => 'solo_review_tester', 'DB_PASS' => 'x', 'SMS_PROVIDER' => 'test'], [])) !== null, "Non-approved temporary DB_HOST '{$host}' was accepted.");
}
// config/app.php (testing mode) must use the identical allowlist (static text only; the file is never loaded here).
$appConfigSource = file_get_contents(__DIR__ . '/../config/app.php');
safety_check($appConfigSource !== false && str_contains($appConfigSource, "in_array(\$testHost, ['localhost', '127.0.0.1'], true)") && !str_contains($appConfigSource, "'::1'"),
    'config/app.php testing DB_HOST allowlist differs from database/tool_safety.php.');

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
$temporary = ['APP_ENV' => 'testing', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307', 'DB_USER' => 'solo_review_tester', 'DB_PASS' => 'test-only-secret', 'SMS_PROVIDER' => 'test'];
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

// DB_PORT guard: explicit decimal port 1024-65535 only; never defaulted, trimmed or normalized (no 3306 fallback).
foreach ([['1024'], ['3307'], ['65535']] as [$port]) {
    safety_check(database_port_error($port) === null, "Valid DB_PORT '{$port}' was rejected.");
    safety_check(database_tool_configuration_error(array_replace($valid, ['DB_PORT' => $port])) === null, "Valid DB_PORT '{$port}' was rejected by the isolated guard.");
    safety_check(temporary_database_tool_configuration_error(array_replace($temporary, ['DB_PORT' => $port])) === null, "Valid DB_PORT '{$port}' was rejected by the temporary guard.");
}
foreach ([
    'empty' => '', 'non-numeric' => 'abc', 'mixed' => '33o7', 'whitespace-padded' => ' 3307', 'trailing whitespace' => '3307 ',
    'trailing newline' => "3307\n", 'inner whitespace' => '33 07', 'below 1024' => '1023', 'privileged' => '80', 'zero' => '0',
    'above 65535' => '65536', 'far above' => '99999', 'negative' => '-3307', 'decimal' => '3307.0', 'hex' => '0xCEB', 'leading zero' => '03307',
] as $label => $port) {
    safety_check(database_port_error($port) !== null, "Invalid DB_PORT ({$label}) was accepted by database_port_error().");
    safety_check(database_tool_configuration_error(array_replace($valid, ['DB_PORT' => $port])) !== null, "Invalid DB_PORT ({$label}) was accepted by the isolated guard.");
    safety_check(temporary_database_tool_configuration_error(array_replace($temporary, ['DB_PORT' => $port])) !== null, "Invalid DB_PORT ({$label}) was accepted by the temporary guard.");
}
safety_check(database_port_error(3307) !== null, 'Non-string DB_PORT was accepted.');
safety_check(database_port_error(null) !== null, 'Null DB_PORT was accepted.');
safety_check(database_tool_configuration_error(array_diff_key($valid, ['DB_PORT' => 1])) !== null, 'Missing DB_PORT was accepted by the isolated guard.');
safety_check(temporary_database_tool_configuration_error(array_diff_key($temporary, ['DB_PORT' => 1])) !== null, 'Missing DB_PORT was accepted by the temporary guard.');
safety_check(database_tool_configuration_error(array_replace($valid, ['DB_PORT' => '3306'])) === null, 'Explicit port 3306 is a valid explicit value; only an absent port may not default to it.');

// Every hand-built MySQL DSN under database/ must carry an explicit port (static text only).
foreach (glob(__DIR__ . '/*.php') as $path) {
    foreach (preg_split('/\R/', (string) file_get_contents($path)) as $lineNo => $line) {
        if (preg_match("/mysql:host=/", $line) && basename($path) !== 'tool_safety_guard_checks.php') {
            safety_check(str_contains($line, ';port=') && preg_match('/;port=.{0,5}(DB_PORT|\$connection\[.port.\])/', $line) === 1,
                basename($path) . ':' . ($lineNo + 1) . ' builds a MySQL DSN without the validated port.');
        }
    }
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

// ---- The one deliberate exemption: the OFFLINE login-identifier test ----------------------------------------------------
// Every other database/test_*.php must call the isolation guard before it loads config/database.php (checked below). This
// single test is OFFLINE by design: an in-memory SQLite database, no config/app.php, no config/database.php, no storage,
// no network. It has no connector to guard, so it is exempt BY NAME, and only while its source keeps that design: the
// checks below fail the guard the moment it includes anything outside a fixed list, opens any other database, reads the
// environment, reaches the network, or starts any process other than a copy of itself. Every file it can reach through
// includes is followed too, and the follow FAILS CLOSED: an include it cannot resolve to a literal path inside the project
// is a failure, except one pinned, explicitly named view-rendering include. Static text and tokens only.
const SAFETY_OFFLINE_TEST = 'test_login_identifiers_offline.php';
/** The only files the offline test may include (paths relative to the project root). */
const SAFETY_OFFLINE_INCLUDES = [
    'config/auth.php', 'includes/functions.php', 'includes/csrf.php', 'models/User.php', 'services/AuthService.php',
    'services/UserManagementService.php', 'controllers/Controller.php', 'controllers/AuthController.php',
];
/**
 * The ONLY non-literal include tolerated in a reached file: Controller::render() requires a view path chosen by its caller.
 * It is pinned by file AND exact expression (whitespace ignored); the same expression anywhere else is still a failure.
 * Limitation: the target of this one include is not statically known.
 */
const SAFETY_OFFLINE_KNOWN_DYNAMIC_INCLUDES = [
    'controllers/Controller.php' => ["APP_ROOT.'/'.ltrim(\$viewPath,'/')"],
];

/** PHP code only: comments and inline HTML removed (string contents kept), so prose in a docblock can neither satisfy nor trip a check. */
function safety_code_only(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

/** Tokens with whitespace, comments, inline HTML and PHP tags removed, so adjacent code tokens are really adjacent. */
function safety_significant_tokens(string $source): array
{
    $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG];
    $significant = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], $skip, true)) {
            continue;
        }
        $significant[] = $token;
    }
    return $significant;
}

/** Lower-cased names of plain function calls (name, optional whitespace, "("); method/static calls and definitions are not calls. */
function safety_called_functions(array $significant): array
{
    $calls = [];
    $notAFunctionCall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW];
    foreach ($significant as $i => $token) {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ($significant[$i + 1] ?? null) !== '(') {
            continue;
        }
        $previous = $significant[$i - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], $notAFunctionCall, true)) {
            continue;
        }
        $calls[] = strtolower(ltrim($token[1], '\\'));
    }
    return $calls;
}

/** The expression after every real include/require keyword (token-based, so the word inside a string never counts). */
function safety_include_expressions(string $source): array
{
    $tokens = token_get_all($source);
    $found = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true)) {
            continue;
        }
        $expression = '';
        for ($j = $i + 1; $j < count($tokens) && $tokens[$j] !== ';'; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $expression .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
        }
        $found[] = trim($expression);
    }
    return $found;
}

/** Why a source file does NOT have the offline-only design. An empty list means it does. */
function safety_offline_source_problems(string $source): array
{
    $code = safety_code_only($source);
    $significant = safety_significant_tokens($source);
    $problems = [];
    foreach (safety_include_expressions($source) as $expression) {
        if (preg_match("~^__DIR__\\s*\\.\\s*'/\\.\\./([A-Za-z0-9_/]+\\.php)'\$~", $expression, $match) !== 1 || !in_array($match[1], SAFETY_OFFLINE_INCLUDES, true)) {
            $problems[] = 'unexpected include/require: ' . $expression;
        }
    }
    if (preg_match_all("~new \\\$class\\('sqlite::memory:'\\)~", $code) !== 1) {
        $problems[] = "the database must be created exactly once, as new \$class('sqlite::memory:')";
    }
    if (preg_match('~\bnew\s+\\\\?PDO\b~i', $code) === 1) {
        $problems[] = 'a PDO connection is created directly';
    }
    if (preg_match('~sqlite:(?!:memory:)~i', $code) === 1) {
        $problems[] = 'a SQLite database other than in-memory';
    }
    // String-level references (DSNs, URLs, configuration names): these live inside string literals, so they are searched there.
    foreach (['mysql:', 'mysqli', 'pgsql:', 'http://', 'https://', 'DB_HOST', 'DB_NAME', 'DB_PORT', 'DB_USER', 'DB_PASS', 'APP_ENV', 'SMS_PROVIDER', 'STORAGE_PATH'] as $forbidden) {
        if (stripos($code, $forbidden) !== false) {
            $problems[] = 'forbidden reference: ' . $forbidden;
        }
    }
    // Code-level primitives: matched on real tokens only (case-insensitive, any whitespace before "("), never inside comments or strings.
    $forbiddenCalls = ['getenv', 'putenv', 'system', 'popen', 'passthru', 'shell_exec', 'proc_open', 'pcntl_exec', 'assert',
        'spl_autoload_register', 'fsockopen', 'pfsockopen'];
    $forbiddenCallPrefixes = ['curl_', 'stream_socket_', 'mysqli_'];
    $calls = safety_called_functions($significant);
    foreach ($calls as $call) {
        if (in_array($call, $forbiddenCalls, true)) {
            $problems[] = 'forbidden call: ' . $call . '()';
        }
        foreach ($forbiddenCallPrefixes as $prefix) {
            if (str_starts_with($call, $prefix)) {
                $problems[] = 'forbidden call: ' . $call . '()';
            }
        }
    }
    foreach ($significant as $i => $token) {
        if ($token === '`') {
            $problems[] = 'forbidden construct: backtick shell execution';
            continue;
        }
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_EVAL) {
            $problems[] = 'forbidden construct: eval';
        } elseif ($token[0] === T_VARIABLE && $token[1] === '$_ENV') {
            $problems[] = 'forbidden reference: $_ENV';
        } elseif ($token[0] === T_VARIABLE && $token[1] === '$_SERVER') {
            // Only plain simulated-request assignments are allowed: $_SERVER['REMOTE_ADDR'] = ...; $_SERVER['REQUEST_METHOD'] = ...;
            $key = $significant[$i + 2] ?? null;
            $allowed = ($significant[$i + 1] ?? null) === '[' && is_array($key) && $key[0] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($key[1], "'\""), ['REMOTE_ADDR', 'REQUEST_METHOD'], true)
                && ($significant[$i + 3] ?? null) === ']' && ($significant[$i + 4] ?? null) === '=';
            if (!$allowed) {
                $problems[] = 'forbidden reference: $_SERVER (only plain assignment to REMOTE_ADDR / REQUEST_METHOD is allowed)';
            }
        } elseif ($token[0] === T_DOUBLE_COLON) {
            $member = $significant[$i + 1] ?? null;
            if (is_array($member) && $member[0] === T_STRING && strtolower($member[1]) === 'connect' && ($significant[$i + 2] ?? null) === '(') {
                $problems[] = 'forbidden call: static connect() such as PDO::connect()';
            }
        }
    }
    // Plain exec() (not a ->exec() SQL call) may appear exactly once: the test re-running ITSELF for the staff/admin portal.
    $processCalls = count(array_keys($calls, 'exec', true));
    if ($processCalls !== 1 || preg_match("~exec\\s*\\(\\s*escapeshellarg\\s*\\(\\s*PHP_BINARY\\s*\\)\\s*\\.\\s*' '\\s*\\.\\s*escapeshellarg\\s*\\(\\s*__FILE__\\s*\\)\\s*\\.\\s*' child '~i", $code) !== 1) {
        $problems[] = 'it starts a process other than a copy of itself';
    }
    return array_values(array_unique($problems));
}

/** True only when $absolute is the root itself or lies below it; "root-evil" never counts as inside "root". */
function safety_path_within_root(string $absolute, string $root, string $separator = DIRECTORY_SEPARATOR): bool
{
    $root = rtrim($root, '/\\');
    return $absolute === $root || str_starts_with($absolute, $root . $separator);
}

/** Resolves a literal include path (starting with "/") against the including file; null when it would leave the project root. */
function safety_resolve_include(string $fromRelative, string $literal): ?string
{
    $directory = dirname($fromRelative);
    $parts = [];
    foreach (explode('/', ($directory === '.' ? '' : $directory) . $literal) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            if ($parts === []) {
                return null;
            }
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }
    return $parts === [] ? null : implode('/', $parts);
}

/**
 * Follows the entry file's literal __DIR__ includes and reports any reached file that loads connection/runtime config, opens its
 * own database, includes something that is not a literal in-project path (fail closed), or cannot be read.
 * $entry is relative to the project root. With $virtualFiles (relative path => source) nothing on disk is read.
 */
function safety_offline_closure_problems(string $entry, ?array $virtualFiles = null): array
{
    $root = (string) realpath(__DIR__ . '/..');
    $problems = [];
    $queue = [$entry];
    $seen = [];
    while ($queue) {
        $relative = array_shift($queue);
        if (isset($seen[$relative])) {
            continue;
        }
        $seen[$relative] = true;
        if ($virtualFiles !== null) {
            $source = $virtualFiles[$relative] ?? null;
        } else {
            $absolute = realpath($root . '/' . $relative);
            $source = ($absolute !== false && safety_path_within_root($absolute, $root)) ? file_get_contents($absolute) : null;
        }
        if (!is_string($source)) {
            $problems[] = $relative . ' could not be read from inside the project root';
            continue;
        }
        $code = safety_code_only($source);
        if (preg_match('~config/(?:database|app|config|storage|local)\.php~', $code) === 1) {
            $problems[] = $relative . ' references a connection or runtime config file';
        }
        if ($relative !== $entry && preg_match('~mysql:host|\bnew\s+\\\\?PDO\b|\bPDO(?:\\\\\w+)?\s*::\s*connect\s*\(~i', $code) === 1) {
            $problems[] = $relative . ' opens its own database connection';
        }
        foreach (safety_include_expressions($source) as $expression) {
            if (preg_match("~^__DIR__\\s*\\.\\s*'(/[^']+\\.php)'\$~", $expression, $match) === 1) {
                $target = safety_resolve_include($relative, $match[1]);
                if ($target === null) {
                    $problems[] = $relative . ' includes a path outside the project root: ' . $expression;
                } else {
                    $queue[] = $target;
                }
                continue;
            }
            if (in_array(preg_replace('~\s+~', '', $expression), SAFETY_OFFLINE_KNOWN_DYNAMIC_INCLUDES[$relative] ?? [], true)) {
                continue; // the single pinned view-rendering include
            }
            $problems[] = $relative . ' has a non-literal include/require that cannot be followed: ' . $expression;
        }
    }
    return $problems;
}

function safety_has_problem(array $problems, string $needle): bool
{
    foreach ($problems as $problem) {
        if (str_contains($problem, $needle)) {
            return true;
        }
    }
    return false;
}

$offlineSource = (string) file_get_contents(__DIR__ . '/' . SAFETY_OFFLINE_TEST);
safety_check($offlineSource !== '', SAFETY_OFFLINE_TEST . ' could not be read.');
safety_check(safety_offline_source_problems($offlineSource) === [], SAFETY_OFFLINE_TEST . ' no longer has the offline-only design that justifies its exemption: ' . implode('; ', safety_offline_source_problems($offlineSource)));
safety_check(safety_offline_closure_problems('database/' . SAFETY_OFFLINE_TEST) === [], SAFETY_OFFLINE_TEST . ' reaches a connection or runtime config file, or an include that cannot be followed: ' . implode('; ', safety_offline_closure_problems('database/' . SAFETY_OFFLINE_TEST)));
// The exemption must stop applying the moment the offline design is broken: each mutation below must be reported.
foreach ([
    'a database connector include' => $offlineSource . "\nrequire __DIR__ . '/../config/database.php';\n",
    'an application config include' => $offlineSource . "\nrequire_once __DIR__ . '/../config/app.php';\n",
    'an include outside the allowed list' => $offlineSource . "\nrequire_once __DIR__ . '/../services/SMSService.php';\n",
    'a dynamic include' => $offlineSource . "\nrequire APP_ROOT . '/config/database.php';\n",
    'a MySQL connection string instead of in-memory SQLite' => str_replace("'sqlite::memory:'", "'mysql:host=127.0.0.1;port=3307;dbname=x'", $offlineSource),
    'a second, file-based SQLite database' => $offlineSource . "\n\$extra = new \\PDO('sqlite:' . __DIR__ . '/x.db');\n",
    'environment access' => $offlineSource . "\n\$env = getenv('DB_NAME');\n",
    'another program started' => $offlineSource . "\nshell_exec('php other.php');\n",
    'a second exec() call' => $offlineSource . "\nexec('whoami');\n",
    'network access' => $offlineSource . "\n\$page = file_get_contents('http://example.test/');\n",
] as $label => $mutated) {
    safety_check(safety_offline_source_problems($mutated) !== [], 'The offline exemption would still apply to a test with ' . $label . '.');
}
// Hardened detection: each mutation must be reported for the stated reason (not merely for any reason).
foreach ([
    'a mixed-case second process call' => [$offlineSource . "\nExec('whoami');\n", 'process other than a copy of itself'],
    'an upper-case second process call' => [$offlineSource . "\n\\EXEC('whoami');\n", 'process other than a copy of itself'],
    'getenv with whitespace before the parenthesis' => [$offlineSource . "\n\$e = getenv   ('PATH');\n", 'forbidden call: getenv()'],
    'system with whitespace before the parenthesis' => [$offlineSource . "\nsystem\t('whoami');\n", 'forbidden call: system()'],
    'putenv' => [$offlineSource . "\nputenv('A=b');\n", 'forbidden call: putenv()'],
    'backtick shell execution' => [$offlineSource . "\n\$o = `whoami`;\n", 'backtick shell execution'],
    'eval' => [$offlineSource . "\neval('return 1;');\n", 'forbidden construct: eval'],
    'assert' => [$offlineSource . "\nassert(true);\n", 'forbidden call: assert()'],
    'pcntl_exec' => [$offlineSource . "\npcntl_exec('/bin/true');\n", 'forbidden call: pcntl_exec()'],
    'spl_autoload_register' => [$offlineSource . "\nspl_autoload_register(function () {});\n", 'forbidden call: spl_autoload_register()'],
    'PDO::connect' => [$offlineSource . "\n\$p = \\PDO::connect('sqlite::memory:');\n", 'static connect()'],
    '$_SERVER read' => [$offlineSource . "\n\$h = \$_SERVER['HTTP_HOST'];\n", 'forbidden reference: $_SERVER'],
    '$_SERVER assignment to an unlisted key' => [$offlineSource . "\n\$_SERVER['DOCUMENT_ROOT'] = '/x';\n", 'forbidden reference: $_SERVER'],
    '$_SERVER comparison instead of assignment' => [$offlineSource . "\n\$ok = \$_SERVER['REMOTE_ADDR'] == '1';\n", 'forbidden reference: $_SERVER'],
    '$_ENV' => [$offlineSource . "\n\$h = \$_ENV['X'];\n", 'forbidden reference: $_ENV'],
] as $label => [$mutated, $needle]) {
    safety_check(safety_has_problem(safety_offline_source_problems($mutated), $needle), 'The offline exemption would not report ' . $label . ' (expected: ' . $needle . ').');
}
// Comments and ordinary string text that merely mention a primitive are not code and must NOT trip the code-level checks.
safety_check(safety_offline_source_problems($offlineSource
    . "\n// getenv('X'); eval('1'); \$_SERVER['HTTP_HOST']; `whoami`; PDO::connect(); Exec('x');\n/* system('x'); assert(1); putenv('A=b'); */\n"
    . "\$note = \"getenv('X') eval( \\\$_SERVER[HTTP_HOST] assert( spl_autoload_register( PDO::connect(\";\n") === [],
    'Comments and string text mentioning a forbidden primitive were treated as code.');

// Closure mutation tests: an in-memory file map stands in for the project, so no real included file is read or changed.
$closureBase = [
    'database/entry.php' => <<<'PHP'
<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/A.php';
require_once __DIR__ . '/../controllers/Controller.php';
PHP,
    'config/auth.php' => <<<'PHP'
<?php
define('SOME_CONSTANT', 1);
PHP,
    'config/database.php' => <<<'PHP'
<?php
// stand-in connector target, present so a reference to it resolves
PHP,
    'config/app.php' => <<<'PHP'
<?php
// stand-in application config target, present so a reference to it resolves
PHP,
    'services/A.php' => <<<'PHP'
<?php
require_once __DIR__ . '/../models/B.php';
class A {}
PHP,
    'models/B.php' => <<<'PHP'
<?php
class B {}
PHP,
    'services/C.php' => <<<'PHP'
<?php
class C {}
PHP,
    'controllers/Controller.php' => <<<'PHP'
<?php
class Controller
{
    protected function render($viewPath)
    {
        require APP_ROOT . '/' . ltrim($viewPath, '/');
    }
}
PHP,
];
safety_check(safety_offline_closure_problems('database/entry.php', $closureBase) === [],
    'The clean in-memory closure fixture was rejected: ' . implode('; ', safety_offline_closure_problems('database/entry.php', $closureBase)));
foreach ([
    'a reached file loading config/database.php' => [['services/A.php' => "<?php\nrequire_once __DIR__ . '/../config/database.php';\n"], 'references a connection or runtime config file'],
    'a reached file loading config/app.php' => [['models/B.php' => "<?php\nrequire_once __DIR__ . '/../config/app.php';\n"], 'references a connection or runtime config file'],
    'a reached file opening its own PDO connection' => [['models/B.php' => "<?php\n\$db = new PDO('sqlite::memory:');\n"], 'opens its own database connection'],
    'a reached file calling PDO::connect' => [['models/B.php' => "<?php\n\$db = PDO::connect('sqlite::memory:');\n"], 'opens its own database connection'],
    'a reached file with a dynamic include' => [['services/A.php' => "<?php\nrequire_once APP_ROOT . '/models/B.php';\n"], 'non-literal include'],
    'a reached file including a variable' => [['models/B.php' => "<?php\ninclude \$path;\n"], 'non-literal include'],
    'a reached file with an include built by dirname()' => [['models/B.php' => "<?php\nrequire_once dirname(__DIR__) . '/config/' . 'database.php';\n"], 'non-literal include'],
    'the pinned view include copied into another file' => [['services/A.php' => "<?php\nrequire APP_ROOT . '/' . ltrim(\$viewPath, '/');\n"], 'non-literal include'],
    'the pinned file with a different dynamic include' => [['controllers/Controller.php' => "<?php\nrequire APP_ROOT . '/config/database.php';\n"], 'non-literal include'],
    'an unsafe indirect include (second hop loads the connector)' => [['services/A.php' => "<?php\nrequire_once __DIR__ . '/C.php';\n", 'services/C.php' => "<?php\nrequire_once __DIR__ . '/../config/database.php';\n"], 'references a connection or runtime config file'],
    'an unsafe indirect include (second hop includes dynamically)' => [['services/A.php' => "<?php\nrequire_once __DIR__ . '/C.php';\n", 'services/C.php' => "<?php\nrequire \$GLOBALS['loader'];\n"], 'non-literal include'],
    'an include that leaves the project root' => [['services/A.php' => "<?php\nrequire_once __DIR__ . '/../../outside/evil.php';\n"], 'outside the project root'],
    'an include of a file that cannot be read' => [['services/A.php' => "<?php\nrequire_once __DIR__ . '/Missing.php';\n"], 'could not be read'],
] as $label => [$changes, $needle]) {
    $problems = safety_offline_closure_problems('database/entry.php', array_replace($closureBase, $changes));
    safety_check(safety_has_problem($problems, $needle), 'The closure check did not report ' . $label . ' (expected: ' . $needle . ').');
}
// The pinned include is tolerated only in its own file, even when written with different spacing.
safety_check(safety_offline_closure_problems('database/entry.php', array_replace($closureBase, ['controllers/Controller.php' => "<?php\nrequire   APP_ROOT.'/'.ltrim( \$viewPath,'/' );\n"])) === [],
    'The pinned view include was not tolerated in its own file.');

// Root boundary: a sibling directory sharing the root's name prefix is not inside the root.
$separator = DIRECTORY_SEPARATOR;
$boundaryRoot = $separator . 'srv' . $separator . 'project';
safety_check(safety_path_within_root($boundaryRoot . $separator . 'a.php', $boundaryRoot, $separator), 'A file inside the root was rejected.');
safety_check(safety_path_within_root($boundaryRoot, $boundaryRoot, $separator), 'The root itself was rejected.');
safety_check(!safety_path_within_root($boundaryRoot . '-evil' . $separator . 'a.php', $boundaryRoot, $separator), 'A sibling directory with the same prefix was accepted as inside the root.');
safety_check(!safety_path_within_root($boundaryRoot . 'x', $boundaryRoot, $separator), 'A path sharing only the root prefix was accepted as inside the root.');
safety_check(safety_resolve_include('services/A.php', '/../models/B.php') === 'models/B.php', 'A literal include was not resolved inside the root.');
safety_check(safety_resolve_include('services/A.php', '/../../x.php') === null, 'An include escaping the root was resolved.');
safety_check(SAFETY_OFFLINE_TEST === 'test_login_identifiers_offline.php' && !str_contains(SAFETY_OFFLINE_TEST, '*'), 'The offline exemption must name exactly one file.');

foreach (glob(__DIR__ . '/test_*.php') as $path) {
    if (basename($path) === 'test_save_verification.php') {
        continue; // Covered by the dedicated static check above; it uses its own temporary-database guard and never loads the connector.
    }
    if (basename($path) === SAFETY_OFFLINE_TEST) {
        continue; // The single named exemption; its offline-only design was verified above, and any change to it fails the guard.
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
