<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/auth.php';

$staff = require_admin_login($pdo);

$export = (string) ($_GET['export'] ?? '');
if (!in_array($export, ['applications', 'users'], true)) {
    http_response_code(400);
    die('Unknown export type.');
}

audit_log($pdo, (int) $staff['id'], $staff['full_name'], $staff['role'], 'CSV Export', 'report', null, ucfirst($export) . ' export');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $export . '-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders special characters correctly

if ($export === 'applications') {
    fputcsv($out, ['Reference No', 'Type', 'Full Name', 'Category', 'Status', 'Mobile', 'Email', 'Reviewed By', 'Submitted At']);
    $stmt = $pdo->query(
        "SELECT a.reference_no, a.type, a.full_name, c.name AS category_name, a.status, a.mobile, a.email, r.full_name AS reviewer_name, a.submitted_at
         FROM applications a
         JOIN categories c ON c.id = a.category_id
         LEFT JOIN users r ON r.id = a.reviewed_by
         ORDER BY a.submitted_at DESC"
    );
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['reference_no'],
            $row['type'] === 'renewal' ? 'Renewal' : 'New Application',
            $row['full_name'],
            $row['category_name'],
            $row['status'],
            $row['mobile'],
            $row['email'],
            $row['reviewer_name'] ?? '',
            $row['submitted_at'],
        ]);
    }
} else {
    fputcsv($out, ['Username', 'Full Name', 'Email', 'Mobile', 'Role', 'Status', 'Created At']);
    $stmt = $pdo->query('SELECT username, full_name, email, mobile, role, is_active, created_at FROM users ORDER BY created_at DESC');
    foreach ($stmt as $row) {
        fputcsv($out, [
            $row['username'],
            $row['full_name'],
            $row['email'],
            $row['mobile'] ?? '',
            $row['role'],
            $row['is_active'] ? 'Active' : 'Deactivated',
            $row['created_at'],
        ]);
    }
}

fclose($out);
