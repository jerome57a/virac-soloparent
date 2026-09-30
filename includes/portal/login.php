<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../../controllers/AuthController.php';

extract((new AuthController($pdo))->handleApplicantAuth());
$loginTabUrl = base_url('portal.php?tab=login&mode=login');
$signupTabUrl = base_url('portal.php?tab=login&mode=signup');

require __DIR__ . '/../../views/auth/login.php';
