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
header('Content-Type: ' . ($servable ? $doc['mime'] : 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
// ?download=1 saves the file instead of opening it in the browser.
$disposition = (($_GET['download'] ?? '') === '1' || !$servable) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($displayName));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
// Release the session lock so a large file does not block the user's other requests while it streams.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
readfile($path);
