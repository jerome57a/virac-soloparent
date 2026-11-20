<?php
require_once __DIR__ . '/Model.php';

/** Read side of sms_logs plus the admin-editable sms_templates (writes to sms_logs go through services/SMSService.php). */
class SmsLog extends Model
{
    /** Filter key => SQL condition. 'test' = logged in test mode (never sent); 'skipped' is its old name, kept for links. */
    private const FILTERS = [
        'sent' => "s.status = 'SENT'",
        'test' => "s.status = 'SENT' AND s.test_mode = 1",
        'skipped' => "s.status = 'SENT' AND s.test_mode = 1",
        'failed' => "s.status = 'FAILED'",
        'pending' => "s.status = 'PENDING'",
    ];

    /** Paginated logs, with account-level SMS retaining a NULL reference_no. */
    public function page(string $filter, string $q, int $page, int $perPage = 10): array
    {
        $sql = 'SELECT s.*, a.reference_no FROM sms_logs s LEFT JOIN applications a ON a.id = s.application_id WHERE 1=1';
        $params = [];
        if (isset(self::FILTERS[$filter])) {
            $sql .= ' AND ' . self::FILTERS[$filter];
        }
        if ($q !== '') {
            $sql .= ' AND (a.reference_no LIKE ? OR s.mobile LIKE ? OR s.message LIKE ?)';
            $params = ["%$q%", "%$q%", "%$q%"];
        }
        $count = $this->pdo->prepare(str_replace('SELECT s.*, a.reference_no', 'SELECT COUNT(*)', $sql));
        $count->execute($params);
        $pager = paginate((int) $count->fetchColumn(), $page, $perPage);
        $stmt = $this->pdo->prepare($sql . " ORDER BY s.sent_at DESC, s.id DESC LIMIT {$pager['perPage']} OFFSET {$pager['offset']}");
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    /** Templates whose text is never sent again from a log (secrets, or a flow that must be confirmed afresh). */
    public const NOT_RETRYABLE = ['password_reset', 'mobile_verification', 'document_correction'];

    /** The latest SMS sent about one application, newest first, with whether each failed one can still be retried. */
    public function forApplication(int $applicationId, int $maxAttempts, int $limit = 8): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.template_key, s.status, s.attempts, s.error, s.mobile, s.sent_at,
                    (SELECT COUNT(*) FROM sms_logs n WHERE n.application_id = s.application_id AND n.template_key = s.template_key AND n.id > s.id) AS newer
             FROM sms_logs s WHERE s.application_id = ? ORDER BY s.id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$applicationId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            // Retryable: failed, attempts left, a kind that may be re-sent, and no later message of the same kind replaced it.
            $row['can_retry'] = $row['status'] === 'FAILED' && (int) $row['attempts'] < $maxAttempts
                && !in_array($row['template_key'], self::NOT_RETRYABLE, true) && (int) $row['newer'] === 0;
        }
        return $rows;
    }

    /** How many days a failed text stays on the staff "failed texts" list. */
    public const ATTENTION_DAYS = 7;

    /**
     * Failed texts about an application that staff should look at: failed within ATTENTION_DAYS, not replaced by a newer text of
     * the same kind for that application, and not a code the applicant requests for themselves (reset / sign-up codes).
     * Newest first, with whether each can still be retried. The list empties itself: a retry that works, a newer text of the same
     * kind, or the passing of ATTENTION_DAYS all remove an entry.
     */
    public function needingAttention(int $maxAttempts, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT s.id, s.application_id, s.template_key, s.status, s.attempts, s.error, s.sent_at, a.reference_no
             FROM sms_logs s LEFT JOIN applications a ON a.id = s.application_id
             WHERE s.status = 'FAILED' AND s.application_id IS NOT NULL
               AND (s.template_key IS NULL OR s.template_key NOT IN ('password_reset', 'mobile_verification'))
               AND s.sent_at >= DATE_SUB(NOW(), INTERVAL " . self::ATTENTION_DAYS . " DAY)
               AND NOT EXISTS (SELECT 1 FROM sms_logs n WHERE n.application_id = s.application_id AND n.template_key <=> s.template_key AND n.id > s.id)
             ORDER BY s.id DESC LIMIT " . max(1, min(500, $limit))
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['can_retry'] = (int) $row['attempts'] < $maxAttempts && !in_array($row['template_key'], self::NOT_RETRYABLE, true);
        }
        return $rows;
    }
    /** Kept for callers that need the latest 200 logs. */
    public function search(string $filter, string $q): array
    {
        return $this->page($filter, $q, 1, 200)['rows'];
    }

    /** How many logs match a filter key (sent / test / failed / pending). */
    public function countByStatus(string $filter): int
    {
        if (!isset(self::FILTERS[$filter])) {
            return 0;
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM sms_logs s WHERE ' . self::FILTERS[$filter])->fetchColumn();
    }

    public function templates(): array
    {
        return $this->pdo->query('SELECT * FROM sms_templates ORDER BY template_key')->fetchAll();
    }

    public function templateExists(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM sms_templates WHERE template_key = ?');
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateTemplate(string $key, string $body): void
    {
        $this->pdo->prepare('UPDATE sms_templates SET body = ? WHERE template_key = ?')->execute([$body, $key]);
    }
}
