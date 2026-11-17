<?php
/** Applicant preparation guidance, keyed by the configured requirement's stable doc_key. */
class RequirementGuideService
{
    /** Built from the same configured requirements as the apply form and the home page: shared (standard) rows, then each active category's rows. */
    public static function catalog(PDO $pdo): array
    {
        $requirements = [];
        foreach (get_document_requirements($pdo, null) as $row) {
            $requirements[] = ['category_name' => null] + $row;
        }
        foreach (get_categories($pdo) as $category) {
            foreach (get_document_requirements($pdo, (int) $category['id']) as $row) {
                $requirements[] = ['category_name' => $category['name']] + $row;
            }
        }
        return self::forRequirements($requirements);
    }

    public static function forRequirements(array $requirements): array
    {
        $guides = [];
        foreach ($requirements as $requirement) {
            $key = (string) $requirement['doc_key'];
            if ($key === 'photo') {
                continue; // The 2x2 photo is excluded from the preparation guide.
            }
            if (!isset($guides[$key])) {
                $guides[$key] = ['key' => $key, 'label' => $requirement['label'], 'labels' => [], 'applies' => []] + self::instructions($key);
            }
            if (!in_array($requirement['label'], $guides[$key]['labels'], true)) {
                $guides[$key]['labels'][] = $requirement['label'];
            }
            // Where this document applies (null category = shared requirement) and whether it is Required or Optional there.
            $guides[$key]['applies'][] = ['category' => $requirement['category_name'] ?? null, 'required' => !empty($requirement['is_required'])];
        }
        return $guides;
    }

    public const REVIEWED_ON = '2026-10-12';

    public static function offices(): array
    {
        return self::content()['offices'];
    }

    public static function instructions(string $key): array
    {
        $content = self::content();
        $canonical = $content['aliases'][$key] ?? $key;
        return $content['guides'][$canonical] ?? $content['fallback'];
    }

    private static function content(): array
    {
        static $content = null;
        return $content ??= require __DIR__ . '/../includes/requirement-guides.php';
    }
}
