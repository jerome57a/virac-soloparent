<?php
require_once __DIR__ . '/SmsService.php';

/**
 * Session-backed draft for the (single-step) ID Renewal flow, plus the
 * finalize step that creates a real `applications` row with type='renewal'.
 * Deliberately separate from ApplyDraft.php/apply_finalize_submission() so
 * the existing, heavily-used Apply Online wizard is never touched by this.
 */

/** The applicant's most recent released ID -- the thing being renewed. Null if they don't have one yet. */
function renewal_source_application(PDO $pdo, array $user): ?array
{
    $stmt = $pdo->prepare(
        "SELECT a.*, c.name AS category_name FROM applications a
         JOIN categories c ON c.id = a.category_id
         WHERE a.user_id = ? AND a.type = 'new' AND a.status = 'ID Released'
         ORDER BY a.submitted_at DESC LIMIT 1"
    );
    $stmt->execute([$user['id']]);
    $app = $stmt->fetch();
    return $app ?: null;
}

function renewal_fresh_draft(): array
{
    return ['documents' => [], 'docErrors' => []];
}

function renewal_get_draft(): array
{
    if (empty($_SESSION['renewal_draft'])) {
        $_SESSION['renewal_draft'] = renewal_fresh_draft();
    }
    return $_SESSION['renewal_draft'];
}

function renewal_save_draft(array $draft): void
{
    $_SESSION['renewal_draft'] = $draft;
}

function renewal_tmp_dir(): string
{
    return STORAGE_PATH . '/tmp/renewal_' . session_id();
}

function renewal_clear_draft(): void
{
    unset($_SESSION['renewal_draft']);
    $dir = renewal_tmp_dir();
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

/** Standard (category_id IS NULL) requirements only -- a renewal re-confirms residency/ID, not the original qualifying circumstance. */
function renewal_doc_defs(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM document_requirements WHERE category_id IS NULL ORDER BY sort_order, id')->fetchAll();
}

/** Creates the renewal application row + document rows, moves files out of tmp storage. */
function renewal_finalize_submission(PDO $pdo, array $user, array $source, array $documents): string
{
    $pdo->beginTransaction();
    try {
        $refNumber = generate_reference_no($pdo);
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO applications
                (reference_no, user_id, type, full_name, barangay, address, mobile, email, birthdate, sex, civil_status, category_id, status, consent_at, submitted_at)
             VALUES (?, ?, \'renewal\', ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending Review\', ?, ?)'
        );
        $stmt->execute([
            $refNumber, $user['id'], $source['full_name'], $source['barangay'], $source['address'],
            $source['mobile'], $source['email'], $source['birthdate'], $source['sex'],
            $source['civil_status'], $source['category_id'], $now, $now,
        ]);
        $applicationId = (int) $pdo->lastInsertId();

        $destDir = STORAGE_PATH . '/' . $applicationId;
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new RuntimeException('Could not create storage folder for uploaded documents.');
        }

        $docStmt = $pdo->prepare(
            'INSERT INTO application_documents (application_id, doc_key, original_name, stored_path, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($documents as $docKey => $meta) {
            $finalPath = $destDir . '/' . $meta['stored_name'];
            if (!@rename($meta['path'], $finalPath)) {
                throw new RuntimeException('Could not store an uploaded document. Please try again.');
            }
            $relativePath = $applicationId . '/' . $meta['stored_name'];
            $docStmt->execute([$applicationId, $docKey, $meta['original_name'], $relativePath, $meta['mime'], $meta['size']]);
        }

        $histStmt = $pdo->prepare(
            'INSERT INTO status_history (application_id, status, remarks, changed_by) VALUES (?, \'Pending Review\', \'Renewal submitted online.\', ?)'
        );
        $histStmt->execute([$applicationId, $source['full_name']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    renewal_clear_draft();

    audit_log($pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Renewal Submission', 'application', $applicationId, $refNumber);
    (new SmsService($pdo))->notifyRenewalSubmitted($applicationId, $source['mobile'], $refNumber);

    return $refNumber;
}
