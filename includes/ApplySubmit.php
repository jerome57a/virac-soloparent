<?php
require_once __DIR__ . '/SmsService.php';

/** Creates the application + children + document rows, moves files out of tmp storage. */
function apply_finalize_submission(PDO $pdo, array $user, array $draft): string
{
    $pdo->beginTransaction();
    try {
        $refNumber = generate_reference_no($pdo);
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO applications
                (reference_no, user_id, type, full_name, barangay, address, mobile, email, birthdate, sex, civil_status, category_id, status, consent_at, submitted_at)
             VALUES (?, ?, \'new\', ?, ?, ?, ?, ?, ?, ?, ?, ?, \'Pending Review\', ?, ?)'
        );
        $stmt->execute([
            $refNumber, $user['id'], $draft['fullName'], $draft['barangay'], $draft['address'],
            normalize_mobile($draft['contact']), $draft['email'], $draft['birthdate'], $draft['sex'],
            $draft['civilStatus'], $draft['categoryId'], $now, $now,
        ]);
        $applicationId = (int) $pdo->lastInsertId();

        $childStmt = $pdo->prepare('INSERT INTO application_children (application_id, full_name, birthdate) VALUES (?, ?, ?)');
        foreach ($draft['children'] as $c) {
            $childStmt->execute([$applicationId, $c['name'], $c['birthdate']]);
        }

        $destDir = STORAGE_PATH . '/' . $applicationId;
        if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
            throw new RuntimeException('Could not create storage folder for uploaded documents.');
        }

        $docStmt = $pdo->prepare(
            'INSERT INTO application_documents (application_id, doc_key, original_name, stored_path, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($draft['documents'] as $docKey => $meta) {
            $finalPath = $destDir . '/' . $meta['stored_name'];
            if (!@rename($meta['path'], $finalPath)) {
                throw new RuntimeException('Could not store an uploaded document. Please try again.');
            }
            $relativePath = $applicationId . '/' . $meta['stored_name'];
            $docStmt->execute([$applicationId, $docKey, $meta['original_name'], $relativePath, $meta['mime'], $meta['size']]);
        }

        $histStmt = $pdo->prepare('INSERT INTO status_history (application_id, status, remarks, changed_by) VALUES (?, \'Pending Review\', \'Application submitted online.\', ?)');
        $histStmt->execute([$applicationId, $draft['fullName']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    apply_clear_draft();

    (new SmsService($pdo))->notifySubmitted($applicationId, $draft['contact'], $refNumber);

    return $refNumber;
}
