<?php
/**
 * The public home screen's "Required Documents": every document opens its "how to get it" guide (same content as the signed-in
 * Requirements Guide), except the 2x2 photo, which has none. Disposable database, SYNTHETIC categories and documents only.
 *
 *   php database/test_home_requirement_guides.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../services/RequirementGuideService.php';

function hg_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

$live = $pdo;
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $test->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($live->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $test->exec($live->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1]);
    }
    $pdo = $test;
    $GLOBALS['pdo'] = $test;

    $cat = $test->prepare('INSERT INTO categories (id, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    foreach ([[910, 'Fixture category A'], [911, 'Fixture category B'], [912, 'Fixture empty category']] as $i => [$id, $name]) { $cat->execute([$id, $name, 'Fixture', $i + 1]); }
    $doc = $test->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, ?, ?)');
    $doc->execute([910, 'validId', 'Fixture valid ID', 1, 1]);
    $doc->execute([910, 'birthCert', 'Fixture birth certificate', 1, 2]);
    $doc->execute([910, 'photo', 'Fixture ID photo', 1, 3]);
    $doc->execute([911, 'zzNoGuideForThisKey', 'Fixture custom document', 0, 1]);
    $doc->execute([911, 'brgyCert', '<b>Fixture</b> barangay certificate', 1, 2]);

    set_error_handler(function (int $no, string $msg, string $file, int $line) { throw new ErrorException($msg, 0, $no, $file, $line); });
    $user = null;
    ob_start();
    require __DIR__ . '/../includes/portal/overview.php';
    $html = ob_get_clean();
    restore_error_handler();

    $guideCount = substr_count($html, 'class="home-requirement-guide"');
    hg_assert($guideCount === 4, 'Expected 4 documents to open a guide (all but the photo), found ' . $guideCount . '.');
    hg_assert(substr_count($html, '>How to get it<') === 4, 'Each guide row must say "How to get it".');
    hg_assert(str_contains($html, 'Tap a document to see where and how to get it.'), 'The hint line is missing.');
    hg_assert(substr_count($html, '<details') === substr_count($html, '</details>'), 'The page has unbalanced <details> tags.');

    // The guide shown is the real one for that document.
    $valid = RequirementGuideService::instructions('validId');
    hg_assert(str_contains($html, e((string) $valid['where'])), 'The "Where to get it" text of the valid ID guide is missing.');
    foreach (array_slice($valid['steps'], 0, 2) as $step) { hg_assert(str_contains($html, e((string) $step)), 'A request step of the valid ID guide is missing.'); }
    hg_assert(str_contains($html, '>Where to get it<') && str_contains($html, '>What to prepare<') && str_contains($html, '>How to request it<'), 'The guide headings are missing.');
    if (!empty($valid['sources'])) {
        $url = array_values($valid['sources'])[0];
        hg_assert(str_contains($html, 'href="' . e($url) . '" target="_blank" rel="noopener"'), 'Official reference links must open in a new tab safely.');
    }

    // A key with no specific guide still gets the generic one, as in the signed-in guide.
    $fallback = RequirementGuideService::instructions('zzNoGuideForThisKey');
    hg_assert(str_contains($html, e((string) $fallback['where'])), 'A document without a specific guide did not show the generic guidance.');

    // The photo is listed but has no guide; names are escaped; an empty category keeps its message.
    hg_assert(str_contains($html, 'Fixture ID photo'), 'The photo requirement is missing from the list.');
    $photoAt = strpos($html, 'Fixture ID photo');
    $liStart = strrpos(substr($html, 0, $photoAt), '<li');
    $liEnd = strpos($html, '</li>', $photoAt);
    $photoRow = substr($html, $liStart, $liEnd - $liStart);
    hg_assert(!str_contains($photoRow, 'home-requirement-guide') && !str_contains($photoRow, 'How to get it'), 'The photo row must not open a guide.');
    hg_assert(!str_contains($html, '<b>Fixture</b>') && str_contains($html, '&lt;b&gt;Fixture&lt;/b&gt;'), 'A document name was not escaped.');
    hg_assert(str_contains($html, 'Contact MSWDO for the requirements for this category.'), 'An empty category lost its contact message.');
    hg_assert(str_contains($html, 'tab=help'), 'The "Contact MSWDO for help" link is missing.');

    echo "PASS: home screen requirement guides: every document opens its guide (4 of 5; the photo has none), real guide text, generic fallback, escaping, balanced markup.\n";
} catch (Throwable $e) {
    if (ob_get_level() > 0) { ob_end_clean(); }
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
