<?php
/**
 * The sample-account "never text" mark (services/SampleRecords.php) on SYNTHETIC data in a disposable database:
 *   - a number matches however it is written (09..., +639..., with spaces), on its last 10 digits;
 *   - only a sample account marked no_text is never texted; a sample account without the mark, and every other number, is texted;
 *   - without the sample_records table (a real installation) nothing is ever blocked and nothing fails;
 *   - registering twice keeps one row, and the cache follows a change.
 *
 *   php database/test_sample_never_text.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'solo_review_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'solo_review_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/SampleRecords.php';

function sn_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

$live = $pdo;
$created = false;
$exitCode = 0;
try {
    $live->exec('CREATE DATABASE `' . $testDb . '` CHARACTER SET utf8mb4');
    $created = true;
    $test = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

    sn_assert(SampleRecords::digits('09990001006') === '9990001006' && SampleRecords::digits('+639990001006') === '9990001006' && SampleRecords::digits('+63 999 000 1006') === '9990001006', 'The last 10 digits are the same however the number is written.');

    // No sample_records table: a real installation. Nothing is blocked, nothing throws.
    sn_assert(SampleRecords::neverText($test, '09990001006') === false, 'Without the table nothing is blocked.');

    SampleRecords::ensureTable($test);
    SampleRecords::ensureTable($test); // safe to run twice
    SampleRecords::forgetCache();
    sn_assert(SampleRecords::neverText($test, '09990001006') === false, 'An empty table blocks nothing.');

    SampleRecords::register($test, 'user', 1, 'A1', '09990001001', true);
    SampleRecords::register($test, 'user', 2, 'A6', '09990001006', false);   // a sample account that may be texted
    SampleRecords::register($test, 'staff', 3, 'ENCODER', '09990001099', true);
    sn_assert(SampleRecords::neverText($test, '09990001001') === true, 'A marked sample number is never texted.');
    sn_assert(SampleRecords::neverText($test, '+639990001001') === true && SampleRecords::neverText($test, '0999 000 1001') === true, 'It matches in every written form.');
    sn_assert(SampleRecords::neverText($test, '09990001006') === false, 'A sample account without the mark is texted.');
    sn_assert(SampleRecords::neverText($test, '09171234567') === false, 'Any other number is texted.');
    sn_assert(SampleRecords::neverText($test, '09990001099') === false, 'Only user rows count, not the staff row.');
    sn_assert(SampleRecords::neverText($test, '') === false && SampleRecords::neverText($test, '123') === false, 'An empty or short number is never matched.');

    // Registering again changes the one row; the cache follows.
    SampleRecords::register($test, 'user', 2, 'A6', '09990001006', true);
    sn_assert(SampleRecords::neverText($test, '09990001006') === true, 'The mark can be set later.');
    sn_assert((int) $test->query("SELECT COUNT(*) FROM sample_records WHERE kind = 'user' AND ref_id = 2")->fetchColumn() === 1, 'One row per record.');

    echo "PASS: sample never-text: numbers match in every written form, only marked sample accounts are skipped, a real installation (no table) is never affected, the mark can change, one row per record.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL . $e->getFile() . ':' . $e->getLine() . PHP_EOL); $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
