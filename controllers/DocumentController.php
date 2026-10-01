<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';
require_once __DIR__ . '/../services/NotificationService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';

/** Dashboard "Upload Documents" section: view documents per application and (re)upload while still editable. */
class DocumentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $documents = new Document($this->pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_doc') {
            csrf_require();
            $error = $this->upload($user, $documents, $backInReview);
            flash($error ?? ($backInReview
                ? 'Document uploaded. All requested corrections are in -- your application is back with MSWD for review.'
                : 'Document uploaded. MSWD will review it.'));
            $this->redirectTo('applicant/dashboard.php?section=documents');
        }

        $requirementsByApp = [];
        foreach ($applications as $app) {
            $requirementsByApp[$app['id']] = $documents->requirementsFor((int) $app['category_id']);
        }

        return ['view' => 'documents/index.php', 'data' => [
            'myApplications' => $applications,
            'myDocumentsByApp' => $documents->forUserByReference((int) $user['id']),
            'requirementsByApp' => $requirementsByApp,
            'editableStatuses' => Application::EDITABLE_STATUSES,
            'lockedStatuses' => Document::LOCKED_STATUSES,
        ]];
    }

    /**
     * Uploads or replaces one document on an application the user owns and
     * that is still editable. Returns an error message, or null on success.
     * $backInReview is set when this upload completed the requested
     * corrections and the application went back to Under Verification.
     */
    private function upload(array $user, Document $documents, ?bool &$backInReview = false): ?string
    {
        $backInReview = false;
        $applicationId = is_string($_POST['application_id'] ?? null) ? (int) $_POST['application_id'] : 0;
        $docKey = is_string($_POST['doc_key'] ?? null) ? $_POST['doc_key'] : '';
        $app = (new Application($this->pdo))->findOwned($applicationId, (int) $user['id']);
        if (!$app || !in_array($app['status'], Application::EDITABLE_STATUSES, true)) {
            return 'Documents for this application can no longer be changed.';
        }
        // Only a requirement of this application's category (or a standard one) can be uploaded.
        $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
        if (!in_array($docKey, array_column($documents->requirementsFor($categoryId), 'doc_key'), true)) {
            return 'That document is not a requirement for this application.';
        }
        $existing = $documents->findForApplication($applicationId, $docKey);
        if ($existing && in_array($existing['verification_status'], Document::LOCKED_STATUSES, true)) {
            return 'This document has already been verified by MSWD and can no longer be replaced.';
        }

        $result = (new DocumentValidationService($this->pdo))->storeDocument($_FILES['file'] ?? [], STORAGE_PATH . '/' . $applicationId);
        if (!$result['ok']) {
            return $result['error'];
        }

        $relativePath = $applicationId . '/' . $result['meta']['stored_name'];
        $existing = $documents->findForApplication($applicationId, $docKey);
        if ($existing) {
            $documents->replace($existing, $applicationId, $result['meta'], $relativePath, $user);
            $oldPath = STORAGE_PATH . '/' . $existing['stored_path'];
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Resubmission', 'application_document', (int) $existing['id'], $docKey);
        } else {
            $documents->create($applicationId, $docKey, $result['meta'], $relativePath, $user);
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Upload', 'application', $applicationId, $docKey);
        }
        (new NotificationService($this->pdo))->notifyStaff('document_resubmitted',
            $user['full_name'] . ' ' . ($existing ? 're-uploaded' : 'uploaded') . ' "' . ($documents->requirementLabel($docKey) ?? $docKey) . '" for ' . $app['reference_no'] . ' — ready to verify.', $applicationId);
        $backInReview = (new ApplicationReviewService($this->pdo))->returnToReviewIfCorrected($user, $app);
        return null;
    }
}
