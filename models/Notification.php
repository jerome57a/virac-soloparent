<?php
require_once __DIR__ . '/Model.php';

class Notification extends Model
{
    public function forUser(int $userId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . (int) $limit);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function unreadCount(int $userId): int
    {
        return count_unread_notifications($this->pdo, $userId);
    }

    public function markAllRead(int $userId): void
    {
        mark_all_notifications_read($this->pdo, $userId);
    }
}
