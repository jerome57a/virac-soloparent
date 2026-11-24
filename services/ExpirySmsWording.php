<?php
require_once __DIR__ . '/SMSService.php';

/**
 * Brings the stored ID-expiry SMS templates up to the wording used since renewal opens on the expiration date:
 *   renewal_reminder  "ID Expiry Day (auto)"        sent on the expiry day:       "... expires today, <date>. You can renew online starting today ..."
 *   renewal_expired   "ID Expired, Sent Late (Send now)"  sent by staff for a missed day: "... expired on <date> ..."
 * A template body an administrator edited by hand is never overwritten: only a body that still holds the previous built-in wording word
 * for word (full or compact) is replaced. The two labels are renamed when they still hold the old names.
 * Used by database/migrate_expiry_sms_wording.php and its test.
 */
final class ExpirySmsWording
{
    private const OLD = [
        'renewal_reminder' => [
            'label' => 'Renewal Reminder (auto)',
            'bodies' => [
                'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please visit MSWDO or apply for renewal online soon to avoid interruption.',
                'MSWDO Virac: Your Solo Parent ID ({ref}) expires on {when}. Please renew online or visit MSWDO.',
            ],
        ],
        'renewal_expired' => ['label' => 'Renewal Reminder after Expiry (auto)', 'bodies' => []],
    ];

    /**
     * @return array<string,string> template key => what was (or would be) done
     */
    public static function migrate(PDO $pdo, bool $apply): array
    {
        $report = [];
        foreach (self::OLD as $key => $old) {
            $stmt = $pdo->prepare('SELECT label, body FROM sms_templates WHERE template_key = ?');
            $stmt->execute([$key]);
            $row = $stmt->fetch();
            if (!$row) {
                $report[$key] = 'no stored row (the built-in wording is used)';
                continue;
            }
            [$label, $newFull] = SMSService::TEMPLATES[$key];
            $newCompact = SMSService::COMPACT_TEMPLATES[$key][1] ?? $newFull;
            $notes = [];
            if ($old['bodies']) {
                $target = null;
                if ($row['body'] === $old['bodies'][0]) {
                    $target = $newFull;
                } elseif ($row['body'] === $old['bodies'][1]) {
                    $target = $newCompact;
                }
                if ($target !== null) {
                    $notes[] = 'text switched to the new wording';
                    if ($apply) {
                        $pdo->prepare('UPDATE sms_templates SET body = ? WHERE template_key = ? AND body = ?')->execute([$target, $key, $row['body']]);
                    }
                } elseif ($row['body'] === $newFull || $row['body'] === $newCompact) {
                    $notes[] = 'text already updated';
                } else {
                    $notes[] = 'text edited by an administrator, left alone';
                }
            }
            if ($row['label'] === $old['label']) {
                $notes[] = 'renamed to "' . $label . '"';
                if ($apply) {
                    $pdo->prepare('UPDATE sms_templates SET label = ? WHERE template_key = ? AND label = ?')->execute([$label, $key, $old['label']]);
                }
            }
            $report[$key] = implode('; ', $notes) ?: 'nothing to change';
        }
        return $report;
    }
}
