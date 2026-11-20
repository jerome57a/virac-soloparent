<?php
/**
 * Every administrator-configurable setting (system_settings table), by
 * section of the System Settings page: type, default and allowed range.
 * A setting that was never saved uses its default here. Read values with
 * setting('key') (includes/functions.php); change them only through
 * SettingsService, which validates against this list.
 *
 * Types: text, email, phone, int, decimal, bool, time (HH:MM), list
 * (comma-separated subset of 'options').
 */
const SETTINGS_REGISTRY = [
    'general' => [
        'title' => 'Basic System Information',
        'note' => 'Shown in page titles, the footer, Help, and Solo Parent pages.',
        'keys' => [
            'system_name' => ['label' => 'System name', 'type' => 'text', 'default' => 'Virac Solo Parent ID Online Portal', 'max' => 120],
            'office_name' => ['label' => 'Office name', 'type' => 'text', 'default' => 'MSWDO Virac', 'max' => 100],
            'office_address' => ['label' => 'Office address', 'type' => 'text', 'default' => 'Municipal Building, Salvacion, Virac, Catanduanes, 4800', 'max' => 255],
            'office_hours' => ['label' => 'Office hours', 'type' => 'text', 'default' => 'Monday to Friday, 8:00 AM to 5:00 PM', 'max' => 100],
            'office_email' => ['label' => 'Contact email', 'type' => 'email', 'default' => 'lguvirac2022@gmail.com', 'max' => 150],
            'office_phone' => ['label' => 'Contact phone', 'type' => 'phone', 'default' => '052 740 1512', 'max' => 30],
        ],
    ],
    'documents' => [
        'title' => 'Document Limits',
        'note' => 'Applies to every new upload (applications, corrections and renewals). Files already uploaded are not affected.',
        'keys' => [
            'document_max_size_mb' => ['label' => 'Maximum file size (MB)', 'type' => 'decimal', 'default' => '5', 'min' => 0.5, 'max' => 20, 'step' => 0.5],
            'document_allowed_types' => ['label' => 'Allowed file types', 'type' => 'list', 'default' => 'jpg,png,pdf',
                'options' => ['jpg' => 'JPG / JPEG image', 'png' => 'PNG image', 'pdf' => 'PDF document']],
        ],
    ],
    'appointments' => [
        'title' => 'Appointments',
        'note' => 'Solo Parents choose their own interview day after MSWDO staff send an invitation. These rules decide which days are offered and the hours used for cancellation deadlines.',
        'keys' => [
            'appointment_location' => ['label' => 'Default appointment location', 'type' => 'text', 'default' => 'MSWDO Office, Municipal Building, Salvacion, Virac, Catanduanes', 'max' => 255],
            'appointment_days' => ['label' => 'Days the office holds interviews', 'type' => 'list', 'default' => '1,2,3,4,5',
                'options' => ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri']],
            'appointment_holiday_dates' => ['label' => 'Additional holiday dates', 'type' => 'dates', 'default' => '', 'max' => 2500,
                'help' => 'Add newly declared or local holidays as YYYY-MM-DD, separated by commas. Declared national holidays for 2026–2027 and Catanduanes Foundation Day are already blocked. Add 2027 Eid dates when officially declared.'],
            'appointment_closure_dates' => ['label' => 'Announced office closure dates', 'type' => 'dates', 'default' => '', 'max' => 2500,
                'help' => 'Enter every closed date as YYYY-MM-DD, separated by commas. Include dates from office closure announcements. Cancel or reschedule existing interviews on these dates first.'],
            'appointment_day_end' => ['label' => 'Office closing time', 'type' => 'time', 'default' => '17:00',
                'help' => 'The office closing time. Solo Parents can cancel a booked interview day online until this time on the previous office day.'],
            'appointment_min_notice_hours' => ['label' => 'Minimum notice to cancel online (hours)', 'type' => 'int', 'default' => '2', 'min' => 0, 'max' => 168],
            'interview_default_capacity' => ['label' => 'Default daily interview capacity (Solo Parents per interview day)', 'type' => 'int', 'default' => '0', 'min' => 0, 'max' => 500,
                'help' => 'How many Solo Parents can book each interview day (1 to 500). 0 means not configured: no day can accept bookings until a number is set. It cannot be lowered below the seats already used on an upcoming day that follows it.'],
            'appointment_reminder_hours' => ['label' => 'Send the appointment reminder (hours before)', 'type' => 'int', 'default' => '24', 'min' => 1, 'max' => 168],
        ],
    ],
    'renewals' => [
        'title' => 'ID Validity & Renewal',
        'note' => 'New and renewed IDs expire one year after confirmed release. Previously released IDs keep their recorded expiry date.',
        'keys' => [
            'id_validity_years' => ['label' => 'ID validity (years, fixed)', 'type' => 'int', 'default' => '1', 'min' => 1, 'max' => 1, 'readonly' => true],
            'renewal_reminder_days' => ['label' => 'Renewal reminder period (days before expiry; renewal opens then)', 'type' => 'int', 'default' => '30', 'min' => 1, 'max' => 180],
        ],
    ],
    'notifications' => [
        'title' => 'Notification Settings',
        'note' => 'In-app notifications to Solo Parents are always kept: they are the record of what happened to an application.',
        'keys' => [
            'sms_enabled' => ['label' => 'Send SMS notifications to Solo Parents', 'type' => 'bool', 'default' => '1'],
            'notify_staff_new_work' => ['label' => 'Alert MSWDO staff in-app about new work (new applications and renewals, re-uploaded documents, appointment changes)', 'type' => 'bool', 'default' => '1'],
        ],
    ],
];
