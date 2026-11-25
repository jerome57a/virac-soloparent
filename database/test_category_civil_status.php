<?php
/**
 * The civil status filters the Solo Parent category (services/CategoryCivilStatus.php, ApplicationService step 1,
 * database/migrate_category_civil_status.php) on SYNTHETIC data in a disposable database:
 *   - each civil status fits exactly the categories of the agreed table, plus the ones for every civil status;
 *   - a category with no setting (NULL) fits everyone; an unknown civil status filters nothing; a setting with no known status is ignored;
 *   - the server refuses a mismatched pair ("This category does not apply ...") and accepts a fitting one, for every pair;
 *   - without the column (an older database) nothing is refused or filtered;
 *   - the migration adds the column, fills only the six situational categories, changes nothing on a second run and never overwrites a value.
 *
 *   php database/test_category_civil_status.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/data.php';
require_once __DIR__ . '/../services/ApplicationService.php';
require_once __DIR__ . '/../services/CategoryCivilStatus.php';
$_SESSION = $_SESSION ?? [];

function cc_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

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
    unset($GLOBALS['__settings_cache']);
    $hasCol = fn() => (bool) $test->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'civil_statuses'")->fetchColumn();
    if ($hasCol()) { $test->exec('ALTER TABLE categories DROP COLUMN civil_statuses'); }   // start like an older database
    CategoryCivilStatus::forgetColumn();

    // The nine categories, as in seed.sql / the live database.
    $names = ['Abandonment by the spouse', 'Declaration of nullity or annulment of marriage', 'Legal guardian, adoptive parent, or foster parent', 'Unmarried father or mother who keeps and rears the child/children',
        'Detention or imprisonment of the spouse', 'Child/children as a consequence of rape', 'Death of the spouse', 'Legal or de facto separation of spouse for at least six months', 'Spouse or family member of an OFW'];
    $ins = $test->prepare('INSERT INTO categories (name, description, sort_order, is_active) VALUES (?, ?, ?, 1)');
    foreach ($names as $i => $n) { $ins->execute([$n, $n, $i + 1]); }
    $id = fn(string $name) => (int) $test->query('SELECT id FROM categories WHERE name = ' . $test->quote($name))->fetchColumn();

    // Without the column: nothing is filtered or refused.
    $service = new ApplicationService($test);
    $draft = fn(string $civil, ?int $category) => ['fullName' => 'Maria Santos', 'barangay' => 'Concepcion', 'address' => 'Purok 2', 'contact' => '09990001001', 'email' => '', 'birthdate' => '1986-04-12',
        'sex' => 'Female', 'civilStatus' => $civil, 'categoryId' => $category];
    cc_assert(CategoryCivilStatus::columnExists($test) === false, 'An older database has no column.');
    cc_assert($service->validateStep(1, $draft('Single', $id('Death of the spouse'))) === [], 'Without the column any pair is accepted.');

    // The migration: check run changes nothing; apply adds the column and fills only the six situational categories.
    $run = function (string ...$args) use ($test, $testDb) {
        $php = PHP_BINARY;
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/migrate_category_civil_status.php') . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        // The tool runs as it does for real (no testing guard), pointed at THIS disposable database on the isolated instance.
        $env = array_filter(getenv(), 'is_string');
        unset($env['APP_ENV'], $env['TEST_STORAGE_ROOT'], $env['TEST_DOCUMENT_ROOT']);
        $proc = proc_open($cmd, $descriptors, $pipes, null, array_merge($env, ['DB_NAME' => $testDb, 'DB_HOST' => DB_HOST, 'DB_PORT' => (string) DB_PORT, 'DB_USER' => DB_USER, 'DB_PASS' => DB_PASS]));
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    };
    $out = $run();
    cc_assert(str_contains($out, '[would] add categories.civil_statuses') && !$hasCol(), 'A check run changes nothing: ' . $out);
    $out = $run('apply');
    cc_assert($hasCol() && str_contains($out, 'Done.'), 'Apply adds the column: ' . $out);
    CategoryCivilStatus::forgetColumn();
    $stored = $test->query('SELECT name, civil_statuses FROM categories')->fetchAll(PDO::FETCH_KEY_PAIR);
    $expect = ['Abandonment by the spouse' => 'Married,Separated', 'Declaration of nullity or annulment of marriage' => 'Annulled', 'Legal guardian, adoptive parent, or foster parent' => null,
        'Unmarried father or mother who keeps and rears the child/children' => 'Single', 'Detention or imprisonment of the spouse' => 'Married,Separated', 'Child/children as a consequence of rape' => null,
        'Death of the spouse' => 'Widowed', 'Legal or de facto separation of spouse for at least six months' => 'Married,Separated', 'Spouse or family member of an OFW' => null];
    cc_assert($stored === $expect, 'The six situational categories are filled, the three general ones stay empty: ' . json_encode($stored));
    $again = $run('apply');
    cc_assert(str_contains($again, 'Nothing to change') && !str_contains($again, '[would]'), 'A second run changes nothing: ' . $again);
    $test->exec("UPDATE categories SET civil_statuses = 'Single,Widowed' WHERE name = 'Death of the spouse'");
    $run('apply');
    cc_assert($test->query("SELECT civil_statuses FROM categories WHERE name = 'Death of the spouse'")->fetchColumn() === 'Single,Widowed', 'A value that was set is never overwritten.');
    $test->exec("UPDATE categories SET civil_statuses = 'Widowed' WHERE name = 'Death of the spouse'");

    // The table: each civil status fits its categories plus the general ones.
    $cats = $test->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
    $general = ['Legal guardian, adoptive parent, or foster parent', 'Child/children as a consequence of rape', 'Spouse or family member of an OFW'];
    $table = [
        'Single' => ['Unmarried father or mother who keeps and rears the child/children'],
        'Married' => ['Abandonment by the spouse', 'Detention or imprisonment of the spouse', 'Legal or de facto separation of spouse for at least six months'],
        'Widowed' => ['Death of the spouse'],
        'Separated' => ['Abandonment by the spouse', 'Detention or imprisonment of the spouse', 'Legal or de facto separation of spouse for at least six months'],
        'Annulled' => ['Declaration of nullity or annulment of marriage'],
    ];
    foreach ($table as $status => $own) {
        $shown = array_column(CategoryCivilStatus::filter($cats, $status), 'name');
        sort($shown); $want = array_merge($own, $general); sort($want);
        cc_assert($shown === $want, "$status shows: " . json_encode($shown));
    }
    cc_assert(count(CategoryCivilStatus::filter($cats, '')) === 9 && count(CategoryCivilStatus::filter($cats, 'Divorced')) === 9, 'An empty or unknown civil status filters nothing.');
    cc_assert(CategoryCivilStatus::allowed(['civil_statuses' => 'Nonsense,Other']) === null && CategoryCivilStatus::allowed(['civil_statuses' => ' Single , Married ']) === ['Single', 'Married'], 'A setting with no known status is ignored; spaces are tolerated.');
    cc_assert(CategoryCivilStatus::forBrowser($cats)[0] === ['id' => (int) $cats[0]['id'], 'name' => 'Abandonment by the spouse', 'statuses' => ['Married', 'Separated']], 'What the browser receives.');

    // The server: every pair is accepted or refused exactly as the table says.
    $msg = 'This category does not apply to the civil status you chose. Choose a category that fits, or change your civil status.';
    $checked = 0;
    foreach (CategoryCivilStatus::STATUSES as $status) {
        foreach ($cats as $c) {
            $errors = $service->validateStep(1, $draft($status, (int) $c['id']));
            $fits = in_array($c['name'], array_merge($table[$status], $general), true);
            cc_assert($fits ? $errors === [] : (($errors['circumstance'] ?? '') === $msg), "$status + {$c['name']}: " . json_encode($errors));
            $checked++;
        }
    }
    cc_assert(($service->validateStep(1, $draft('', $id('Death of the spouse')))['civilStatus'] ?? '') === 'Select your civil status.' && !isset($service->validateStep(1, $draft('', $id('Death of the spouse')))['circumstance']), 'No civil status: only the civil status is reported.');
    cc_assert(($service->validateStep(1, $draft('Single', null))['circumstance'] ?? '') === 'Select your solo parent category.', 'No category: asked to select one.');
    cc_assert(CategoryCivilStatus::fitsId($test, 999999, 'Single') === true, 'An unknown category id is left to the existing check.');

    echo "PASS: category and civil status: the migration (adds the column, fills only the six situational categories, no overwrite, safe twice), each civil status lists its table plus the three for everyone, the server accepts or refuses all $checked pairs as the table says, no civil status or category reported as before, and nothing is filtered without the column.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
