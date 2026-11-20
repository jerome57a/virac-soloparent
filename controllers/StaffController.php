<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../services/ApplicationReviewService.php';
require_once __DIR__ . '/../services/AppointmentService.php';
require_once __DIR__ . '/../services/InterviewDateService.php';
require_once __DIR__ . '/../services/InterviewInvitationService.php';
require_once __DIR__ . '/../services/InterviewBookingService.php';
require_once __DIR__ . '/../services/ApplicationNextStepService.php';
require_once __DIR__ . '/../services/SchedulingQueueService.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/IdReleaseService.php';
require_once __DIR__ . '/../services/AutoVerificationService.php';
require_once __DIR__ . '/../models/ApplicationAutoCheck.php';
require_once __DIR__ . '/../models/SmsLog.php';
require_once __DIR__ . '/NotificationController.php';
require_once __DIR__ . '/ReportController.php';
require_once __DIR__ . '/../services/RecordService.php';
require_once __DIR__ . '/../models/Applicant.php';
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../models/Appointment.php';

/**
 * MSWD pages under staff/ and shared management pages with admin/ entry points.
 * Entry points check the portal role; every action also checks its permission.
 */
class StaffController extends Controller
{
    private const PER_PAGE = 20;

    private array $staff;

    public function __construct(PDO $pdo, bool $adminPortal = false)
    {
        parent::__construct($pdo);
        $auth = new AuthService($pdo);
        if ($auth->currentUser()) {
            $this->redirectTo('applicant/dashboard.php');
        }
        $staff = $auth->currentStaff();
        if (!$staff) {
            $this->redirectTo('mswd-login.php');
        }
        if ($staff['role'] !== ($adminPortal ? 'admin' : 'mswd_social_worker')) {
            $this->redirectTo($staff['role'] === 'admin' ? 'admin/dashboard.php' : 'staff/dashboard.php');
        }
        $this->staff = $staff;
        $accountPage = role_portal_path($staff, 'account.php');
        $this->requireOwnPassword($staff, $accountPage, str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/' . $accountPage));
    }

    public function dashboard(): void
    {
        $this->authorize($this->staff, 'staff.dashboard', 'admin/dashboard.php');
        $applications = new Application($this->pdo);
        $documents = new Document($this->pdo);
        $appointments = new Appointment($this->pdo);
        // Expected today = active BOOKED appointments on today's Manila date only (cancelled, completed and missed are left out);
        // the same list feeds the count and the "Today's Appointments" card, so the two always agree.
        $todays = array_values(array_filter($appointments->todaysInterviews(), fn($iv) => $iv['status'] === 'BOOKED'));
        $queues = new SchedulingQueueService($this->pdo);
        $readyToSchedule = $queues->readyToSchedule();
        $this->page('MSWD Dashboard', 'views/staff/dashboard.php', [
            'stats' => [
                'pending'           => $applications->countWhere("type = 'new' AND status = 'Pending Review'"),
                'underVerification' => $applications->countWhere("status = 'Under Verification'"),
                'forRevision'       => $applications->countWhere("status = 'For Revision'"),
                'pendingRenewals'   => $applications->countWhere("type = 'renewal' AND status = 'Pending Review'"),
                'todaysAppointments' => count($todays),
                'approved'          => $applications->countWhere("status IN ('Approved','ID Released')"),
                'readyToSchedule'   => count($readyToSchedule),
                'awaitingRelease'   => $queues->idReleaseCount(),
                'rejected'          => $applications->countWhere("status = 'Rejected'"),
            ],
            'docBacklog' => $documents->verificationBacklog(),
            'verificationQueue' => $documents->verificationQueue(5),
            'todaysAppointments' => $todays,
            'readyToSchedule' => array_slice($readyToSchedule, 0, 5),
            'idReleaseQueue' => $queues->idReleaseQueue(5),
            'bookingEnabled' => InterviewDateFeature::enabled($this->pdo), // applicants pick their own date: wording only
            'upcomingInterviews' => $appointments->upcomingInterviews(5),
            'recentlyProcessed' => $applications->recentlyReviewed(),
        ]);
    }

    /** staff/index.php: the application queue with filters. */
    public function applications(): void
    {
        $this->authorize($this->staff, 'applications.process', 'admin/applications.php');
        $filters = [
            'status' => (string) ($_GET['status'] ?? 'ALL'),
            'type' => (string) ($_GET['type'] ?? 'ALL'),
            'barangay' => trim((string) ($_GET['barangay'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? '')),
            'auto' => in_array($_GET['auto'] ?? '', ['ready', 'review', 'fix'], true) ? $_GET['auto'] : 'ALL',
        ];
        try {
            $result = (new Application($this->pdo))->search($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE);
        } catch (PDOException $e) {
            // The saved-results table has not been created yet (database/migrate_auto_verification.sql): list without that filter.
            if ($filters['auto'] === 'ALL') {
                throw $e;
            }
            $filters['auto'] = 'ALL';
            $result = (new Application($this->pdo))->search($filters, (int) ($_GET['page'] ?? 1), self::PER_PAGE);
        }
        $this->page('Applications', 'views/staff/applications/index.php', [
            'filters' => $filters,
            'statuses' => Application::STATUSES,
            'apps' => $result['rows'],
            'autoChecks' => (new ApplicationAutoCheck($this->pdo))->latestFor(array_column($result['rows'], 'id')),
            'nextSteps' => (new ApplicationNextStepService($this->pdo))->forRows($result['rows']),
            'pager' => $result,
        ]);
    }

    /** staff/application.php?id=: one application, with every review action. */
    public function application(): void
    {
        $this->authorize($this->staff, 'applications.process', 'admin/application_view.php?id=' . (int) ($_GET['id'] ?? 0));
        $id = (int) ($_GET['id'] ?? 0);
        $applications = new Application($this->pdo);
        $app = $applications->findForReview($id);
        if (!$app) {
            http_response_code(404);
            die('Application not found.');
        }

        $review = new ApplicationReviewService($this->pdo);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $review->startReviewOnOpen($this->staff, $app);
            $app = $applications->findForReview($id);
        }
        $errors = ['status' => '', 'interview' => '', 'invitation' => '', 'assessment' => '', 'release' => '', 'documents' => []];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $errors = $this->handleReviewAction($app) + $errors;
        }
        [$renewalOf, $renewalChanges] = $this->renewalContext($app);
        $appointmentModel = new Appointment($this->pdo);

        // Invitation card: nothing is read unless the rollout flag is on and the schema is ready (off: the card is hidden).
        $invitationState = InterviewDateFeature::flagOn($this->pdo) ? (InterviewDateFeature::schemaReady($this->pdo) ? 'ready' : 'incomplete') : 'off';
        $invitationPanel = $invitationState === 'ready' ? (new InterviewInvitationService($this->pdo))->panel($id) : null;

        $children = $applications->children($id);
        $submittedChecklist = (new Document($this->pdo))->checklistForReview($id, $app['category_id'] !== null ? (int) $app['category_id'] : null, ($app['type'] ?? '') === 'renewal');
        $autoChecks = new ApplicationAutoCheck($this->pdo);
        $autoCheck = (new AutoVerificationService($this->pdo))->forApplication($app, $children, $submittedChecklist);
        // Reading the documents' text (OCR) is slow, so its results come from the evaluation saved at submission.
        $savedChecks = $autoChecks->latestChecks($id);
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && AutoVerificationService::ocrAvailable() && $autoChecks->available()) {
            // Is there an uploaded image whose text has not been read yet? That is so for an application submitted before OCR or the
            // results table existed, and for a document the applicant replaced after a correction request (a new file, a new checksum).
            $imageShas = [];
            foreach ($submittedChecklist as $row) {
                $doc = $row['uploaded_document'] ?? null;
                if ($doc && !empty($doc['sha256']) && in_array($doc['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
                    $imageShas[$doc['sha256']] = true;
                }
            }
            $readShas = array_flip(array_filter(array_column(array_filter($savedChecks, fn($c) => ($c['key'] ?? '') === 'ocr_name'), 'sha')));
            $latestSaved = $autoChecks->latestFor([$id])[$id]['created_at'] ?? null;
            // The pause stops a file that cannot be read from being retried on every page view.
            $recentlyTried = $latestSaved !== null && strtotime((string) $latestSaved) > time() - 120;
            if (array_diff_key($imageShas, $readShas) && !$recentlyTried) {
                // Read the missing ones now (a few seconds) and keep the result, so the next visit is instant.
                $fresh = (new AutoVerificationService($this->pdo))->snapshot($id, 'review');
                if ($fresh) {
                    $savedChecks = $fresh['checks'];
                }
            }
        }
        $autoCheck = AutoVerificationService::withSavedOcr($autoCheck, $savedChecks, $submittedChecklist);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $autoChecks->record($id, $autoCheck, 'review'); // only when the result has changed
        }

        $this->page('Application ' . $app['reference_no'], 'views/staff/applications/show.php', [
            'app' => $app,
            'autoCheck' => $autoCheck,
            'statusError' => $errors['status'],
            'assessmentError' => $errors['assessment'],
            'releaseError' => $errors['release'],
            'documentErrors' => $errors['documents'],
            'interviewError' => $errors['interview'],
            'invitationError' => $errors['invitation'],
            'invitationState' => $invitationState,
            'invitationPanel' => $invitationPanel,
            'scheduleAutoMessage' => $invitationState === 'ready' ? (new SMSService($this->pdo))->interviewDateAssignedMessage((string) $app['reference_no'], null, get_setting($this->pdo, 'appointment_location', AppointmentService::DEFAULT_LOCATION)) : '',
            'scheduleDateStyle' => SMSService::compact() ? 'short' : 'long',
            'idReleaseTemplate' => SMSService::idReleaseTemplate(),
            'idReleaseCompact' => SMSService::compact(),
            'scheduleMinDate' => date('Y-m-d', strtotime(Appointment::manilaDate() . ' +1 day')),
            'scheduleMaxDate' => date('Y-m-d', strtotime(Appointment::manilaDate() . ' +' . InterviewBookingService::BOOKING_WINDOW_DAYS . ' days')),
            'flashMessage' => take_flash(),
            'smsNotice' => take_sms_notice(),
            'smsLogs' => (new SmsLog($this->pdo))->forApplication($id, SMS_MAX_ATTEMPTS),
            'verificationNotice' => (function () { $n = $_SESSION['verification_notice'] ?? null; unset($_SESSION['verification_notice']); return is_array($n) ? $n : null; })(),
            'reviewUndoToken' => $review->reviewUndoToken($id),
            'correctionSms' => $review->correctionSmsPreview($app),
            'correctionNotice' => (function () { $n = $_SESSION['correction_sms_notice'] ?? null; unset($_SESSION['correction_sms_notice']); return is_array($n) ? $n : null; })(),
            'showCorrectionPreview' => $_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['preview_correction_sms'] ?? '') === '1',
            'allowedTransitions' => Application::TRANSITIONS[$app['status']] ?? [],
            'approvalChecklist' => $review->approvalChecklist($app),
            'interview' => $appointmentModel->interviewFor($id),
            'interviewSms' => $appointmentModel->interviewSms($id),
            'idReleaseSchedule' => (new IdReleaseService($this->pdo))->scheduleFor($id),
            'defaultReleaseLocation' => get_setting($this->pdo, 'appointment_location', 'MSWDO Virac office'),
            'children' => $children,
            'submittedChecklist' => $submittedChecklist,
            'documentHistory' => (new Document($this->pdo))->statusHistoryFor($id),
            'history' => $applications->history($id),
            'decisions' => $applications->decisions($id),
            'notes' => $applications->notes($id),
            'renewalOf' => $renewalOf,
            'renewalChanges' => $renewalChanges,
        ]);
    }

    /** Runs one review action; redirects back on success, else returns an action-specific error. */
    private function handleReviewAction(array $app): array
    {
        $review = new ApplicationReviewService($this->pdo);
        $id = (int) $app['id'];
        $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
        $back = 'staff/application.php?id=' . $id;

        switch ($post('form_action') ?: 'update_status') {
            case 'undo_review':
                $section = $post('review_section');
                if (!in_array($section, ['eligibility', 'interview'], true)) {
                    return ['assessment' => 'That review cannot be undone.'];
                }
                $error = $review->undoReview($this->staff, $id, $section, $post('expected_review'));
                if ($error) {
                    return [$section === 'interview' ? 'interview' : 'assessment' => $error];
                }
                flash(ucfirst($section) . ' review undone. You can review it again.');
                $back .= $section === 'interview' ? '#interview' : '#decision';
                break;
            case 'schedule_id_release':
                $error = (new IdReleaseService($this->pdo))->schedule($this->staff, $app, $post('release_date'), $post('release_time'), $post('release_location'), $post('sms_message_type') ?: 'automatic', $post('release_custom_message'));
                if ($error) {
                    return ['release' => $error];
                }
                $back .= '#id-release';
                break;
            case 'retry_id_release_sms':
                $releaseService = new IdReleaseService($this->pdo);
                $schedule = $releaseService->scheduleFor($id);
                $logId = (int) $post('sms_log_id');
                if ($app['status'] !== 'Approved' || !$schedule || (int) $schedule['sms_log_id'] !== $logId || $schedule['sms_status'] !== 'FAILED') {
                    return ['release' => 'That failed SMS is no longer the current release notification. Refresh and review the latest schedule.'];
                }
                [$retryError, $retryResult] = (new SMSService($this->pdo))->retry($logId);
                if ($retryError) {
                    return ['release' => $retryError];
                }
                set_sms_notice(SMSService::outcomeNotice($retryResult, 'Retry of the ID release SMS'));
                $back .= '#id-release';
                break;
            case 'confirm_id_release':
                if ($post('confirm_handover') !== '1') {
                    return ['release' => 'Confirm that the physical ID was handed to the Solo Parent before recording release.'];
                }
                $error = (new IdReleaseService($this->pdo))->confirmRelease($this->staff, $app);
                if ($error) {
                    return ['release' => $error];
                }
                $back .= '#id-release';
                break;
            case 'schedule_interview_date':
                // Staff set the interview date: booked at once, the applicant is texted and may keep it or pick another date.
                $scheduleDate = $post('interview_date');
                $error = (new InterviewBookingService($this->pdo))->scheduleForApplicant($this->staff, $id, $scheduleDate, $post('sms_message_type') === 'custom' ? $post('invitation_custom_message') : null, $smsResult);
                if ($error) {
                    return ['invitation' => $error];
                }
                $scheduled = 'Interview scheduled for ' . date('l, F j, Y', strtotime($scheduleDate));
                flash($scheduled . '. The Solo Parent can keep that date or pick another one.');
                // Tell staff what really happened to the text (sent, failed or not sent), with a Retry button when it failed.
                set_sms_notice(SMSService::outcomeNotice($smsResult ?? ['status' => 'FAILED', 'error' => 'no SMS result was recorded'], $scheduled));
                $back .= '#invitation';
                break;
            // Interviews: staff schedule a date (the case above) and the applicant may keep it or pick another (InterviewBookingService).
            case 'retry_interview_sms':
                $appointment = (new Appointment($this->pdo))->interviewFor($id);
                $sms = new SMSService($this->pdo);
                $latest = $appointment ? (new Appointment($this->pdo))->interviewSms($id) : null;
                $logId = (int) $post('sms_log_id');
                if (!$appointment || $appointment['status'] !== 'BOOKED' || !$latest || (int) $latest['id'] !== $logId || $latest['status'] !== 'FAILED') {
                    return ['interview' => 'That failed SMS is no longer the current interview notification. Refresh the page and review the latest schedule.'];
                }
                if (!$sms->templateEnabled('interview_scheduled')) {
                    return ['interview' => 'Interview SMS notifications are disabled. Enable them in Notification Settings before retrying.'];
                }
                [$retryError, $retryResult] = $sms->retry($logId);
                if ($retryError) {
                    return ['interview' => $retryError];
                }
                set_sms_notice(SMSService::outcomeNotice($retryResult, 'Retry of the interview SMS'));
                $back .= '#interview';
                break;
            case 'retry_sms':
                // Any retryable text about this application; the checks live in retrySms().
                $this->retrySms($id, (int) $post('sms_log_id'));
                $back .= '#sms';
                break;
            case 'record_result':
                $error = $review->recordInterviewOutcome($this->staff, $id, $post('interview_outcome'), $post('interview_notes'));
                if ($error) {
                    return ['interview' => $error];
                }
                $back .= '#interview';
                break;
            case 'save_verification':
                $rows = [];
                $docs = $_POST['docs'] ?? [];
                foreach (is_array($docs) ? $docs : [] as $docId => $row) {
                    if (!is_array($row) || !ctype_digit((string) $docId)) {
                        continue;
                    }
                    $rows[(int) $docId] = [
                        'status' => is_string($row['status'] ?? null) ? trim($row['status']) : '',
                        'remarks' => is_string($row['remarks'] ?? null) ? $row['remarks'] : '',
                        'expected' => is_string($row['expected'] ?? null) ? trim($row['expected']) : '',
                        'expected_remarks' => is_string($row['expected_remarks'] ?? null) ? $row['expected_remarks'] : null,
                        'expected_version' => is_string($row['expected_version'] ?? null) ? trim($row['expected_version']) : '',
                    ];
                }
                if (!$rows) {
                    return ['documents' => ['checklist' => 'There are no documents to save.']];
                }
                $result = $review->saveVerification($this->staff, $app, $rows);
                if ($result['errors']) {
                    return ['documents' => $result['errors']];
                }
                $notice = $result['changed'] > 0
                    ? 'Verification saved: ' . $result['changed'] . ' document' . ($result['changed'] === 1 ? '' : 's') . ' updated.'
                    : 'No changes to save. Verification is already up to date.';
                if ($result['notify_failures'] > 0) {
                    $notice .= ' The changes are saved, but ' . $result['notify_failures'] . ' Solo Parent notification' . ($result['notify_failures'] === 1 ? '' : 's') . ' could not be sent. Check the SMS log.';
                }
                $_SESSION['verification_notice'] = ['text' => $notice, 'warning' => $result['notify_failures'] > 0];
                $back .= '#checklist';
                break;
            case 'confirm_correction_sms':
                // The application comes from the staff page's own ?id= lookup, so the batch token (which embeds the
                // application id) can only confirm a batch of this record. Applicants cannot reach this controller.
                $sent = $review->sendCorrectionSms($this->staff, $app, $post('batch_token'));
                // Shown inside the #correction-sms panel (its own session key, not the Save Verification notice).
                $_SESSION['correction_sms_notice'] = [
                    'outcome' => $sent['outcome'],
                    'test_mode' => !empty($sent['test_mode']),
                    'text' => $sent['message'],
                ];
                $back .= '#correction-sms';
                break;
            default:
                $status = $post('status');
                $error = $review->updateStatus($this->staff, $app, $status, $post('remarks'), $post('expected_status'));
                if ($error) {
                    return ['status' => $error];
                }
                flash('Application is now ' . $status . '. The Solo Parent was notified.');
                $back .= '#decision';
        }
        $this->redirectTo($back);
        return [];
    }
    /** staff/applicants.php: applicant account search. */
    public function applicants(): void
    {
        $this->authorize($this->staff, 'applicants.view');
        $records = new RecordService($this->pdo);
        $filters = $records->filters($_GET);
        $result = $records->search($filters, (int) ($_GET['page'] ?? 1), $this->staff['role'] === 'admin' ? 10 : RecordService::PER_PAGE);
        $this->page('Solo Parent Records', 'views/staff/applicants/index.php', [
            'filters' => $filters,
            'barangays' => $records->barangays(),
            'statuses' => Application::STATUSES,
            'applicants' => $result['rows'],
            'pager' => $result,
        ]);
    }

    /**
     * staff/applicant_view.php?id=[&app=][&hpage=]: everything on file for one
     * applicant -- profile, applications, renewals, documents, appointments,
     * decisions and status history (optionally for one application only).
     */
    public function applicant(): void
    {
        $this->authorize($this->staff, 'applicants.view');
        $record = (new RecordService($this->pdo))->record((int) ($_GET['id'] ?? 0), (int) ($_GET['app'] ?? 0), (int) ($_GET['hpage'] ?? 1));
        if (!$record) {
            http_response_code(404);
            die('Solo Parent not found.');
        }
        $this->page($record['applicant']['full_name'] . ' — Solo Parent Record', 'views/staff/applicants/show.php', $record);
    }

    /**
     * staff/appointments.php: the schedule (needs-outcome, today, upcoming). MSWD staff can also cancel an appointment or mark a no-show;
     * administrators see the schedule read-only.
     */
    public function appointments(): void
    {
        $this->authorize($this->staff, 'appointments.view');
        $canManage = can($this->staff, 'applications.process');
        $error = '';
        $dateError = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $this->authorize($this->staff, 'applications.process', 'staff/appointments.php');
            $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
            if ($post('form_action') === 'retry_sms') {
                $this->retrySms(null, (int) $post('sms_log_id'));
                $this->redirectTo('staff/appointments.php');
            }
            $outcome = ['cancel' => 'CANCELLED', 'missed' => 'MISSED'][$post('action')] ?? '';
            $smsResult = null;
            $error = (new AppointmentService($this->pdo))->staffSetOutcome($this->staff, (int) $post('appointment_id'), $outcome, $post('reason'), $smsResult) ?? '';
            if ($error === '') {
                if ($smsResult !== null) {
                    set_sms_notice(SMSService::outcomeNotice($smsResult, $outcome === 'CANCELLED' ? 'Appointment cancelled' : 'Interview marked as missed'));
                }
                flash($outcome === 'CANCELLED' ? 'Appointment cancelled and the Solo Parent notified.' : 'Appointment marked as missed and the Solo Parent notified.');
                $this->redirectTo('staff/appointments.php');
            }
        }

        // 'off' = the rollout flag is off (the card is hidden and no interview-date table is read); 'ready' = flag on and
        // the whole schema ready; 'incomplete' = flag on but the schema is not ready or could not be checked (fail closed).
        $dateState = InterviewDateFeature::flagOn($this->pdo) ? (InterviewDateFeature::schemaReady($this->pdo) ? 'ready' : 'incomplete') : 'off';
        $dateService = new InterviewDateService($this->pdo);
        $appointments = new Appointment($this->pdo);
        $awaitingSchedule = $canManage ? $appointments->awaitingStaffSchedule() : [];
        $waitingForDate = [];
        if ($canManage && $dateState === 'ready') {
            // Ready to schedule: every required document verified, no interview date yet and not already waiting on the applicant
            // (those are listed separately). The same list feeds the staff dashboard.
            $waitingForDate = (new InterviewInvitation($this->pdo))->waitingForApplicant();
            $awaitingSchedule = (new SchedulingQueueService($this->pdo))->readyToSchedule();
        }
        $this->page($this->staff['role'] === 'admin' ? 'Appointment Overview' : 'Appointment Management', 'views/staff/appointments/index.php', [
            'error' => $error,
            'flashMessage' => take_flash(),
            'canManage' => $canManage,
            'smsNotice' => take_sms_notice(),
            'awaitingOutcome' => $appointments->awaitingOutcome(),
            'overdueReleases' => (new IdReleaseService($this->pdo))->overdue(),
            'awaitingSchedule' => $awaitingSchedule,
            'waitingForDate' => $waitingForDate,
            // Past-but-still-booked timed ones are already listed under Needs Outcome; a date-only one stays here all day.
            'todayAppointments' => array_values(array_filter($appointments->todaysInterviews(), fn($iv) => Appointment::staysOnTodayList($iv))),
            'upcomingByDate' => $appointments->laterInterviewsByDate(),
            'dateState' => $dateState,
            'dateError' => $dateError,
            'interviewDates' => $dateState === 'ready' ? $dateService->upcoming() : [],
            'defaultCapacity' => $dateState === 'ready' ? $dateService->defaultCapacity() : 0,
        ]);
    }

    /** staff/sms.php: failed texts to applicants that still need a decision, each with Retry. */
    public function smsAttention(): void
    {
        $this->authorize($this->staff, 'applications.process', 'staff/dashboard.php');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $post = fn(string $key) => is_string($_POST[$key] ?? null) ? trim($_POST[$key]) : '';
            if ($post('form_action') === 'retry_sms') {
                $this->retrySms(null, (int) $post('sms_log_id'));
            }
            $this->redirectTo('staff/sms.php');
        }
        $this->page('Failed Texts', 'views/staff/sms/index.php', [
            'smsNotice' => take_sms_notice(),
            'rows' => (new SmsLog($this->pdo))->needingAttention(SMS_MAX_ATTEMPTS),
        ]);
    }

    /**
     * Retries one failed text and reports what happened as a notice. $applicationId null = look it up from the text. The text must
     * belong to that application, have failed, still be the newest of its kind and have attempts left.
     */
    private function retrySms(?int $applicationId, int $logId): void
    {
        if ($applicationId === null) {
            $find = $this->pdo->prepare('SELECT application_id FROM sms_logs WHERE id = ?');
            $find->execute([$logId]);
            $applicationId = (int) $find->fetchColumn();
        }
        $mine = null;
        foreach ($applicationId > 0 ? (new SmsLog($this->pdo))->forApplication($applicationId, SMS_MAX_ATTEMPTS, 50) : [] as $row) {
            if ((int) $row['id'] === $logId) {
                $mine = $row;
                break;
            }
        }
        if (!$mine || !$mine['can_retry']) {
            set_sms_notice(['state' => 'failed', 'log_id' => null, 'text' => 'That SMS cannot be retried from here (it was already resent, it used all its attempts, or it is a kind that must be sent afresh).']);
            return;
        }
        [$retryError, $retryResult] = (new SMSService($this->pdo))->retry($logId);
        set_sms_notice($retryError
            ? ['state' => 'failed', 'log_id' => $logId, 'text' => 'Retry not possible: ' . $retryError]
            : SMSService::outcomeNotice($retryResult, 'Retry of the SMS'));
    }
    /** staff/notifications.php: the signed-in staff member's own notifications. */
    public function notifications(): void
    {
        $this->authorize($this->staff, 'staff.dashboard', 'admin/notifications.php');
        $data = (new NotificationController($this->pdo))->handle($this->staff, 'staff/notifications.php');
        $this->page('Notifications', 'views/staff/notifications.php', $data);
    }

    /** staff/account.php: the signed-in staff member's (or admin's) own account -- details and password change. */
    public function account(): void
    {
        [$error, $success] = ['', ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$error, $success] = (new AuthService($this->pdo))->changeOwnPassword(
                $this->staff,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            if ($success) {
                flash($success);
                $this->redirectTo(role_portal_path($this->staff, 'account.php'));
            }
        }
        $this->page('My Account', 'views/staff/account.php', [
            'error' => $error,
            'success' => (string) take_flash(),
            'roleLabels' => User::ROLE_LABELS,
        ]);
    }

    public function reports(): void
    {
        $this->authorize($this->staff, 'reports.view');
        if ($this->staff['role'] === 'admin') {
            // Administrators use the same reports on their own page.
            $this->redirectTo('admin/reports.php' . ($_GET ? '?' . http_build_query($_GET) : ''));
        }
        $data = (new ReportController($this->pdo))->handle($this->staff, 'staff/reports.php');
        $this->page(Report::TYPES[$data['type']], 'views/reports/index.php', $data);
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
    /** [the record a renewal renews (with children) or null, the changes since it]. */
    protected function renewalContext(array $app): array
    {
        if ($app['type'] !== 'renewal' || !$app['renewal_of_id']) {
            return [null, []];
        }
        $applications = new Application($this->pdo);
        $previous = $applications->findWithChildren((int) $app['renewal_of_id']);
        if (!$previous) {
            return [null, []];
        }
        return [$previous, RenewalService::diff($previous, $app + ['children' => $applications->children((int) $app['id'])])];
    }
}
