<?php
require_once __DIR__ . '/Model.php';

/**
 * eligibility_assessments rows of type PRELIMINARY: the public checker's
 * answers and result. A visitor is identified only by a hash of a random
 * token kept in their session (no personal data is asked for); after they
 * log in or register, the row is linked to their account.
 */
class EligibilityAssessment extends Model
{
    /** Latest preliminary assessment for this visitor token, or null. */
    public function findByToken(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM eligibility_assessments WHERE session_token = ? AND assessment_type = 'PRELIMINARY' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        return $stmt->fetch() ?: null;
    }

    public function latestForUser(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM eligibility_assessments WHERE user_id = ? AND assessment_type = 'PRELIMINARY' ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /** One row per visitor token: re-checking replaces the earlier answers rather than piling up rows. */
    public function savePreliminary(string $tokenHash, ?int $userId, array $answers, string $result, ?string $reason): void
    {
        $existing = $this->findByToken($tokenHash);
        $json = json_encode($answers, JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $this->pdo->prepare(
                'UPDATE eligibility_assessments SET answers = ?, preliminary_result = ?, result_reason = ?, user_id = COALESCE(user_id, ?), created_at = NOW() WHERE id = ?'
            )->execute([$json, $result, $reason, $userId, $existing['id']]);
            return;
        }
        $this->pdo->prepare(
            "INSERT INTO eligibility_assessments (session_token, user_id, assessment_type, answers, preliminary_result, result_reason) VALUES (?, ?, 'PRELIMINARY', ?, ?, ?)"
        )->execute([$tokenHash, $userId, $json, $result, $reason]);
    }

    /** Links this visitor's anonymous assessment(s) to the account they just logged in or registered with. */
    public function claimForUser(string $tokenHash, int $userId): int
    {
        $stmt = $this->pdo->prepare('UPDATE eligibility_assessments SET user_id = ? WHERE session_token = ? AND user_id IS NULL');
        $stmt->execute([$userId, $tokenHash]);
        return $stmt->rowCount();
    }

    /** Records one official MSWD assessment of an application ($result ELIGIBLE or NOT_ELIGIBLE). */
    public function saveMswd(int $applicationId, int $applicantUserId, string $result, ?string $notes, int $staffId): void
    {
        $this->pdo->prepare(
            "INSERT INTO eligibility_assessments (user_id, application_id, assessment_type, answers, preliminary_result, result_reason, assessed_by)
             VALUES (?, ?, 'MSWD', '{}', ?, ?, ?)"
        )->execute([$applicantUserId, $applicationId, $result, $notes, $staffId]);
    }

    /** The application's MSWD assessments, newest first, with the assessor's name. */
    public function mswdForApplication(int $applicationId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT e.*, u.full_name AS assessor_name FROM eligibility_assessments e
             LEFT JOIN users u ON u.id = e.assessed_by
             WHERE e.application_id = ? AND e.assessment_type = 'MSWD' ORDER BY e.created_at DESC, e.id DESC"
        );
        $stmt->execute([$applicationId]);
        return $stmt->fetchAll();
    }

    /** Anonymous checks nobody ever claimed are temporary: removed after $days days. */
    public function purgeUnclaimed(int $days): void
    {
        $this->pdo->prepare(
            "DELETE FROM eligibility_assessments WHERE assessment_type = 'PRELIMINARY' AND user_id IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)"
        )->execute([$days]);
    }
}
