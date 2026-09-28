<?php

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
    // Discard any HTML already buffered for this request (e.g. shared
    // header/nav markup rendered before a tab's controller decided to
    // redirect) so the 3xx response body is clean.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Location: ' . $path);
    exit;
}

function base_url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return base_url('assets/' . ltrim($path, '/'));
}

function today_iso(): string
{
    return date('Y-m-d');
}

function format_date_long(?string $ymd): string
{
    if (!$ymd) {
        return '—';
    }
    $ts = strtotime($ymd);
    return $ts ? date('F j, Y', $ts) : '—';
}

function normalize_mobile(string $mobile): string
{
    return preg_replace('/[\s\-]/', '', $mobile);
}

function is_valid_ph_mobile(string $mobile): bool
{
    return (bool) preg_match('/^(09\d{9}|\+639\d{9})$/', normalize_mobile($mobile));
}

function mask_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName));
    $out = [];
    foreach ($parts as $i => $p) {
        if ($p === '') {
            continue;
        }
        $out[] = $i === 0 ? $p : mb_substr($p, 0, 1) . '.';
    }
    return implode(' ', $out);
}

function status_style(string $status): array
{
    $map = [
        'Pending Review'     => ['bg' => '#fdf1d6', 'color' => '#8a6d1a'],
        'Under Verification' => ['bg' => '#dce8f5', 'color' => '#1b3a6b'],
        'Approved'           => ['bg' => '#dcf3e2', 'color' => '#1c7a3f'],
        'ID Released'        => ['bg' => '#1b3a6b', 'color' => '#ffffff'],
        'Returned'           => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
    ];
    return $map[$status] ?? $map['Pending Review'];
}

function generate_reference_no(PDO $pdo): string
{
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM applications WHERE reference_no LIKE ?");
    $stmt->execute(["VIRAC-SP-$year-%"]);
    $count = (int) $stmt->fetchColumn();
    do {
        $count++;
        $ref = sprintf('VIRAC-SP-%s-%04d', $year, $count);
        $check = $pdo->prepare('SELECT id FROM applications WHERE reference_no = ?');
        $check->execute([$ref]);
    } while ($check->fetch());
    return $ref;
}

/** Fetch all categories ordered for display. */
function get_categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll();
}

/** Standard (category_id IS NULL) + category-specific document requirements, in order. */
function get_document_requirements(PDO $pdo, ?int $categoryId): array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM document_requirements
         WHERE category_id IS NULL OR category_id = ?
         ORDER BY (category_id IS NULL) DESC, sort_order, id'
    );
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}

function get_category_only_requirements(PDO $pdo, ?int $categoryId): array
{
    if (!$categoryId) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM document_requirements WHERE category_id = ? ORDER BY sort_order, id');
    $stmt->execute([$categoryId]);
    return $stmt->fetchAll();
}
