<?php
/**
 * OFFLINE checks of what the staff-scheduled interview text tells the Solo Parent to bring (SMSService::interviewBringText,
 * SMSService::addBringNote) and that the ID release text asks for a valid ID. No database, no SMS, no network.
 *
 *   php database/test_interview_bring_note.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/SMSService.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $message\n"); }
}

// Full wording (the default for deployment).
$new = SMSService::interviewBringText(false, false);
$renewal = SMSService::interviewBringText(true, false);
check(str_contains($new, 'valid ID') && str_contains($new, 'two 1x1 pictures') && !str_contains($new, 'Solo Parent ID'), 'new application: bring a valid ID and two 1x1 pictures (no Solo Parent ID)');
check(str_contains($renewal, 'current Solo Parent ID') && str_contains($renewal, 'valid ID') && str_contains($renewal, 'two 1x1 pictures'), 'renewal: bring the current Solo Parent ID, a valid ID and two 1x1 pictures');

$base = 'MSWDO Virac: Your interview for application VIRAC-SP-2026-0001 is scheduled on Monday, November 23, 2026, 7:00 AM to 5:00 PM at MSWD office. You may come on that date, or log in to your e-SoloParentID account and pick another date.';
check(SMSService::addBringNote($base, false, false) === $base . ' ' . $new, 'full wording: the sentence is added after the text');
check(SMSService::addBringNote($base, true, false) === $base . ' ' . $renewal, 'full wording, renewal: the renewal sentence is added');

// Compact wording: the closing line is replaced, so the text never gets longer than before and stays one 160-character text.
$compact = SMSService::COMPACT_TEMPLATES['interview_date_assigned'][1];
$filled = strtr($compact, ['{ref}' => 'VIRAC-SP-2026-0001', '{when}' => 'Wed, Nov 25, 2026', '{hours}' => '7AM-5PM']);
$compactNew = SMSService::addBringNote($filled, false, true);
$compactRenewal = SMSService::addBringNote($filled, true, true);
check(str_contains($compactNew, 'valid ID') && str_contains($compactNew, '2 1x1 pics') && str_contains($compactNew, 'pick another date'), 'compact, new: bring list kept and the "pick another date" line stays');
check(str_contains($compactRenewal, 'Solo Parent ID') && str_contains($compactRenewal, 'valid ID') && str_contains($compactRenewal, '2 1x1 pics'), 'compact, renewal: all three things to bring');
check(mb_strlen($compactNew) <= mb_strlen($filled), 'compact, new: not longer than the original text (' . mb_strlen($compactNew) . ' vs ' . mb_strlen($filled) . ')');
check(mb_strlen($compactRenewal) <= mb_strlen($filled), 'compact, renewal: not longer than the original text (' . mb_strlen($compactRenewal) . ' vs ' . mb_strlen($filled) . ')');
check(mb_strlen($compactNew) <= 146 && mb_strlen($compactRenewal) <= 146, 'compact texts fit one 160-character SMS with the gateway header (146 + header)');

// ID release text asks for a valid ID in both wordings.
check(str_contains(SMSService::idReleaseTemplate(false), 'valid ID'), 'full ID release text asks for a valid ID');
check(str_contains(SMSService::idReleaseTemplate(true), 'valid ID'), 'compact ID release text asks for a valid ID');

echo $failures === 0 ? "PASS: interview bring note and ID release wording ($checks checks).\n" : "FAILED: $failures of $checks checks.\n";
exit($failures === 0 ? 0 : 1);
