<?php
/**
 * Checks database/migrate_renewal_requirements.sql in a disposable database, with SYNTHETIC categories 10 to 18 that carry the
 * same document keys as the real new-application lists (no row is copied from any existing database).
 *   - every category gets exactly the intended renewal documents, in order, each to be uploaded fresh;
 *   - a renewal asks FEWER documents than a new application in every category;
 *   - one-time proofs (birth, marriage, death certificates, decrees...) are never asked at renewal;
 *   - running the migration twice changes nothing; a document added by an administrator is kept.
 *
 *   php database/test_renewal_requirements.php   (isolated test environment only)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
$testDb = 'renewal_test_' . bin2hex(random_bytes(6));
validate_temporary_database_target($testDb, 'renewal_test_');
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/RenewalRequirement.php';

function rr_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }

/** New-application documents (keys only) per active category, as configured in the system. */
$newApplication = [
    10 => ['photo1x1', 'birthCert', 'marriageOrAffidavit', 'witnessAffidavit', 'abandonmentRecord', 'barangayCareAffidavit', 'soleCareAffidavit'],
    11 => ['birthCert', 'marriageCert', 'judicialDecree', 'soleCareAffidavit'],
    12 => ['photo1x1', 'birthCert', 'guardianshipProof', 'soleCareAffidavit', 'barangayCareAffidavit'],
    13 => ['photo1x1', 'birthCert', 'cenomar', 'barangayCareAffidavit', 'soleCareAffidavit'],
    14 => ['photo1x1', 'birthCert', 'marriageOrCenomar', 'detentionCertificate', 'soleCareAffidavit', 'barangayCareAffidavit'],
    15 => ['birthCert', 'complaintAffidavit', 'incidentMedicalRecord', 'soleCareAffidavit'],
    16 => ['photo1x1', 'birthCert', 'marriageCert', 'deathCert', 'soleCareAffidavit', 'barangayCareAffidavit'],
    17 => ['birthCert', 'marriageCert', 'separationProof', 'soleCareAffidavit'],
    18 => ['photo1x1', 'birthCert', 'ofwRelationshipProof', 'ofwEmploymentContract', 'ofwPassportProof', 'ofwIncomeProof', 'barangayCareAffidavit', 'soleCareAffidavit'],
];
/** What a renewal must ask, in order. */
$expected = [
    10 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit'],
    11 => ['soleCareAffidavit'],
    12 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit'],
    13 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit'],
    14 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit', 'detentionCertificate'],
    15 => ['soleCareAffidavit'],
    16 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit'],
    17 => ['soleCareAffidavit'],
    18 => ['photo1x1', 'barangayCareAffidavit', 'soleCareAffidavit', 'ofwPassportProof'],
];
$oneTime = ['birthCert', 'marriageCert', 'marriageOrAffidavit', 'marriageOrCenomar', 'deathCert', 'judicialDecree', 'cenomar', 'guardianshipProof',
    'witnessAffidavit', 'abandonmentRecord', 'complaintAffidavit', 'incidentMedicalRecord', 'separationProof', 'ofwRelationshipProof', 'ofwEmploymentContract', 'ofwIncomeProof'];

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
    $test->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo = $test;

    // Synthetic catalogue. Category ids 1 to 9 are left empty on purpose: the migration must not touch inactive categories.
    $insertCategory = $test->prepare('INSERT INTO categories (id, name, description, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
    $insertDoc = $test->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, 1, ?)');
    foreach ($newApplication as $categoryId => $keys) {
        $insertCategory->execute([$categoryId, 'Fixture category ' . $categoryId, 'Fixture', $categoryId]);
        foreach ($keys as $order => $key) { $insertDoc->execute([$categoryId, $key, 'Fixture ' . $key, $order + 1]); }
    }
    $count = fn(): int => (int) $test->query('SELECT COUNT(*) FROM renewal_requirements')->fetchColumn();
    rr_assert($count() === 0, 'The disposable database should start with no renewal requirements.');

    $sql = file_get_contents(__DIR__ . '/migrate_renewal_requirements.sql');
    rr_assert(is_string($sql) && $sql !== '', 'The migration file could not be read.');
    $test->exec($sql);
    $afterFirst = $count();
    rr_assert($afterFirst === array_sum(array_map('count', $expected)), 'Unexpected number of renewal requirements: ' . $afterFirst);

    $renewal = new RenewalRequirement($test);
    foreach ($expected as $categoryId => $keys) {
        $plan = $renewal->plan(['id' => 0, 'category_id' => $categoryId, 'type' => 'renewal']);
        $asked = array_column($plan['fresh'], 'doc_key');
        rr_assert($asked === $keys, 'Category ' . $categoryId . ' renewal asks [' . implode(', ', $asked) . '], expected [' . implode(', ', $keys) . '].');
        rr_assert(count($asked) < count($newApplication[$categoryId]), 'Category ' . $categoryId . ': a renewal must ask fewer documents than a new application.');
        rr_assert(!array_intersect($asked, $oneTime), 'Category ' . $categoryId . ': a one-time proof is asked again at renewal.');
        foreach ($plan['fresh'] as $item) {
            rr_assert(trim((string) $item['label']) !== '', 'Category ' . $categoryId . ': ' . $item['doc_key'] . ' has no label.');
        }
        $always = $test->prepare('SELECT COUNT(*) FROM renewal_requirements WHERE category_id = ? AND always_refresh <> 1');
        $always->execute([$categoryId]);
        rr_assert((int) $always->fetchColumn() === 0, 'Category ' . $categoryId . ': every renewal document must be uploaded fresh.');
    }
    $labels = $test->query("SELECT category_id, label FROM renewal_requirements WHERE doc_key IN ('detentionCertificate','ofwPassportProof') ORDER BY category_id")->fetchAll();
    rr_assert(count($labels) === 2 && str_starts_with($labels[0]['label'], 'Updated') && str_starts_with($labels[1]['label'], 'Updated'), 'The two "updated proof" documents lack their renewal wording.');

    foreach ([1, 5, 9] as $inactive) {
        rr_assert(!(int) $test->query('SELECT COUNT(*) FROM renewal_requirements WHERE category_id = ' . $inactive)->fetchColumn(), 'An inactive/unknown category received renewal requirements.');
    }

    // Idempotent, and an administrator's own addition survives a second run.
    $test->prepare("INSERT INTO renewal_requirements (category_id, doc_key, label, always_refresh) VALUES (16, 'adminAdded', 'Added by an administrator', 1)")->execute();
    $test->exec($sql);
    rr_assert($count() === $afterFirst + 1, 'Running the migration again duplicated or removed rows.');
    $kept = $renewal->plan(['id' => 0, 'category_id' => 16, 'type' => 'renewal']);
    rr_assert(in_array('adminAdded', array_column($kept['fresh'], 'doc_key'), true), 'A document added by an administrator was lost.');

    echo 'PASS: renewal documents set for 9 categories (' . $afterFirst . ' rows): fewer than a new application, no one-time proofs, all uploaded fresh, safe to re-run.' . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    $pdo = $live;
    if ($created) { $live->exec('DROP DATABASE `' . $testDb . '`'); }
}
exit($exitCode);
