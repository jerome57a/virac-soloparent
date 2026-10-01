<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/EligibilityService.php';
require_once __DIR__ . '/../services/AuthService.php';

/**
 * eligibility.php -- the public, no-login Eligibility Checker.
 * GET shows the questions; POST stores the answers as this visitor's
 * preliminary assessment and redirects to ?result=1, so the result page
 * can be refreshed or revisited without resubmitting.
 */
class PublicEligibilityController extends Controller
{
    private EligibilityService $eligibility;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->eligibility = new EligibilityService($pdo);
    }

    public function handle(?array $user): void
    {
        $error = '';
        $answers = ['resident' => '', 'circ' => '', 'child' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $answers = $this->eligibility->normalizeAnswers($_POST);
            if (!$this->eligibility->missingAnswers($answers)) {
                $this->eligibility->record($answers, $user ? (int) $user['id'] : null);
                $this->redirectTo('eligibility.php?result=1');
            }
            $error = 'Please answer all three questions to see your result.';
        } else {
            $current = $this->eligibility->current();
            if (isset($_GET['result'])) {
                if (!$current) {
                    $this->redirectTo('eligibility.php');
                }
                $this->render('views/home/eligibility-result.php', $current + [
                    'loggedIn' => $user !== null,
                    'applyUrl' => base_url('portal.php?tab=apply'),
                    'restartUrl' => base_url('eligibility.php'),
                ]);
                return;
            }
            $answers = $current['answers'] ?? $answers;
        }

        $this->render('views/home/eligibility.php', [
            'answers' => $answers,
            'error' => $error,
            'circumstances' => EligibilityService::circumstances(),
            'noneOption' => EligibilityService::NONE_OPTION,
            'formAction' => base_url('eligibility.php'),
        ]);
    }
}
