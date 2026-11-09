<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/RenewalService.php';
require_once __DIR__ . '/../services/ApplicationTrackerService.php';

/** Dashboard "ID Renewal" section: ?type=new (the renewal wizard), status, or history. */
class RenewalController extends Controller
{
    private const TYPE_TITLES = ['new' => 'Renew ID', 'status' => 'Renewal Status', 'history' => 'Renewal History'];
    private const URL = 'applicant/dashboard.php?section=renewal&type=new';

    public function handle(array $user, array $applications): array
    {
        $type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
        if (!isset(self::TYPE_TITLES[$type])) {
            $type = 'new';
        }
        $renewals = array_values(array_filter($applications, fn($a) => $a['type'] === 'renewal'));

        if ($type === 'status') {
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/status.php', 'data' => [
                'latestRenewal' => $renewals[0] ?? null,
                'renewalTracker' => isset($renewals[0]) ? (new ApplicationTrackerService($this->pdo))->track($renewals[0]) : null,
            ]];
        }
        if ($type === 'history') {
            $refs = array_column($applications, 'reference_no', 'id');
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/history.php', 'data' => ['renewalHistory' => $renewals, 'referenceById' => $refs]];
        }
        return ['title' => self::TYPE_TITLES[$type]] + $this->wizard($user);
    }

    /** The short renewal form uses the current record and separate category requirements. */
    private function wizard(array $user): array
    {
        $service = new RenewalService($this->pdo);
        $source = $service->sourceApplication($user);
        $inProgress = $source ? $service->renewalInProgress($user) : null;
        $window = $source ? $service->window($source) : null;
        $data = ['renewalSource' => $source, 'renewalInProgress' => $inProgress, 'renewalWindow' => $window];
        if (!$source || $inProgress || !$window['open']) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                csrf_require();
            }
            return ['view' => 'renewal/confirmation.php', 'data' => $data];
        }

        $draft = $service->getDraft($user, $source);
        $errors = [];
        $submitError = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            [$draft, $errors, $submitError] = $this->handleAction($service, $user, $source, $draft);
        }
        $saved = (new ApplicationDraft($this->pdo))->find((int) $user['id'], 'renewal');

        return ['view' => 'renewal/confirmation.php', 'data' => $data + [
            'draft' => $draft,
            'errors' => $errors,
            'submitError' => $submitError,
            'step' => (int) $draft['step'],
            'stepMeta' => RenewalService::STEP_META,
            'docDefs' => $service->documentRequirements($source),
            'renewalPlan' => (new RenewalRequirement($this->pdo))->plan($source),
            'changes' => $service->changes($source, $draft),
            'draftSavedAt' => $saved['updated_at'] ?? null,
            'flashMessage' => take_flash(),
        ]];
    }

    /** A single renewal confirmation form; prior identity and children stay on file. */
    private function handleAction(RenewalService $service, array $user, array $source, array $draft): array
    {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if ($action === 'discard_draft') {
            $service->clearDraft($user);
            flash('Your renewal draft was discarded.');
            $this->redirectTo(self::URL);
        }
        if (!in_array($action, ['save_draft','submit'], true)) {
            return [$draft,['renewal'=>'Use Save draft or Submit Renewal on the renewal form.'],null];
        }
        $draft = $service->collectStepInput(1,$draft,$_POST,$source);
        if ($action === 'save_draft') {
            $service->saveDraft($user,$draft);
            flash('Your renewal draft is saved.');
            $this->redirectTo(self::URL);
        }
        $invalid = $service->firstInvalidStep($draft);
        if ($invalid) {
            $service->saveDraft($user,$draft);
            return [$draft,$invalid['errors'],null];
        }
        try {
            $ref = $service->submit($user,$source,$draft);
            $this->redirectTo('slip.php?ref=' . urlencode($ref));
        } catch (DomainException $e) {
            return [$draft,[], $e->getMessage()];
        } catch (Throwable $e) {
            error_log('Renewal submission failed: ' . $e->getMessage());
            $service->saveDraft($user,$draft);
            return [$draft,[], 'Could not submit your renewal. Your draft is saved; please try again.'];
        }
    }
}
