<?php
require_once __DIR__ . '/../models/Notification.php';

/**
 * In-app notifications for every role.
 *
 * Applicants hear about their own applications (submitted, documents need
 * correction, appointment confirmed/cancelled, approved, rejected, renewal
 * reminders). MSWD staff hear about new work (new
 * applications and renewals, re-uploaded documents, appointments booked or
 * cancelled by applicants). Administrators hear about system problems
 * (failed scheduled jobs, locked accounts).
 *
 * Each notification may carry an in-app link (validated: only paths inside
 * this app, never an external URL); opening a notification marks it read.
 */
class NotificationService
{
    /** type => [label, icon] -- unknown types still display, with a bell. */
    public const TYPES = [
        'registration' => ['Welcome', '👋'],
        'submission' => ['Submitted', '📨'],
        'correction' => ['Needs correction', '⚠️'],
        'appointment_confirmation' => ['Appointment', '📅'],
        'appointment_reminder' => ['Appointment reminder', '⏰'],
        'approval' => ['Approved', '✅'],
        'rejection' => ['Not approved', '❌'],
        'status_change' => ['Status update', '🔄'],
        'renewal_reminder' => ['Renewal reminder', '🔔'],
        'new_application' => ['New application', '📥'],
        'new_renewal' => ['New renewal', '♻️'],
        'document_resubmitted' => ['Document re-uploaded', '📎'],
        'corrections_submitted' => ['Corrections submitted', '🔁'],
        'appointment_update' => ['Appointment update', '📅'],
        'office_arrival' => ['Arrived at the office', '🏢'],
        'job_failed' => ['Scheduled job problem', '🛠️'],
        'security_alert' => ['Security alert', '🔒'],
    ];
    public const PER_PAGE = 20;

    /** Types that ask MSWDO staff or the admin to do something ("Needs your action"); every other type is for information. */
    public const ACTION_TYPES = ['new_application', 'new_renewal', 'document_resubmitted', 'corrections_submitted', 'appointment_update', 'office_arrival', 'job_failed', 'security_alert'];

    /** Where on the staff application page each type opens (older notifications without an anchor get it too). */
    public const STAFF_ANCHORS = ['new_application' => 'checklist', 'new_renewal' => 'checklist', 'document_resubmitted' => 'checklist',
        'corrections_submitted' => 'checklist', 'appointment_update' => 'interview', 'office_arrival' => 'decision'];

    public static function isAction(array $n): bool
    {
        return in_array($n['type'] ?? '', self::ACTION_TYPES, true);
    }
    private const LINK_PREFIXES = ['applicant/', 'staff/', 'admin/', 'portal.php'];

    private PDO $pdo;
    private Notification $notifications;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->notifications = new Notification($pdo);
    }

    /** Sends one notification. Returns its id. */
    public function notify(int $userId, string $type, string $message, ?int $applicationId = null, ?string $link = null): int
    {
        return create_notification($this->pdo, $userId, $type, $message, $applicationId, $link);
    }

    /** Sends the same notification to every active user with one of $roles. Returns how many were sent. */
    public function notifyRoles(array $roles, string $type, string $message, ?int $applicationId = null, ?string $link = null, ?int $exceptUserId = null): int
    {
        $count = 0;
        foreach ($this->notifications->activeUserIds($roles) as $userId) {
            if ($exceptUserId !== null && (int) $userId === $exceptUserId) {
                continue; // the staff member who did it does not need to be told
            }
            $this->notify($userId, $type, $message, $applicationId, $link);
            $count++;
        }
        return $count;
    }

    /** MSWD staff (who process applications) get new work to act on. $anchor: the part of the application page it opens (e.g. doc-12). */
    public function notifyStaff(string $type, string $message, int $applicationId, ?string $anchor = null, ?int $exceptUserId = null): int
    {
        if (setting('notify_staff_new_work') !== '1') {
            return 0;
        }
        $anchor = $anchor !== null && preg_match('/^[a-z][a-z0-9-]{0,40}$/', $anchor) ? $anchor : (self::STAFF_ANCHORS[$type] ?? null);
        return $this->notifyRoles(['mswd_social_worker'], $type, $message, $applicationId, 'staff/application.php?id=' . $applicationId . ($anchor ? '#' . $anchor : ''), $exceptUserId);
    }

    public function notifyAdmins(string $type, string $message, string $link): int
    {
        return $this->notifyRoles(['admin'], $type, $message, null, $link);
    }

    /** A page of $userId's notifications (newest first), with the pager and unread count. */
    public function page(int $userId, int $page, bool $unreadOnly): array
    {
        $total = $this->notifications->countFor($userId, $unreadOnly);
        $pager = paginate($total, $page, self::PER_PAGE);
        return [
            'rows' => $this->notifications->pageFor($userId, $unreadOnly, $pager['perPage'], $pager['offset']),
            'pager' => $pager,
            'unread' => $this->notifications->unreadCount($userId),
        ];
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    /** Marks one of the user's own notifications read. False if it isn't theirs. */
    public function markRead(int $userId, int $notificationId): bool
    {
        return $this->notifications->markRead($userId, $notificationId);
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllRead($userId);
    }

    /** Opens one of the user's notifications: marks it read and returns where it leads (null if nowhere / not theirs). */
    public function open(int $userId, int $notificationId, string $role): ?string
    {
        $n = $this->notifications->findForUser($userId, $notificationId);
        if (!$n) {
            return null;
        }
        $this->notifications->markRead($userId, $notificationId);
        return self::linkFor($n, $role);
    }

    /**
     * Where a notification leads for a recipient with $role: its stored link
     * if that is a safe in-app path, else a sensible page for its type.
     */
    public static function linkFor(array $n, string $role): ?string
    {
        if ($n['link'] && self::safeLink($n['link'])) {
            // An older staff notification opened the top of the application page: send it to the right card.
            if ($role !== 'applicant' && preg_match('~^staff/application\.php\?id=\d+$~', $n['link']) && isset(self::STAFF_ANCHORS[$n['type']])) {
                return $n['link'] . '#' . self::STAFF_ANCHORS[$n['type']];
            }
            return $n['link'];
        }
        if ($role !== 'applicant') {
            return null;
        }
        $sections = [
            'submission' => 'application', 'approval' => 'application', 'rejection' => 'application', 'status_change' => 'application',
            'correction' => 'documents', 'appointment_confirmation' => 'appointment', 'appointment_reminder' => 'appointment',
            'renewal_reminder' => 'renewal&type=new', 'registration' => 'profile',
        ];
        return isset($sections[$n['type']]) ? 'applicant/dashboard.php?section=' . $sections[$n['type']] : null;
    }

    /** Only relative paths into this app -- no scheme, no host, no protocol-relative "//", no traversal. */
    public static function safeLink(string $link): bool
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:|^//|\\\\|\.\.~i', $link)) {
            return false;
        }
        foreach (self::LINK_PREFIXES as $prefix) {
            if (str_starts_with($link, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function label(string $type): array
    {
        return self::TYPES[$type] ?? [ucfirst(str_replace('_', ' ', $type)), '🔔'];
    }
}
