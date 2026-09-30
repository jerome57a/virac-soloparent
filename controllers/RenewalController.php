<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/RenewalService.php';

/** Dashboard "ID Renewal" section: ?type=new (request form), status, or history. */
class RenewalController extends Controller
{
    private const TYPE_TITLES = ['new' => 'Renew ID', 'status' => 'Renewal Status', 'history' => 'Renewal History'];

    public function handle(array $user, array $applications): array
    {
        $type = (string) ($_GET['type'] ?? '');
        if (!isset(self::TYPE_TITLES[$type])) {
            $type = 'new';
        }
        $renewals = array_values(array_filter($applications, fn($a) => $a['type'] === 'renewal'));

        if ($type === 'status') {
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/status.php', 'data' => ['latestRenewal' => $renewals[0] ?? null]];
        }
        if ($type === 'history') {
            return ['title' => self::TYPE_TITLES[$type], 'view' => 'renewal/history.php', 'data' => ['renewalHistory' => $renewals]];
        }
        return ['title' => self::TYPE_TITLES[$type]] + $this->requestForm($user);
    }

    private function requestForm(array $user): array
    {
        $service = new RenewalService($this->pdo);
        $source = $service->sourceApplication($user);
        $renewalError = null;

        if ($source && $_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $draft = $service->getDraft();
            $action = (string) ($_POST['renewal_action'] ?? '');

            if ($action === 'upload_doc') {
                $service->saveDraft($service->files()->attachToDraft($draft, (string) ($_POST['doc_key'] ?? ''), $_FILES['file'] ?? [], $service->tmpDir()));
                $this->redirectTo('applicant/dashboard.php?section=renewal&type=new');
            } elseif ($action === 'remove_doc') {
                $service->saveDraft($service->files()->detachFromDraft($draft, (string) ($_POST['doc_key'] ?? '')));
                $this->redirectTo('applicant/dashboard.php?section=renewal&type=new');
            } elseif ($action === 'submit') {
                $errors = $service->validate($draft, !empty($_POST['consent']));
                if ($errors) {
                    $draft['docErrors'] = $errors;
                    $service->saveDraft($draft);
                    $renewalError = 'Please attach all required documents and confirm to submit.';
                } else {
                    try {
                        $refNumber = $service->submit($user, $source, $draft['documents']);
                        $this->redirectTo('slip.php?ref=' . urlencode($refNumber));
                    } catch (Throwable $e) {
                        $renewalError = 'Something went wrong while submitting your renewal. Please try again.';
                    }
                }
            }
        }

        return ['view' => 'renewal/index.php', 'data' => [
            'renewalSource' => $source,
            'renewalError' => $renewalError,
            'renewalDraft' => $service->getDraft(),
            'renewalDocDefs' => $service->documentRequirements(),
        ]];
    }
}
