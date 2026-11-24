<?php
/**
 * CLI-only preview of the Children step of a new application (views/applicant/application/step-children.php) with a given draft.
 * No database, no login, no writes. Usage: php scripts/fixtures/children-step.php [none|pwd|young]
 *   none  a child over 22 without a disability (nothing qualifies; the answer is Yes, nobody ticked)
 *   pwd   a child over 22 with the tick
 *   young a child of 10 (the answer is No)
 *   docs  the Documents step (step 3) for the 'pwd' draft, with the extra PWD ID or medical certificate requirement
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$state = $argv[1] ?? 'young';
$_SESSION = ['csrf_token' => str_repeat('a', 64)];
$_SERVER['SCRIPT_NAME'] = '/virac-soloparent/applicant/apply.php';
define('BASE_URL', 'http://localhost/virac-soloparent');
require dirname(__DIR__, 2) . '/config/constants.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/config/settings.php'; // setting() falls back to its defaults without a database
require APP_ROOT . '/services/SoloParentChildRules.php';
$stepView = $state === 'docs' ? 'docs' : 'children';
if ($state === 'docs') { $state = 'pwd'; }
$years = fn(int $n) => date('Y-m-d', strtotime("-$n years -40 days"));
$draft = ['children' => match ($state) {
    'none' => [['name' => 'Pedro Santos', 'birthdate' => $years(30), 'disability' => false]],
    'pwd' => [['name' => 'Juan Santos', 'birthdate' => $years(30), 'disability' => true], ['name' => 'Maria Santos', 'birthdate' => $years(10), 'disability' => false]],
    default => [['name' => 'Maria Santos', 'birthdate' => $years(10), 'disability' => false]],
}, 'hasPwdChild' => $state !== 'young'];
$errors = $state === 'none' ? ['children' => 'x'] : [];
$isApplicationWizard = true;
?><!doctype html>
<html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Children step</title>
<link rel="stylesheet" href="<?= public_asset('lib/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/style.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/dashboard.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences-surfaces.css') ?>">
<link rel="stylesheet" href="<?= public_asset('css/preferences.css') ?>">
</head><body>
<div class="portal-container application-wizard"><div class="dash-card">
<?php if ($stepView === 'docs') {
    $draft['documents'] = []; $catOnlyKeys = [];
    $docDefs = [['doc_key' => 'valid_id', 'label' => 'Valid government ID', 'is_required' => 1], SoloParentChildRules::proofRequirement($draft['children'], date('Y-m-d'))];
    require APP_ROOT . '/views/applicant/application/step-documents.php';
} else { require APP_ROOT . '/views/applicant/application/step-children.php'; } ?>
</div></div>
<script src="<?= public_asset('js/translations.js') ?>"></script>
<script src="<?= public_asset('js/preferences.js') ?>"></script>
</body></html>
