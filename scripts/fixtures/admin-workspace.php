<?php
/**
 * CLI-only preview of the real GET controllers and templates. SQL is read-only;
 * no authenticated browser session, messages, exports, or live records are created.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$pages = require dirname(__DIR__, 2) . '/includes/admin-pages.php';
$route = $argv[1] ?? 'notifications.php';
if (!isset($pages[$route]) && $route !== 'account.php') { fwrite(STDERR, 'Unknown preview page.'); exit(1); }
$_GET = []; $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/admin/' . $route;
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/database/tool_safety.php';
require_isolated_database_tool_environment();
require dirname(__DIR__, 2) . '/config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/controllers/AdminController.php';
require APP_ROOT . '/controllers/StaffController.php';
$pdo->exec('START TRANSACTION READ ONLY');
$actions = ['notifications.php' => 'notifications', 'applicants.php' => 'applicants', 'appointments.php' => 'appointments', 'reports.php' => 'reports', 'eligibility_logs.php' => 'eligibilityLogs', 'applications.php' => 'applications', 'users.php' => 'users', 'sms.php' => 'sms', 'settings.php' => 'settings', 'audit_log.php' => 'auditLog'];
$actions['account.php'] = 'account';
$isStaff = in_array($route, ['applicants.php', 'appointments.php', 'account.php'], true);
$class = $isStaff ? StaffController::class : AdminController::class;
// The CLI fixture supplies an authorized presentation identity, never a web login.
$reflection = new ReflectionClass($class);
$controller = $reflection->newInstanceWithoutConstructor();
(new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $pdo);
(new ReflectionProperty($class, $isStaff ? 'staff' : 'admin'))->setValue($controller, ['id' => 0, 'full_name' => 'UI Preview Administrator', 'role' => 'admin', 'username' => 'preview.admin', 'email' => 'preview@example.test', 'mobile' => '', 'last_login_at' => null]);
try {
    $controller->{$actions[$route]}();
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
