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

/** URL for a file under public/ (CSS/JS), cache-busted by its mtime. */
function public_asset(string $path): string
{
    $path = ltrim($path, '/');
    return base_url('public/' . $path) . '?v=' . (@filemtime(APP_ROOT . '/public/' . $path) ?: 1);
}

/**
 * Clamps a requested page into range. Returns ['total', 'page', 'totalPages',
 * 'perPage', 'offset'] -- the shape views/partials/pagination.php expects.
 */
function paginate(int $total, int $page, int $perPage): array
{
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $totalPages);
    return ['total' => $total, 'page' => $page, 'totalPages' => $totalPages, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/** Current query string with $overrides merged in -- for pagination/filter links that preserve the rest of the filters. */
function query_with(array $overrides): string
{
    return '?' . http_build_query(array_merge($_GET, $overrides));
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

function is_ymd(string $date): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
}

function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
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

/**
 * Maps an application's DB status onto the 6-step applicant-facing progress
 * tracker (Eligibility Check -> Application Submitted -> Documents Under
 * Review -> Interview/Assessment -> Approved -> QR/Slip Generated). "Needs
 * Correction" isn't a fixed slot in that sequence -- it's a detour back to
 * the review step -- so it's returned as a separate flag the caller renders
 * as a banner instead of consuming a step.
 */
function application_stage_info(string $status): array
{
    $labels = [
        'Eligibility Check',
        'Application Submitted',
        'Documents Under Review',
        'Interview / Assessment',
        'Approved',
        'QR / Slip Generated',
    ];
    $doneThroughByStatus = [
        'Pending Review'      => 1,
        'Under Verification'  => 1,
        'For Revision'        => 1,
        'Interview Scheduled' => 2,
        'Approved'            => 3,
        'ID Released'         => 5,
        'Rejected'            => 1,
    ];
    $doneThrough = $doneThroughByStatus[$status] ?? 1;

    $steps = [];
    foreach ($labels as $i => $label) {
        if ($i <= $doneThrough) {
            $state = 'done';
        } elseif ($i === $doneThrough + 1) {
            $state = 'active';
        } else {
            $state = 'pending';
        }
        $steps[] = ['label' => $label, 'state' => $state];
    }

    return [
        'steps' => $steps,
        'currentLabel' => $labels[min($doneThrough + 1, count($labels) - 1)],
        'needsCorrection' => $status === 'For Revision',
        'rejected' => $status === 'Rejected',
    ];
}

/** Active FAQ entries for the applicant-facing Chat Support page, in display order. */
function get_faqs(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM faqs WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
}

/** Admin-configurable runtime setting, falling back to $default if unset. */
function get_setting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false && $value !== '' ? (string) $value : $default;
}

function set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    )->execute([$key, $value]);
}

function doc_verification_style(string $status): array
{
    $map = [
        'Pending'          => ['bg' => '#fdf1d6', 'color' => '#8a6d1a'],
        'Verified'         => ['bg' => '#dcf3e2', 'color' => '#1c7a3f'],
        'Needs Correction' => ['bg' => '#fde8e8', 'color' => '#a51c1c'],
    ];
    return $map[$status] ?? $map['Pending'];
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

/**
 * Records an in-app notification for one applicant. Independent of
 * SmsService/sms_logs -- a notification can exist with no SMS behind it
 * (e.g. registration, which has no application yet for sms_logs to key
 * off), and every caller that also sends an SMS calls both explicitly.
 */
function create_notification(PDO $pdo, int $userId, string $type, string $message, ?int $applicationId = null): void
{
    $pdo->prepare(
        'INSERT INTO notifications (user_id, type, message, related_application_id) VALUES (?, ?, ?, ?)'
    )->execute([$userId, $type, $message, $applicationId]);
}

function count_unread_notifications(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function mark_all_notifications_read(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0')->execute([$userId]);
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
