<?php
/**
 * CLI-only preview of step 1 of the new application (views/applicant/application/step-personal.php) with SYNTHETIC categories. No database,
 * no login, no writes. Usage: php scripts/fixtures/personal-step.php [empty|single|widowed|married|refit|mismatch|nocolumn]
 *   empty     no civil status yet (the category list is disabled in the browser)
 *   single | widowed | married   that civil status chosen, no category
 *   refit     a saved draft: civil status Widowed, but the category was "Abandonment by the spouse" (asked for again, with a note)
 *   mismatch  the server refused the pair (Single + Death of the spouse): the red message
 *   nocolumn  a database without categories.civil_statuses: nothing is filtered
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$state = $argv[1] ?? 'empty';
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/applicant/apply.php';
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/config/constants.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/includes/data.php';
require APP_ROOT . '/config/settings.php';
require APP_ROOT . '/services/CategoryCivilStatus.php';
$rows = [
    [10, 'Abandonment by the spouse', 'Married,Separated'],
    [11, 'Declaration of nullity or annulment of marriage', 'Annulled'],
    [12, 'Legal guardian, adoptive parent, or foster parent', null],
    [13, 'Unmarried father or mother who keeps and rears the child/children', 'Single'],
    [14, 'Detention or imprisonment of the spouse', 'Married,Separated'],
    [15, 'Child/children as a consequence of rape', null],
    [16, 'Death of the spouse', 'Widowed'],
    [17, 'Legal or de facto separation of spouse for at least six months', 'Married,Separated'],
    [18, 'Spouse or family member of an OFW', null],
];
$categories = array_map(fn($r) => ['id' => $r[0], 'name' => $r[1], 'civil_statuses' => $state === 'nocolumn' ? null : $r[2], 'is_active' => 1], $rows);
$categoryMap = array_column($categories, 'name', 'id');
$categoryDocs = [];
foreach ($categories as $c) { $categoryDocs[$c['id']] = [['label' => 'Fixture document for ' . $c['name'], 'url' => '#']]; }
$civil = ['empty' => '', 'single' => 'Single', 'widowed' => 'Widowed', 'married' => 'Married', 'refit' => 'Widowed', 'mismatch' => 'Single', 'nocolumn' => 'Widowed'][$state] ?? '';
$draft = ['fullName' => 'Maria Santos', 'birthdate' => '1986-04-12', 'sex' => 'Female', 'civilStatus' => $civil, 'barangay' => 'Concepcion', 'address' => 'Purok 2', 'email' => '', 'contact' => '09990001001',
    'categoryId' => $state === 'mismatch' ? 16 : null];
$errors = $state === 'mismatch' ? ['circumstance' => 'This category does not apply to the civil status you chose. Choose a category that fits, or change your civil status.'] : [];
// The controller's saved-draft rule, as in ApplicationController::createPage
$categoryRefit = false;
if ($state === 'refit') { $draft['categoryId'] = 10; foreach ($categories as $c) { if ($c['id'] === 10 && !CategoryCivilStatus::fits($c, $civil)) { $draft['categoryId'] = null; $categoryRefit = true; } } }
$legacyCategoryName = null;
$isApplicationWizard = true;
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Personal step</title>
<link rel="stylesheet" href="<?= public_asset('lib/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/dashboard.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences-surfaces.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences.css') ?>">
</head><body>
<div class="portal-container application-wizard"><div class="dash-card">
<?php require APP_ROOT . '/views/applicant/application/step-personal.php'; ?>
</div></div>
<script src="<?= public_asset('js/translations.js') ?>"></script>
<script src="<?= public_asset('js/preferences.js') ?>"></script>
<script src="<?= public_asset('js/app.js') ?>"></script>
</body></html>
