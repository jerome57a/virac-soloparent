<?php
/**
 * "What do I do next?" for the MSWDO staff and admin screens. Presentation logic only (no writes):
 *   - applicationGuide(): the step tracker of one application (Review > Verify documents > Interview > Decision > ID release) and the single
 *     next action, so a staff member who has never used the system always sees what to do and where the button is;
 *   - adminSetupChecklist(): the one-time setup of the system for a new administrator (accounts, requirements, SMS, renewal days, test text).
 */
class StaffGuideService
{
    public const STEPS = [
        'review'    => ['Review', 'file-text'],
        'verify'    => ['Verify documents', 'file-check'],
        'interview' => ['Interview', 'calendar-check'],
        'decision'  => ['Decision', 'clipboard-check'],
        'release'   => ['ID release', 'id-card'],
    ];

    /**
     * @param array       $app          the application row (status, renewal_simplified)
     * @param ?array      $interview    the application's appointment row, or null
     * @param int         $outstanding  required documents not yet verified (or expired)
     * @return array{steps: list<array{key:string,label:string,icon:string,state:string}>, next: ?array{title:string,text:string,label:string,href:string,icon:string,tone:string}, closed: ?string}
     */
    public static function applicationGuide(array $app, ?array $interview, int $outstanding): array
    {
        $status = (string) ($app['status'] ?? '');
        $keys = array_keys(self::STEPS);
        if (!empty($app['renewal_simplified'])) {
            $keys = array_values(array_diff($keys, ['interview']));
        }
        if ($status === 'Rejected') {
            return ['steps' => [], 'next' => null, 'closed' => 'This application was rejected. No further step is needed.'];
        }

        $next = fn(string $title, string $text, string $label, string $href, string $icon, string $tone = 'navy') => compact('title', 'text', 'label', 'href', 'icon', 'tone');
        $current = 'review';
        $action = null;

        if ($status === 'ID Released') {
            $current = '';
            $action = $next('All done', 'The ID has been released. No further step is needed.', '', '', 'circle-check', 'green');
        } elseif ($status === 'Approved') {
            $current = 'release';
            $action = $next('Next step: release the ID', 'The application is approved. Set the ID release and hand the ID to the Solo Parent.', 'Open ID release', '#id-release', 'id-card');
        } elseif ($status === 'Pending Review') {
            $current = 'review';
            $action = $next('Next step: check the documents', 'Open each document, then mark it Verified or ask for a correction.', 'Open the documents', '#checklist', 'file-check');
        } elseif ($outstanding > 0) {
            $current = 'verify';
            $action = $status === 'For Revision'
                ? $next('Waiting for the Solo Parent', 'Corrections were requested. You will be alerted when the Solo Parent sends the documents again.', 'Review the documents', '#checklist', 'clock', 'amber')
                : $next('Next step: verify the documents', 'Required documents still to verify: ' . $outstanding, 'Open the documents', '#checklist', 'file-check');
        } elseif (in_array('interview', $keys, true) && !Appointment::interviewSuccessful($interview)) {
            $current = 'interview';
            $state = $interview['status'] ?? '';
            if ($interview === null || $state === 'CANCELLED') {
                $action = $next('Next step: schedule the interview', 'All required documents are verified.', 'Schedule the interview', '#schedule-interview', 'calendar-plus');
            } elseif ($state === 'MISSED') {
                $action = $next('The Solo Parent missed the interview', 'Schedule another interview date.', 'Schedule another interview', '#schedule-interview', 'calendar-plus', 'amber');
            } elseif (Appointment::interviewOutcome($interview) === 'Unsuccessful') {
                $action = $next('The interview was unsuccessful', 'Request corrections, schedule another interview, or reject.', 'Open the decision', '#decision', 'clipboard-check', 'amber');
            } else {
                $action = $next('Next step: record the interview result', 'When the interview has taken place, record who attended and the result.', 'Record the result', '#decision', 'clipboard-check');
            }
        } else {
            $current = 'decision';
            $action = $next('Next step: approve or reject', 'The documents are verified' . (in_array('interview', $keys, true) ? ' and the interview was successful.' : '.'), 'Open the decision', '#decision', 'clipboard-check');
        }

        $steps = [];
        $reached = $current === '';
        foreach ($keys as $key) {
            if ($current === '') {
                $state = 'done';
            } elseif ($key === $current) {
                $state = 'now';
                $reached = true;
            } else {
                $state = $reached ? 'todo' : 'done';
            }
            $steps[] = ['key' => $key, 'label' => self::STEPS[$key][0], 'icon' => self::STEPS[$key][1], 'state' => $state];
        }
        return ['steps' => $steps, 'next' => $action, 'closed' => null];
    }

    /**
     * The setup of the system, once: each step is ['label','hint','done','href','icon'].
     * @return array{steps: list<array>, done: int, total: int}
     */
    public static function adminSetupChecklist(PDO $pdo): array
    {
        $count = function (string $sql) use ($pdo): int {
            try {
                return (int) $pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                return 0;
            }
        };
        $smsReady = defined('SMS_PROVIDER') && SMS_PROVIDER === 'iprog' && defined('SMS_API_KEY') && trim((string) SMS_API_KEY) !== '';
        $steps = [
            ['label' => 'Staff accounts created', 'hint' => 'User Management: add the MSWDO staff who will process applications.', 'icon' => 'users',
             'done' => $count("SELECT COUNT(*) FROM users WHERE is_active = 1 AND role NOT IN ('applicant', 'admin')") > 0, 'href' => base_url('admin/users.php')],
            ['label' => 'Document requirements set', 'hint' => 'System Settings: choose the documents a Solo Parent must upload.', 'icon' => 'list-checks',
             'done' => $count('SELECT COUNT(*) FROM document_requirements WHERE is_required = 1') > 0, 'href' => base_url('admin/requirements.php')],
            ['label' => 'SMS provider connected', 'hint' => 'The text messages are only sent when the SMS provider and its token are set on the server.', 'icon' => 'message-square',
             'done' => $smsReady, 'href' => base_url('admin/settings.php#server')],
            ['label' => 'Renewal period checked', 'hint' => 'System Settings: confirm how long after the ID expires a Solo Parent can still renew.', 'icon' => 'refresh-cw',
             'done' => $count("SELECT COUNT(*) FROM system_settings WHERE setting_key = 'renewal_grace_days'") > 0, 'href' => base_url('admin/settings.php')],
            ['label' => 'A text message was sent', 'hint' => 'SMS Management: confirm that a real text reaches a phone.', 'icon' => 'send',
             'done' => $count("SELECT COUNT(*) FROM sms_logs WHERE status = 'SENT'") > 0, 'href' => base_url('admin/sms.php')],
        ];
        return ['steps' => $steps, 'done' => count(array_filter($steps, fn($s) => $s['done'])), 'total' => count($steps)];
    }
}
