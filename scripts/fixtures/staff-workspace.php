<?php
/** CLI-only GET previews. No login, writes, QR scans, notification opens or messages. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$actions = ['dashboard.php' => 'dashboard', 'notifications.php' => 'notifications', 'index.php' => 'applications', 'applicants.php' => 'applicants', 'appointments.php' => 'appointments', 'qr_verify.php' => 'qrVerify', 'chat.php' => 'chat', 'reports.php' => 'reports'];
$route = $argv[1] ?? 'dashboard.php';
if (!isset($actions[$route])) { fwrite(STDERR, 'Unknown preview page.'); exit(1); }
$_GET = []; $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/staff/' . $route;
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/controllers/StaffController.php';
$pdo->exec('START TRANSACTION READ ONLY');
$controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $pdo);
(new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, ['id' => 0, 'full_name' => 'UI Preview MSWD Staff', 'username' => 'preview.staff', 'role' => 'mswd_social_worker']);
try { $controller->{$actions[$route]}(); }
finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } }
