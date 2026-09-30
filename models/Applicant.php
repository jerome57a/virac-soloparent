<?php
require_once __DIR__ . '/User.php';

/** The applicant's own account/profile data (a users row with role 'applicant'). */
class Applicant extends User
{
    public function findApplicant(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'applicant'");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Staff applicant-records search by name/username/email/mobile and/or
     * any application's barangay. Returns ['rows', ...paginate() fields];
     * each row carries app_count and last_submitted.
     */
    public function search(string $q, string $barangay, int $page, int $perPage): array
    {
        $where = "u.role = 'applicant'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.mobile LIKE ?)';
            array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
        }
        if ($barangay !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM applications a2 WHERE a2.user_id = u.id AND a2.barangay LIKE ?)';
            $params[] = "%$barangay%";
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM users u WHERE $where");
        $countStmt->execute($params);
        $pager = paginate((int) $countStmt->fetchColumn(), $page, $perPage);

        $stmt = $this->pdo->prepare(
            "SELECT u.*,
               (SELECT COUNT(*) FROM applications a WHERE a.user_id = u.id) AS app_count,
               (SELECT MAX(a.submitted_at) FROM applications a WHERE a.user_id = u.id) AS last_submitted
             FROM users u WHERE $where ORDER BY u.full_name LIMIT {$pager['perPage']} OFFSET {$pager['offset']}"
        );
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll()] + $pager;
    }

    public function updateProfile(int $userId, string $fullName, string $email, string $mobile): void
    {
        $this->pdo->prepare('UPDATE users SET full_name = ?, email = ?, mobile = ? WHERE id = ?')
            ->execute([$fullName, $email, $mobile, $userId]);
    }

    public function passwordHash(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        return (string) $stmt->fetchColumn();
    }

    public function setProfilePhoto(int $userId, string $relativePath): void
    {
        $this->pdo->prepare('UPDATE users SET profile_photo = ? WHERE id = ?')->execute([$relativePath, $userId]);
    }
}
