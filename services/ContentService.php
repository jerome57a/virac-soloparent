<?php
require_once __DIR__ . '/../models/Faq.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/SmsLog.php';

/**
 * Admin-managed content applicants see: Chat Support FAQs, document
 * requirements per category, and SMS notification templates. Each method
 * validates, writes, audits, and returns [error, success] (one is '').
 */
class ContentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- FAQ ----

    /** @return array{0: string, 1: string} */
    public function saveFaq(array $admin, ?int $id, string $question, string $answer, int $sortOrder): array
    {
        if ($question === '' || $answer === '') {
            return ['Enter both a question and an answer.', ''];
        }
        $faqs = new Faq($this->pdo);
        if ($id === null) {
            $this->audit($admin, 'FAQ Created', 'faq', $faqs->create($question, $answer, $sortOrder), $question);
            return ['', 'FAQ added.'];
        }
        $faqs->update($id, $question, $answer, $sortOrder);
        $this->audit($admin, 'FAQ Updated', 'faq', $id, $question);
        return ['', 'FAQ updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function toggleFaq(array $admin, int $id): array
    {
        (new Faq($this->pdo))->toggleActive($id);
        $this->audit($admin, 'FAQ Visibility Toggled', 'faq', $id);
        return ['', 'FAQ visibility updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function deleteFaq(array $admin, int $id): array
    {
        (new Faq($this->pdo))->delete($id);
        $this->audit($admin, 'FAQ Deleted', 'faq', $id);
        return ['', 'FAQ deleted.'];
    }

    // ---- Document requirements ----

    /** $categoryId null = standard requirement for every category. @return array{0: string, 1: string} */
    public function createRequirement(array $admin, ?int $categoryId, string $docKey, string $label, bool $isRequired, int $sortOrder): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $docKey)) {
            return ['Doc key must be letters, numbers, or underscores only (no spaces).', ''];
        }
        if ($label === '') {
            return ['Enter a requirement label.', ''];
        }
        $documents = new Document($this->pdo);
        if ($documents->requirementExists($docKey, $categoryId)) {
            return ['That doc key already exists for this category.', ''];
        }
        $id = $documents->createRequirement($categoryId, $docKey, $label, $isRequired, $sortOrder);
        $this->audit($admin, 'Document Requirement Created', 'document_requirement', $id, $label);
        return ['', 'Requirement added.'];
    }

    /** @return array{0: string, 1: string} */
    public function updateRequirement(array $admin, int $id, string $label, bool $isRequired, int $sortOrder): array
    {
        if ($label === '') {
            return ['Enter a requirement label.', ''];
        }
        (new Document($this->pdo))->updateRequirement($id, $label, $isRequired, $sortOrder);
        $this->audit($admin, 'Document Requirement Updated', 'document_requirement', $id, $label);
        return ['', 'Requirement updated.'];
    }

    /** @return array{0: string, 1: string} */
    public function deleteRequirement(array $admin, int $id): array
    {
        (new Document($this->pdo))->deleteRequirement($id);
        $this->audit($admin, 'Document Requirement Deleted', 'document_requirement', $id);
        return ['', 'Requirement removed.'];
    }

    // ---- SMS templates ----

    /** @return array{0: string, 1: string} */
    public function updateSmsTemplate(array $admin, string $key, string $body): array
    {
        if ($body === '') {
            return ['Template text cannot be empty.', ''];
        }
        (new SmsLog($this->pdo))->updateTemplate($key, $body);
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], 'SMS Template Updated', 'sms_template', null, $key);
        return ['', 'Template updated.'];
    }

    private function audit(array $admin, string $action, string $entityType, int $entityId, ?string $details = null): void
    {
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], $action, $entityType, $entityId, $details);
    }
}
