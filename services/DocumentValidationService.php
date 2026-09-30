<?php
/**
 * Validates uploaded files (extension, size, real MIME type) and stores
 * them under a random name. Also manages the per-draft document map used
 * by the Apply Online and ID Renewal flows.
 */
class DocumentValidationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validates one application document and moves it into $destDir.
     * Returns ['ok' => bool, 'error' => string, 'meta' => array|null].
     */
    public function storeDocument(array $file, string $destDir): array
    {
        $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'meta' => null];

        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $fail('Please choose a file.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $fail('Upload failed. Please try again.');
        }

        $originalName = $file['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_UPLOAD_EXT, true)) {
            return $fail('File must be JPG, PNG, or PDF.');
        }
        $maxMb = (float) get_setting($this->pdo, 'document_max_size_mb', (string) round(MAX_UPLOAD_BYTES / 1024 / 1024, 1));
        if ($file['size'] > (int) ($maxMb * 1024 * 1024)) {
            return $fail("File is larger than {$maxMb}MB.");
        }
        $mime = $this->detectMime($file['tmp_name']);
        if (!in_array($mime, ALLOWED_UPLOAD_MIME, true)) {
            return $fail('File must be JPG, PNG, or PDF.');
        }

        if (!$this->ensureDir($destDir)) {
            return $fail('Could not save the file. Please try again.');
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $destDir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return $fail('Could not save the file. Please try again.');
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

    /** Validates a profile photo and stores it in AVATAR_STORAGE_PATH. Returns ['ok', 'error', 'relativePath']. */
    public function storeProfilePhoto(array $file, int $userId): array
    {
        $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'relativePath' => null];

        if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return $fail('Please choose a photo.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return $fail('Upload failed. Please try again.');
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_AVATAR_EXT, true)) {
            return $fail('Photo must be a JPG or PNG image.');
        }
        if ($file['size'] > MAX_AVATAR_BYTES) {
            return $fail('Photo is larger than 2MB.');
        }
        if (!in_array($this->detectMime($file['tmp_name']), ALLOWED_AVATAR_MIME, true)) {
            return $fail('Photo must be a JPG or PNG image.');
        }
        if (!$this->ensureDir(AVATAR_STORAGE_PATH)) {
            return $fail('Could not save the photo. Please try again.');
        }

        $relativePath = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], AVATAR_STORAGE_PATH . '/' . $relativePath)) {
            return $fail('Could not save the photo. Please try again.');
        }
        return ['ok' => true, 'error' => '', 'relativePath' => $relativePath];
    }

    /** Stores an upload into a draft's tmp folder, replacing any earlier file for the same doc_key. */
    public function attachToDraft(array $draft, string $docKey, array $file, string $tmpDir): array
    {
        $result = $this->storeDocument($file, $tmpDir);
        if ($result['ok']) {
            $this->deleteDraftFile($draft, $docKey);
            $draft['documents'][$docKey] = $result['meta'];
            unset($draft['docErrors'][$docKey]);
        } else {
            $draft['docErrors'][$docKey] = $result['error'];
        }
        return $draft;
    }

    public function detachFromDraft(array $draft, string $docKey): array
    {
        $this->deleteDraftFile($draft, $docKey);
        unset($draft['documents'][$docKey], $draft['docErrors'][$docKey]);
        return $draft;
    }

    /** Deletes every file in a draft tmp folder, then the folder itself. */
    public function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function deleteDraftFile(array $draft, string $docKey): void
    {
        $path = $draft['documents'][$docKey]['path'] ?? '';
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime;
    }

    private function ensureDir(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0750, true) || is_dir($dir);
    }
}
