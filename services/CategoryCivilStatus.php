<?php
/**
 * Which Solo Parent categories fit which civil status (new application, step 1).
 *
 * categories.civil_statuses holds a comma list of the civil statuses a category is shown for ("Married,Separated"); NULL means it
 * fits every civil status. Categories 12 (guardian), 15 (child as a consequence of rape) and 18 (spouse or family member of an OFW)
 * fit anyone; the others depend on the Solo Parent's situation. The rule is data, so a new category needs no code change.
 * Without the column (a database that has not run database/migrate_category_civil_status.php) every category fits everyone and
 * nothing is filtered. The renewal form does not use this: its category is fixed.
 */
final class CategoryCivilStatus
{
    /** The civil statuses the form offers (includes/data.php CIVIL_STATUS_OPTIONS). */
    public const STATUSES = ['Single', 'Married', 'Widowed', 'Separated', 'Annulled'];

    /** Situational categories, by name: the civil statuses they are shown for. Every other category fits every civil status. */
    public const BY_NAME = [
        'Abandonment by the spouse' => ['Married', 'Separated'],
        'Declaration of nullity or annulment of marriage' => ['Annulled'],
        'Unmarried father or mother who keeps and rears the child/children' => ['Single'],
        'Detention or imprisonment of the spouse' => ['Married', 'Separated'],
        'Death of the spouse' => ['Widowed'],
        'Legal or de facto separation of spouse for at least six months' => ['Married', 'Separated'],
    ];

    private static ?bool $hasColumn = null;

    /** The statuses a category row is limited to, or null when it fits every civil status. */
    public static function allowed(array $category): ?array
    {
        $raw = trim((string) ($category['civil_statuses'] ?? ''));
        if ($raw === '') {
            return null;
        }
        $list = array_values(array_intersect(self::STATUSES, array_map('trim', explode(',', $raw))));
        return $list ?: null; // a list with no known status is ignored rather than hiding the category from everyone
    }

    /** True when the category may be chosen with this civil status. An unknown or empty civil status filters nothing. */
    public static function fits(array $category, string $civilStatus): bool
    {
        $allowed = self::allowed($category);
        return $allowed === null || !in_array($civilStatus, self::STATUSES, true) || in_array($civilStatus, $allowed, true);
    }

    /** @param array<int, array> $categories @return array<int, array> the categories that fit, in their order */
    public static function filter(array $categories, string $civilStatus): array
    {
        return array_values(array_filter($categories, fn($c) => self::fits($c, $civilStatus)));
    }

    /** What the browser needs to filter the list: [{id, name, statuses: [..]|null}]. */
    public static function forBrowser(array $categories): array
    {
        return array_map(fn($c) => ['id' => (int) $c['id'], 'name' => (string) $c['name'], 'statuses' => self::allowed($c)], $categories);
    }

    public static function columnExists(PDO $pdo): bool
    {
        if (self::$hasColumn === null) {
            try {
                self::$hasColumn = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'civil_statuses'")->fetchColumn() === 1;
            } catch (Throwable $e) {
                self::$hasColumn = false;
            }
        }
        return self::$hasColumn;
    }

    public static function forgetColumn(): void
    {
        self::$hasColumn = null;
    }

    /** Whether the category (by id) may be chosen with this civil status; true when the column does not exist. */
    public static function fitsId(PDO $pdo, int $categoryId, string $civilStatus): bool
    {
        if (!self::columnExists($pdo)) {
            return true;
        }
        $stmt = $pdo->prepare('SELECT civil_statuses FROM categories WHERE id = ?');
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch();
        return !$row || self::fits($row, $civilStatus);
    }
}
