<?php
/**
 * The icons of the MSWDO staff and admin screens: Lucide (https://lucide.dev, ISC licence), the same 24-grid rounded style as the side
 * menu. ui_icon('file-check') returns an inline <svg> that takes the text colour, so it follows light and dark mode.
 */
function ui_icon_set(): array
{
    static $set = [
        'arrow-right' => '<path d="M5 12h14"/> <path d="m12 5 7 7-7 7"/>',
        'bell' => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/> <path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
        'calendar-check' => '<path d="M8 2v4"/> <path d="M16 2v4"/> <rect width="18" height="18" x="3" y="4" rx="2"/> <path d="M3 10h18"/> <path d="m9 16 2 2 4-4"/>',
        'calendar-plus' => '<path d="M8 2v4"/> <path d="M16 2v4"/> <path d="M21 13V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8"/> <path d="M3 10h18"/> <path d="M16 19h6"/> <path d="M19 16v6"/>',
        'calendar-x' => '<path d="M8 2v4"/> <path d="M16 2v4"/> <rect width="18" height="18" x="3" y="4" rx="2"/> <path d="M3 10h18"/> <path d="m14 14-4 4"/> <path d="m10 14 4 4"/>',
        'calendar' => '<path d="M8 2v4"/> <path d="M16 2v4"/> <rect width="18" height="18" x="3" y="4" rx="2"/> <path d="M3 10h18"/>',
        'chart-column' => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/> <path d="M18 17V9"/> <path d="M13 17V5"/> <path d="M8 17v-3"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
        'chevron-up' => '<path d="m18 15-6-6-6 6"/>',
        'circle-alert' => '<circle cx="12" cy="12" r="10"/> <line x1="12" x2="12" y1="8" y2="12"/> <line x1="12" x2="12.01" y1="16" y2="16"/>',
        'circle-check' => '<circle cx="12" cy="12" r="10"/> <path d="m9 12 2 2 4-4"/>',
        'circle-dot' => '<circle cx="12" cy="12" r="10"/> <circle cx="12" cy="12" r="1"/>',
        'circle-help' => '<circle cx="12" cy="12" r="10"/> <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/> <path d="M12 17h.01"/>',
        'circle-x' => '<circle cx="12" cy="12" r="10"/> <path d="m15 9-6 6"/> <path d="m9 9 6 6"/>',
        'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/> <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/> <path d="m9 14 2 2 4-4"/>',
        'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1"/> <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/> <path d="M12 11h4"/> <path d="M12 16h4"/> <path d="M8 11h.01"/> <path d="M8 16h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/> <polyline points="12 6 12 12 16 14"/>',
        'contact' => '<path d="M16 2v2"/> <path d="M7 22v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/> <path d="M8 2v2"/> <circle cx="12" cy="11" r="3"/> <rect x="3" y="4" width="18" height="18" rx="2"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/> <polyline points="7 10 12 15 17 10"/> <line x1="12" x2="12" y1="15" y2="3"/>',
        'ellipsis' => '<circle cx="12" cy="12" r="1"/> <circle cx="19" cy="12" r="1"/> <circle cx="5" cy="12" r="1"/>',
        'eye-off' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/> <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/> <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/> <path d="m2 2 20 20"/>',
        'file-check' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/> <path d="M14 2v4a2 2 0 0 0 2 2h4"/> <path d="m9 15 2 2 4-4"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/> <path d="M14 2v4a2 2 0 0 0 2 2h4"/> <path d="M10 9H8"/> <path d="M16 13H8"/> <path d="M16 17H8"/>',
        'file-x' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/> <path d="M14 2v4a2 2 0 0 0 2 2h4"/> <path d="m14.5 12.5-5 5"/> <path d="m9.5 12.5 5 5"/>',
        'flag' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/> <line x1="4" x2="4" y1="22" y2="15"/>',
        'hand-helping' => '<path d="M11 12h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 14"/> <path d="m7 18 1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9"/> <path d="m2 13 6 6"/>',
        'house' => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/> <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'id-card' => '<path d="M16 10h2"/> <path d="M16 14h2"/> <path d="M6.17 15a3 3 0 0 1 5.66 0"/> <circle cx="9" cy="11" r="2"/> <rect x="2" y="5" width="20" height="14" rx="2"/>',
        'inbox' => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/> <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'info' => '<circle cx="12" cy="12" r="10"/> <path d="M12 16v-4"/> <path d="M12 8h.01"/>',
        'key-round' => '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/> <circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/> <rect width="7" height="5" x="14" y="3" rx="1"/> <rect width="7" height="9" x="14" y="12" rx="1"/> <rect width="7" height="5" x="3" y="16" rx="1"/>',
        'lightbulb' => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/> <path d="M9 18h6"/> <path d="M10 22h4"/>',
        'list-checks' => '<path d="m3 17 2 2 4-4"/> <path d="m3 7 2 2 4-4"/> <path d="M13 6h8"/> <path d="M13 12h8"/> <path d="M13 18h8"/>',
        'lock-open' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/> <path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/> <path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'message-square-warning' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/> <path d="M12 7v2"/> <path d="M12 13h.01"/>',
        'message-square' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
        'notebook-text' => '<path d="M2 6h4"/> <path d="M2 10h4"/> <path d="M2 14h4"/> <path d="M2 18h4"/> <rect width="16" height="20" x="4" y="2" rx="2"/> <path d="M9.5 8h5"/> <path d="M9.5 12H16"/> <path d="M9.5 16H14"/>',
        'package-check' => '<path d="m16 16 2 2 4-4"/> <path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l2-1.14"/> <path d="m7.5 4.27 9 5.15"/> <polyline points="3.29 7 12 12 20.71 7"/> <line x1="12" x2="12" y1="22" y2="12"/>',
        'phone' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'printer' => '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/> <path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/> <rect x="6" y="14" width="12" height="8" rx="1"/>',
        'refresh-cw' => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/> <path d="M21 3v5h-5"/> <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/> <path d="M8 16H3v5"/>',
        'rotate-cw' => '<path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/> <path d="M21 3v5h-5"/>',
        'scroll-text' => '<path d="M15 12h-5"/> <path d="M15 8h-5"/> <path d="M19 17V5a2 2 0 0 0-2-2H4"/> <path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>',
        'search' => '<circle cx="11" cy="11" r="8"/> <path d="m21 21-4.3-4.3"/>',
        'send' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/> <path d="m21.854 2.147-10.94 10.939"/>',
        'settings' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/> <circle cx="12" cy="12" r="3"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/> <path d="m9 12 2 2 4-4"/>',
        'square-user' => '<rect width="18" height="18" x="3" y="3" rx="2"/> <circle cx="12" cy="10" r="3"/> <path d="M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2"/>',
        'triangle-alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/> <path d="M12 9v4"/> <path d="M12 17h.01"/>',
        'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/> <circle cx="9" cy="7" r="4"/> <polyline points="16 11 18 13 22 9"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/> <circle cx="9" cy="7" r="4"/> <line x1="19" x2="19" y1="8" y2="14"/> <line x1="22" x2="16" y1="11" y2="11"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/> <circle cx="12" cy="7" r="4"/>',
        'users-round' => '<path d="M18 21a8 8 0 0 0-16 0"/> <circle cx="10" cy="8" r="5"/> <path d="M22 20c0-3.37-2-6.5-4-8a5 5 0 0 0-.45-8.3"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/> <circle cx="9" cy="7" r="4"/> <path d="M22 21v-2a4 4 0 0 0-3-3.87"/> <path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'x' => '<path d="M18 6 6 18"/> <path d="m6 6 12 12"/>',
    ];
    return $set;
}

/** An inline icon (decorative: hidden from screen readers). An unknown name returns an empty string. */
function ui_icon(string $name, int $size = 20, string $class = ''): string
{
    $inner = ui_icon_set()[$name] ?? null;
    if ($inner === null) {
        return '';
    }
    return '<svg class="ui-icon' . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" aria-hidden="true" focusable="false" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $inner . '</svg>';
}

/** The icon of each MSWDO staff and admin page (the same one the side menu and the page heading show). */
function ui_page_icon(string $script): string
{
    return [
        'dashboard.php' => 'house', 'index.php' => 'file-text', 'application.php' => 'file-text', 'verification.php' => 'file-check',
        'appointments.php' => 'calendar-check', 'follow_up.php' => 'phone', 'sms.php' => 'message-square-warning', 'applicants.php' => 'user',
        'applicant_view.php' => 'user', 'reports.php' => 'chart-column', 'notifications.php' => 'bell', 'account.php' => 'key-round',
        'applications.php' => 'clipboard-list', 'application_view.php' => 'file-text', 'users.php' => 'users', 'user_edit.php' => 'user',
        'expiry_sms.php' => 'message-square-warning', 'settings.php' => 'settings', 'requirements.php' => 'list-checks',
        'audit_log.php' => 'scroll-text', 'eligibility_logs.php' => 'shield-check',
    ][$script] ?? 'info';
}
