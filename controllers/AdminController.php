<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/UserManagementService.php';
require_once __DIR__ . '/../services/SettingsService.php';
require_once __DIR__ . '/../services/BackupService.php';
require_once __DIR__ . '/../services/ContentService.php';
require_once __DIR__ . '/../services/ReportService.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Appointment.php';
require_once __DIR__ . '/../models/AuditLog.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Faq.php';
require_once __DIR__ . '/../models/Report.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/../models/User.php';

/**
 * System Administrator pages under admin/. Admin-only: no login goes to
 * the staff login, and MSWD social workers are bounced to their own
 * dashboard. Application decisions stay with MSWD staff (staff/), so the
 * application pages here are read-only monitoring.
 */
class AdminController extends Controller
{
    private array $admin;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $staff = (new AuthService($pdo))->currentStaff();
        if (!$staff) {
            $this->redirectTo('admin/login.php');
        }
        if ($staff['role'] !== 'admin') {
            $this->redirectTo('staff/dashboard.php');
        }
        $this->admin = $staff;
    }

    public function dashboard(): void
    {
        $this->page('Administrator Dashboard', 'dashboard.php', [
            'stats' => (new ReportService($this->pdo))->dashboardStats(),
            'recentActivity' => (new AuditLog($this->pdo))->recent(8),
        ]);
    }

    // ---- Application monitoring (read-only) ----

    public function applications(): void
    {
        $statusFilter = (string) ($_GET['status'] ?? 'ALL');
        $typeFilter = (string) ($_GET['type'] ?? 'ALL');
        $q = trim((string) ($_GET['q'] ?? ''));
        $applications = new Application($this->pdo);
        $this->page('Application Monitoring', 'applications/index.php', [
            'statusFilter' => $statusFilter,
            'typeFilter' => $typeFilter,
            'q' => $q,
            'validStatuses' => Application::STATUSES,
            'apps' => $applications->monitoringList($statusFilter, $typeFilter, $q),
            'stageCounts' => $applications->countsByStatus(),
        ]);
    }

    public function application(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($id);
        if (!$app) {
            http_response_code(404);
            die('Application not found.');
        }
        $this->page('Application ' . $app['reference_no'] . ' (Monitoring)', 'applications/show.php', [
            'app' => $app,
            'interview' => (new Appointment($this->pdo))->interviewFor($id),
            'children' => $applications->children($id),
            'docs' => (new Document($this->pdo))->forApplication($id),
            'history' => $applications->history($id),
        ]);
    }

    // ---- Users ----

    public function users(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $service = new UserManagementService($this->pdo);
            $targetId = (int) ($_POST['user_id'] ?? 0);
            switch ((string) ($_POST['action'] ?? '')) {
                case 'create':
                    [$error, $success] = $service->create(
                        $this->admin,
                        trim((string) ($_POST['fullName'] ?? '')),
                        trim((string) ($_POST['email'] ?? '')),
                        trim((string) ($_POST['username'] ?? '')),
                        (string) ($_POST['password'] ?? ''),
                        (string) ($_POST['role'] ?? '')
                    );
                    break;
                case 'toggle_active':
                    [$error, $success] = $service->toggleActive($this->admin, $targetId);
                    break;
                case 'reset_password':
                    [$error, $success] = $service->resetPassword($this->admin, $targetId);
                    break;
                case 'change_role':
                    [$error, $success] = $service->changeRole($this->admin, $targetId, (string) ($_POST['role'] ?? ''));
                    break;
            }
        }

        $roleFilter = (string) ($_GET['role'] ?? 'ALL');
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->page('Manage Users', 'users/index.php', [
            'error' => $error,
            'success' => $success,
            'roleFilter' => $roleFilter,
            'q' => $q,
            'roleLabels' => User::ROLE_LABELS,
            'users' => (new User($this->pdo))->listForAdmin($roleFilter, $q),
        ]);
    }

    public function userEdit(): void
    {
        $target = (new User($this->pdo))->findById((int) ($_GET['id'] ?? 0));
        if (!$target) {
            http_response_code(404);
            die('Account not found.');
        }

        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $mobile = trim((string) ($_POST['mobile'] ?? ''));
            [$error, $success] = (new UserManagementService($this->pdo))->updateAccount($this->admin, $target, $fullName, $email, $mobile);
            if ($success) {
                $target = array_merge($target, ['full_name' => $fullName, 'email' => $email, 'mobile' => $mobile]);
            }
        }

        $this->page('Edit ' . $target['username'], 'users/edit.php', [
            'error' => $error,
            'success' => $success,
            'target' => $target,
            'roleLabels' => User::ROLE_LABELS,
        ]);
    }

    // ---- Audit, analytics, reports ----

    public function auditLog(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $actionFilter = trim((string) ($_GET['action'] ?? ''));
        $dateFrom = trim((string) ($_GET['from'] ?? ''));
        $dateTo = trim((string) ($_GET['to'] ?? ''));
        $auditLog = new AuditLog($this->pdo);
        $this->page('Audit Logs', 'audit/index.php', [
            'q' => $q,
            'actionFilter' => $actionFilter,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'logs' => $auditLog->search($q, $actionFilter, $dateFrom, $dateTo),
            'actions' => $auditLog->distinctActions(),
        ]);
    }

    public function analytics(): void
    {
        $this->page('Analytics & Reports', 'analytics/index.php', (new ReportService($this->pdo))->analytics());
    }

    /** Filterable reports; ?export=csv downloads the same rows. */
    public function reports(): void
    {
        $type = (string) ($_GET['type'] ?? 'application');
        if (!isset(Report::TYPES[$type])) {
            $type = 'application';
        }
        $dateFrom = trim((string) ($_GET['from'] ?? ''));
        $dateTo = trim((string) ($_GET['to'] ?? ''));
        $q = trim((string) ($_GET['q'] ?? ''));
        $report = new Report($this->pdo);
        $result = $report->run($type, $dateFrom, $dateTo, $q);

        if (($_GET['export'] ?? '') === 'csv') {
            audit_log($this->pdo, (int) $this->admin['id'], $this->admin['full_name'], $this->admin['role'], 'Report Export', 'report', null, Report::TYPES[$type]);
            $keys = array_keys($result['columns']);
            (new ReportService($this->pdo))->streamCsv(
                $type . '-report-' . date('Y-m-d') . '.csv',
                array_values($result['columns']),
                array_map(fn($row) => array_map(fn($k) => $row[$k] ?? '', $keys), $result['rows'])
            );
            return;
        }

        $this->page(Report::TYPES[$type], 'reports/index.php', $result + [
            'type' => $type,
            'typeLabels' => Report::TYPES,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'q' => $q,
            'rowLimit' => $report->rowLimit(),
        ]);
    }

    public function exportCsv(): void
    {
        $type = (string) ($_GET['export'] ?? '');
        $service = new ReportService($this->pdo);
        $export = $service->fullExport($type);
        if (!$export) {
            http_response_code(400);
            die('Unknown export type.');
        }
        audit_log($this->pdo, (int) $this->admin['id'], $this->admin['full_name'], $this->admin['role'], 'CSV Export', 'report', null, ucfirst($type) . ' export');
        $service->streamCsv($type . '-' . date('Y-m-d') . '.csv', $export[0], $export[1]);
    }

    // ---- System ----

    public function monitoring(): void
    {
        $backups = new BackupService($this->pdo);
        [$error, $success] = ['', ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'create_backup') {
                try {
                    $filename = $backups->create();
                    $this->auditSystem('Database Backup Created', $filename);
                    $success = 'Backup created: ' . $filename;
                } catch (Throwable $e) {
                    $error = 'Backup failed: ' . $e->getMessage();
                }
            } elseif ($action === 'delete_backup') {
                $name = basename((string) ($_POST['name'] ?? ''));
                if ($backups->delete($name)) {
                    $this->auditSystem('Database Backup Deleted', $name);
                    $success = 'Backup deleted.';
                }
            }
        }

        $recentActivity = (new AuditLog($this->pdo))->recent(10);
        $this->page('Database & System Monitoring', 'monitoring/index.php', [
            'error' => $error,
            'success' => $success,
            'tableCounts' => (new Report($this->pdo))->tableRowCounts(),
            'uploadsSize' => BackupService::folderSize(STORAGE_PATH),
            'backupsSize' => BackupService::folderSize($backups->directory()),
            'backups' => $backups->all(),
            'recentActivity' => $recentActivity,
            'lastActivityAt' => $recentActivity[0]['created_at'] ?? null,
        ]);
    }

    public function downloadBackup(): void
    {
        $backups = new BackupService($this->pdo);
        $name = basename((string) ($_GET['name'] ?? ''));
        if (!$backups->isValidName($name)) {
            http_response_code(400);
            die('Invalid backup filename.');
        }
        $path = $backups->pathFor($name);
        if (!$path) {
            http_response_code(404);
            die('Backup not found.');
        }
        $this->auditSystem('Database Backup Downloaded', $name);
        header('Content-Type: application/sql');
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    public function settings(): void
    {
        $settings = new SettingsService($this->pdo);
        [$error, $success] = ['', ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? 'save_settings');
            if ($action === 'send_reminders_now') {
                $result = (new RenewalService($this->pdo))->sendDueReminders();
                $this->auditSystem('Renewal Reminders Sent (Manual)', implode(', ', $result['sent']) ?: 'none due');
                $success = 'Checked ' . $result['checked'] . ' expiring ID(s), sent ' . count($result['sent']) . ' reminder(s).';
            } elseif ($action === 'send_appointment_reminders_now') {
                $result = (new AppointmentService($this->pdo))->sendDueReminders();
                $this->auditSystem('Appointment Reminders Sent (Manual)', implode(', ', $result['sent']) ?: 'none due');
                $success = 'Checked ' . $result['checked'] . ' upcoming appointment(s), sent ' . count($result['sent']) . ' reminder(s).';
            } else {
                $error = $settings->save($this->admin, $_POST) ?? '';
                $success = $error === '' ? 'Settings saved.' : '';
            }
        }

        $this->page('System Settings', 'settings/index.php', [
            'error' => $error,
            'success' => $success,
            'current' => $settings->current(),
            'recentReminders' => $settings->recentRenewalReminders(),
            'recentApptReminders' => $settings->recentAppointmentReminders(),
        ]);
    }

    public function requirements(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $content = new ContentService($this->pdo);
            $label = trim((string) ($_POST['label'] ?? ''));
            $isRequired = !empty($_POST['is_required']);
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $reqId = (int) ($_POST['req_id'] ?? 0);
            switch ((string) ($_POST['action'] ?? '')) {
                case 'create':
                    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int) $_POST['category_id'] : null;
                    [$error, $success] = $content->createRequirement($this->admin, $categoryId, trim((string) ($_POST['doc_key'] ?? '')), $label, $isRequired, $sortOrder);
                    break;
                case 'update':
                    [$error, $success] = $content->updateRequirement($this->admin, $reqId, $label, $isRequired, $sortOrder);
                    break;
                case 'delete':
                    [$error, $success] = $content->deleteRequirement($this->admin, $reqId);
                    break;
            }
        }

        $categories = get_categories($this->pdo);
        $this->page('Application Requirements', 'settings/requirements.php', [
            'error' => $error,
            'success' => $success,
            'categories' => $categories,
            'categoriesById' => array_column($categories, 'name', 'id'),
            'requirements' => (new Document($this->pdo))->allRequirements(),
        ]);
    }

    public function faq(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $content = new ContentService($this->pdo);
            $faqId = (int) ($_POST['faq_id'] ?? 0);
            $question = trim((string) ($_POST['question'] ?? ''));
            $answer = trim((string) ($_POST['answer'] ?? ''));
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            switch ((string) ($_POST['action'] ?? '')) {
                case 'create':
                    [$error, $success] = $content->saveFaq($this->admin, null, $question, $answer, $sortOrder);
                    break;
                case 'update':
                    [$error, $success] = $content->saveFaq($this->admin, $faqId, $question, $answer, $sortOrder);
                    break;
                case 'toggle_active':
                    [$error, $success] = $content->toggleFaq($this->admin, $faqId);
                    break;
                case 'delete':
                    [$error, $success] = $content->deleteFaq($this->admin, $faqId);
                    break;
            }
        }

        $this->page('Manage FAQ (Chat Support)', 'settings/faq.php', [
            'error' => $error,
            'success' => $success,
            'faqs' => (new Faq($this->pdo))->all(),
        ]);
    }

    public function sms(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if (($_POST['action'] ?? '') === 'update_template') {
                [$error, $success] = (new ContentService($this->pdo))->updateSmsTemplate(
                    $this->admin,
                    (string) ($_POST['template_key'] ?? ''),
                    trim((string) ($_POST['body'] ?? ''))
                );
            }
        }

        $statusFilter = (string) ($_GET['status'] ?? 'ALL');
        $q = trim((string) ($_GET['q'] ?? ''));
        $sms = new SmsLog($this->pdo);
        $this->page('SMS Notification Management', 'sms/index.php', [
            'error' => $error,
            'success' => $success,
            'statusFilter' => $statusFilter,
            'q' => $q,
            'templates' => $sms->templates(),
            'logs' => $sms->search($statusFilter, $q),
            'totals' => [
                'sent' => $sms->countByStatus('sent'),
                'failed' => $sms->countByStatus('failed'),
                'skipped' => $sms->countByStatus('skipped-no-api-key'),
            ],
        ]);
    }

    private function auditSystem(string $action, string $details): void
    {
        audit_log($this->pdo, (int) $this->admin['id'], $this->admin['full_name'], $this->admin['role'], $action, 'system', null, $details);
    }

    /** Renders views/admin/$view inside the standard page layout, with $staff set for the header nav. */
    private function page(string $title, string $view, array $data): void
    {
        $this->render('views/layouts/page.php', array_merge($data, [
            'pageTitle' => $title,
            'contentView' => 'views/admin/' . $view,
            'staff' => $this->admin,
        ]));
    }
}
