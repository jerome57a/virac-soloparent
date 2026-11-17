<?php
/**
 * Environment-specific settings. The defaults suit a stock local XAMPP
 * install. Each setting is taken, in order, from a server environment
 * variable of the same name (e.g. SetEnv in Apache, or the shell for cron),
 * then config/local.php (copy local.example.php; git-ignored so real
 * credentials never reach version control), then the default below.
 */
$localConfig = is_file(__DIR__ . '/local.php') ? (require __DIR__ . '/local.php') : [];
$env = function (string $key, $default) use ($localConfig) {
    $fromServer = getenv($key);
    return $fromServer !== false && $fromServer !== '' ? $fromServer : ($localConfig[$key] ?? $default);
};

// Debug mode shows PHP errors and exception details in the browser.
// Keep it off anywhere real applicant data is served.
define('APP_ENV', (string) $env('APP_ENV', 'local'));
define('APP_DEBUG', (bool) $env('APP_DEBUG', false));

// With APP_ENV=testing the database and SMS settings are taken ONLY from the process environment (never from
// config/local.php, which holds the ordinary credentials) and are validated; any problem throws, so a test instance
// that was not given a complete, isolated test configuration refuses to start instead of falling back to the
// ordinary database defaults. This cannot detect a server that omits APP_ENV=testing altogether: that still needs
// explicit operator configuration and verification (see docs/database-tooling-safety.md).
if (APP_ENV === 'testing') {
    $testEnv = static function (string $key): string {
        $value = getenv($key);
        return $value === false ? '' : (string) $value;
    };
    $testFail = static function (string $why): void {
        throw new RuntimeException('Testing configuration refused: ' . $why . ' No default or production setting is used as a fallback.');
    };
    $testHost = $testEnv('DB_HOST');
    $testName = $testEnv('DB_NAME');
    $testUser = $testEnv('DB_USER');
    // Same exact, case-sensitive loopback allowlist as database/tool_safety.php (::1 is rejected by both).
    if (!in_array($testHost, ['localhost', '127.0.0.1'], true)) {
        $testFail('APP_ENV=testing requires the environment variable DB_HOST to be exactly localhost or 127.0.0.1.');
    }
    // Explicit port required (1024-65535); the same validator the database tools use. No 3306 fallback.
    require_once __DIR__ . '/../database/tool_safety.php';
    $testPort = $testEnv('DB_PORT');
    if (($testPortError = database_port_error($testPort)) !== null) {
        $testFail('APP_ENV=testing requires the environment variable DB_PORT. ' . $testPortError);
    }
    if ($testName !== 'virac_solo_parent_test') {
        $testFail('APP_ENV=testing requires the environment variable DB_NAME to be exactly virac_solo_parent_test.');
    }
    if ($testUser === '' || $testUser !== trim($testUser) || strtolower($testUser) === 'root') {
        $testFail('APP_ENV=testing requires the environment variable DB_USER to be set to a dedicated, non-root database user.');
    }
    if ($testEnv('SMS_PROVIDER') !== 'test') {
        $testFail('APP_ENV=testing requires the environment variable SMS_PROVIDER to be exactly test.');
    }
    define('DB_HOST', $testHost);
    define('DB_PORT', (int) $testPort);
    define('DB_NAME', $testName);
    define('DB_USER', $testUser);
    define('DB_PASS', $testEnv('DB_PASS'));
    unset($testEnv, $testFail, $testHost, $testName, $testUser, $testPort, $testPortError);
} else {
    // Optional outside testing: null (no port in the DSN, driver default) when absent, exactly as before.
    $configuredPort = (string) $env('DB_PORT', '');
    if ($configuredPort !== '' && (!preg_match('/\A[0-9]{1,5}\z/', $configuredPort) || (int) $configuredPort < 1 || (int) $configuredPort > 65535)) {
        throw new RuntimeException('DB_PORT, when set, must be a decimal port between 1 and 65535.');
    }
    define('DB_PORT', $configuredPort === '' ? null : (int) $configuredPort);
    unset($configuredPort);
    define('DB_HOST', (string) $env('DB_HOST', 'localhost'));
    define('DB_NAME', (string) $env('DB_NAME', 'virac_solo_parent'));
    define('DB_USER', (string) $env('DB_USER', 'root'));
    define('DB_PASS', (string) $env('DB_PASS', ''));
}

// Uploads, avatars, sessions and logs: APP_ROOT/storage/... in production. With APP_ENV=testing a dedicated,
// validated TEST_STORAGE_ROOT (process environment only, never local.php) is required and ALL of them live inside it
// (uploads, avatars, sessions, logs, tmp); see config/storage.php. Fails closed, never falls back to production.
require_once __DIR__ . '/storage.php';
$testStorageRoot = getenv('TEST_STORAGE_ROOT');
$storageRoots = resolve_storage_roots(APP_ENV, $testStorageRoot === false ? null : $testStorageRoot, APP_ROOT);
define('STORAGE_PATH', $storageRoots['uploads']);
define('AVATAR_STORAGE_PATH', $storageRoots['avatars']);
define('SESSION_PATH', $storageRoots['sessions']);
define('LOG_PATH', $storageRoots['logs']);
define('TEST_TMP_PATH', $storageRoots['tmp']);                 // null outside testing
define('TEST_STORAGE_ROOT_PATH', $storageRoots['root']);       // null outside testing
define('STORAGE_IS_TEST_ROOT', $storageRoots['test']);
unset($testStorageRoot, $storageRoots);

// SMS gateway (services/SMSService.php). SMS_PROVIDER:
//   'test'      -- test mode (default): every message is logged in sms_logs, flagged as not delivered; nothing is sent.
// SMS_API_URL can point at the provider's sandbox or a local mock gateway for testing.
define('SMS_PROVIDER', APP_ENV === 'testing' ? 'test' : strtolower((string) $env('SMS_PROVIDER', 'test'))); // testing: validated above
define('SMS_API_KEY', (string) $env('SMS_API_KEY', ''));
//   'iprog'     -- IPROG SMS (https://sms.iprogtech.com): needs SMS_API_KEY (your IPROG API token); no sender name.
define('SMS_API_URL', (string) $env('SMS_API_URL', 'https://sms.iprogtech.com/api/v1/sms_messages'));
define('SMS_TIMEOUT', max(2, (int) $env('SMS_TIMEOUT', 10)));          // seconds per gateway call
define('SMS_MAX_ATTEMPTS', max(1, (int) $env('SMS_MAX_ATTEMPTS', 3)));  // a failed SMS can be retried up to this many attempts in total

unset($localConfig, $env);
