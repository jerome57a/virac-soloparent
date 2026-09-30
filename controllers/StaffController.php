<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * MSWD case-processing pages under staff/. Reachable by both staff roles
 * (MSWD social workers and admins); every action requires a staff login.
 */
class StaffController extends Controller
{
    private const PER_PAGE = 20;

    private array $staff;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $staff = (new AuthService($pdo))->currentStaff();
        if (!$staff) {
            $this->redirectTo('admin/login.php');
        }
        $this->staff = $staff;
    }

    public function dashboard(): void
    {
        $applications = new Application($this->pdo);
        $this->page('MSWD Dashboard', 'views/staff/dashboard.php', [
            'stats' => [
                'pending'           => $applications->countWhere("type = 'new' AND status = 'Pending Review'"),
                'underVerification' => $applications->countWhere("status = 'Under Verification'"),
                'forRevision'       => $applications->countWhere("status = 'For Revision'"),
                'pendingRenewals'   => $applications->countWhere("type = 'renewal' AND status = 'Pending Review'"),
            ],
            'upcomingInterviews' => (new Appointment($this->pdo))->upcomingInterviews(5),
            'recentlyProcessed' => $applications->recentlyReviewedBy((int) $this->staff['id']),
        ]);
    }

    /** staff/index.php: the application queue with filters. */
    public function applications(): void
    {
        $filters = [
            'status' => (string) ($_GET['status'] ?? 'ALL'),
            'type' => (string) ($_GET['type'] ?? 'ALL'),
            'barangay' => trim((string) ($_GET['barangay'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $result = (new Application($this->pdo))->search($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE);
        $this->page('Applications', 'views/staff/applications/index.php', [
            'filters' => $filters,
            'statuses' => Application::STATUSES,
            'apps' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /** staff/application.php?id=: one application, with every review action. */
    public function application(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($id);
        if (!$app) {
            http_response_code(404);
            die('Application not found.');
        }

        $statusError = '';
        $interviewError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$statusError, $interviewError] = $this->handleReviewAction($app);
        }

        $this->page('Application ' . $app['reference_no'], 'views/staff/applications/show.php', [
            'app' => $app,
            'statusError' => $statusError,
            'interviewError' => $interviewError,
            'statuses' => Application::STATUSES,
            'eligibilityStatuses' => Application::ELIGIBILITY_STATUSES,
            'verificationStatuses' => Document::VERIFICATION_STATUSES,
            'interview' => (new Appointment($this->pdo))->interviewFor($id),
            'children' => $applications->children($id),
            'docs' => (new Document($this->pdo))->forApplication($id),
            'history' => $applications->history($id),
        ]);
    }

    /** @return array{0: string, 1: string} [statusError, interviewError]; redirects back on success. */
    private function handleReviewAction(array $app): array
    {
        $review = new ApplicationReviewService($this->pdo);
        $id = (int) $app['id'];
        $post = fn(string $key) => trim((string) ($_POST[$key] ?? ''));

        switch ((string) ($_POST['form_action'] ?? 'update_status')) {
            case 'schedule_interview':
                $error = $review->scheduleInterview($this->staff, $app, (string) ($_POST['scheduled_at'] ?? ''), $post('location'), $post('notes'));
                if ($error) {
                    return ['', $error];
                }
                break;
            case 'record_result':
                $error = $review->recordInterviewResult($this->staff, $id, $post('result'));
                if ($error) {
                    return ['', $error];
                }
                break;
            case 'update_document_status':
                $review->verifyDocument($this->staff, $id, (int) ($_POST['doc_id'] ?? 0), (string) ($_POST['verification_status'] ?? ''), $post('staff_remarks'));
                break;
            case 'update_eligibility':
                $review->assessEligibility($this->staff, $app, (string) ($_POST['eligibility_status'] ?? ''), $post('eligibility_remarks'));
                break;
            default:
                $error = $review->updateStatus($this->staff, $app, (string) ($_POST['status'] ?? ''), $post('remarks'));
                if ($error) {
                    return [$error, ''];
                }
        }
        $this->redirectTo('staff/application.php?id=' . $id);
        return ['', ''];
    }

    /** staff/applicants.php: applicant account search. */
    public function applicants(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $barangay = trim((string) ($_GET['barangay'] ?? ''));
        $result = (new Applicant($this->pdo))->search($q, $barangay, (int) ($_GET['page'] ?? 1), self::PER_PAGE);
        $this->page('Applicant Records', 'views/staff/applicants/index.php', [
            'q' => $q,
            'barangayFilter' => $barangay,
            'applicants' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /** staff/applicant_view.php?id=: one applicant's profile, applications, and renewals. */
    public function applicant(): void
    {
        $applicant = (new Applicant($this->pdo))->findApplicant((int) ($_GET['id'] ?? 0));
        if (!$applicant) {
            http_response_code(404);
            die('Applicant not found.');
        }

        $applications = new Application($this->pdo);
        $apps = $applications->forUserWithCategory((int) $applicant['id']);
        $appIds = array_column($apps, 'id');

        $this->page($applicant['full_name'] . ' — Applicant Record', 'views/staff/applicants/show.php', [
            'applicant' => $applicant,
            'newApps' => array_values(array_filter($apps, fn($a) => $a['type'] === 'new')),
            'renewals' => array_values(array_filter($apps, fn($a) => $a['type'] === 'renewal')),
            'interviewsByApp' => (new Appointment($this->pdo))->interviewsFor($appIds),
            'docCountsByApp' => (new Document($this->pdo))->verificationCountsFor($appIds),
            'latestDecisionByApp' => $applications->latestHistoryFor($appIds),
        ]);
    }

    public function appointments(): void
    {
        $appointments = new Appointment($this->pdo);
        $slotsByDate = [];
        foreach ($appointments->upcomingSlotsWithBookings(100) as $s) {
            $slotsByDate[$s['slot_date']][] = $s;
        }
        $this->page('Appointment Management', 'views/staff/appointments/index.php', [
            'todayAppointments' => $appointments->todaysInterviews(),
            'upcomingByDate' => $appointments->laterInterviewsByDate(),
            'slotsByDate' => $slotsByDate,
        ]);
    }

    public function appointmentSlots(): void
    {
        $service = new AppointmentService($this->pdo);
        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'create') {
                $error = $service->createSlot(
                    $this->staff,
                    (string) ($_POST['slot_date'] ?? ''),
                    (string) ($_POST['start_time'] ?? ''),
                    (string) ($_POST['end_time'] ?? ''),
                    (int) ($_POST['capacity'] ?? 0)
                ) ?? '';
                $success = $error === '' ? 'Slot added.' : '';
            } elseif ($action === 'delete') {
                $error = $service->deleteSlot($this->staff, (int) ($_POST['slot_id'] ?? 0)) ?? '';
                $success = $error === '' ? 'Slot removed.' : '';
            }
        }

        $this->page('Appointment Slots', 'views/staff/appointments/slots.php', [
            'error' => $error,
            'success' => $success,
            'slots' => (new Appointment($this->pdo))->upcomingSlotsWithBookings(),
            'defaultCapacity' => (int) get_setting($this->pdo, 'appointment_default_capacity', '10'),
        ]);
    }

    /** staff/qr_verify.php: jump to an application by scanned QR (a status URL) or typed reference number. */
    public function qrVerify(): void
    {
        $error = '';
        $ref = trim((string) ($_GET['ref'] ?? ''));
        if ($ref !== '') {
            if (preg_match('/[?&]ref=([^&]+)/', $ref, $m)) {
                $ref = urldecode($m[1]);
            }
            $id = (new Application($this->pdo))->findIdByReference($ref);
            if ($id) {
                $this->redirectTo('staff/application.php?id=' . $id);
            }
            $error = 'No application found for reference number "' . $ref . '".';
        }
        $this->page('QR Verification', 'views/staff/qr/verify.php', ['error' => $error]);
    }

    public function reports(): void
    {
        $applications = new Application($this->pdo);
        $monthly = $applications->monthlyCounts();
        $this->page('Reports', 'views/staff/reports/index.php', [
            'counts' => [
                'today' => $applications->countWhere('DATE(submitted_at) = CURDATE()'),
                'thisMonth' => $applications->countWhere('YEAR(submitted_at) = YEAR(CURDATE()) AND MONTH(submitted_at) = MONTH(CURDATE())'),
                'approved' => $applications->countWhere("status IN ('Approved','ID Released')"),
                'rejected' => $applications->countWhere("status = 'Rejected'"),
                'pending' => $applications->countWhere("status IN ('Pending Review','Under Verification','For Revision','Interview Scheduled')"),
                'renewals' => $applications->countWhere("type = 'renewal'"),
                'renewalsPending' => $applications->countWhere("type = 'renewal' AND status NOT IN ('ID Released','Rejected')"),
            ],
            'monthly' => $monthly,
            'monthlyMax' => max(1, ...array_column($monthly, 'n') ?: [0]),
        ]);
    }

    private function page(string $title, string $view, array $data): void
    {
        $this->render('views/layouts/page.php', array_merge($data, [
            'pageTitle' => $title,
            'contentView' => $view,
            'staff' => $this->staff,
            'ownDashboard' => base_url($this->staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php'),
        ]));
    }
}
