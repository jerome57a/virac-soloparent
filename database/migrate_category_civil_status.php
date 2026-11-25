<?php
/**
 * Civil status filters the Solo Parent category in the new application (services/CategoryCivilStatus.php).
 * Adds categories.civil_statuses (a comma list of the civil statuses a category is shown for; NULL = every civil status) and sets it
 * for the six situational categories:
 *   Single: Unmarried father or mother · Married and Separated: Abandonment, Detention of the spouse, Legal or de facto separation ·
 *   Widowed: Death of the spouse · Annulled: Declaration of nullity or annulment.
 * The guardian, rape and OFW categories stay NULL (they fit everyone).
 *
 *   php database/migrate_category_civil_status.php          show what would change (changes nothing)
 *   php database/migrate_category_civil_status.php apply    make the change
 *
 * Back the database up first. Safe to run again: it only fills a situational category whose value is still empty.
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require_once 'services/CategoryCivilStatus.php';

$apply = ($argv[1] ?? '') === 'apply';
echo 'Database: ', $pdo->query('SELECT DATABASE()')->fetchColumn(), $apply ? '  (APPLY)' : '  (check only)', "\n";
$hasColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'civil_statuses'")->fetchColumn() === 1;
if (!$hasColumn) {
    echo ($apply ? '' : '[would] '), "add categories.civil_statuses\n";
    if ($apply) {
        $pdo->exec("ALTER TABLE categories ADD COLUMN civil_statuses VARCHAR(100) DEFAULT NULL AFTER description");
    }
}
$rows = $pdo->query('SELECT id, name, is_active' . ($hasColumn || $apply ? ', civil_statuses' : ', NULL AS civil_statuses') . ' FROM categories ORDER BY sort_order, id')->fetchAll();
$set = null; // prepared only when it is used: on an older database a check run has no column to update yet
$changed = 0;
foreach ($rows as $r) {
    $want = CategoryCivilStatus::BY_NAME[$r['name']] ?? null;
    if ($want === null) {
        continue;
    }
    if (trim((string) $r['civil_statuses']) !== '') {
        echo sprintf('%3d %-66s already %s', $r['id'], mb_substr($r['name'], 0, 66), $r['civil_statuses']), "\n";
        continue;
    }
    echo sprintf('%3d %-66s %s%s', $r['id'], mb_substr($r['name'], 0, 66), $apply ? '' : '[would] ', implode(',', $want)), $r['is_active'] ? '' : '  (inactive)', "\n";
    if ($apply) {
        $set ??= $pdo->prepare('UPDATE categories SET civil_statuses = ? WHERE id = ? AND (civil_statuses IS NULL OR civil_statuses = \'\')');
        $set->execute([implode(',', $want), (int) $r['id']]);
    }
    $changed++;
}
echo $changed === 0 && $hasColumn ? "Nothing to change.\n" : ($apply ? "Done.\n" : "Nothing was changed. Run with \"apply\" to make the change (back up the database first).\n");
