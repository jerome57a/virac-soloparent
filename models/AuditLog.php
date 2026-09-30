<?php
require_once __DIR__ . '/Model.php';

/** Read side of audit_logs (writes go through audit_log() in includes/functions.php). Records are never edited or deleted. */
class AuditLog extends Model
{
    public function recent(int $limit): array
    {
        return $this->pdo->query('SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT ' . (int) $limit)->fetchAll();
    }

    /** Most recent 300 events, filtered by actor/details text, exact action, and Y-m-d date range (invalid dates ignored). */
    public function search(string $q, string $action, string $from, string $to): array
    {
        $sql = 'SELECT * FROM audit_logs WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (actor_name LIKE ? OR details LIKE ?)';
            array_push($params, "%$q%", "%$q%");
        }
        if ($action !== '') {
            $sql .= ' AND action = ?';
            $params[] = $action;
        }
        if (is_ymd($from)) {
            $sql .= ' AND created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if (is_ymd($to)) {
            $sql .= ' AND created_at <= ?';
            $params[] = $to . ' 23:59:59';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY created_at DESC LIMIT 300');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function distinctActions(): array
    {
        return $this->pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
    }
}
