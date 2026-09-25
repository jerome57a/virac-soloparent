<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';

$docId = (int) ($_GET['doc_id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT d.*, a.user_id FROM application_documents d JOIN applications a ON a.id = d.application_id WHERE d.id = ?'
);
$stmt->execute([$docId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Document not found.');
}

$user = current_user($pdo);
$staff = current_staff($pdo);
$isOwner = $user && (int) $user['id'] === (int) $doc['user_id'];
if (!$isOwner && !$staff) {
    http_response_code(403);
    die('You are not allowed to view this document.');
}

$path = STORAGE_PATH . '/' . $doc['stored_path'];
if (!is_file($path)) {
    http_response_code(404);
    die('File is missing from storage.');
}

header('Content-Type: ' . $doc['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
