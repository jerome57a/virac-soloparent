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
        'Pending Review'      => ['bg' => '#fdf1d6', 'color' => '#8a6d1a'],
        'Under Verification'  => ['bg' => '#dce8f5', 'color' => '#1b3a6b'],
        'For Revision'        => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
        'Interview Scheduled' => ['bg' => '#f0eafe', 'color' => '#5b3a9e'],
        'Approved'            => ['bg' => '#dcf3e2', 'color' => '#1c7a3f'],
        'ID Released'         => ['bg' => '#1b3a6b', 'color' => '#ffffff'],
        'Rejected'            => ['bg' => '#fde8e8', 'color' => '#7f1616'],
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

/** Session-tracked read state for the News & Announcements tab (works for logged-in and anonymous visitors alike). */
function is_announcement_unread(int $id): bool
{
    return empty($_SESSION['read_announcements'][$id]);
}

function mark_announcement_read(int $id): void
{
    $_SESSION['read_announcements'][$id] = true;
}

function count_unread_announcements(array $announcements): int
{
    $count = 0;
    foreach ($announcements as $a) {
        if (is_announcement_unread($a['id'])) {
            $count++;
        }
    }
    return $count;
}

/** Session-tracked read state for the applicant's Notifications list (sms_logs rows). */
function is_notification_unread(int $id): bool
{
    return empty($_SESSION['read_notifications'][$id]);
}

function mark_notification_read(int $id): void
{
    $_SESSION['read_notifications'][$id] = true;
}

function count_unread_notifications(array $notifications): int
{
    $count = 0;
    foreach ($notifications as $n) {
        if (is_notification_unread($n['id'])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Records a security/workflow-relevant action. actor_name/actor_role are
 * snapshotted rather than resolved via a live join, so the log stays
 * accurate even if that account's name/role changes or the account is
 * later removed (user_id is nullable and ON DELETE SET NULL for that reason).
 */
function audit_log(
    PDO $pdo,
    ?int $userId,
    string $actorName,
    string $actorRole,
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $details = null
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, actor_name, actor_role, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $actorName, $actorRole, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}
