<?php
/**
 * Bootstrap only: output buffering, BASE_URL detection, timezone, session
 * setup. App-wide constants live in constants.php, auth policy in
 * auth.php -- both required below.
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
    // Strip a trailing /admin, /staff, or /applicant (the app's
    // one-level-deep script subfolders) so BASE_URL always points at the app root.
    $root = $script;
    if (in_array(basename($root), ['admin', 'staff', 'applicant'], true)) {
        $root = dirname($root);
    }
    if ($root === '/' || $root === '\\') {
        $root = '';
    }
    define('BASE_URL', $scheme . '://' . $host . $root);
}

date_default_timezone_set('Asia/Manila');

require __DIR__ . '/constants.php';
require __DIR__ . '/auth.php';

// 'secure' only when actually served over HTTPS -- forcing it on would
// stop the session cookie being sent at all on a plain-HTTP dev install
// (like this local XAMPP setup), silently breaking every login.
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
