<?php
require_once __DIR__ . '/Document.php';

/** Separate category renewal rules and a plan retaining accepted, unexpired records. */
class RenewalRequirement extends Model
{
    public function all(): array
    {
        return $this->pdo->query('SELECT r.*, c.name AS category_name FROM renewal_requirements r JOIN categories c ON c.id=r.category_id ORDER BY c.sort_order,r.sort_order,r.id')->fetchAll();
    }

    public function save(array $admin, array $input): ?string
    {
        $categoryId = filter_var($input['category_id'] ?? null,FILTER_VALIDATE_INT) ?: 0;
        $key = is_string($input['doc_key'] ?? null) ? trim($input['doc_key']) : '';
        $label = is_string($input['label'] ?? null) ? trim($input['label']) : '';
        $category = $this->pdo->prepare('SELECT 1 FROM categories WHERE id=?');
        $category->execute([$categoryId]);
        if (!$category->fetchColumn() || !preg_match('/^[A-Za-z0-9_]{1,50}$/', $key) || $label === '' || mb_strlen($label) > 255) {
            return 'Choose a category and enter a document key and label (up to 255 characters).';
        }
        $this->pdo->prepare('INSERT INTO renewal_requirements (category_id,doc_key,label,always_refresh) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),always_refresh=VALUES(always_refresh)')
            ->execute([$categoryId,$key,$label,(int) !empty($input['always_refresh'])]);
        audit_log($this->pdo,(int) $admin['id'],$admin['full_name'],$admin['role'],'Renewal Requirement Saved','category',$categoryId,$key . ': ' . $label);
        return null;
    }

    public function plan(array $source): array
    {
        $query = $this->pdo->prepare('SELECT * FROM renewal_requirements WHERE category_id=? ORDER BY sort_order,id');
        $query->execute([$source['category_id']]);
        $rules = $query->fetchAll();
        $accepted = [];
        $labels = array_column((new Document($this->pdo))->checklistForReview((int) $source['id'], (int) $source['category_id'], $source['type'] === 'renewal'),'label','doc_key');
        $records = $this->pdo->prepare("SELECT c.*,u.full_name AS verifier_name FROM application_document_checklists c LEFT JOIN users u ON u.id=c.reviewed_by WHERE c.application_id=? AND c.applicant_has=1 AND c.verification_status='Verified' ORDER BY c.id");
        $records->execute([$source['id']]);
        foreach ($records->fetchAll() as $row) {
            $row['label'] = $labels[$row['doc_key']] ?? $row['requirement_label'] ?? $row['doc_key'];
            $accepted[$row['doc_key']] = $row;
        }
        $fresh = $retained = [];
        foreach ($accepted as $key => $row) {
            // A retired requirement (the 1x1 pictures, now brought to the interview) is never asked for again, even if it expired.
            if (!empty($row['expires_at']) && $row['expires_at'] < date('Y-m-d') && !isset(Document::RETIRED_LABELS[$key])) {
                $fresh[$key] = ['doc_key'=>$key,'label'=>$row['label'],'is_required'=>1,'source_checklist_id'=>(int) $row['id'],'reason'=>'Previously accepted document has expired.'];
            } else {
                $retained[$key] = $row;
            }
        }
        foreach ($rules as $rule) {
            $key = $rule['doc_key'];
            if ($rule['always_refresh'] || !isset($retained[$key])) {
                $fresh[$key] = ['doc_key'=>$key,'label'=>$rule['label'],'is_required'=>1,'source_checklist_id'=>$accepted[$key]['id'] ?? null,'reason'=>$rule['always_refresh'] ? 'A fresh copy is required for this category renewal.' : 'No accepted, unexpired document is available.'];
                unset($retained[$key]);
            }
        }
        return ['configured'=>(bool) $rules,'fresh'=>array_values($fresh),'retained'=>array_values($retained)];
    }

    public function delete(array $admin, int $id): void
    {
        $query = $this->pdo->prepare('SELECT * FROM renewal_requirements WHERE id=?');
        $query->execute([$id]);
        $row = $query->fetch();
        if (!$row) { return; }
        $this->pdo->prepare('DELETE FROM renewal_requirements WHERE id=?')->execute([$id]);
        audit_log($this->pdo,(int) $admin['id'],$admin['full_name'],$admin['role'],'Renewal Requirement Removed','category',(int) $row['category_id'],$row['doc_key']);
    }

    /**
     * Records the renewal's requirement snapshot. Retained rows keep their earlier staff-accepted
     * verification; $selected holds the doc_keys that were uploaded as files with this renewal.
     * An uploaded file is only Pending here: it is never treated as verified until staff review it.
     */
    public function snapshot(int $applicationId, array $plan, array $selected): void
    {
        $insert = $this->pdo->prepare('INSERT INTO application_document_checklists (application_id,doc_key,applicant_has,verification_status,staff_remarks,reviewed_by,reviewed_at,expires_at,source_checklist_id,requirement_label,required_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($plan['retained'] as $row) {
            $insert->execute([$applicationId,$row['doc_key'],1,'Verified',$row['staff_remarks'],$row['reviewed_by'],$row['reviewed_at'],$row['expires_at'] ?? null,$row['id'],$row['label'],0]);
        }
        foreach ($plan['fresh'] as $row) {
            $uploaded = !empty($selected[$row['doc_key']]);
            $insert->execute([$applicationId,$row['doc_key'],(int) $uploaded,'Pending',null,null,null,null,$row['source_checklist_id'],$row['label'],1]);
        }
    }
}
