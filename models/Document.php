<?php
require_once __DIR__ . '/../services/SoloParentChildRules.php';
require_once __DIR__ . '/Model.php';

/** application_documents rows plus the document_requirements definitions they satisfy. */
class Document extends Model
{
    public const CHECKLIST_STATUSES = ['Pending', 'Verified', 'Needs Correction'];

    /** Requirements that were removed but may still have uploads on older applications: shown with a readable name, never required. */
    public const RETIRED_LABELS = ['photo1x1' => 'Two 1x1 ID pictures (uploaded before; now brought to the interview)'];

    /** A readable name for a document whose requirement no longer exists. */
    public static function fallbackLabel(string $docKey, ?string $stored = null): string
    {
        return self::RETIRED_LABELS[$docKey] ?? ($stored !== null && $stored !== '' && $stored !== $docKey ? $stored : $docKey);
    }

    /** A staff-accepted physical document is already satisfied even if its declaration was unchecked. */
    public static function needsPreparation(array $item): bool
    {
        $verified = ($item['verification_status'] ?? '') === 'Verified'
            && (empty($item['expires_at']) || $item['expires_at'] >= date('Y-m-d'));
        return !empty($item['is_required']) && empty($item['applicant_has']) && !$verified;
    }

    /** Requirements with applicant declarations and independent staff review. */
    public function checklistForReview(int $applicationId, ?int $categoryId, bool $standardOnly = false): array
    {
        $uploads = array_column($this->uploadedForApplication($applicationId), null, 'doc_key');
        if ($standardOnly) {
            $app = $this->pdo->prepare('SELECT renewal_simplified OR (type = \'renewal\' AND EXISTS (SELECT 1 FROM application_document_checklists s WHERE s.application_id = applications.id AND s.required_snapshot IS NOT NULL)) FROM applications WHERE id=?');
            $app->execute([$applicationId]);
            if ($app->fetchColumn()) {
                $stmt = $this->pdo->prepare('SELECT c.*, COALESCE(c.requirement_label,c.doc_key) AS label, COALESCE(c.required_snapshot,0) AS is_required, 1 AS category_specific, u.full_name AS verifier_name FROM application_document_checklists c LEFT JOIN users u ON u.id=c.reviewed_by WHERE c.application_id=? ORDER BY c.required_snapshot DESC,c.id');
                $stmt->execute([$applicationId]);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    if ($row['source_checklist_id'] && !empty($row['expires_at']) && $row['expires_at'] < date('Y-m-d')) {
                        $row['is_required'] = 1;
                        $row['verification_status'] = 'Pending';
                    }
                    if (isset($uploads[$row['doc_key']])) {
                        $this->mergeUploadStatus($row, $uploads[$row['doc_key']]);
                        unset($uploads[$row['doc_key']]);
                    }
                }
                unset($row);
                return $this->appendUnlistedUploads($rows, $uploads);
            }
        }
        $requirements = $standardOnly ? $this->standardRequirements() : $this->requirementsFor($categoryId);
        $requirements = $this->withDisabilityProof($applicationId, $requirements);
        $stmt = $this->pdo->prepare('SELECT c.*, u.full_name AS verifier_name FROM application_document_checklists c LEFT JOIN users u ON u.id = c.reviewed_by WHERE c.application_id = ?');
        $stmt->execute([$applicationId]);
        $saved = array_column($stmt->fetchAll(), null, 'doc_key');
        $rows = [];
        foreach ($requirements as $r) {
            $c = $saved[$r['doc_key']] ?? [];
            $row = $c + ['doc_key' => $r['doc_key'], 'applicant_has' => null, 'verification_status' => 'Pending', 'staff_remarks' => null,
                'reviewed_by' => null, 'reviewed_at' => null, 'verifier_name' => null, 'label' => $r['label'],
                'is_required' => (int) $r['is_required'], 'category_specific' => $r['category_id'] !== null];
            if (isset($uploads[$r['doc_key']])) {
                $this->mergeUploadStatus($row, $uploads[$r['doc_key']]);
                unset($uploads[$r['doc_key']]);
            }
            $rows[] = $row;
            unset($saved[$r['doc_key']]);
        }
        foreach ($saved as $key => $c) {
            $row = $c + ['label' => self::fallbackLabel($c['doc_key'], $c['requirement_label'] ?? null), 'is_required' => 0, 'category_specific' => false];
            if (isset($uploads[$key])) {
                $this->mergeUploadStatus($row, $uploads[$key]);
                unset($uploads[$key]);
            }
            $rows[] = $row;
        }
        return $this->appendUnlistedUploads($rows, $uploads);
    }

    /** Adds the required PWD ID / medical certificate row when the application lists a child over 22 with a disability. */
    private function withDisabilityProof(int $applicationId, array $requirements): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT full_name AS name, birthdate, has_disability FROM application_children WHERE application_id = ?');
            $stmt->execute([$applicationId]);
            $proof = SoloParentChildRules::proofRequirement($stmt->fetchAll(), date('Y-m-d'));
        } catch (Throwable $e) {
            return $requirements; // the column is not there yet (old database): nothing to add
        }
        if ($proof && !in_array(SoloParentChildRules::PROOF_KEY, array_column($requirements, 'doc_key'), true)) {
            $requirements[] = $proof;
        }
        return $requirements;
    }

    private function mergeUploadStatus(array &$row, array $upload): void
    {
        $row['applicant_has'] = 1;
        $row['verification_status'] = match ($upload['verification_status']) {
            'VERIFIED' => 'Verified',
            'NEEDS_CORRECTION', 'REJECTED' => 'Needs Correction',
            default => 'Pending',
        };
        $row['staff_remarks'] = $upload['staff_remarks'];
        $row['reviewed_by'] = $upload['verified_by'];
        $row['reviewed_at'] = $upload['verified_at'];
        $row['verifier_name'] = $upload['verifier_name'] ?? null;
        $row['uploaded_document'] = $upload;
    }

    private function appendUnlistedUploads(array $rows, array $uploads): array
    {
        foreach ($uploads as $upload) {
            $row = ['doc_key' => $upload['doc_key'], 'label' => self::fallbackLabel($upload['doc_key']), 'is_required' => 0,
                'category_specific' => false, 'applicant_has' => 1, 'verification_status' => 'Pending',
                'staff_remarks' => null, 'reviewed_by' => null, 'reviewed_at' => null, 'verifier_name' => null];
            $this->mergeUploadStatus($row, $upload);
            $rows[] = $row;
        }
        return $rows;
    }

    /** Requirements that still block approval and ID handover, including expired evidence. */
    public function outstandingRequired(array $app): array
    {
        return array_values(array_filter($this->checklistForReview((int) $app['id'],
            $app['category_id'] !== null ? (int) $app['category_id'] : null, $app['type'] === 'renewal'),
            fn($item) => !empty($item['is_required']) && ($item['verification_status'] !== 'Verified'
                || (!empty($item['expires_at']) && $item['expires_at'] < date('Y-m-d')))));
    }

    /** Standard (category_id IS NULL) + category-specific requirements, in display order. */
    public function requirementsFor(?int $categoryId): array
    {
        return get_document_requirements($this->pdo, $categoryId);
    }

    public function categoryOnlyRequirements(?int $categoryId): array
    {
        return get_category_only_requirements($this->pdo, $categoryId);
    }

    /** Every requirement: standard (NULL category) first, then per category. */
    public function allRequirements(): array
    {
        return $this->pdo->query('SELECT * FROM document_requirements ORDER BY (category_id IS NULL) DESC, category_id, sort_order, id')->fetchAll();
    }

    /** The display label for a doc_key (standard or any category), or null if it isn't a requirement any more. */
    public function requirementLabel(string $docKey): ?string
    {
        $stmt = $this->pdo->prepare('SELECT label FROM document_requirements WHERE doc_key = ? ORDER BY category_id IS NULL DESC LIMIT 1');
        $stmt->execute([$docKey]);
        $label = $stmt->fetchColumn();
        return $label !== false ? (string) $label : null;
    }

    public function findRequirement(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_requirements WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * True if $docKey would appear twice on some applicant's form: a standard
     * key clashes with any requirement using it; a category key clashes with
     * the same key in that category or among the standard requirements.
     */
    public function requirementKeyConflicts(string $docKey, ?int $categoryId): bool
    {
        $stmt = $categoryId === null
            ? $this->pdo->prepare('SELECT 1 FROM document_requirements WHERE doc_key = ?')
            : $this->pdo->prepare('SELECT 1 FROM document_requirements WHERE doc_key = ? AND (category_id IS NULL OR category_id = ?)');
        $stmt->execute($categoryId === null ? [$docKey] : [$docKey, $categoryId]);
        return (bool) $stmt->fetch();
    }

    /** True if any submitted application already holds a document for this requirement. */
    public function requirementInUse(array $requirement): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM application_documents d JOIN applications a ON a.id = d.application_id
             WHERE d.doc_key = ? AND (? IS NULL OR a.category_id = ?) LIMIT 1'
        );
        $stmt->execute([$requirement['doc_key'], $requirement['category_id'], $requirement['category_id']]);
        return (bool) $stmt->fetch();
    }

    public function createRequirement(?int $categoryId, string $docKey, string $label, bool $isRequired, int $sortOrder): int
    {
        $this->pdo->prepare('INSERT INTO document_requirements (category_id, doc_key, label, is_required, sort_order) VALUES (?, ?, ?, ?, ?)')
            ->execute([$categoryId, $docKey, $label, (int) $isRequired, $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateRequirement(int $id, string $label, bool $isRequired, int $sortOrder): void
    {
        $this->pdo->prepare('UPDATE document_requirements SET label = ?, is_required = ?, sort_order = ? WHERE id = ?')
            ->execute([$label, (int) $isRequired, $sortOrder, $id]);
    }

    public function deleteRequirement(int $id): void
    {
        $this->pdo->prepare('DELETE FROM document_requirements WHERE id = ?')->execute([$id]);
    }

    public function standardRequirements(): array
    {
        return $this->pdo->query('SELECT * FROM document_requirements WHERE category_id IS NULL ORDER BY sort_order, id')->fetchAll();
    }

    /**
     * Document lifecycle (application_documents.verification_status):
     * UPLOADED -> VALIDATING -> VALID or INVALID happen during the upload
     * request (DocumentValidationService); an INVALID file is never stored.
     * A valid file attached to a submitted application waits as
     * FOR_VERIFICATION until MSWD marks it VERIFIED, NEEDS_CORRECTION or
     * REJECTED. Replacing a file puts it back to FOR_VERIFICATION.
     */
    public const STATUSES = ['UPLOADED', 'VALIDATING', 'VALID', 'INVALID', 'FOR_VERIFICATION', 'VERIFIED', 'NEEDS_CORRECTION', 'REJECTED'];

    /** What MSWD staff can set (FOR_VERIFICATION undoes an earlier verdict). Legacy REJECTED stays readable but is no longer assignable. */
    public const VERIFICATION_STATUSES = ['FOR_VERIFICATION', 'VERIFIED', 'NEEDS_CORRECTION'];

    /** Staff have accepted it -- the applicant can no longer replace it. */
    public const LOCKED_STATUSES = ['VERIFIED'];

    /** The document only if it belongs to that application (guards against a tampered doc_id). */
    public function findInApplication(int $documentId, int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_documents WHERE id = ? AND application_id = ?');
        $stmt->execute([$documentId, $applicationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Records the verdict and who gave it, when. Setting it back to
     * FOR_VERIFICATION clears the verifier (the trail keeps who reset it).
     */
    public function setVerification(int $documentId, string $status, ?string $remarks, int $verifiedBy): void
    {
        if ($status === 'FOR_VERIFICATION') {
            $this->pdo->prepare('UPDATE application_documents SET verification_status = ?, staff_remarks = ?, verified_by = NULL, verified_at = NULL WHERE id = ?')
                ->execute([$status, $remarks, $documentId]);
            return;
        }
        $this->pdo->prepare('UPDATE application_documents SET verification_status = ?, staff_remarks = ?, verified_by = ?, verified_at = NOW() WHERE id = ?')
            ->execute([$status, $remarks, $verifiedBy, $documentId]);
    }

    /**
     * The application's documents for review, in requirement order, each with
     * its requirement label/is_required and the verifier's name. Requirements
     * with no upload come back as rows with id NULL (missing documents).
     */
    public function forReview(int $applicationId, ?int $categoryId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, u.full_name AS verifier_name FROM application_documents d
             LEFT JOIN users u ON u.id = d.verified_by WHERE d.application_id = ?'
        );
        $stmt->execute([$applicationId]);
        $uploaded = array_column($stmt->fetchAll(), null, 'doc_key');

        $rows = [];
        foreach ($this->requirementsFor($categoryId) as $r) {
            $doc = $uploaded[$r['doc_key']] ?? ['id' => null, 'doc_key' => $r['doc_key']];
            unset($uploaded[$r['doc_key']]);
            $rows[] = $doc + ['label' => $r['label'], 'is_required' => (int) $r['is_required'], 'category_specific' => $r['category_id'] !== null];
        }
        // Documents whose requirement has since been removed are still shown.
        foreach ($uploaded as $doc) {
            $rows[] = $doc + ['label' => self::fallbackLabel($doc['doc_key']), 'is_required' => 0, 'category_specific' => false];
        }
        return $rows;
    }

    /** One entry in the document's status trail. $actor: id, full_name, role. */
    public function addStatusHistory(int $documentId, int $applicationId, string $docKey, ?string $from, string $to, ?string $remarks, array $actor): void
    {
        $this->pdo->prepare(
            'INSERT INTO document_status_history (document_id, application_id, doc_key, from_status, to_status, remarks, actor_user_id, actor_name, actor_role)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$documentId, $applicationId, $docKey, $from, $to, $remarks, $actor['id'] ?? null, $actor['full_name'], $actor['role']]);
    }

    /** document_id => its status trail, oldest first. */
    public function statusHistoryFor(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_status_history WHERE application_id = ? ORDER BY created_at, id');
        $stmt->execute([$applicationId]);
        $byDoc = [];
        foreach ($stmt->fetchAll() as $h) {
            $byDoc[$h['document_id']][] = $h;
        }
        return $byDoc;
    }

    /**
     * In-progress applications with documents still awaiting staff
     * verification, oldest upload first (the verification queue). Each row:
     * id, reference_no, full_name, status, pending_docs, oldest_upload.
     */
    /**
     * One row per document awaiting verification: an uploaded file still FOR_VERIFICATION,
     * plus legacy checklist rows the applicant has but that were never uploaded as a file
     * (the upload, when present, is authoritative). Both tables are unique per
     * (application_id, doc_key), so the two halves never overlap and nothing counts twice.
     */
    private const PENDING_DOCUMENTS_SQL =
        "SELECT d.application_id, d.uploaded_at AS sent_at,
                CASE WHEN EXISTS (SELECT 1 FROM document_status_history h WHERE h.document_id = d.id AND h.remarks = '" . self::RESUBMIT_NOTE . "'
                                    AND h.created_at >= d.uploaded_at - INTERVAL 5 SECOND) THEN 1 ELSE 0 END AS resent
         FROM application_documents d WHERE d.verification_status = 'FOR_VERIFICATION'
         UNION ALL
         SELECT c.application_id, (SELECT a2.submitted_at FROM applications a2 WHERE a2.id = c.application_id) AS sent_at, 0 AS resent
         FROM application_document_checklists c
         WHERE c.verification_status = 'Pending' AND c.applicant_has = 1
           AND NOT EXISTS (SELECT 1 FROM application_documents u WHERE u.application_id = c.application_id AND u.doc_key = c.doc_key)";

    /** The trail note written when a Solo Parent replaces a file; the queue reads it to mark a re-upload. */
    public const RESUBMIT_NOTE = 'File replaced by Solo Parent.';

    /**
     * Applications with documents waiting, oldest application first (as before). oldest_upload is the date the application was
     * submitted; resent_docs is how many of the waiting documents are re-uploads (marked on the dashboard).
     */
    public function verificationQueue(int $limit): array
    {
        return $this->pdo->query(
            "SELECT a.id, a.reference_no, a.full_name, a.status, COUNT(*) AS pending_docs, SUM(d.resent) AS resent_docs, MIN(a.submitted_at) AS oldest_upload
             FROM applications a JOIN (" . self::PENDING_DOCUMENTS_SQL . ") d ON d.application_id = a.id
             WHERE a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')
             GROUP BY a.id, a.reference_no, a.full_name, a.status
             ORDER BY oldest_upload, a.id
             LIMIT " . (int) $limit
        )->fetchAll();
    }

    /** The newest send time among documents waiting for verification (null when none): the alert toast uses it to spot new arrivals. */
    public function latestSentAt(): ?string
    {
        $value = $this->pdo->query(
            "SELECT MAX(d.sent_at) FROM applications a JOIN (" . self::PENDING_DOCUMENTS_SQL . ") d ON d.application_id = a.id
             WHERE a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')"
        )->fetchColumn();
        return $value ? (string) $value : null;
    }

    /** ['applications' => in-progress applications with unverified documents, 'documents' => those documents]. */
    public function verificationBacklog(): array
    {
        return $this->pdo->query(
            "SELECT COUNT(DISTINCT a.id) AS applications, COUNT(*) AS documents,
                    COUNT(DISTINCT CASE WHEN d.resent = 1 THEN a.id END) AS resent_applications
             FROM applications a JOIN (" . self::PENDING_DOCUMENTS_SQL . ") d ON d.application_id = a.id
             WHERE a.status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')"
        )->fetch();
    }

    public function findForApplication(int $applicationId, string $docKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, doc_key, stored_path, verification_status FROM application_documents WHERE application_id = ? AND doc_key = ?');
        $stmt->execute([$applicationId, $docKey]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Identity of the exact file currently attached to a document row. It changes whenever the file is replaced
     * (new random stored_path, new checksum, new upload time), so a staff review form can prove which file it showed.
     * $doc is a full application_documents row.
     */
    public static function versionToken(array $doc): string
    {
        return hash('sha256', implode('|', [(int) $doc['id'], (string) $doc['stored_path'], (string) ($doc['sha256'] ?? ''), (string) $doc['uploaded_at']]));
    }

    /**
     * The document row, locked FOR UPDATE until the surrounding transaction ends. Call only inside a transaction.
     * Lock order is always application row first, then document row(s) by ascending id, the same order staff
     * verification and interview scheduling use. Taking the application row first matters because the status-history
     * insert that follows a replacement needs a shared lock on that row; holding a document lock while waiting for
     * it would deadlock against a staff transaction that holds the application row and wants the document.
     */
    public function lockForApplication(int $applicationId, string $docKey): ?array
    {
        $this->pdo->prepare('SELECT id FROM applications WHERE id = ? FOR UPDATE')->execute([$applicationId]);
        $stmt = $this->pdo->prepare('SELECT id, doc_key, stored_path, verification_status FROM application_documents WHERE application_id = ? AND doc_key = ? FOR UPDATE');
        $stmt->execute([$applicationId, $docKey]);
        return $stmt->fetch() ?: null;
    }

    /** All files currently attached to one application, newest first. */
    public function uploadedForApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT d.*, u.full_name AS verifier_name FROM application_documents d LEFT JOIN users u ON u.id = d.verified_by WHERE d.application_id = ? ORDER BY d.uploaded_at DESC, d.id DESC');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** Stores a submitted document (FOR_VERIFICATION) and starts its status trail with $actor (id, full_name, role). */
    public function create(int $applicationId, string $docKey, array $meta, string $relativePath, array $actor): int
    {
        $this->pdo->prepare("INSERT INTO application_documents (application_id, doc_key, original_name, stored_path, mime, size_bytes, sha256, verification_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'FOR_VERIFICATION')")
            ->execute([$applicationId, $docKey, $meta['original_name'], $relativePath, $meta['mime'], $meta['size'], $meta['sha256'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->addStatusHistory($id, $applicationId, $docKey, null, 'FOR_VERIFICATION', null, $actor);
        return $id;
    }

    /** Swaps in a new file and resets verification so staff re-review it (recorded in the trail as $actor's resubmission). */
    public function replace(array $existing, int $applicationId, array $meta, string $relativePath, array $actor): void
    {
        $documentId = (int) $existing['id'];
        $this->pdo->prepare("UPDATE application_documents SET original_name=?, stored_path=?, mime=?, size_bytes=?, sha256=?, verification_status='FOR_VERIFICATION', staff_remarks=NULL, verified_by=NULL, verified_at=NULL, uploaded_at=NOW() WHERE id=?")
            ->execute([$meta['original_name'], $relativePath, $meta['mime'], $meta['size'], $meta['sha256'] ?? null, $documentId]);
        $this->addStatusHistory($documentId, $applicationId, $existing['doc_key'], $existing['verification_status'], 'FOR_VERIFICATION', self::RESUBMIT_NOTE, $actor);
    }
}
