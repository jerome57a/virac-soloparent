<?php
// Retired separate MSWD login page. The unified sign-in is login.php; this stub keeps old links, bookmarks and the
// staff/admin redirects working. It runs in the portal's own session only to pass on a pending "session expired" or
// "password changed" notice (as a fixed code, never free text), then sends the visitor to login.php. It never signs
// anyone in and never redirects back here, so it cannot loop.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$notice = (string) ($_SESSION['auth_notice'] ?? '');
unset($_SESSION['auth_notice']);
$code = str_contains($notice, 'expired') ? 'expired' : (str_contains($notice, 'password was changed') ? 'password_changed' : '');
redirect(base_url('login.php' . ($code !== '' ? '?notice=' . $code : '')));
