<?php
/**
 * Office check-in: when a Solo Parent arrives at the MSWDO office, staff type the reference number and record the arrival.
 * Adds appointments.arrived_at (when) and appointments.arrived_by (which staff member), with its foreign key and an index.
 *
 *   php database/migrate_office_checkin.php          show what would change (changes nothing)
 *   php database/migrate_office_checkin.php apply    make the change
 *
 * Back the database up first. Safe to run again. Without these columns the system still works: the "Today at the office" panel then
 * lists today's interviews without the check-in box.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';

$apply = ($argv[1] ?? '') === 'apply';
$has = function (string $what, string $sql) use ($pdo): bool { return (int) $pdo->query($sql)->fetchColumn() > 0; };
$col = fn(string $c) => $has($c, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND COLUMN_NAME = '$c'");
$steps = [];
if (!$col('arrived_at')) $steps[] = ['add appointments.arrived_at', 'ALTER TABLE appointments ADD COLUMN arrived_at DATETIME DEFAULT NULL AFTER result_recorded_at'];
if (!$col('arrived_by')) $steps[] = ['add appointments.arrived_by', 'ALTER TABLE appointments ADD COLUMN arrived_by INT NULL AFTER arrived_at'];
if (!$has('fk', "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND CONSTRAINT_NAME = 'fk_appt_arrived_by'")) {
    $steps[] = ['add the foreign key arrived_by -> users', 'ALTER TABLE appointments ADD CONSTRAINT fk_appt_arrived_by FOREIGN KEY (arrived_by) REFERENCES users(id) ON DELETE SET NULL'];
}
if (!$has('idx', "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'appointments' AND INDEX_NAME = 'idx_appt_arrived'")) {
    $steps[] = ['add the index on arrived_at', 'ALTER TABLE appointments ADD KEY idx_appt_arrived (arrived_at)'];
}
echo 'Database: ', $pdo->query('SELECT DATABASE()')->fetchColumn(), $apply ? '  (APPLY)' : '  (check only)', "\n";
if (!$steps) {
    echo "Already done: appointments has arrived_at and arrived_by.\n";
    exit(0);
}
foreach ($steps as [$label, $sql]) {
    echo ($apply ? '' : '[would] '), $label, "\n";
    if ($apply) {
        $pdo->exec($sql);
    }
}
echo $apply ? "Done.\n" : "Nothing was changed. Run with \"apply\" to make the change (back up the database first).\n";
