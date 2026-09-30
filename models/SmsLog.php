<?php
require_once __DIR__ . '/Model.php';

/** Read side of sms_logs plus the admin-editable sms_templates (writes to sms_logs go through SmsService). */
class SmsLog extends Model
{
    private const STATUS_FILTERS = ['failed' => "s.provider_status IN ('failed')", 'sent' => "s.provider_status = 'sent'", 'skipped' => "s.provider_status = 'skipped-no-api-key'"];

    /** Latest 200 logs, optionally by status filter (sent/failed/skipped) and reference/mobile/message search. Account-level SMS have a NULL reference_no. */
    public function search(string $statusFilter, string $q): array
    {
        $sql = 'SELECT s.*, a.reference_no FROM sms_logs s LEFT JOIN applications a ON a.id = s.application_id WHERE 1=1';
        $params = [];
        if (isset(self::STATUS_FILTERS[$statusFilter])) {
            $sql .= ' AND ' . self::STATUS_FILTERS[$statusFilter];
        }
        if ($q !== '') {
            $sql .= ' AND (a.reference_no LIKE ? OR s.mobile LIKE ? OR s.message LIKE ?)';
            $params = ["%$q%", "%$q%", "%$q%"];
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY s.sent_at DESC LIMIT 200');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function countByStatus(string $providerStatus): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE provider_status = ?');
        $stmt->execute([$providerStatus]);
        return (int) $stmt->fetchColumn();
    }

    public function templates(): array
    {
        return $this->pdo->query('SELECT * FROM sms_templates ORDER BY template_key')->fetchAll();
    }

    public function updateTemplate(string $key, string $body): void
    {
        $this->pdo->prepare('UPDATE sms_templates SET body = ? WHERE template_key = ?')->execute([$body, $key]);
    }
}
