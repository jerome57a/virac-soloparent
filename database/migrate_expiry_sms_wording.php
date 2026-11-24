<?php
/**
 * Updates the stored ID-expiry SMS templates to the wording used since renewal opens on the expiration date.
 *
 *   php database/migrate_expiry_sms_wording.php          show what would change (changes nothing)
 *   php database/migrate_expiry_sms_wording.php apply    make the changes
 *
 * Only a text that still holds the previous built-in wording word for word is replaced; a template an administrator edited by hand is left
 * alone. Safe to run again. This tool changes the database named in config/local.php when you use "apply".
 */
if (PHP_SAPI !== 'cli') {
    exit('Run this from the command line.');
}
chdir(__DIR__ . '/..');
require 'config/database.php';
require 'includes/functions.php';
require_once 'services/ExpirySmsWording.php';

$apply = ($argv[1] ?? '') === 'apply';
foreach (ExpirySmsWording::migrate($pdo, $apply) as $key => $state) {
    echo str_pad($key, 20), ($apply ? '' : '[would] '), $state, "\n";
}
echo $apply ? "\nDone.\n" : "\nNothing was changed. Run with \"apply\" to make these changes.\n";
