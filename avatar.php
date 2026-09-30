<?php
require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/auth.php';

$userId = (int) ($_GET['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, profile_photo FROM users WHERE id = ?');
$stmt->execute([$userId]);
$target = $stmt->fetch();

if (!$target || !$target['profile_photo']) {
    http_response_code(404);
    die('No photo.');
}

$viewer = current_user($pdo);
$staff = current_staff($pdo);
$isOwner = $viewer && (int) $viewer['id'] === $userId;
if (!$isOwner && !$staff) {
    http_response_code(403);
    die('You are not allowed to view this photo.');
}

$path = AVATAR_STORAGE_PATH . '/' . $target['profile_photo'];
if (!is_file($path)) {
    http_response_code(404);
    die('Photo is missing from storage.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $path);
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
