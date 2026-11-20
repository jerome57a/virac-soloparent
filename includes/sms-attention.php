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
