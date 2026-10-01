<?php
require_once __DIR__ . '/../models/EligibilityAssessment.php';

/**
 * Public, non-binding eligibility pre-check (Section 3, RA 8972 / RA 11861).
 * The result is only PRELIMINARY -- final eligibility is always decided by
 * the MSWD Social Worker during processing.
 *
 * The latest check is stored as a temporary assessment keyed by a random
 * token kept in the visitor's session (the database only ever sees its
 * SHA-256 hash). Logging in or registering links it to the account.
 * Methods that store or load need the PDO; the rules themselves don't.
 */
class EligibilityService
{
    public const NONE_OPTION = 'None of these apply to me';
    /** Anonymous (never-claimed) assessments are deleted after this many days. */
    private const UNCLAIMED_RETENTION_DAYS = 30;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Evaluates, stores as this visitor's preliminary assessment (linked to $userId if logged in), and returns the result. */
    public function record(array $answers, ?int $userId): array
    {
        $result = $this->evaluate($answers);
        $model = new EligibilityAssessment($this->pdo);
        $model->purgeUnclaimed(self::UNCLAIMED_RETENTION_DAYS);
        $model->savePreliminary($this->tokenHash(true), $userId, $answers, $result['eligible'] ? 'LIKELY_ELIGIBLE' : 'NOT_ELIGIBLE', $result['reason']);
        return $result;
    }

    /** This visitor's latest stored check as ['answers', 'result', 'checkedAt'], or null if they haven't checked yet. */
    public function current(): ?array
    {
        $tokenHash = $this->tokenHash(false);
        $row = $tokenHash ? (new EligibilityAssessment($this->pdo))->findByToken($tokenHash) : null;
        return $row ? $this->hydrate($row) : null;
    }

    /** The applicant's latest preliminary check (for their dashboard and staff review), or null. */
    public function latestForUser(int $userId): ?array
    {
        $row = (new EligibilityAssessment($this->pdo))->latestForUser($userId);
        return $row ? $this->hydrate($row) : null;
    }

    /** Called right after an applicant logs in or registers: attaches the check they made while signed out. */
    public function claimForUser(int $userId): void
    {
        $tokenHash = $this->tokenHash(false);
        if ($tokenHash) {
            (new EligibilityAssessment($this->pdo))->claimForUser($tokenHash, $userId);
        }
    }

    private function hydrate(array $row): array
    {
        $answers = $this->normalizeAnswers(json_decode($row['answers'], true) ?: []);
        return [
            'answers' => $answers,
            'result' => ['eligible' => $row['preliminary_result'] === 'LIKELY_ELIGIBLE', 'reason' => $row['result_reason']],
            'checkedAt' => $row['created_at'],
        ];
    }

    /** SHA-256 of this visitor's session token (created on first check), or null if none exists and $create is false. */
    private function tokenHash(bool $create): ?string
    {
        if (empty($_SESSION['eligibility_token'])) {
            if (!$create) {
                return null;
            }
            $_SESSION['eligibility_token'] = bin2hex(random_bytes(32));
        }
        return hash('sha256', $_SESSION['eligibility_token']);
    }

    public static function circumstances(): array
    {
        return [
            ['title' => 'Deceased spouse', 'desc' => 'The other parent has passed away and you are solely responsible for your child/children.'],
            ['title' => 'Abandoned or separated (6+ months)', 'desc' => 'Your spouse has been absent for at least six (6) months and you bear sole parental responsibility.'],
            ['title' => 'Spouse detained or imprisoned (3+ months)', 'desc' => 'Your spouse is serving a sentence and you are the sole caregiver of your child/children.'],
            ['title' => 'Spouse physically or mentally incapacitated', 'desc' => 'Your spouse is unable to fulfill parental responsibilities due to a certified medical condition.'],
            ['title' => 'Legal separation, annulment, or nullity of marriage', 'desc' => 'As decreed by a court or church, with you entrusted with the custody of the child/children.'],
            ['title' => 'Unwed parent', 'desc' => 'An unmarried mother or father who has chosen to keep and raise their child/children.'],
            ['title' => 'Legal guardian', 'desc' => 'Any person who solely provides parental care and support to a minor child or children.'],
            ['title' => 'Spouse is an OFW (RA 11861)', 'desc' => 'Spouse is a low-skilled or semi-skilled overseas Filipino worker who has been abroad for an uninterrupted period of 12 months or more.'],
            ['title' => 'Victim of rape or crimes against chastity', 'desc' => 'A woman who gives birth as a result of rape or similar crimes and keeps and raises the child.'],
        ];
    }

    /** Valid answers for the circumstance question: every listed title plus the "none" option. */
    public function circumstanceOptions(): array
    {
        return array_merge(array_column(self::circumstances(), 'title'), [self::NONE_OPTION]);
    }

    /**
     * Normalizes raw form input to ['resident','circ','child'] with each
     * value either a recognised option or ''. Anything unrecognised is
     * treated as unanswered rather than trusted.
     */
    public function normalizeAnswers(array $input): array
    {
        $yesNo = ['Yes', 'No'];
        $resident = (string) ($input['resident'] ?? '');
        $circ = (string) ($input['circ'] ?? '');
        $child = (string) ($input['child'] ?? '');
        return [
            'resident' => in_array($resident, $yesNo, true) ? $resident : '',
            'circ' => in_array($circ, $this->circumstanceOptions(), true) ? $circ : '',
            'child' => in_array($child, $yesNo, true) ? $child : '',
        ];
    }

    public function missingAnswers(array $answers): bool
    {
        return $answers['resident'] === '' || $answers['circ'] === '' || $answers['child'] === '';
    }

    /**
     * Returns ['eligible' => bool, 'reason' => ?string]. Checks run in the
     * same order as the original client-side checker so the first failing
     * criterion is the one explained.
     */
    public function evaluate(array $answers): array
    {
        if ($answers['resident'] !== 'Yes') {
            return ['eligible' => false, 'reason' => 'The Solo Parent ID from MSWDO Virac is for residents of Virac; apply at the MSWDO of your own municipality.'];
        }
        if ($answers['circ'] === self::NONE_OPTION) {
            return ['eligible' => false, 'reason' => 'Your situation does not match the circumstances listed under RA 8972 / RA 11861.'];
        }
        if ($answers['child'] !== 'Yes') {
            return ['eligible' => false, 'reason' => 'The Solo Parent ID requires that you solely care for a qualifying child.'];
        }
        return ['eligible' => true, 'reason' => null];
    }
}
