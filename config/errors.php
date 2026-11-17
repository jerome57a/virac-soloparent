<?php
/**
 * Global error handling. Every PHP error and uncaught exception is written
 * to storage/logs/php-error.log (<TEST_STORAGE_ROOT>/logs when APP_ENV=testing). In the browser, users get a plain
 * "something went wrong" page -- never stack traces, SQL, or file paths --
 * unless APP_DEBUG is on.
 */
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
// Testing: LOG_PATH is <TEST_STORAGE_ROOT>/logs, already validated by config/storage.php. If it is not usable, stop
// rather than let PHP fall back to its default log (which could be a production log).
if (STORAGE_IS_TEST_ROOT && (!storage_root_still_safe() || ini_set('error_log', LOG_PATH . '/php-error.log') === false)) {
    throw new RuntimeException('The test log folder is not usable; refusing to run without isolated logs.');
}
if (!STORAGE_IS_TEST_ROOT && (is_dir(LOG_PATH) || @mkdir(LOG_PATH, 0750, true))) {
    ini_set('error_log', LOG_PATH . '/php-error.log');
}

set_exception_handler(function (Throwable $e): void {
    error_log(sprintf('Uncaught %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    render_error_page(500, APP_DEBUG ? (string) $e : null);
});

/** Discards partial output and shows the standalone error page (no DB or session needed). */
function render_error_page(int $status, ?string $debugDetail = null): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $debugDetail ?? "Error $status\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
    }
    require APP_ROOT . '/views/errors/error.php';
    exit;
}
