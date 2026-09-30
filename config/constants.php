<?php
/**
 * App-wide constants (moved out of config/config.php, which now only
 * handles bootstrapping -- output buffering, BASE_URL detection, session
 * setup). Everything here is a plain define(), same as before.
 */

define('APP_NAME', 'Virac Solo Parent ID Online Portal');
define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage/uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5MB
define('ALLOWED_UPLOAD_EXT', ['jpg', 'jpeg', 'png', 'pdf']);
define('ALLOWED_UPLOAD_MIME', ['image/jpeg', 'image/png', 'application/pdf']);

// Profile photos: smaller size cap, images only, stored separately from application documents.
define('AVATAR_STORAGE_PATH', APP_ROOT . '/storage/avatars');
define('MAX_AVATAR_BYTES', 2 * 1024 * 1024); // 2MB
define('ALLOWED_AVATAR_EXT', ['jpg', 'jpeg', 'png']);
define('ALLOWED_AVATAR_MIME', ['image/jpeg', 'image/png']);

// A Solo Parent ID is valid for one year from release (RA 8972/11861), then must be renewed.
define('ID_VALIDITY_YEARS', 1);

// SMS provider (Semaphore PH). Leave SMS_API_KEY blank to run in "log only" mode.
define('SMS_PROVIDER', 'semaphore');
define('SMS_API_KEY', '');
define('SMS_SENDER_NAME', 'VIRACMSWD');
