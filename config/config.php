<?php
/**
 * App-wide constants. Adjust BASE_URL if the project folder name
 * or virtual host differs from the default XAMPP setup.
 */

// Buffer all output for the whole request. Several pages render shared
// header/nav markup before a tab's own controller logic runs (e.g. login,
// apply) and that logic may still need to redirect() after a form post.
// Buffering means header() keeps working no matter how much HTML already
// echoed, regardless of the server's own output_buffering ini setting.
ob_start();

// e.g. http://localhost/virac-soloparent
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    // Strip a trailing /admin (or any subfolder) so BASE_URL always points at the app root.
    $root = $script;
    if (basename($root) === 'admin') {
        $root = dirname($root);
    }
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    define('BASE_URL', $scheme . '://' . $host . $root);
}

define('APP_NAME', 'Virac Solo Parent ID Online Portal');
define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage/uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5MB
define('ALLOWED_UPLOAD_EXT', ['jpg', 'jpeg', 'png', 'pdf']);
define('ALLOWED_UPLOAD_MIME', ['image/jpeg', 'image/png', 'application/pdf']);

// SMS provider (Semaphore PH). Leave SMS_API_KEY blank to run in "log only" mode.
define('SMS_PROVIDER', 'semaphore');
define('SMS_API_KEY', '');
define('SMS_SENDER_NAME', 'VIRACMSWD');

date_default_timezone_set('Asia/Manila');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
