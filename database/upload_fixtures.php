<?php
/**
 * Helpers for disposable-database tests that submit applications with staged upload files.
 * Synthetic bytes only; no real document, applicant record or existing upload is read.
 *
 * STORAGE_PATH is fixed to storage/uploads and is NOT redirected by the test environment guard, and high ids do
 * not prove that nothing exists at those paths. These helpers therefore fail closed instead of assuming:
 *   - a folder is used only if it lies at STORAGE_PATH/<id> or STORAGE_PATH/drafts/<id> with <id> >= 1800000000
 *     AND does not exist yet (an existing folder is refused, never reused);
 *   - cleanup deletes only the exact files this run created or recorded (staged files, and the stored files of
 *     applications this run submitted, read from the disposable database), and removes a folder only with rmdir,
 *     which fails on any folder that still holds something else. Nothing is deleted recursively or by glob, and
 *     STORAGE_PATH itself, its parents and the shared folders can never match.
 * Independent verification of the test database and of storage is still required before running any test.
 */
const UPLOAD_FIXTURE_MIN_ID = 1800000000;
$GLOBALS['__upload_fixture'] = ['files' => [], 'dirs' => [], 'blockers' => []];
register_shutdown_function('upload_fixture_cleanup'); // also runs after a failed assertion

function upload_fixture_norm(string $path): string
{
    return str_replace('\\', '/', $path);
}

/** True only for STORAGE_PATH/<id> or STORAGE_PATH/drafts/<id>, with <id> a ten-digit number >= UPLOAD_FIXTURE_MIN_ID. */
function upload_fixture_dir_ok(string $dir): bool
{
    $root = rtrim(upload_fixture_norm(STORAGE_PATH), '/') . '/';
    $dir = upload_fixture_norm($dir);
    if (!str_starts_with($dir, $root)) {
        return false;
    }
    return preg_match('#\A(?:drafts/)?([1-9][0-9]{9})\z#', substr($dir, strlen($root)), $m) === 1 && (int) $m[1] >= UPLOAD_FIXTURE_MIN_ID;
}

/** A plain, non-hidden file name directly inside an allowed folder. */
function upload_fixture_file_ok(string $path): bool
{
    $name = basename($path);
    return upload_fixture_dir_ok(dirname($path)) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/', $name) === 1;
}

/** Creates (or re-enters, if this run created it) an allowed folder. An existing folder this run did not create is refused. */
function upload_fixture_claim_dir(string $dir): void
{
    if (!upload_fixture_dir_ok($dir)) {
        throw new RuntimeException('Refusing to use a folder outside the test id range: ' . $dir);
    }
    $known = isset($GLOBALS['__upload_fixture']['dirs'][$dir]);
    if (file_exists($dir) && !$known) {
        throw new RuntimeException('Refusing to use a storage folder that already exists: ' . $dir);
    }
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the test folder: ' . $dir);
    }
    $GLOBALS['__upload_fixture']['dirs'][$dir] = true;
}

function upload_fixture_track_file(string $path): void
{
    if (!upload_fixture_file_ok($path)) {
        throw new RuntimeException('Refusing to track a file outside the allowed test folders: ' . $path);
    }
    $GLOBALS['__upload_fixture']['files'][$path] = true;
}

/** Writes one tracked synthetic file into an allowed folder, claiming the folder first. */
function upload_fixture_write(string $dir, string $name, string $bytes): string
{
    upload_fixture_claim_dir($dir);
    $path = $dir . '/' . $name;
    upload_fixture_track_file($path);
    file_put_contents($path, $bytes);
    return $path;
}

/** Makes the next user ids far above any real id. Application ids are chosen per submission by upload_fixture_prepare_application_id(). */
function upload_fixture_isolate_ids(PDO $test): void
{
    $test->exec('ALTER TABLE users AUTO_INCREMENT = ' . (UPLOAD_FIXTURE_MIN_ID + random_int(1, 1000000)));
}

/**
 * Chooses the id the next application will get (or uses $id), above every existing test id, and refuses it
 * unless the application folder for it and the next id do not exist. Returns the id.
 */
function upload_fixture_prepare_application_id(PDO $test, ?int $id = null): int
{
    if ($id === null) {
        $max = (int) $test->query('SELECT COALESCE(MAX(id), 0) FROM applications')->fetchColumn();
        $id = max(1900000000, $max) + random_int(1000, 100000);
    }
    if ($id < UPLOAD_FIXTURE_MIN_ID) {
        throw new RuntimeException('Refusing an application id below the test range.');
    }
    foreach ([$id, $id + 1] as $candidate) { // +1: a duplicate reference number makes the service retry with the next id
        if (file_exists(STORAGE_PATH . '/' . $candidate)) {
            throw new RuntimeException('Refusing to submit: the storage folder for application ' . $candidate . ' already exists.');
        }
    }
    $test->exec('ALTER TABLE applications AUTO_INCREMENT = ' . $id);
    return $id;
}

/** Records the stored files (and their folders) of every application with id >= $firstId, from the disposable database. */
function upload_fixture_track_applications(PDO $test, int $firstId): void
{
    $stmt = $test->prepare('SELECT application_id, stored_path FROM application_documents WHERE application_id >= ?');
    $stmt->execute([$firstId]);
    foreach ($stmt->fetchAll() as $row) {
        $dir = STORAGE_PATH . '/' . (int) $row['application_id'];
        if (!upload_fixture_dir_ok($dir)) {
            throw new RuntimeException('A submitted application folder is outside the test id range: ' . $dir);
        }
        $GLOBALS['__upload_fixture']['dirs'][$dir] = true;
        upload_fixture_track_file(STORAGE_PATH . '/' . $row['stored_path']);
    }
}

/** ApplicationService::submit() with an unused application folder guaranteed beforehand and its files tracked afterwards. */
function upload_fixture_submit(PDO $test, ApplicationService $service, array $user, array $draft): string
{
    $firstId = upload_fixture_prepare_application_id($test);
    try {
        return $service->submit($user, $draft);
    } finally {
        upload_fixture_track_applications($test, $firstId);
    }
}

/** A plain file where an application folder must go, so storing the files fails. Refused if anything is there already. */
function upload_fixture_make_blocker(int $applicationId): string
{
    $path = STORAGE_PATH . '/' . $applicationId;
    if ($applicationId < UPLOAD_FIXTURE_MIN_ID || file_exists($path)) {
        throw new RuntimeException('Refusing to create the blocker: out of range or already present.');
    }
    file_put_contents($path, 'blocker');
    $GLOBALS['__upload_fixture']['blockers'][$path] = true;
    return $path;
}

/** A valid 1x1 PNG. */
function upload_fixture_png(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
}

/**
 * Stages a synthetic file for $docKey in the applicant's draft folder and records it in the draft, the
 * way DocumentValidationService::attachToDraft() does (move_uploaded_file cannot run on the CLI).
 * A file already staged for the key is replaced and deleted. $bytes defaults to a valid PNG.
 */
function upload_fixture_stage(ApplicationService $service, array $user, array $draft, string $docKey, ?string $bytes = null): array
{
    $dir = $service->draftDir((int) $user['id']);
    $bytes ??= upload_fixture_png();
    $old = $draft['documents'][$docKey]['path'] ?? '';
    $stored = bin2hex(random_bytes(16)) . '.png';
    $path = upload_fixture_write($dir, $stored, $bytes);
    $draft['documents'][$docKey] = [
        'original_name' => $docKey . '.png', 'stored_name' => $stored, 'path' => $path,
        'mime' => 'image/png', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => 'VALID',
    ];
    if ($old !== '' && upload_fixture_file_ok($old) && is_file($old) && !is_link($old)) {
        unlink($old);
    }
    unset($draft['docErrors'][$docKey]);
    return $draft;
}

/** Stages a file for every REQUIRED requirement of the draft's category. */
function upload_fixture_stage_required(ApplicationService $service, Document $documents, array $user, array $draft): array
{
    foreach ($documents->requirementsFor($draft['categoryId'] ?? null) as $requirement) {
        if (!empty($requirement['is_required'])) {
            $draft = upload_fixture_stage($service, $user, $draft, $requirement['doc_key']);
        }
    }
    return $draft;
}

/**
 * Deletes exactly the files this run tracked and the blockers it made, then removes each folder it claimed with
 * rmdir (which refuses a folder that is not empty). Anything else, including untracked files, is left alone.
 */
function upload_fixture_cleanup(): void
{
    $state = $GLOBALS['__upload_fixture'] ?? null;
    if (!$state) {
        return;
    }
    foreach (array_keys($state['files']) as $file) {
        if (upload_fixture_file_ok($file) && is_file($file) && !is_link($file)) {
            @unlink($file);
        }
    }
    foreach (array_keys($state['blockers']) as $blocker) {
        if (preg_match('#/([1-9][0-9]{9})\z#', upload_fixture_norm($blocker), $m) === 1 && (int) $m[1] >= UPLOAD_FIXTURE_MIN_ID && is_file($blocker) && !is_link($blocker)) {
            @unlink($blocker);
        }
    }
    foreach (array_keys($state['dirs']) as $dir) {
        if (upload_fixture_dir_ok($dir) && is_dir($dir) && !is_link($dir)) {
            @rmdir($dir);
        }
    }
    $GLOBALS['__upload_fixture'] = ['files' => [], 'dirs' => [], 'blockers' => []];
}
