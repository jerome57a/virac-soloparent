<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';

// Only the owning applicant or MSWDO staff may view a document. Require a
// login before touching the database, and answer "not found" whether the
// document is missing or just not yours, so IDs can't be probed.
$user = current_user($pdo);
$staff = current_staff($pdo);
if (!$user && !can($staff, 'applications.process')) {
    http_response_code(403);
    die('Please log in to view this document.');
}

$docId = (int) ($_GET['doc_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.*, a.user_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ?'
);
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc || (!$staff && (int) $user['id'] !== (int) $doc['user_id']) || ($staff && !can($staff, 'applications.process'))) {
    http_response_code(404);
    die('Document not found.');
}

// In-system viewer mode (?preview=1&app_id=N), used by the staff review page. It is staff-only, the document must
// belong to the application being reviewed, and it can never be turned into an attachment download.
$preview = ($_GET['preview'] ?? '') === '1';
if ($preview && (!$staff || (int) ($_GET['app_id'] ?? 0) !== (int) $doc['application_id'])) {
    http_response_code(404);
    die('Document not found.');
}

// Staff access to an applicant's document is traced (once per document per session, so previews don't flood the log).
if ($staff && empty($_SESSION['viewed_docs'][$docId])) {
    $_SESSION['viewed_docs'][$docId] = true;
    audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'Document Viewed', 'application_document', $docId, $doc['doc_key']);
}

$path = STORAGE_PATH . '/' . $doc['stored_path'];
// The stored path must resolve to a real file inside the uploads folder (defence in depth: never serve anything outside it).
$realPath = realpath($path);
$uploadRoot = realpath(STORAGE_PATH);
if ($realPath === false || $uploadRoot === false || strncmp($realPath, $uploadRoot . DIRECTORY_SEPARATOR, strlen($uploadRoot) + 1) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    die('File is missing from storage.');
}
$path = $realPath;

// The stored MIME was verified against the file's real content at upload; nosniff stops the browser second-guessing it.
// The display filename is the applicant's, so it goes out as an ASCII fallback plus the RFC 5987 UTF-8 form.
$displayName = basename(str_replace('\\', '/', (string) $doc['original_name']));
$asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $displayName);
// Only the upload types the system accepts are shown inline; any other stored type is forced to download as opaque bytes.
$servable = in_array($doc['mime'], ['image/jpeg', 'image/png', 'application/pdf'], true);
$contentType = $servable ? $doc['mime'] : 'application/octet-stream';
if ($preview) {
    // Preview trusts the file's real content, not the stored MIME or the filename extension.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detected = $finfo ? (string) finfo_file($finfo, $path) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!in_array($detected, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=UTF-8');
        die('This file type cannot be previewed.');
    }
    $contentType = $detected;
    $servable = true;
    header('Pragma: no-cache');
    header('Expires: 0');
}
header('Content-Type: ' . $contentType);
header('Content-Length: ' . filesize($path));
// ?download=1 saves the file instead of opening it in the browser (never in preview mode).
$disposition = ((!$preview && ($_GET['download'] ?? '') === '1') || !$servable) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($preview ? 'private, no-store, no-cache, must-revalidate, max-age=0' : 'private, no-store'));
// Release the session lock so a large file does not block the user's other requests while it streams.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
readfile($path);
