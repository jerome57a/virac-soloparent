<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/ApplicationService.php';
require_once __DIR__ . '/../services/AuthService.php';
require_once __DIR__ . '/../models/Application.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../includes/QrService.php';

class ApplicationController extends Controller
{
    /** Dashboard "My Application" section: every application with its progress tracker. */
    public function statusList(array $user, array $applications): array
    {
        return ['view' => 'application/status.php', 'data' => [
            'myApplications' => $applications,
            'latestRemarksByApp' => (new Application($this->pdo))->latestHistoryByApplication((int) $user['id']),
        ]];
    }

    /** Portal "Apply" tab: the 4-step wizard. Renders into the portal page. */
    public function apply(?array $user): void
    {
        if (!$user) {
            $this->render('views/applicant/application/login-required.php', ['message' => 'to start your application.']);
            return;
        }

        $service = new ApplicationService($this->pdo);
        $draft = $service->getDraft($user);
        $errors = [];
        $submitError = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$draft, $errors, $submitError] = $this->handleApplyAction($service, $user, $draft);
        }

        $step = (int) $draft['step'];
        $documents = new Document($this->pdo);
        $categories = get_categories($this->pdo);
        $categoryMap = array_column($categories, 'name', 'id');
        $categoryDocs = [];
        foreach ($categories as $c) {
            $categoryDocs[$c['id']] = array_column($documents->categoryOnlyRequirements((int) $c['id']), 'label');
        }
        $errCount = count($errors);

        $this->render('views/applicant/application/create.php', [
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
        ]);
    }

    /** @return array{0: array, 1: array, 2: ?string} [draft, errors, submitError] -- redirects on every action that succeeds. */
    private function handleApplyAction(ApplicationService $service, array $user, array $draft): array
    {
        $action = (string) ($_POST['action'] ?? '');
        $step = (int) $draft['step'];
        $applyUrl = 'portal.php?tab=apply';

        switch ($action) {
            case 'continue':
                $draft = $service->collectStepInput($step, $draft, $_POST);
                $errors = $service->validateStep($step, $draft);
                if (!$errors) {
                    $draft['step'] = min(4, $step + 1);
                }
                $service->saveDraft($draft);
                if (!$errors) {
                    $this->redirectTo($applyUrl);
                }
                return [$draft, $errors, null];

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
                $draft = $service->files()->attachToDraft($draft, (string) ($_POST['doc_key'] ?? ''), $_FILES['file'] ?? [], $service->tmpDir());
                break;

            case 'remove_doc':
                $draft = $service->files()->detachFromDraft($draft, (string) ($_POST['doc_key'] ?? ''));
                break;

            case 'submit':
                $draft = $service->collectStepInput(4, $draft, $_POST);
                $invalid = $service->firstInvalidStep($draft);
                if ($invalid) {
                    $draft['step'] = $invalid['step'];
                    $service->saveDraft($draft);
                    return [$draft, $invalid['errors'], null];
                }
                try {
                    $refNumber = $service->submit($user, $draft);
                    $this->redirectTo('slip.php?ref=' . urlencode($refNumber));
                } catch (Throwable $e) {
                    $service->saveDraft($draft);
                    return [$draft, [], 'Something went wrong while submitting your application. Please try again.'];
                }
                return [$draft, [], null];

            default:
                return [$draft, [], null];
        }

        $service->saveDraft($draft);
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

    /** Portal "Check Status" tab: public lookup by reference number, name masked. */
    public function publicStatus(): void
    {
        $query = trim((string) ($_GET['ref'] ?? ''));
        $result = $query !== '' ? (new Application($this->pdo))->findByReference($query) : null;
        $this->render('views/home/status.php', [
            'query' => $query,
            'result' => $result,
            'notFound' => $query !== '' && !$result,
        ]);
    }

    /** slip.php: printable application slip with QR code, for the owner or any staff member. */
    public function slip(): void
    {
        $app = (new Application($this->pdo))->findByReferenceWithCategory(trim((string) ($_GET['ref'] ?? '')));

        if (!$app) {
            http_response_code(404);
            $this->renderPage('Application not found', 'views/applicant/qr/slip-not-found.php');
            return;
        }

        $auth = new AuthService($this->pdo);
        $user = $auth->currentUser();
        $staff = $auth->currentStaff();
        if (!($user && (int) $user['id'] === (int) $app['user_id']) && !$staff) {
            $this->redirectTo('portal.php?tab=login');
        }

        $statusUrl = base_url('portal.php?tab=status&ref=' . urlencode($app['reference_no']));
        $this->renderPage('Application Slip — ' . $app['reference_no'], 'views/applicant/qr/application-slip.php', [
            'app' => $app,
            'statusUrl' => $statusUrl,
            'qrUri' => qr_code_data_uri($statusUrl),
            'user' => $user,
            'staff' => $staff,
        ]);
    }

    /** Full page wrapped in the site header/footer (the header reads $pageTitle/$user/$staff from scope). */
    private function renderPage(string $pageTitle, string $view, array $data = []): void
    {
        $this->render('views/layouts/page.php', array_merge($data, ['pageTitle' => $pageTitle, 'contentView' => $view]));
    }
}
