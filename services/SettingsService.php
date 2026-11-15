<?php
require_once __DIR__ . '/AppointmentService.php';
require_once __DIR__ . '/SMSService.php';

/**
 * Administrator system settings (system_settings table, defined in
 * config/settings.php): basic system information, document limits,
 * appointments, ID validity and renewal, and notification settings
 * (including which SMS messages are sent, stored on sms_templates).
 *
 * Each section of the settings page is saved on its own and validated
 * strictly against the registry; nothing is written unless the whole
 * section is valid. Every change is audit-logged with old and new values.
 * Only administrators (settings.manage) reach this service.
 */
class SettingsService
{
    /** The password reset code can never be switched off -- applicants would be locked out. */
    public const ALWAYS_ON_TEMPLATES = ['password_reset'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** section => key => current value (string). */
    public function current(): array
    {
        $values = [];
        foreach (SETTINGS_REGISTRY as $section => $def) {
            foreach ($def['keys'] as $key => $_) {
                $values[$section][$key] = setting($key);
            }
        }
        return $values;
    }

    /** SMS messages with their on/off state: key => [label, enabled, locked]. */
    public function smsTemplates(): array
    {
        $enabled = $this->pdo->query('SELECT template_key, is_enabled FROM sms_templates')->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach (SMSService::TEMPLATES as $key => [$label]) {
            $locked = in_array($key, self::ALWAYS_ON_TEMPLATES, true);
            $out[$key] = [$label, $locked || (int) ($enabled[$key] ?? 1) === 1, $locked];
        }
        return $out;
    }

    /** The largest upload PHP itself accepts, in MB (upload_max_filesize and post_max_size). */
    public static function serverUploadLimitMb(): float
    {
        $toMb = function (string $v): float {
            $n = (float) $v;
            return match (strtolower(substr(trim($v), -1))) {
                'g' => $n * 1024,
                'k' => $n / 1024,
                'm' => $n,
                default => $n / 1048576,
            };
        };
        $limits = array_filter([$toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size'))], fn($x) => $x > 0);
        return $limits ? min($limits) : 2.0;
    }

    /**
     * Validates and saves one section. Returns [error, success]; on error
     * nothing in the section is saved.
     *
     * @return array{0: string, 1: string}
     */
    public function saveSection(array $admin, string $section, array $input): array
    {
        if (!isset(SETTINGS_REGISTRY[$section])) {
            return ['Unknown settings section.', ''];
        }
        $new = [];
        foreach (SETTINGS_REGISTRY[$section]['keys'] as $key => $def) {
            [$error, $value] = $this->parse($key, $def, $input[$key] ?? null);
            if ($error) {
                return [$def['label'] . ': ' . $error, ''];
            }
            $new[$key] = $value;
        }
        if ($error = $this->crossCheck($section, $new)) {
            return [$error, ''];
        }

        $changes = [];
        foreach ($new as $key => $value) {
            $old = setting($key);
            if ($old !== $value) {
                $changes[] = $key . ': ' . ($old === '' ? '(empty)' : $old) . ' → ' . $value;
            }
        }
        $templateChanges = $section === 'notifications' ? $this->templateChanges($input['sms_template'] ?? []) : [];

        if (!$changes && !$templateChanges) {
            return ['', 'No changes to save.'];
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($new as $key => $value) {
                set_setting($this->pdo, $key, $value);
            }
            foreach ($templateChanges as $key => $on) {
                $this->pdo->prepare('UPDATE sms_templates SET is_enabled = ? WHERE template_key = ?')->execute([$on ? 1 : 0, $key]);
                $changes[] = 'SMS "' . SMSService::TEMPLATES[$key][0] . '": ' . ($on ? 'on' : 'off');
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        audit_log($this->pdo, (int) $admin['id'], $admin['full_name'], $admin['role'], 'System Settings Updated', 'settings', null,
            SETTINGS_REGISTRY[$section]['title'] . ' — ' . implode('; ', $changes));
        return ['', SETTINGS_REGISTRY[$section]['title'] . ' saved (' . count($changes) . ' change' . (count($changes) === 1 ? '' : 's') . ').'];
    }

    /** [error or null, normalised value as stored]. */
    private function parse(string $key, array $def, mixed $raw): array
    {
        if ($def['type'] === 'dates') {
            if ($raw === null) {
                return [null, get_setting($this->pdo, $key, '')];
            }
            if (!is_string($raw) || strlen($raw) > $def['max']) {
                return ['enter dates as YYYY-MM-DD, separated by commas (up to ' . $def['max'] . ' characters).', ''];
            }
            $dates = InterviewCalendarService::dates($raw);
            foreach ($dates as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    return ['enter valid dates as YYYY-MM-DD, separated by commas.', ''];
                }
            }
            $dates = array_values(array_unique($dates));
            sort($dates);
            return [null, implode(',', $dates)];
        }
        if ($def['type'] === 'bool') {
            return [null, !empty($raw) ? '1' : '0'];
        }
        if ($def['type'] === 'list') {
            $picked = is_array($raw) ? array_map('strval', $raw) : [];
            $values = array_values(array_filter(array_keys($def['options']), fn($o) => in_array((string) $o, $picked, true)));
            if (!$values) {
                return ['choose at least one.', ''];
            }
            if (count($picked) !== count(array_unique($picked)) || array_diff($picked, array_map('strval', array_keys($def['options'])))) {
                return ['contains a value that is not allowed.', ''];
            }
            return [null, implode(',', $values)];
        }
        if (!is_string($raw)) {
            return ['is required.', ''];
        }
        $value = trim($raw);
        if ($value === '') {
            return ['is required.', ''];
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return ['contains characters that are not allowed.', ''];
        }
        switch ($def['type']) {
            case 'int':
                if (!preg_match('/^-?\d{1,6}$/', $value)) {
                    return ['enter a whole number.', ''];
                }
                if ((int) $value < $def['min'] || (int) $value > $def['max']) {
                    return ['must be between ' . $def['min'] . ' and ' . $def['max'] . '.', ''];
                }
                return [null, (string) (int) $value];
            case 'decimal':
                if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value)) {
                    return ['enter a number such as 5 or 2.5.', ''];
                }
                $n = (float) $value;
                $max = $def['max'];
                if ($key === 'document_max_size_mb') {
                    $max = min($max, self::serverUploadLimitMb());
                }
                if ($n < $def['min'] || $n > $max) {
                    return ['must be between ' . $def['min'] . ' and ' . $max . ($max < $def['max'] ? ' (the server accepts uploads up to ' . $max . ' MB)' : '') . '.', ''];
                }
                if (isset($def['step']) && fmod($n, $def['step']) > 1e-9) {
                    return ['use steps of ' . $def['step'] . '.', ''];
                }
                return [null, rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.')];
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    return ['enter a time as HH:MM (24-hour).', ''];
                }
                return [null, $value];
            case 'email':
                if (mb_strlen($value) > $def['max'] || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ['enter a valid email address.', ''];
                }
                return [null, $value];
            case 'phone':
                if (mb_strlen($value) > $def['max'] || !preg_match('/^[0-9+()\- ]{5,}$/', $value)) {
                    return ['use digits, spaces, +, - and parentheses only.', ''];
                }
                return [null, $value];
            default: // text
                if (mb_strlen($value) > $def['max']) {
                    return ['must be ' . $def['max'] . ' characters or fewer.', ''];
                }
                return [null, $value];
        }
    }

    /** Rules that involve more than one setting. */
    private function crossCheck(string $section, array $new): ?string
    {
        if ($section === 'appointments') {
            $blockedDates = array_unique(array_merge(InterviewCalendarService::dates($new['appointment_holiday_dates']), InterviewCalendarService::dates($new['appointment_closure_dates'])));
            if ($blockedDates) {
                $conflicts = $this->pdo->prepare("SELECT COUNT(*) FROM appointments WHERE status = 'BOOKED' AND scheduled_at >= NOW() AND DATE(scheduled_at) IN (" . implode(',', array_fill(0, count($blockedDates), '?')) . ')');
                $conflicts->execute(array_values($blockedDates));
                $count = (int) $conflicts->fetchColumn();
                if ($count > 0) {
                    return 'Cancel or reschedule ' . $count . ' booked interview(s) on the selected holiday or closure dates before saving. No settings were changed.';
                }
            }
            if ($new['appointment_day_end'] <= $new['appointment_day_start']) {
                return 'The latest interview end must be after the earliest interview start.';
            }
        }
        if ($section === 'renewals' && (int) $new['renewal_reminder_days'] >= (int) $new['id_validity_years'] * 365) {
            return 'The renewal reminder period must be shorter than the ID validity.';
        }
        return null;
    }

    /** key => new on/off for SMS messages whose switch changed (always-on messages can't be turned off). */
    private function templateChanges(mixed $submitted): array
    {
        $submitted = is_array($submitted) ? $submitted : [];
        $changes = [];
        foreach ($this->smsTemplates() as $key => [, $enabled, $locked]) {
            $on = $locked || !empty($submitted[$key]);
            if ($on !== $enabled) {
                $changes[$key] = $on;
            }
        }
        return $changes;
    }

    /** Last 10 appointment reminders sent. */
    public function recentAppointmentReminders(): array
    {
        return $this->pdo->query(
            'SELECT i.reminder_sent_at, a.reference_no, a.full_name, i.scheduled_at
             FROM appointments i JOIN applications a ON a.id = i.application_id
             WHERE i.reminder_sent_at IS NOT NULL
             ORDER BY i.reminder_sent_at DESC LIMIT 10'
        )->fetchAll();
    }
}
