<?php
require_once __DIR__ . '/../models/SmsLog.php';

/** How many failed texts are waiting for staff (the badge in the staff menu). 0 when it cannot be read; never breaks a page. */
function staff_sms_attention_count(): int
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        return count((new SmsLog($pdo))->needingAttention(SMS_MAX_ATTEMPTS, 500));
    } catch (Throwable $e) {
        return 0;
    }
}

/** Interviews whose date has passed without an outcome (the badge on Appointment Management). 0 when it cannot be read. */
function staff_outcome_waiting_count(): int
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        require_once __DIR__ . '/../models/Appointment.php';
        return count((new Appointment($pdo))->awaitingOutcome());
    } catch (Throwable $e) {
        return 0;
    }
}

/** Documents waiting for MSWDO staff: ["applications" => with documents to verify, "resent" => of those, re-uploaded]. Zeros when it cannot be read. */
function staff_doc_waiting_counts(): array
{
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return ['applications' => 0, 'resent' => 0];
    }
    try {
        require_once __DIR__ . '/../models/Document.php';
        $row = (new Document($pdo))->verificationBacklog();
        return ['applications' => (int) $row['applications'], 'resent' => (int) $row['resent_applications']];
    } catch (Throwable $e) {
        return ['applications' => 0, 'resent' => 0];
    }
}
