<?php
require_once __DIR__ . '/Model.php';

/**
 * application_auto_checks: the saved results of the automated verification (append-only history). Every method is safe
 * to call before the table has been created (database/migrate_auto_verification.sql): reads return nothing and a save
 * is skipped, so the pages that show the live result keep working.
 */
class ApplicationAutoCheck extends Model
{
    /** True when the table exists (database/migrate_auto_verification.sql has been applied). */
    public function available(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM application_auto_checks LIMIT 0');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /** Saves $result (AutoVerificationService output) unless the latest saved one for this application is identical. True if a row was written. */
    public function record(int $applicationId, array $result, string $trigger): bool
    {
        try {
            $latest = $this->latestFor([$applicationId])[$applicationId] ?? null;
            $c = $result['counts'];
            // Identical to the latest saved result (every check, not just the totals): nothing new to keep.
            if ($latest && $latest['verdict'] === $result['verdict'] && $this->latestChecks($applicationId) === json_decode(json_encode($result['checks']), true)) {
                return false;
            }
            $this->pdo->prepare(
                'INSERT INTO application_auto_checks (application_id, verdict, pass_count, flag_count, fail_count, checks, trigger_event) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$applicationId, $result['verdict'], $c['pass'], $c['flag'], $c['fail'], json_encode($result['checks'], JSON_UNESCAPED_UNICODE), mb_substr($trigger, 0, 20)]);
            return true;
        } catch (PDOException $e) {
            error_log('Automated verification result not saved: ' . $e->getMessage());
            return false;
        }
    }

    /** The checks (as saved) of the latest result for one application; [] when none or the table is missing. */
    public function latestChecks(int $applicationId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT checks FROM application_auto_checks WHERE application_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$applicationId]);
            $json = $stmt->fetchColumn();
            $checks = $json ? json_decode((string) $json, true) : [];
            return is_array($checks) ? $checks : [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /** application id => the latest saved row (verdict and counts) for each of $ids; empty when none or the table is missing. */
    public function latestFor(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        try {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT c.application_id, c.verdict, c.pass_count, c.flag_count, c.fail_count, c.created_at
                 FROM application_auto_checks c
                 JOIN (SELECT application_id, MAX(id) AS id FROM application_auto_checks WHERE application_id IN ($marks) GROUP BY application_id) m ON m.id = c.id"
            );
            $stmt->execute($ids);
            return array_column($stmt->fetchAll(), null, 'application_id');
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * How the system's FIRST recommendation for each application compares with what staff did, for the evaluation:
     * rows of [verdict, outcome, n]. outcome is 'corrected' (staff asked for corrections at some point), else 'rejected',
     * 'approved' (Approved or ID Released) or 'open' (still being processed). Empty when the table is missing.
     */
    public function evaluation(): array
    {
        try {
            return $this->pdo->query(
                "SELECT f.verdict,
                        CASE WHEN EXISTS (SELECT 1 FROM application_status_history h WHERE h.application_id = a.id AND h.status = 'For Revision') THEN 'corrected'
                             WHEN a.status = 'Rejected' THEN 'rejected'
                             WHEN a.status IN ('Approved','ID Released') THEN 'approved'
                             ELSE 'open' END AS outcome,
                        COUNT(*) AS n
                 FROM applications a
                 JOIN application_auto_checks f ON f.id = (SELECT MIN(id) FROM application_auto_checks WHERE application_id = a.id)
                 GROUP BY f.verdict, outcome ORDER BY f.verdict, outcome"
            )->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}
