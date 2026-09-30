<?php
/**
 * Standard public-site page: shared header, one content view, portal footer.
 * includes/header.php reads $pageTitle and (optionally) $user/$staff from this scope.
 *
 * @var string $pageTitle
 * @var string $contentView  path relative to APP_ROOT
 */
require APP_ROOT . '/includes/header.php';
require APP_ROOT . '/' . $contentView;
$footerVariant = 'portal';
require APP_ROOT . '/includes/footer.php';
