<?php
/** CLI-only, read-only preview of one Solo Parent section against the ISOLATED test database. Usage: php scripts/fixtures/applicant-section.php <section> */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$section = $argv[1] ?? 'dashboard';
$root = dirname(__DIR__, 2);
$_GET = ['section' => $section]; $_POST = [];
$_SERVER['REQUEST_METHOD'] = 'HEAD';
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/applicant/dashboard.php';
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] . '?section=' . $section;
define('BASE_URL', 'http://localhost/virac-soloparent');
require $root . '/database/tool_safety.php';
require_isolated_database_tool_environment();
require $root . '/config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/includes/data.php';
require APP_ROOT . '/controllers/ApplicantController.php';
$pdo->exec('START TRANSACTION READ ONLY');
try {
    // The applicant with the most applications, so the richest screens render.
    $user = $pdo->query("SELECT u.* FROM users u LEFT JOIN applications a ON a.user_id = u.id WHERE u.role = 'applicant' GROUP BY u.id ORDER BY COUNT(a.id) DESC, u.id LIMIT 1")->fetch();
    if (!$user) { throw new RuntimeException('An existing applicant is needed.'); }
    $user['profile_photo'] = null;
    $c = (new ReflectionClass(ApplicantController::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Controller::class, 'pdo'))->setValue($c, $pdo);
    (new ReflectionProperty(ApplicantController::class, 'user'))->setValue($c, $user);
    $apps = (new Application($pdo))->forUserDetailed((int) $user['id']);
    (new ReflectionProperty(ApplicantController::class, 'applications'))->setValue($c, $apps);
    $titles = (new ReflectionClassConstant(ApplicantController::class, 'SECTION_TITLES'))->getValue();
    $title = $titles[$section] ?? 'Dashboard';
    switch ($section) {
        case 'apply': $result = (new ApplicationController($pdo))->applySection($user); break;
        case 'application': $result = (new ApplicationController($pdo))->statusList($user, $apps); break;
        case 'documents': $result = (new DocumentController($pdo))->handle($user, $apps); break;
        case 'requirements':
            $guides = RequirementGuideService::catalog($pdo);
            $result = ['view' => 'requirements/index.php', 'data' => ['requirementGuides' => $guides, 'selectedGuideKey' => null, 'guideNotFound' => false]]; break;
        case 'appointment': $result = (new AppointmentController($pdo))->handle($user, $apps); break;
        case 'renewal': $result = (new RenewalController($pdo))->handle($user, $apps); $title = $result['title'] ?? $title; break;
        case 'myid': $result = (new ReflectionMethod(ApplicantController::class, 'sectionMyid'))->invoke($c); break;
        case 'notifications': $result = (new ReflectionMethod(ApplicantController::class, 'sectionNotifications'))->invoke($c); break;
        case 'profile': $result = (new ReflectionMethod(ApplicantController::class, 'sectionProfile'))->invoke($c); break;
        case 'help':
            $result = ['view' => 'help.php', 'data' => ['office' => ['name' => setting('office_name'), 'address' => setting('office_address'), 'hours' => setting('office_hours'), 'email' => setting('office_email'), 'phone' => setting('office_phone')], 'hasApplication' => (bool) $apps, 'faqs' => get_faqs($pdo)]]; break;
        default: $result = (new ReflectionMethod(ApplicantController::class, 'sectionDashboard'))->invoke($c);
    }
    (new ReflectionMethod(ApplicantController::class, 'renderLayout'))->invoke($c, $section, $title, $result);
} finally {
    $pdo->exec('ROLLBACK');
}
