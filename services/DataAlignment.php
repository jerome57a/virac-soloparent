<?php
require_once __DIR__ . '/../config/settings.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/ExpirySmsWording.php';
require_once __DIR__ . '/SoloParentChildRules.php';

/**
 * Brings a database's data in line with the current code (November 2026). Used by database/migrate_data_alignment.php and its test.
 *   1. The two 1x1 ID pictures are no longer uploaded (brought to the interview): the 'photo1x1' requirement leaves
 *      document_requirements and renewal_requirements; a renewal's saved checklist row for it becomes not required, with a readable
 *      name. Uploaded pictures are kept as history.
 *   2. Settings never saved (the code used their defaults) are saved with that same default: no change in behaviour.
 *   3. The ID-expiry SMS templates get the "expires today" wording (ExpirySmsWording; hand-edited texts are left alone).
 *   4. The unused users.mobile_code / mobile_code_expires_at / mobile_code_attempts columns are dropped, only when all are empty.
 *   Report only: children over 22 without a disability recorded, and applications whose account no longer exists.
 * Steps 1 to 3 run in one transaction; step 4 is a structure change (it commits by itself) and runs last, on its own.
 */
final class DataAlignment
{
    public const RETIRED_KEY = 'photo1x1';
    public const MOBILE_CODE_COLUMNS = ['mobile_code', 'mobile_code_expires_at', 'mobile_code_attempts'];

    /**
     * @param callable(string):void $say  receives each report line
     * @return array{changed: array<string,int>, review: array{children: array, orphans: array}}
     */
    public static function run(PDO $pdo, bool $apply, callable $say): array
    {
        $would = $apply ? '' : '[would] ';
        $count = function (string $sql, array $args = []) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($args); return (int) $s->fetchColumn(); };
        $changed = ['requirements' => 0, 'renewal_requirements' => 0, 'snapshots' => 0, 'settings' => 0, 'columns' => 0];

        $pdo->beginTransaction();
        try {
            $say('1. 1x1 ID pictures: brought to the interview, not uploaded');
            $key = self::RETIRED_KEY;
            $label = Document::RETIRED_LABELS[$key];
            $changed['requirements'] = $count('SELECT COUNT(*) FROM document_requirements WHERE doc_key = ?', [$key]);
            $changed['renewal_requirements'] = $count('SELECT COUNT(*) FROM renewal_requirements WHERE doc_key = ?', [$key]);
            $changed['snapshots'] = $count('SELECT COUNT(*) FROM application_document_checklists WHERE doc_key = ? AND required_snapshot IS NOT NULL AND (required_snapshot <> 0 OR requirement_label IS NULL OR requirement_label <> ?)', [$key, $label]);
            $say("   {$would}remove from new-application requirements: {$changed['requirements']} row(s)");
            $say("   {$would}remove from renewal requirements: {$changed['renewal_requirements']} row(s)");
            $say("   {$would}mark not required on saved renewal checklists: {$changed['snapshots']} row(s)");
            $say('   uploaded picture files kept as history: ' . $count('SELECT COUNT(*) FROM application_documents WHERE doc_key = ?', [$key]));
            if ($apply) {
                $pdo->prepare('DELETE FROM document_requirements WHERE doc_key = ?')->execute([$key]);
                $pdo->prepare('DELETE FROM renewal_requirements WHERE doc_key = ?')->execute([$key]);
                $pdo->prepare('UPDATE application_document_checklists SET required_snapshot = 0, requirement_label = ? WHERE doc_key = ? AND required_snapshot IS NOT NULL')->execute([$label, $key]);
            }

            $say('');
            $say('2. Settings the code used by default, saved with that same value');
            $stored = $pdo->query('SELECT setting_key FROM system_settings')->fetchAll(PDO::FETCH_COLUMN);
            $missing = [];
            foreach (SETTINGS_REGISTRY as $section) {
                foreach ($section['keys'] as $k => $def) {
                    if (!in_array($k, $stored, true)) {
                        $missing[$k] = (string) $def['default'];
                    }
                }
            }
            if (!$missing) {
                $say('   nothing to save');
            }
            foreach ($missing as $k => $v) {
                $say("   {$would}save " . str_pad($k, 26) . '= ' . ($v === '' ? '(empty)' : $v));
                if ($apply) {
                    $pdo->prepare('INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)')->execute([$k, $v]);
                }
            }
            $changed['settings'] = count($missing);

            $say('');
            $say('3. ID-expiry SMS wording');
            foreach (ExpirySmsWording::migrate($pdo, $apply) as $k => $state) {
                $say('   ' . str_pad($k, 18) . $would . $state);
            }

            $apply ? $pdo->commit() : $pdo->rollBack();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException('Stopped, nothing from steps 1 to 3 was changed: ' . $e->getMessage(), 0, $e);
        }

        $say('');
        $say('4. Unused columns users.mobile_code, mobile_code_expires_at, mobile_code_attempts');
        $cols = array_values(array_filter(self::MOBILE_CODE_COLUMNS, fn($c) => self::hasColumn($pdo, 'users', $c)));
        if (!$cols) {
            $say('   already gone');
        } else {
            $held = 0;
            foreach ($cols as $c) {
                $held += $count('SELECT COUNT(*) FROM users WHERE ' . ($c === 'mobile_code_attempts' ? '`' . $c . '` <> 0' : '`' . $c . '` IS NOT NULL'));
            }
            if ($held > 0) {
                $say("   left in place: $held value(s) are still stored in them. Nothing was dropped.");
            } else {
                $say("   {$would}drop " . implode(', ', $cols) . ' (all empty)');
                if ($apply) {
                    $users = $count('SELECT COUNT(*) FROM users');
                    $pdo->exec('ALTER TABLE users ' . implode(', ', array_map(fn($c) => 'DROP COLUMN `' . $c . '`', $cols)));
                    if (array_filter($cols, fn($c) => self::hasColumn($pdo, 'users', $c)) || $count('SELECT COUNT(*) FROM users') !== $users) {
                        throw new RuntimeException('Check failed after dropping the columns: restore from the backup.');
                    }
                    $changed['columns'] = count($cols);
                    $say('   dropped; users row count unchanged (' . $users . ')');
                }
            }
        }

        $say('');
        $say('For staff to review (nothing changed):');
        $over = $pdo->prepare("SELECT a.reference_no, a.status, TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) AS age
            FROM application_children c JOIN applications a ON a.id = c.application_id
            WHERE c.has_disability = 0 AND TIMESTAMPDIFF(YEAR, c.birthdate, CURDATE()) > ? ORDER BY a.reference_no");
        $over->execute([SoloParentChildRules::MAX_AGE]);
        $children = $over->fetchAll(PDO::FETCH_ASSOC);
        $say('   children over ' . SoloParentChildRules::MAX_AGE . ' without a disability recorded: ' . count($children));
        foreach ($children as $r) {
            $say('     ' . $r['reference_no'] . '  (' . $r['status'] . ', child aged ' . $r['age'] . ')');
        }
        $orphans = $pdo->query('SELECT a.reference_no, a.status FROM applications a LEFT JOIN users u ON u.id = a.user_id WHERE u.id IS NULL ORDER BY a.reference_no')->fetchAll(PDO::FETCH_ASSOC);
        $say('   applications whose Solo Parent account no longer exists: ' . count($orphans));
        foreach ($orphans as $r) {
            $say('     ' . $r['reference_no'] . '  (' . $r['status'] . ')');
        }
        return ['changed' => $changed, 'review' => ['children' => $children, 'orphans' => $orphans]];
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$table, $column]);
        return (int) $s->fetchColumn() > 0;
    }
}
