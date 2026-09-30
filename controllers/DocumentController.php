<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';

/** Dashboard "Upload Documents" section: view documents per application and (re)upload while still editable. */
class DocumentController extends Controller
{
    public function handle(array $user, array $applications): array
    {
        $documents = new Document($this->pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_doc') {
            csrf_require();
            $this->upload($user, $documents);
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
        ]];
    }

    /** Uploads or replaces one document on an application the user owns and that is still editable. Silently ignores anything else. */
    private function upload(array $user, Document $documents): void
    {
        $applicationId = (int) ($_POST['application_id'] ?? 0);
        $docKey = (string) ($_POST['doc_key'] ?? '');
        $app = (new Application($this->pdo))->findOwned($applicationId, (int) $user['id']);
        if (!$app || !in_array($app['status'], Application::EDITABLE_STATUSES, true)) {
            return;
        }

        $result = (new DocumentValidationService($this->pdo))->storeDocument($_FILES['file'] ?? [], STORAGE_PATH . '/' . $applicationId);
        if (!$result['ok']) {
            return;
        }

        $relativePath = $applicationId . '/' . $result['meta']['stored_name'];
        $existing = $documents->findForApplication($applicationId, $docKey);
        if ($existing) {
            $documents->replace((int) $existing['id'], $result['meta'], $relativePath);
            $oldPath = STORAGE_PATH . '/' . $existing['stored_path'];
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Resubmission', 'application_document', (int) $existing['id'], $docKey);
        } else {
            $documents->create($applicationId, $docKey, $result['meta'], $relativePath);
            audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Document Uploaded', 'application', $applicationId, $docKey);
        }
    }
}
