<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/DocumentValidationService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../services/InterviewBookingService.php';
require_once __DIR__ . '/../services/ApplicantProfileService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Notification.php';
require_once __DIR__ . '/ApplicationController.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';
require_once __DIR__ . '/DocumentController.php';
require_once __DIR__ . '/AppointmentController.php';
require_once __DIR__ . '/RenewalController.php';
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/../services/RequirementGuideService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';

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
        'apply'         => 'Apply for Solo Parent ID',
        'application'   => 'My Application',
        'renewal'       => 'ID Renewal',
        'myid'          => 'My Solo Parent ID',
        'appointment'   => 'Appointment',
        'notifications' => 'Notifications',
        'documents'     => 'Supporting Documents',
        'requirements'  => 'Requirements Guide',
        'profile'       => 'My Profile',
        'help'          => 'Help & Support',
    ];

    private array $user;
    private array $applications;
    /** @var ?array cache of documentsToFix() for this request */
    private ?array $fixList = null;

    public function handle(): void
    {
        $auth = new AuthService($this->pdo);
        if ($staff = $auth->currentStaff()) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        $user = $auth->currentUser();
        if (!$user) {
            // Keep a Requirements Guide destination (and its entry) through sign-in; every other section goes to the portal sign-in as before.
            $next = null;
            if (($_GET['section'] ?? '') === 'requirements') {
                $next = 'requirements' . (is_string($_GET['doc'] ?? null) && preg_match('/^[A-Za-z0-9_]{1,50}\z/', $_GET['doc']) ? ':' . $_GET['doc'] : '');
            }
            $this->redirectTo($next !== null ? 'login.php?next=' . rawurlencode($next) : 'portal.php?tab=login');
        }
        $this->authorize($user, 'applicant.portal');
        $this->user = $user;
        $this->applications = (new Application($this->pdo))->forUserDetailed((int) $user['id']);

        $section = (string) ($_GET['section'] ?? 'dashboard');
        // Retain a safe destination for old bookmarks and notification links.
        if ($section === 'chat') {
            $this->redirectTo('applicant/dashboard.php?section=help');
        }
        // Account settings (details, photo, password) all live on My Profile; old "Settings" links go there.
        if ($section === 'settings') {
            $this->redirectTo('applicant/dashboard.php?section=profile');
        }
        if (!isset(self::SECTION_TITLES[$section])) {
            $section = 'dashboard';
        }
        // The Eligibility Checker is a public, pre-application step (eligibility.php); old dashboard links land on the dashboard.
        if ($section === 'eligibility') {
            $this->redirectTo('applicant/dashboard.php');
        }
        if (!isset(self::SECTION_TITLES[$section])) {
            $section = 'dashboard';
        }
        $this->requireOwnPassword($user, 'applicant/dashboard.php?section=profile#password', $section === 'profile');
        $this->requireUsername($user, 'applicant/dashboard.php?section=profile#username', $section === 'profile');
        $title = self::SECTION_TITLES[$section];

        switch ($section) {
            case 'apply':
                $result = (new ApplicationController($this->pdo))->applySection($this->user);
                break;
            case 'application':
                $result = (new ApplicationController($this->pdo))->statusList($this->user, $this->applications);
                break;
            case 'documents':
                $result = (new DocumentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'requirements':
                $guides = RequirementGuideService::catalog($this->pdo);
                $requested = is_string($_GET['doc'] ?? null) ? $_GET['doc'] : '';
                $result = ['view' => 'requirements/index.php', 'data' => [
                    'requirementGuides' => $guides,
                    'selectedGuideKey' => isset($guides[$requested]) ? $requested : null,
                    'guideNotFound' => $requested !== '' && !isset($guides[$requested]),
                ]];
                break;
            case 'appointment':
                $result = (new AppointmentController($this->pdo))->handle($this->user, $this->applications);
                break;
            case 'renewal':
                $result = (new RenewalController($this->pdo))->handle($this->user, $this->applications);
                $title = $result['title'];
                break;
            case 'myid':
                $result = $this->sectionMyid();
                break;
            case 'notifications':
                $result = $this->sectionNotifications();
                break;
            case 'profile':
                $result = $this->sectionProfile();
                break;
            case 'help':
                $result = ['view' => 'help.php', 'data' => [
                    'office' => ['name' => setting('office_name'), 'address' => setting('office_address'), 'hours' => setting('office_hours'),
                                 'email' => setting('office_email'), 'phone' => setting('office_phone')],
                    'hasApplication' => (bool) $this->applications,
                    'faqs' => get_faqs($this->pdo),
                ]];
                break;
            default:
                $result = $this->sectionDashboard();
        }

        $this->renderLayout($section, $title, $result);
    }

    private function renderLayout(string $section, string $title, array $result): void
    {
        $this->render('views/applicant/layout.php', array_merge($result['data'], [
            'user' => $this->user,
            'section' => $section,
            'sectionTitle' => $title,
            'pageTitle' => $title . ' — ' . setting('system_name'),
            'contentView' => 'views/applicant/' . $result['view'],
            'unreadNotificationCount' => (new Notification($this->pdo))->unreadCount((int) $this->user['id']),
            'documentsToFixCount' => count($this->documentsToFix()),
        ]));
    }

    /**
     * The documents MSWDO asked this applicant to replace (needs correction, invalid or legacy-rejected), across the applications
     * whose documents can still be changed: [['application_id', 'reference_no', 'doc_key', 'label', 'remarks']]. Shown on the dashboard
     * and as a count beside Documents in the menu, so a correction is seen right after sign-in.
     */
    private function documentsToFix(): array
    {
        if ($this->fixList !== null) {
            return $this->fixList;
        }
        $documents = new Document($this->pdo);
        $list = [];
        foreach ($this->applications as $app) {
            if (!in_array($app['status'], Application::CHECKLIST_EDITABLE_STATUSES, true)) {
                continue;
            }
            $uploads = array_column($documents->uploadedForApplication((int) $app['id']), null, 'doc_key');
            $categoryId = $app['category_id'] !== null ? (int) $app['category_id'] : null;
            foreach ($documents->checklistForReview((int) $app['id'], $categoryId, $app['type'] === 'renewal') as $item) {
                $upload = $uploads[$item['doc_key']] ?? null;
                if ($upload && in_array($upload['verification_status'], ['NEEDS_CORRECTION', 'INVALID', 'REJECTED'], true)) {
                    $list[] = ['application_id' => (int) $app['id'], 'reference_no' => (string) $app['reference_no'], 'doc_key' => (string) $item['doc_key'],
                        'label' => (string) $item['label'], 'remarks' => trim((string) ($upload['staff_remarks'] ?? ''))];
                }
            }
        }
        return $this->fixList = $list;
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

    /** Only the signed-in applicant's released or release-scheduled IDs can be viewed. */
    private function viewableIdApplication(): ?array
    {
        $requestedRef = is_string($_GET['ref'] ?? null) ? trim($_GET['ref']) : '';
        $releases = new IdReleaseService($this->pdo);
        foreach ($this->applications as $app) {
            if ($requestedRef !== '' && $app['reference_no'] !== $requestedRef) {
                continue;
            }
            if ($app['status'] === 'ID Released'
                || ($app['status'] === 'Approved' && $releases->scheduleFor((int) $app['id']))) {
                return $app;
            }
        }
        return null;
    }

    // ---- Sections ----

    private function sectionDashboard(): array
    {
        $idApplication = $this->idApplication();
        $renewal = $this->renewalInfo($idApplication);
        $upcoming = $this->nextAppointment();
        $bookable = (new AppointmentService($this->pdo))->findBookableApplication($this->applications);
        // Interview date booking: true only when the feature is enabled and the applicant can choose a date now (null/false otherwise).
        $bookingOptions = $bookable ? (new InterviewBookingService($this->pdo))->options($this->user, $bookable) : null;
        $bookingOpen = $bookingOptions !== null && $bookingOptions['state'] === 'open';
        $draft = (new ApplicationService($this->pdo))->draftSummary($this->user);

        return ['view' => 'dashboard.php', 'data' => [
            'isFirstLogin' => (bool) ($_SESSION['applicant_first_login'] ?? false),
            'latestApplication' => $this->applications[0] ?? null,
            'latestTracker' => isset($this->applications[0]) ? (new ApplicationTrackerService($this->pdo))->track($this->applications[0]) : null,
            'idApplication' => $idApplication,
            'viewableIdApplication' => $this->viewableIdApplication(),
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'renewal' => $renewal,
            'upcomingAppointment' => $upcoming,
            'upcomingRequirements' => $upcoming ? (new Document($this->pdo))->checklistForReview((int) $upcoming['id'], $upcoming['category_id'] !== null ? (int) $upcoming['category_id'] : null, $upcoming['type'] === 'renewal') : [],
            'canBookAppointment' => AppointmentService::needsBooking($bookable),
            'bookingOpen' => $bookingOpen,
            'recentNotifications' => (new Notification($this->pdo))->forUser((int) $this->user['id'], 3),
            'eligibilityCheck' => (new EligibilityService($this->pdo))->latestForUser((int) $this->user['id']),
            'quickActions' => $this->quickActions($renewal, $bookable, $draft, $bookingOpen),
            'applicationDraft' => $draft,
            'documentsToFix' => $this->documentsToFix(),
            'staffScheduled' => !empty($bookingOptions['staffAssigned']),
            'nextStep' => $this->nextStep($this->applications[0] ?? null, isset($this->applications[0]) ? (new ApplicationTrackerService($this->pdo))->track($this->applications[0]) : null, $upcoming, $bookingOpen, !empty($bookingOptions['staffAssigned'])),
        ]];
    }

    /**
     * Renewal status of the applicant's released ID: state is none (no
     * released ID), in_progress (a renewal is already being processed),
     * expired, due (within the renewal window, admin-configurable, default
     * 30 days) or valid.
     */
    private function renewalInfo(?array $idApplication): array
    {
        foreach ($this->applications as $app) {
            if ($app['type'] === 'renewal' && !in_array($app['status'], ['ID Released', 'Rejected'], true)) {
                return ['state' => 'in_progress', 'application' => $app];
            }
        }
        if (!$idApplication || $idApplication['status'] !== 'ID Released' || !$idApplication['id_expires_at']) {
            return ['state' => 'none'];
        }
        $windowDays = (int) get_setting($this->pdo, 'renewal_reminder_days', '30');
        $graceDays = max(1, (int) get_setting($this->pdo, 'renewal_grace_days', '365'));
        $daysLeft = (int) floor((strtotime($idApplication['id_expires_at']) - strtotime('today')) / 86400);
        // closed: the renewal period after expiry ended, so the Solo Parent applies again as a new application.
        $state = $daysLeft < -$graceDays ? 'closed' : ($daysLeft < 0 ? 'expired' : ($daysLeft <= $windowDays ? 'due' : 'valid'));
        return ['state' => $state, 'daysLeft' => $daysLeft, 'expiresAt' => $idApplication['id_expires_at'], 'windowDays' => $windowDays];
    }

    /** Soonest appointment still ahead, across all of the applicant's applications. */
    private function nextAppointment(): ?array
    {
        $next = null;
        foreach ($this->applications as $app) {
            if ($app['interview_status'] === 'BOOKED' && Appointment::isStillAhead($app['interview_at'], interview_is_date_only($app, 'interview_date_only'))
                && (!$next || $app['interview_at'] < $next['interview_at'])) {
                $next = $app;
            }
        }
        return $next;
    }

    /**
     * One sentence on the dashboard saying what happens next (and the button, when the applicant has something to do):
     * ['tone' => todo|wait|info|done, 'title', 'text', 'button' => [label, url]|null]. Null when nothing needs saying: no
     * application yet, or documents need a new file (the correction alert covers that).
     */
    private function nextStep(?array $latest, ?array $tracker, ?array $upcoming, bool $bookingOpen, bool $staffScheduled): ?array
    {
        if (!$latest || $this->documentsToFix()) {
            return null;
        }
        $docsDone = false;
        foreach ($tracker['stages'] ?? [] as $stage) {
            if ($stage['key'] === 'documents') {
                $docsDone = $stage['state'] === 'done';
            }
        }
        $appointmentUrl = base_url('applicant/dashboard.php?section=appointment');
        switch ($latest['status']) {
            case 'Pending Review':
                return ['tone' => 'wait', 'title' => 'We received your application', 'text' => 'MSWDO will check your documents. We will text you if something needs to be fixed.', 'button' => null];
            case 'Interview Scheduled':
                if ($upcoming) {
                    $when = strtotime($upcoming['interview_at']);
                    $dateOnly = interview_is_date_only($upcoming, 'interview_date_only');
                    $day = date('Y-m-d', $when) === date('Y-m-d') ? 'today' : (date('Y-m-d', $when) === date('Y-m-d', strtotime('+1 day')) ? 'tomorrow' : 'on ' . date('l, F j, Y', $when));
                    return ['tone' => 'info', 'title' => 'Your interview is ' . $day,
                        'text' => ($dateOnly ? 'Come any time from 7:00 AM to 5:00 PM' : 'Come at ' . date('g:i A', $when))
                            . (($latest['type'] ?? '') === 'renewal' ? ' and bring your current Solo Parent ID and a valid ID. MSWDO will check that you still qualify.' : ' and bring a valid ID.')
                            . ($staffScheduled ? ' MSWDO picked this date for you. You can keep it or choose another day.' : ''),
                        'button' => $staffScheduled ? ['Choose another day', $appointmentUrl] : ['View appointment', $appointmentUrl]];
                }
                return ['tone' => 'wait', 'title' => 'Your interview needs a new date', 'text' => 'Please contact MSWDO or check the Appointment page.', 'button' => ['Open Appointment', $appointmentUrl]];
            case 'Approved':
                $release = (new IdReleaseService($this->pdo))->scheduleFor((int) $latest['id']);
                $myIdUrl = base_url('applicant/dashboard.php?section=myid');
                if ($release && empty($release['released_at']) && strtotime((string) $release['scheduled_at']) < time()) {
                    return ['tone' => 'todo', 'title' => 'Your ID is ready', 'text' => 'Your release date has passed, but your Solo Parent ID is kept at the MSWDO office. Please visit MSWDO during office hours and bring a valid ID. Only you can receive it.', 'button' => ['View release details', $myIdUrl]];
                }
                if ($release && empty($release['released_at'])) {
                    return ['tone' => 'info', 'title' => 'Collect your Solo Parent ID', 'text' => 'Come on ' . date('l, F j, Y \a\t g:i A', strtotime((string) $release['scheduled_at'])) . ' at ' . $release['location'] . '. Bring a valid ID. Only you can receive it.', 'button' => ['View release details', $myIdUrl]];
                }
                return ['tone' => 'done', 'title' => 'Your application is approved', 'text' => 'MSWDO will tell you when and where to pick up your Solo Parent ID. Bring a valid ID.', 'button' => null];
            case 'ID Released':
                return ['tone' => 'done', 'title' => 'Your Solo Parent ID has been released', 'text' => 'You can view your ID details and renewal date any time.', 'button' => ['View my ID', base_url('applicant/dashboard.php?section=myid')]];
            case 'Rejected':
                return null;
        }
        // Under Verification (or For Revision with nothing left to fix)
        if ($bookingOpen && ($latest['interview_status'] ?? '') === 'MISSED') {
            return ['tone' => 'todo', 'title' => 'Please choose a new interview date', 'text' => 'Your last interview date has passed without an attendance record. Pick the new day you can come, Monday to Friday.', 'button' => ['Choose interview date', $appointmentUrl]];
        }
        if ($bookingOpen) {
            return ['tone' => 'todo', 'title' => 'Choose your interview date', 'text' => 'Your documents are verified. Pick the day you want to come, Monday to Friday.', 'button' => ['Choose interview date', $appointmentUrl]];
        }
        if ($docsDone) {
            return ['tone' => 'wait', 'title' => 'Your documents are verified', 'text' => 'MSWDO will give you an interview date soon. We will text you.', 'button' => null];
        }
        return ['tone' => 'wait', 'title' => 'MSWDO is checking your documents', 'text' => 'You do not need to do anything now. We will text you if a document needs to be fixed.', 'button' => null];
    }

    /** Shortcuts for what this applicant most likely needs to do next, most urgent first. */
    private function quickActions(array $renewal, ?array $bookable, ?array $draft, bool $bookingOpen = false): array
    {
        $latest = $this->applications[0] ?? null;
        $actions = [];
        if ($draft) {
            $actions[] = ['Continue My Application', base_url('applicant/dashboard.php?section=apply'), 'apply'];
        } elseif (!$latest || $latest['status'] === 'Rejected' || $renewal['state'] === 'closed') {
            $actions[] = ['Apply for Solo Parent ID', base_url('applicant/dashboard.php?section=apply'), 'apply'];
        }
        if ($latest && $latest['status'] === 'For Revision') {
            $actions[] = ['Fix My Documents', base_url('applicant/dashboard.php?section=documents'), 'documents'];
        }
        if (AppointmentService::needsBooking($bookable)) {
            $actions[] = [$bookingOpen ? 'Choose Interview Date' : 'View Interview Status', base_url('applicant/dashboard.php?section=appointment'), 'calendar'];
        }
        if (in_array($renewal['state'], ['due', 'expired'], true)) {
            $actions[] = ['Renew My ID', base_url('applicant/dashboard.php?section=renewal&type=new'), 'renew'];
        }
        if ($latest) {
            $actions[] = ['Track My Application', base_url('applicant/dashboard.php?section=application'), 'apply'];
        }
        $actions[] = ['Notifications', base_url('applicant/dashboard.php?section=notifications'), 'bell'];
        return $actions;
    }

    private function sectionMyid(): array
    {
        $idApplication = $this->viewableIdApplication();
        return ['view' => 'my-id.php', 'data' => [
            'idApplication' => $idApplication,
            'idExpiryInfo' => $this->idExpiryInfo($idApplication),
            'idReleaseSchedule' => $idApplication ? (new IdReleaseService($this->pdo))->scheduleFor((int) $idApplication['id']) : null,
            'idChildren' => $idApplication ? (new Application($this->pdo))->children((int) $idApplication['id']) : [],
            'latestApplication' => $this->applications[0] ?? null,
        ]];
    }

    private function sectionNotifications(): array
    {
        return ['view' => 'notifications/index.php', 'data' => (new NotificationController($this->pdo))->handle($this->user, 'applicant/dashboard.php?section=notifications')];
    }

    private function sectionProfile(): array
    {
        $profileError = '';
        $profileSuccess = '';
        $action = '';

        $profiles = new ApplicantProfileService($this->pdo);
        $formValues = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'update_info') {
                [$profileError, $profileSuccess] = $profiles->update($this->user, $_POST);
                if ($profileError) {
                    $formValues = $_POST; // redisplay what they typed
                }
            } elseif ($action === 'set_username') {
                [$profileError, $profileSuccess] = (new AuthService($this->pdo))->chooseUsername($this->user, (string) ($_POST['username'] ?? ''));
            } elseif ($action === 'change_password') {
                [$profileError, $profileSuccess] = $this->changePassword();
            } elseif ($action === 'upload_photo') {
                [$profileError, $profileSuccess] = $profiles->uploadPhoto($this->user, $_FILES['photo'] ?? []);
            } elseif ($action === 'remove_photo') {
                [$profileError, $profileSuccess] = $profiles->removePhoto($this->user);
            }
            $this->refreshUser();
        }

        $saved = $profiles->profile((int) $this->user['id']) + [
            'full_name' => $this->user['full_name'], 'email' => $this->user['email'], 'mobile' => $this->user['mobile'],
        ];
        $form = [];
        foreach (['full_name', 'email', 'mobile', 'birthdate', 'sex', 'civil_status', 'barangay', 'address'] as $key) {
            $form[$key] = $formValues !== null && is_string($formValues[$key] ?? null) ? $formValues[$key] : (string) ($saved[$key] ?? '');
        }

        return ['view' => 'profile.php', 'data' => [
            'profileError' => $profileError,
            'profileSuccess' => $profileSuccess,
            'profileAction' => $action,
            'form' => $form,
            'profileUpdatedAt' => $saved['updated_at'] ?? null,
            'civilStatusOptions' => CIVIL_STATUS_OPTIONS,
        ]];
    }

    /** @return array{0: string, 1: string} [error, success] */
    private function changePassword(): array
    {
        return (new AuthService($this->pdo))->changeOwnPassword(
            $this->user,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['new_password_confirm'] ?? '')
        );
    }

    private function refreshUser(): void
    {
        $this->user = (new AuthService($this->pdo))->currentUser() ?? $this->user;
    }
}
