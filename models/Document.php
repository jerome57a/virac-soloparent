<?php
require_once __DIR__ . '/Model.php';

/** application_documents rows plus the document_requirements definitions they satisfy. */
class Document extends Model
{
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

    public function requirementExists(string $docKey, ?int $categoryId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM document_requirements WHERE doc_key = ? AND (category_id <=> ?)');
        $stmt->execute([$docKey, $categoryId]);
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

    /** All of one applicant's uploaded documents, grouped by application reference_no. */
    public function forUserByReference(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, a.reference_no, a.category_id, COALESCE(dr.label, d.doc_key) AS doc_label
             FROM application_documents d
             JOIN applications a ON a.id = d.application_id
             LEFT JOIN document_requirements dr ON dr.doc_key = d.doc_key AND (dr.category_id IS NULL OR dr.category_id = a.category_id)
             WHERE a.user_id = ?
             ORDER BY a.submitted_at DESC, d.uploaded_at'
        );
        $stmt->execute([$userId]);
        $byRef = [];
        foreach ($stmt->fetchAll() as $doc) {
            $byRef[$doc['reference_no']][] = $doc;
        }
        return $byRef;
    }

    public const VERIFICATION_STATUSES = ['Pending', 'Verified', 'Needs Correction'];

    public function forApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_documents WHERE application_id = ?');
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** The document only if it belongs to that application (guards against a tampered doc_id). */
    public function findInApplication(int $documentId, int $applicationId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM application_documents WHERE id = ? AND application_id = ?');
        $stmt->execute([$documentId, $applicationId]);
        return $stmt->fetch() ?: null;
    }

    public function setVerification(int $documentId, string $status, ?string $remarks): void
    {
        $this->pdo->prepare('UPDATE application_documents SET verification_status = ?, staff_remarks = ? WHERE id = ?')
            ->execute([$status, $remarks, $documentId]);
    }

    /** application_id => [verification_status => count], for the given applications. */
    public function verificationCountsFor(array $applicationIds): array
    {
        if (!$applicationIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($applicationIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT application_id, verification_status, COUNT(*) AS n FROM application_documents
             WHERE application_id IN ($placeholders) GROUP BY application_id, verification_status"
        );
        $stmt->execute(array_values($applicationIds));
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['application_id']][$row['verification_status']] = (int) $row['n'];
        }
        return $counts;
    }

    public function findForApplication(int $applicationId, string $docKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, stored_path FROM application_documents WHERE application_id = ? AND doc_key = ?');
        $stmt->execute([$applicationId, $docKey]);
        return $stmt->fetch() ?: null;
    }

    public function create(int $applicationId, string $docKey, array $meta, string $relativePath): int
    {
        $this->pdo->prepare('INSERT INTO application_documents (application_id, doc_key, original_name, stored_path, mime, size_bytes) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$applicationId, $docKey, $meta['original_name'], $relativePath, $meta['mime'], $meta['size']]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Swaps in a new file and resets verification so staff re-review it. */
    public function replace(int $documentId, array $meta, string $relativePath): void
    {
        $this->pdo->prepare("UPDATE application_documents SET original_name=?, stored_path=?, mime=?, size_bytes=?, verification_status='Pending', staff_remarks=NULL, uploaded_at=NOW() WHERE id=?")
            ->execute([$meta['original_name'], $relativePath, $meta['mime'], $meta['size'], $documentId]);
    }
}
