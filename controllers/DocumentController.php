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
                flash('Your document was uploaded and is waiting for staff review.');
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

        $result = (new DocumentValidationService($this->pdo))->storeDocument($_FILES['file'] ?? [], STORAGE_PATH . '/' . $applicationId);
        if (!$result['ok']) {
            return $result['error'];
        }

        $relativePath = $applicationId . '/' . $result['meta']['stored_name'];
        $existing = $documents->findForApplication($applicationId, $docKey);
        if ($existing) {
            $documents->replace($existing, $applicationId, $result['meta'], $relativePath, $user);
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
