<?php
/**
 * Evaluation report for the automated verification: how the system's FIRST recommendation for each application compares
 * with what MSWD staff did. READ-ONLY (one SELECT); prints counts, never names or contact details.
 *
 *   php database/auto_verification_report.php
 *
 * Runs against whichever database the environment configures (use the test instance unless you mean otherwise).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
require __DIR__ . '/../config/database.php';
require APP_ROOT . '/models/ApplicationAutoCheck.php';

$rows = (new ApplicationAutoCheck($pdo))->evaluation();
if (!$rows) {
    echo "No saved automated results yet (run database/migrate_auto_verification.sql, then submit or open applications).\n";
    exit(0);
}
$table = [];
foreach ($rows as $r) {
    $table[$r['verdict']][$r['outcome']] = (int) $r['n'];
}
$outcomes = ['approved', 'corrected', 'rejected', 'open'];
printf("%-10s %9s %10s %9s %6s %7s\n", ...array_merge(['System'], array_map('ucfirst', $outcomes), ['Total']));
$total = array_fill_keys($outcomes, 0);
foreach (['ready', 'review', 'fix'] as $verdict) {
    $line = array_map(fn($o) => $table[$verdict][$o] ?? 0, $outcomes);
    foreach ($outcomes as $i => $o) { $total[$o] += $line[$i]; }
    printf("%-10s %9d %10d %9d %6d %7d\n", ...array_merge([$verdict], $line, [array_sum($line)]));
}
printf("%-10s %9d %10d %9d %6d %7d\n", ...array_merge(['All'], array_values($total), [array_sum($total)]));

// "corrected" = staff asked for corrections at some point; "approved"/"rejected" = final outcome with no correction request.
$decided = fn(string $v) => ($table[$v]['approved'] ?? 0) + ($table[$v]['corrected'] ?? 0) + ($table[$v]['rejected'] ?? 0);
$readyClean = $table['ready']['approved'] ?? 0;
$readyAll = $decided('ready');
$flagged = $decided('review') + $decided('fix');
$flaggedProblem = ($table['review']['corrected'] ?? 0) + ($table['fix']['corrected'] ?? 0) + ($table['review']['rejected'] ?? 0) + ($table['fix']['rejected'] ?? 0);
$allProblem = array_sum(array_map(fn($v) => ($table[$v]['corrected'] ?? 0) + ($table[$v]['rejected'] ?? 0), ['ready', 'review', 'fix']));
echo "\n";
if ($readyAll) { printf("Of %d decided applications the system called 'ready', %d (%.0f%%) went through with no correction request.\n", $readyAll, $readyClean, 100 * $readyClean / $readyAll); }
if ($allProblem) { printf("Of %d applications that needed corrections or were rejected, the system had flagged %d (%.0f%%).\n", $allProblem, $flaggedProblem, 100 * $flaggedProblem / $allProblem); }
if ($flagged) { printf("Of %d applications the system flagged or failed, %d (%.0f%%) really needed corrections or were rejected.\n", $flagged, $flaggedProblem, 100 * $flaggedProblem / $flagged); }
