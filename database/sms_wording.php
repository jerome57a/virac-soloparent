<?php
/**
 * Switches the stored SMS templates between the full wording (deployment) and the shortened wording (small test token).
 *
 *   php database/sms_wording.php compact    shortened wording: one gateway credit per text
 *   php database/sms_wording.php full       the full wording (use this before deployment)
 *
 * Only the templates listed in SMSService::COMPACT_TEMPLATES are touched, and only rows that still hold the OTHER
 * wording word for word: a template an administrator edited by hand is never overwritten. The correction text and the
 * automatic ID-release text are built in code; they follow the SMS_COMPACT_TEXTS setting in config/local.php, so set
 * that too ('SMS_COMPACT_TEXTS' => true for compact, false for full). This tool changes the live database.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
$mode = $argv[1] ?? '';
if (!in_array($mode, ['compact', 'full'], true)) {
    exit("Usage: php database/sms_wording.php compact|full\n");
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';
require_once 'services/SMSService.php';

$update = $pdo->prepare('UPDATE sms_templates SET body = ? WHERE template_key = ? AND body = ?');
$changed = 0;
foreach (SMSService::COMPACT_TEMPLATES as $key => [, $compactBody]) {
    $fullBody = SMSService::TEMPLATES[$key][1];
    [$from, $to] = $mode === 'compact' ? [$fullBody, $compactBody] : [$compactBody, $fullBody];
    $update->execute([$to, $key, $from]);
    $n = $update->rowCount();
    $changed += $n;
    if ($n === 0) {
        $now = $pdo->prepare('SELECT body FROM sms_templates WHERE template_key = ?');
        $now->execute([$key]);
        $body = $now->fetchColumn();
        $state = $body === false ? 'no stored row (the built-in wording is used)' : ($body === $to ? 'already ' . $mode : 'edited by an administrator, left alone');
        echo str_pad($key, 26), $state, "\n";
    } else {
        echo str_pad($key, 26), "switched to $mode wording\n";
    }
}
echo "\n$changed template row(s) changed.\n";
echo "Now set 'SMS_COMPACT_TEXTS' => " . ($mode === 'compact' ? 'true' : 'false') . " in config/local.php (it controls the correction text and the automatic ID-release text).\n";
echo 'Currently SMS_COMPACT_TEXTS is ' . (SMSService::compact() ? 'ON' : 'OFF') . ".\n";
