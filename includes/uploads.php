<?php
/**
 * Validates and stores one uploaded document into a temporary per-session
 * folder (the Apply Online draft's folder by default; pass $destDir to use
 * a different one, e.g. the ID Renewal draft's own folder). Returns
 * ['ok' => bool, 'error' => string, 'meta' => array|null] where meta
 * matches the shape stored in $draft['documents'][doc_key].
 */
function handle_doc_upload(array $file, ?string $destDir = null): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose a file.', 'meta' => null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.', 'meta' => null];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'Upload failed. Please try again.', 'meta' => null];
    }

    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
        return ['ok' => false, 'error' => 'File must be JPG, PNG, or PDF.', 'meta' => null];
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'File is larger than 5MB.', 'meta' => null];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, ALLOWED_UPLOAD_MIME, true)) {
        return ['ok' => false, 'error' => 'File must be JPG, PNG, or PDF.', 'meta' => null];
    }

    $dir = $destDir ?? apply_tmp_dir();
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not save the file. Please try again.', 'meta' => null];
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $dir . '/' . $storedName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['ok' => false, 'error' => 'Could not save the file. Please try again.', 'meta' => null];
    }

    return [
        'ok' => true,
        'error' => '',
        'meta' => [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'path' => $destination,
            'mime' => $mime,
            'size' => (int) $file['size'],
        ],
    ];
}
