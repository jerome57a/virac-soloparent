<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';

/** Applicant document submission and review results for owned applications. */
class DocumentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $documents = new Document($this->pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document') {
            csrf_require();
            $backInReview = false;
            $error = $this->upload($user, $documents, $backInReview);
            if ($error !== null) {
                flash($error);
            } else {
                flash('Your document was uploaded and is waiting for MSWDO staff review.');
            }
            $this->redirectTo('applicant/dashboard.php?section=documents');
        }

        $checklistByApp = [];
        $uploadedByApp = [];
        foreach ($applications as $app) {
            $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
            $checklistByApp[$app['id']] = $documents->checklistForReview((int) $app['id'], $categoryId, $app['type'] === 'renewal');
            $uploadedByApp[$app['id']] = array_column($documents->uploadedForApplication((int) $app['id']), null, 'doc_key');
        }

        return ['view' => 'documents/index.php', 'data' => [
            'myApplications' => $applications,
            'checklistByApp' => $checklistByApp,
            'uploadedByApp' => $uploadedByApp,
            'flashMessage' => take_flash(),
        ]];
    }

    /** Validates and saves one applicant document upload. */
    private function upload(array $user, Document $documents, ?bool &$backInReview = false): ?string
    {
        $backInReview = false;
        $applicationId = is_string($_POST['application_id'] ?? null) ? (int) $_POST['application_id'] : 0;
        $docKey = is_string($_POST['doc_key'] ?? null) ? $_POST['doc_key'] : '';
        $app = (new Application($this->pdo))->findOwned($applicationId, (int) $user['id']);
        if (!$app || !in_array($app['status'], Application::CHECKLIST_EDITABLE_STATUSES, true)) {
            return 'Documents for this application can no longer be changed.';
        }
        // Only a requirement of this application's category (or a standard one) can be uploaded.
        $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
        $requirements = $documents->checklistForReview($applicationId, $categoryId, ($app['type'] ?? '') === 'renewal');
        if (!in_array($docKey, array_column($requirements, 'doc_key'), true)) {
            return 'That document is not a requirement for this application.';
        }
        $existing = $documents->findForApplication($applicationId, $docKey);
        if ($existing && in_array($existing['verification_status'], Document::LOCKED_STATUSES, true)) {
            return 'This document has already been verified by MSWD and can no longer be replaced.';
        }

        $result = (new DocumentValidationService($this->pdo))->storeDocument($_FILES['file'] ?? [], STORAGE_PATH . '/' . $applicationId, true); // Supporting Documents are image-only (JPG/JPEG/PNG)
        if (!$result['ok']) {
            return $result['error'];
        }

        $relativePath = $applicationId . '/' . $result['meta']['stored_name'];
        $existing = $documents->findForApplication($applicationId, $docKey);

        // Only the document row (create/replace) and its status-history row are saved atomically here. The file is
        // already on disk, and a rollback does not remove it, so it is deleted below ONLY when the rollback is
        // confirmed (or no transaction was ever started). Any uncertain state keeps the file and logs it.
        $state = 'none'; // none | outer (a caller's transaction is active) | owned | committing
        try {
            if ($this->pdo->inTransaction()) {
                $state = 'outer'; // never commit or roll back a transaction this controller did not start
            } else {
                $this->pdo->beginTransaction();
                $state = 'owned';
            }
            // Lock the document row the same way staff verification does, then re-check it: a verification that
            // committed while the file was being stored must not be overwritten by this replacement.
            $blocked = null;
            if ($existing) {
                $current = $documents->lockForApplication($applicationId, $docKey);
                if (!$current) {
                    $blocked = 'This document is no longer available. Please reload the page and try again.';
                } elseif (in_array($current['verification_status'], Document::LOCKED_STATUSES, true)) {
                    $blocked = 'This document has already been verified by MSWD and can no longer be replaced.';
                } else {
                    $existing = $current;
                    $documents->replace($existing, $applicationId, $result['meta'], $relativePath, $user);
                }
            } else {
                $documents->create($applicationId, $docKey, $result['meta'], $relativePath, $user);
            }
            if ($blocked !== null) {
                // Nothing was written, so the new file is unreferenced: end the transaction we own and discard it.
                if ($state === 'owned') {
                    $this->pdo->rollBack();
                }
                $this->discardNewUpload($result['meta'], $applicationId);
                return $blocked;
            }
            if ($state === 'owned') {
                $state = 'committing';
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            $rolledBack = false;
            if ($state === 'owned' || $state === 'committing') {
                try {
                    $rolledBack = $this->pdo->rollBack() && !$this->pdo->inTransaction();
                } catch (Throwable $rollbackError) {
                    error_log('Document upload: rollback failed (' . get_class($rollbackError) . ').');
                }
            }
            // A failed commit may or may not have been applied, so only a rollback confirmed BEFORE the commit started,
            // or a transaction that never started (no database operation ran), allows the new file to be removed.
            if ($state === 'none' || ($state === 'owned' && $rolledBack)) {
                $this->discardNewUpload($result['meta'], $applicationId);
            } else {
                error_log('Document upload: the new file ' . ($result['meta']['stored_name'] ?? '?') . ' for application ' . $applicationId
                    . ' was kept because the database state is not confirmed rolled back (state: ' . $state . ').');
            }
            throw $e;
        }

        // Outside the transaction, exactly as before: a failure here leaves a saved, referenced document.
        if ($existing) {
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Resubmission', 'application_document', (int) $existing['id'], $docKey);
        } else {
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Upload', 'application', $applicationId, $docKey);
        }
        (new NotificationService($this->pdo))->notifyStaff('document_resubmitted',
            $user['full_name'] . ' ' . ($existing ? 're-uploaded' : 'uploaded') . ' "' . ($documents->requirementLabel($docKey) ?? $docKey) . '" for ' . $app['reference_no'] . ' — ready to verify.', $applicationId);
        $backInReview = (new ApplicationReviewService($this->pdo))->returnToReviewIfCorrected($user, $app);
        return null;
    }

    /**
     * Removes the one file this request just stored, after its database save was rolled back. Deletes nothing else:
     * a single unlink() of a generated name, with no glob, no recursion and no directory removal. Never throws,
     * so the caller's original exception is the one that propagates.
     */
    private function discardNewUpload(array $meta, int $applicationId): void
    {
        try {
            $name = (string) ($meta['stored_name'] ?? '');
            if ($applicationId < 1 || preg_match('/\A[a-f0-9]{32}\.(?:jpe?g|png|pdf)\z/', $name) !== 1) {
                error_log('Document upload cleanup skipped: unexpected application id or stored name.');
                return;
            }
            $dir = STORAGE_PATH . '/' . $applicationId;
            $path = $dir . '/' . $name;
            if (($meta['path'] ?? null) !== $path || !is_file($path) || is_link($path)) {
                error_log('Document upload cleanup skipped: the file is not the expected regular file (' . $name . ').');
                return;
            }
            $realRoot = realpath(STORAGE_PATH);
            $realDir = realpath($dir);
            $realFile = realpath($path);
            if ($realRoot === false || $realDir === false || $realFile === false) {
                error_log('Document upload cleanup skipped: a path could not be resolved (' . $name . ').');
                return;
            }
            $root = storage_norm_path($realRoot);
            $file = storage_norm_path($realFile);
            if (storage_norm_path($realDir) !== $root . '/' . $applicationId
                || $file !== $root . '/' . $applicationId . '/' . $name
                || !storage_path_within($file, $root)) {
                error_log('Document upload cleanup skipped: the file does not resolve inside the storage root (' . $name . ').');
                return;
            }
            if (!@unlink($realFile)) {
                error_log('Document upload cleanup failed: could not remove ' . $name . ' for application ' . $applicationId . '.');
            }
        } catch (Throwable $cleanupError) {
            error_log('Document upload cleanup error (' . get_class($cleanupError) . ').');
        }
    }
}
