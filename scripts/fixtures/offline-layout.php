<?php
/** CLI-only previews. HEAD skips automatic review writes; SQL forbids all writes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$portal = $argv[1] ?? 'staff';
if (!in_array($portal, ['staff', 'applicant'], true)) { exit(1); }
$_GET = []; $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/' . $portal . '/' . ($portal === 'staff' ? 'application.php' : 'dashboard.php');
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/database/tool_safety.php';
require_isolated_database_tool_environment();
require dirname(__DIR__, 2) . '/config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/includes/data.php';
$pdo->exec('START TRANSACTION READ ONLY');
try {
    if ($portal === 'staff') {
        require APP_ROOT . '/controllers/StaffController.php';
        $id = $pdo->query('SELECT id FROM applications ORDER BY id DESC LIMIT 1')->fetchColumn();
        if (!$id) { throw new RuntimeException('An existing application is needed for the review preview.'); }
        $_GET = ['id' => (int) $id];
        $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $pdo);
        (new ReflectionProperty(StaffController::class, 'staff'))->setValue($controller, ['id' => 0, 'full_name' => 'Offline Layout Preview', 'username' => 'preview.staff', 'role' => 'mswd_social_worker']);
        $controller->application();
    } else {
        require APP_ROOT . '/controllers/ApplicantController.php';
        $user = $pdo->query("SELECT * FROM users WHERE role = 'applicant' ORDER BY id LIMIT 1")->fetch();
        if (!$user) { throw new RuntimeException('An existing applicant is needed for the dashboard preview.'); }
        // No authenticated browser session: exercise the initials avatar variant.
        $user['profile_photo'] = null;
        $controller = (new ReflectionClass(ApplicantController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Controller::class, 'pdo'))->setValue($controller, $pdo);
        (new ReflectionProperty(ApplicantController::class, 'user'))->setValue($controller, $user);
        (new ReflectionProperty(ApplicantController::class, 'applications'))->setValue($controller, (new Application($pdo))->forUserDetailed((int) $user['id']));
        $result = (new ReflectionMethod(ApplicantController::class, 'sectionDashboard'))->invoke($controller);
        (new ReflectionMethod(ApplicantController::class, 'renderLayout'))->invoke($controller, 'dashboard', 'Dashboard', $result);
    }
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}
