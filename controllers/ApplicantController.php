<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/../includes/QrService.php';
require_once __DIR__ . '/ApplicationController.php';
require_once __DIR__ . '/DocumentController.php';
require_once __DIR__ . '/AppointmentController.php';
require_once __DIR__ . '/RenewalController.php';

/**
 * The applicant dashboard (applicant/dashboard.php?section=...). Owns the
 * shared layout and the account-level sections; module sections are
 * delegated to their own controllers. Every section handler returns
 * ['view' => path under views/applicant/, 'data' => [...]] and may
 * redirect instead for a successful POST.
 */
class ApplicantController extends Controller
{
    private const SECTION_TITLES = [
        'dashboard'     => 'Dashboard',
        'eligibility'   => 'Eligibility Checker',
        'application'   => 'My Application',
        'renewal'       => 'ID Renewal',
        'myid'          => 'My Solo Parent ID',
        'appointment'   => 'Appointment',
        'news'          => 'News & Announcements',
        'notifications' => 'Notifications',
        'documents'     => 'Upload Documents',
        'chat'          => 'Chat Support',
        'profile'       => 'My Profile',
        'help'          => 'Help & Support',
        'settings'      => 'Settings',
    ];

    private array $user;
    private array $applications;

    public function handle(): void
    {
        $user = (new AuthService($this->pdo))->currentUser();
        if (!$user) {
            $this->redirectTo('portal.php?tab=login');
        }
        $this->user = $user;
        $this->applications = (new Application($this->pdo))->forUserDetailed((int) $user['id']);

        $section = (string) ($_GET['section'] ?? 'dashboard');
        if (!isset(self::SECTION_TITLES[$section])) {
            $section = 'dashboard';
        }
        $title = self::SECTION_TITLES[$section];

        switch ($section) {
            case 'application':
                $result = (new ApplicationController($this->pdo))->statusList($this->user, $this->applications);
                break;
            case 'documents':
                $result = (new DocumentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'appointment':
                $result = (new AppointmentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'renewal':
                $result = (new RenewalController($this->pdo))->handle($this->user, $this->applications);
                $title = $result['title'];
                break;
            case 'eligibility':
                $result = $this->sectionEligibility();
                break;
            case 'news':
                $result = $this->sectionNews();
                break;
            case 'myid':
                $result = $this->sectionMyid();
                break;
            case 'notifications':
                $result = $this->sectionNotifications();
                break;
            case 'chat':
                $result = $this->sectionChat();
                break;
            case 'profile':
                $result = $this->sectionProfile();
                break;
            case 'help':
            case 'settings':
                $result = ['view' => 'coming-soon.php', 'data' => []];
                break;
            default:
                $result = $this->sectionDashboard();
        }

        $this->renderLayout($section, $title, $result);
    }

    private function renderLayout(string $section, string $title, array $result): void
    {
        $announcements = announcements_list();
        $this->render('views/applicant/layout.php', array_merge($result['data'], [
            'user' => $this->user,
            'section' => $section,
            'sectionTitle' => $title,
            'pageTitle' => $title . ' — ' . APP_NAME,
            'contentView' => 'views/applicant/' . $result['view'],
            'unreadAnnouncementCount' => count_unread_announcements($announcements),
            'unreadNotificationCount' => (new Notification($this->pdo))->unreadCount((int) $this->user['id']),
        ]));
    }

    /** The released ID if there is one, otherwise the approved-but-not-yet-released one. */
    private function idApplication(): ?array
    {
        foreach (['ID Released', 'Approved'] as $status) {
            foreach ($this->applications as $app) {
                if ($app['status'] === $status) {
                    return $app;
                }
            }
        }
        return null;
    }

    private function idExpiryInfo(?array $idApplication): ?array
    {
        if (!$idApplication || !$idApplication['id_expires_at']) {
            return null;
        }
        return [
            'date' => $idApplication['id_expires_at'],
            'expired' => strtotime($idApplication['id_expires_at']) < strtotime('today'),
        ];
    }

    // ---- Sections ----

    private function sectionDashboard(): array
    {
        $idApplication = $this->idApplication();
        return ['view' => 'dashboard.php', 'data' => [
            'latestApplication' => $this->applications[0] ?? null,
            'idApplication' => $idApplication,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
        ]];
    }

    private function sectionEligibility(): array
    {
        $service = new EligibilityService();
        $answers = ['resident' => '', 'circ' => '', 'child' => ''];
        $error = '';
        $result = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $answers = $service->normalizeAnswers($_POST);
            if ($service->missingAnswers($answers)) {
                $error = 'Please answer all three questions to see your result.';
            } else {
                $result = $service->evaluate($answers);
            }
        }

        return ['view' => 'eligibility.php', 'data' => [
            'answers' => $answers,
            'error' => $error,
            'result' => $result,
            'circumstances' => EligibilityService::circumstances(),
            'noneOption' => EligibilityService::NONE_OPTION,
            'applyUrl' => base_url('portal.php?tab=apply'),
            'formAction' => base_url('applicant/dashboard.php?section=eligibility'),
            'restartUrl' => base_url('applicant/dashboard.php?section=eligibility'),
        ]];
    }

    private function sectionNews(): array
    {
        $activeAnnouncement = null;
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            foreach (announcements_list() as $a) {
                if ($a['id'] === $id) {
                    $activeAnnouncement = $a;
                    mark_announcement_read($a['id']);
                    break;
                }
            }
        }
        return ['view' => 'news/index.php', 'data' => [
            'announcements' => announcements_list(),
            'activeAnnouncement' => $activeAnnouncement,
        ]];
    }

    private function sectionMyid(): array
    {
        $idApplication = $this->idApplication();
        $idStatusUrl = null;
        $idQrUri = null;
        if ($idApplication) {
            $idStatusUrl = base_url('portal.php?tab=status&ref=' . urlencode($idApplication['reference_no']));
            $idQrUri = qr_code_data_uri($idStatusUrl);
        }
        return ['view' => 'qr/my-id.php', 'data' => [
            'idApplication' => $idApplication,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'idStatusUrl' => $idStatusUrl,
            'idQrUri' => $idQrUri,
            'latestApplication' => $this->applications[0] ?? null,
        ]];
    }

    private function sectionNotifications(): array
    {
        $notifications = new Notification($this->pdo);
        $userId = (int) $this->user['id'];
        $myNotifications = $notifications->forUser($userId);
        if ($notifications->unreadCount($userId) > 0) {
            $notifications->markAllRead($userId);
            foreach ($myNotifications as &$n) {
                $n['is_read'] = 1;
            }
            unset($n);
        }
        return ['view' => 'notifications/index.php', 'data' => ['myNotifications' => $myNotifications]];
    }

    private function sectionChat(): array
    {
        return ['view' => 'chat/index.php', 'data' => ['faqs' => get_faqs($this->pdo)]];
    }

    private function sectionProfile(): array
    {
        $profileError = '';
        $profileSuccess = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'update_info') {
                [$profileError, $profileSuccess] = $this->updateInfo();
            } elseif ($action === 'change_password') {
                [$profileError, $profileSuccess] = $this->changePassword();
            } elseif ($action === 'upload_photo') {
                [$profileError, $profileSuccess] = $this->uploadPhoto();
            }
        }

        return ['view' => 'profile.php', 'data' => compact('profileError', 'profileSuccess')];
    }

    /** @return array{0: string, 1: string} [error, success] */
    private function updateInfo(): array
    {
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $mobile = trim((string) ($_POST['mobile'] ?? ''));
        if ($fullName === '') {
            return ['Enter your full name.', ''];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['Enter a valid email address.', ''];
        }
        if (!is_valid_ph_mobile($mobile)) {
            return ['Enter a valid Philippine mobile number (e.g. 09171234567).', ''];
        }
        (new Applicant($this->pdo))->updateProfile((int) $this->user['id'], $fullName, $email, $mobile);
        audit_log($this->pdo, (int) $this->user['id'], $fullName, $this->user['role'], 'Profile Updated');
        $this->refreshUser();
        return ['', 'Your profile has been updated.'];
    }

    /** @return array{0: string, 1: string} [error, success] */
    private function changePassword(): array
    {
        $applicant = new Applicant($this->pdo);
        $userId = (int) $this->user['id'];
        $newPassword = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $applicant->passwordHash($userId))) {
            return ['Current password is incorrect.', ''];
        }
        if (strlen($newPassword) < 6) {
            return ['New password must be at least 6 characters.', ''];
        }
        $applicant->updatePasswordHash($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        audit_log($this->pdo, $userId, $this->user['full_name'], $this->user['role'], 'Password Changed');
        return ['', 'Your password has been updated.'];
    }

    /** @return array{0: string, 1: string} [error, success] */
    private function uploadPhoto(): array
    {
        $result = (new DocumentValidationService($this->pdo))->storeProfilePhoto($_FILES['photo'] ?? [], (int) $this->user['id']);
        if (!$result['ok']) {
            return [$result['error'], ''];
        }
        $oldPhoto = $this->user['profile_photo'];
        (new Applicant($this->pdo))->setProfilePhoto((int) $this->user['id'], $result['relativePath']);
        if ($oldPhoto && is_file(AVATAR_STORAGE_PATH . '/' . $oldPhoto)) {
            @unlink(AVATAR_STORAGE_PATH . '/' . $oldPhoto);
        }
        audit_log($this->pdo, (int) $this->user['id'], $this->user['full_name'], $this->user['role'], 'Profile Photo Updated');
        $this->refreshUser();
        return ['', 'Your profile photo has been updated.'];
    }

    private function refreshUser(): void
    {
        $this->user = (new AuthService($this->pdo))->currentUser() ?? $this->user;
    }
}
