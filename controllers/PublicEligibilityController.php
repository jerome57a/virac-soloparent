<?php
require_once __DIR__ . '/Controller.php';
require_once __DIR__ . '/../services/EligibilityService.php';

class PublicEligibilityController extends Controller
{
    private EligibilityService $eligibility;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->eligibility = new EligibilityService();
    }

    /** GET renders the questions; POST evaluates and renders the result (or the form again if incomplete). */
    public function handle(): void
    {
        $answers = ['resident' => '', 'circ' => '', 'child' => ''];
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_require();
            $answers = $this->eligibility->normalizeAnswers($_POST);
            if (!$this->eligibility->missingAnswers($answers)) {
                $this->submit($answers);
                return;
            }
            $error = 'Please answer all three questions to see your result.';
        }

        $this->show($answers, $error);
    }

    private function show(array $answers, string $error): void
    {
        $this->render('views/home/eligibility.php', [
            'answers' => $answers,
            'error' => $error,
            'circumstances' => EligibilityService::circumstances(),
            'noneOption' => EligibilityService::NONE_OPTION,
            'formAction' => base_url('eligibility.php'),
        ]);
    }

    private function submit(array $answers): void
    {
        $this->render('views/home/eligibility-result.php', [
            'answers' => $answers,
            'result' => $this->eligibility->evaluate($answers),
            'applyUrl' => base_url('portal.php?tab=apply'),
            'restartUrl' => base_url('eligibility.php'),
        ]);
    }
}
