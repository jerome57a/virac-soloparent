<?php
/**
 * Copy to config/local.php and change what differs on this machine.
 * Any key left out keeps its default from config/app.php.
 */
return [
    'APP_ENV' => 'local',
    'APP_DEBUG' => false,

    'DB_HOST' => 'localhost',
    'DB_NAME' => 'virac_solo_parent',
    'DB_USER' => 'root',
    'DB_PASS' => '',

    // SMS: 'test' logs messages without sending them; 'iprog' sends through IPROG SMS (put your IPROG API token in SMS_API_KEY).
    'SMS_PROVIDER' => 'test',
    'SMS_API_KEY' => '',
    'SMS_API_URL' => 'https://sms.iprogtech.com/api/v1/sms_messages',
    // Save credits while testing: only these numbers get real texts, and at most this many per day (0 = no limit).
    'SMS_ALLOWED_NUMBERS' => '',
    'SMS_DAILY_LIMIT' => 0,
    'SMS_TIMEOUT' => 10,
    'SMS_MAX_ATTEMPTS' => 3,
];
