<?php
/**
 * Auth-policy constants, used by services/AuthService.php. Moved out of
 * includes/functions.php so auth policy lives in one obvious place.
 */

// Brute-force lockout: this many failed attempts locks the account for
// this many minutes, applicant and staff login alike.
const LOGIN_LOCKOUT_THRESHOLD = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

// Password reset code: how long a texted reset code stays valid.
const PASSWORD_RESET_CODE_MINUTES = 15;
