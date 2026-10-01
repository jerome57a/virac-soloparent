<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/RateLimiter.php';
require_once __DIR__ . '/../services/ApplicationService.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/QrSlipService.php';
require_once __DIR__ . '/../models/Appointment.php';

class ApplicationController extends Controller
{
    /** Dashboard "My Application" section: every application with its progress tracker. */
    public function statusList(array $user, array $applications): array
    {
        $tracker = new ApplicationTrackerService($this->pdo);
        $trackers = [];
        foreach ($applications as $app) {
            $trackers[$app['id']] = $tracker->track($app);
        }
        return ['view' => 'application/status.php', 'data' => [
            'myApplications' => $applications,
            'trackers' => $trackers,
            'applicationDraft' => (new ApplicationService($this->pdo))->draftSummary($user),
        ]];
    }

    /** Where the application wizard lives: inside the applicant dashboard layout. */
    private const APPLY_URL = 'applicant/dashboard.php?section=apply';

    /** Portal "Apply" tab: visitors are asked to log in; signed-in applicants apply from their dashboard. */
    public function apply(?array $user): void
    {
        if (!$user) {
            $this->render('views/applicant/application/login-required.php', ['message' => 'to start your application.']);
            return;
        }
        $this->redirectTo(self::APPLY_URL);
    }

    /** Dashboard "Apply for Solo Parent ID" section: the 4-step wizard. */
    public function applySection(array $user): array
    {
        $service = new ApplicationService($this->pdo);

        // One active application at a time: no new wizard (and no draft actions) while one exists.
        $blocking = $service->blockingApplication($user);
        if ($blocking) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                csrf_require();
            }
            return ['view' => 'application/blocked.php', 'data' => ['app' => $blocking]];
        }

        $draft = $service->getDraft($user);
        $errors = [];
        $submitError = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$draft, $errors, $submitError] = $this->handleApplyAction($service, $user, $draft);
        }
        $draftInfo = $service->draftSummary($user);

        $step = (int) $draft['step'];
        $documents = new Document($this->pdo);
        $categories = get_categories($this->pdo);
        $categoryMap = array_column($categories, 'name', 'id');
        $categoryDocs = [];
        foreach ($categories as $c) {
            $categoryDocs[$c['id']] = array_column($documents->categoryOnlyRequirements((int) $c['id']), 'label');
        }
        $errCount = count($errors);

        return ['view' => 'application/create.php', 'data' => [
            'draft' => $draft,
            'errors' => $errors,
            'submitError' => $submitError,
            'step' => $step,
            'stepMeta' => ApplicationService::STEP_META,
            'stepError' => $errCount === 1 ? 'Please fix the highlighted field before continuing.'
                : ($errCount > 1 ? "Please fix the $errCount highlighted fields before continuing." : ''),
            'categories' => $categories,
            'categoryMap' => $categoryMap,
            'categoryDocs' => $categoryDocs,
            'docDefs' => $documents->requirementsFor($draft['categoryId']),
            'catOnlyKeys' => array_column($documents->categoryOnlyRequirements($draft['categoryId']), 'doc_key'),
            'draftSavedAt' => $draftInfo['updatedAt'] ?? null,
            'flashMessage' => take_flash(),
        ]];
    }

    /** @return array{0: array, 1: array, 2: ?string} [draft, errors, submitError] -- redirects on every action that succeeds. */
    private function handleApplyAction(ApplicationService $service, array $user, array $draft): array
    {
        $action = (string) ($_POST['action'] ?? '');
        $step = (int) $draft['step'];
        $applyUrl = self::APPLY_URL;

        switch ($action) {
            case 'continue':
                $draft = $service->collectStepInput($step, $draft, $_POST);
                $errors = $service->validateStep($step, $draft);
                if (!$errors) {
                    $draft['step'] = min(4, $step + 1);
                }
                $service->saveDraft($user, $draft);
                if (!$errors) {
                    $this->redirectTo($applyUrl);
                }
                return [$draft, $errors, null];

            case 'save_draft':
                // Keep what's on the current step as-is (no validation) and leave.
                $service->saveDraft($user, $service->collectStepInput($step, $draft, $_POST));
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Draft Saved', 'user', (int) $user['id'], 'Step ' . $step);
                flash('Your application draft is saved. Continue it any time from your dashboard or the Apply tab.');
                $this->redirectTo('applicant/dashboard.php?section=application');
                return [$draft, [], null];

            case 'discard_draft':
                $service->clearDraft($user);
                audit_log($this->pdo, (int) $user['id'], $user['full_name'], $user['role'], 'Application Draft Discarded', 'user', (int) $user['id'], 'Draft and its uploaded files deleted (was at step ' . $step . ')');
                flash('Your application draft was discarded.');
                $this->redirectTo($applyUrl);
                return [$draft, [], null];

            case 'back':
                $draft = $service->collectStepInput($step, $draft, $_POST);
                $draft['step'] = max(1, $step - 1);
                break;

            case 'goto':
                $n = (int) ($_POST['n'] ?? 0);
                if ($n >= 1 && $n < $step) {
                    $draft['step'] = $n;
                }
                break;

            case 'add_child':
                $draft = $service->collectStepInput(2, $draft, $_POST);
                $draft['children'][] = ['name' => '', 'birthdate' => ''];
                break;

            case 'remove_child':
                $draft = $service->collectStepInput(2, $draft, $_POST);
                $idx = (int) ($_POST['idx'] ?? -1);
                if (count($draft['children']) > 1 && isset($draft['children'][$idx])) {
                    array_splice($draft['children'], $idx, 1);
                }
                break;

            case 'upload_doc':
            case 'remove_doc':
                // Only requirement keys that apply to the chosen category are accepted.
                $docKey = is_string($_POST['doc_key'] ?? null) ? $_POST['doc_key'] : '';
                $allowed = array_column((new Document($this->pdo))->requirementsFor($draft['categoryId']), 'doc_key');
                if (!in_array($docKey, $allowed, true)) {
                    break;
                }
                $draft = $action === 'upload_doc'
                    ? $service->files()->attachToDraft($draft, $docKey, $_FILES['file'] ?? [], $service->draftDir((int) $user['id']))
                    : $service->files()->detachFromDraft($draft, $docKey);
                break;

            case 'submit':
                $draft = $service->collectStepInput(4, $draft, $_POST);
                $invalid = $service->firstInvalidStep($draft);
                if ($invalid) {
                    $draft['step'] = $invalid['step'];
                    $service->saveDraft($user, $draft);
                    return [$draft, $invalid['errors'], null];
                }
                try {
                    $refNumber = $service->submit($user, $draft);
                    $this->redirectTo('slip.php?ref=' . urlencode($refNumber));
                } catch (DomainException $e) {
                    $this->redirectTo($applyUrl); // shows the "you already have an application" page
                } catch (Throwable $e) {
                    error_log('Application submission failed: ' . $e->getMessage());
                    $service->saveDraft($user, $draft);
                    return [$draft, [], 'Something went wrong while submitting your application. Your draft is saved -- please try again.'];
                }
                return [$draft, [], null];

            default:
                return [$draft, [], null];
        }

        $service->saveDraft($user, $draft);
        $this->redirectTo($applyUrl);
        return [$draft, [], null];
    }

    /** Portal "My Applications" tab. */
    public function myApplications(?array $user): void
    {
        if (!$user) {
            $this->render('views/applicant/application/login-required.php', ['message' => 'to view your applications.', 'linkText' => 'log in']);
            return;
        }
        $this->render('views/applicant/application/my-applications.php', [
            'apps' => (new Application($this->pdo))->forUser((int) $user['id']),
        ]);
    }

    /**
     * Portal "Check Status" tab: public lookup, name masked. Reference
     * numbers are sequential, so the last 4 digits of the mobile number on
     * the application are required too, and failed lookups are limited per
     * IP -- the list of applicants can't be walked through.
     */
    public function publicStatus(): void
    {
        $query = is_string($_GET['ref'] ?? null) ? mb_substr(trim($_GET['ref']), 0, 40) : '';
        $mobile4 = is_string($_GET['m4'] ?? null) ? trim($_GET['m4']) : '';
        $result = null;
        $error = '';
        if ($query !== '' || $mobile4 !== '') {
            $limiter = new RateLimiter($this->pdo);
            if (!preg_match('/^\d{4}$/', $mobile4) || $query === '') {
                $error = 'Enter your reference number and the last 4 digits of the mobile number on your application.';
            } elseif ($limiter->tooMany('status_ip', RateLimiter::clientIp())) {
                $error = 'Too many unsuccessful lookups. Please try again in 15 minutes.';
            } else {
                $app = (new Application($this->pdo))->findByReference($query);
                if ($app && hash_equals(substr(preg_replace('/\D/', '', (string) $app['mobile']), -4), $mobile4)) {
                    $result = $app;
                } else {
                    $limiter->hit('status_ip', RateLimiter::clientIp());
                    $error = 'No application matches that reference number and mobile number.';
                }
            }
        }
        $this->render('views/home/status.php', [
            'query' => $query,
            'mobile4' => $mobile4,
            'result' => $result,
            'error' => $error,
        ]);
    }

    /**
     * slip.php?ref=: the printable QR application slip, for the applicant who
     * owns it or any staff member. POST action=replace_slip replaces a lost
     * slip (the old QR stops working).
     */
    public function slip(): void
    {
        $applications = new Application($this->pdo);
        $ref = is_string($_GET['ref'] ?? null) ? trim($_GET['ref']) : '';
        $app = $applications->findByReferenceWithCategory($ref);
        $auth = new AuthService($this->pdo);
        $user = $auth->currentUser();
        $staff = $auth->currentStaff();

        // Same answer whether the reference doesn't exist or isn't yours, so references can't be probed.
        if (!$app || (!($user && (int) $user['id'] === (int) $app['user_id']) && !$staff)) {
            if (!$user && !$staff) {
                $this->redirectTo('portal.php?tab=login');
            }
            http_response_code(404);
            $this->renderPage('Application not found', 'views/applicant/qr/slip-not-found.php');
            return;
        }

        $service = new QrSlipService($this->pdo);
        $slipUrl = 'slip.php?ref=' . urlencode($app['reference_no']);
        // Replacing the QR invalidates the old slip: the owner may, and MSWD staff (application processing) may.
        // Administrators only monitor applications, so they can view and print but not replace.
        $isOwner = $user && (int) $user['id'] === (int) $app['user_id'];
        $canReplace = $isOwner || can($staff, 'applications.process');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            if (!$isOwner) {
                $this->authorize($staff, 'applications.process');
            }
            if (($_POST['action'] ?? '') === 'replace_slip' && $app['status'] !== 'Rejected') {
                $service->replace($app, $isOwner ? $user : $staff);
                flash('A new QR code was issued. The old slip no longer works — print or save this one.');
            }
            $this->redirectTo($slipUrl);
        }

        $slip = $service->slipFor($app);
        $appointment = (new Appointment($this->pdo))->interviewFor((int) $app['id']);
        $renewalOf = $app['renewal_of_id'] ? $applications->findWithChildren((int) $app['renewal_of_id']) : null;
        $this->renderPage('Application Slip — ' . $app['reference_no'], 'views/applicant/qr/application-slip.php', [
            'app' => $app,
            'slip' => $slip,
            'qrUri' => $slip ? QrSlipService::qrImage($slip['token']) : null,
            'slipCode' => $slip ? QrSlipService::slipCode($slip['token']) : null,
            'appointment' => $appointment && $appointment['status'] === 'BOOKED' ? $appointment : null,
            'renewalOfRef' => $renewalOf['reference_no'] ?? null,
            'flashMessage' => take_flash(),
            'user' => $user,
            'staff' => $staff,
            'canReplace' => $canReplace,
        ]);
    }
    /** Full page wrapped in the site header/footer (the header reads $pageTitle/$user/$staff from scope). */
    private function renderPage(string $pageTitle, string $view, array $data = []): void
    {
        $this->render('views/layouts/page.php', array_merge($data, ['pageTitle' => $pageTitle, 'contentView' => $view]));
    }
}
