<?php
/**
 * Resolves where uploaded documents and avatars live.
 *
 * Production (APP_ENV is anything but 'testing'): APP_ROOT/storage/uploads and APP_ROOT/storage/avatars,
 * exactly as before. TEST_STORAGE_ROOT is ignored.
 *
 * Testing (APP_ENV === 'testing'): a dedicated test directory is REQUIRED, taken from the process environment
 * variable TEST_STORAGE_ROOT only (never config/local.php). Any problem throws; there is no fallback to the
 * production folders. The directory must:
 *   - be an absolute local path with no "." / ".." segments (UNC paths are refused);
 *   - exist, and be neither a symlink/junction nor reached through one (every path component is checked, and the
 *     real path must equal the given path);
 *   - lie outside the application folder entirely (not inside it, not equal to it, not a parent of it), so it can
 *     never be, contain or sit under APP_ROOT/storage and is never web-served;
 *   - lie outside the Apache document root (not inside it, not equal to it, not a parent of it). The document root is
 *     taken from $_SERVER['DOCUMENT_ROOT'] and/or the environment variable TEST_DOCUMENT_ROOT (needed for command-line
 *     runs); if neither yields an existing directory, validation fails. Aliases/virtual hosts that expose other
 *     folders are not visible to PHP, see docs/database-tooling-safety.md;
 *   - contain the marker file ".virac-test-storage" (created by hand), proving it was set aside for tests.
 * Every runtime artifact a test can produce then goes under that root, never under APP_ROOT/storage:
 *   uploads  -> <root>/uploads      avatars -> <root>/avatars     sessions -> <root>/sessions
 *   logs     -> <root>/logs         tmp     -> <root>/tmp         screenshots (Node UI tests) -> <root>/screenshots
 * Each folder is created if missing and re-checked for links. The root must also not equal, contain or sit inside
 * the real location of APP_ROOT/storage or its sessions/logs/uploads/avatars folders, even when APP_ROOT/storage is
 * itself a junction or symlink that points elsewhere.
 * Pure filesystem checks; no database connection.
 *
 * Windows note: PHP's is_link() is not trusted to detect junctions. Every path component is therefore also compared
 * with its realpath() (which resolves junctions, symlinks, 8.3 short names, trailing dots/spaces and mapped drives);
 * any difference refuses the path. This fails closed, so a root reached through a mapped network drive or a SUBST
 * drive is refused too. Remaining limits are listed in docs/database-tooling-safety.md.
 */

const TEST_STORAGE_MARKER = '.virac-test-storage';

function storage_norm_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $path = rtrim($path, '/');
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
}

/** True if $path is $base or lies inside it (both already normalized). */
function storage_path_within(string $path, string $base): bool
{
    return $path === $base || str_starts_with($path . '/', $base . '/');
}

/** Throws unless every existing component of $path (and $path itself) is a real directory entry, not a link. */
function storage_assert_no_link(string $path): void
{
    $parts = explode('/', str_replace('\\', '/', $path)); // "C:/a/b" -> ["C:","a","b"]; "/a/b" -> ["","a","b"]
    $built = (string) array_shift($parts);                // drive letter or empty (filesystem root)
    foreach ($parts as $segment) {
        $built .= '/' . $segment;
        if ($segment === '') {
            continue;
        }
        // is_link() alone is not reliable for NTFS junctions, so the component must also resolve to itself.
        $real = realpath($built);
        if (is_link($built) || $real === false || storage_norm_path($real) !== storage_norm_path($built)) {
            throw new RuntimeException('Test storage path must not be, or pass through, a symbolic link, junction or alias: ' . $built);
        }
    }
}

/**
 * Testing only: true if $dir is the upload or avatar folder, or lies below one, and no EXISTING component below that
 * folder is a link or junction. Outside testing (STORAGE_IS_TEST_ROOT false) it always returns true, so production
 * storage behaviour is unchanged.
 *
 * Components above the validated base folder are deliberately not examined (APP_ROOT/storage or the test root's
 * parents may be legitimate junctions). Each existing component below the base must resolve to itself with
 * realpath(): on the tested PHP 8.2.12 / Windows build realpath() resolves an NTFS junction to its target while
 * is_link() returns false for it (see docs/database-tooling-safety.md), so equality, not is_link() alone, is the
 * test. Components that do not exist yet are allowed, so nested folders can still be created. Hard links, races
 * between check and use, and redirection types other than the tested junction are not covered.
 */
function storage_dir_confined(string $dir): bool
{
    if (!defined('STORAGE_IS_TEST_ROOT') || !STORAGE_IS_TEST_ROOT) {
        return true;
    }
    if ($dir === '' || str_contains($dir, "\0")) {
        return false;
    }
    $slashed = rtrim(str_replace('\\', '/', $dir), '/');
    foreach (explode('/', $slashed) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }
    $target = storage_norm_path($slashed);
    foreach ([STORAGE_PATH, AVATAR_STORAGE_PATH] as $base) {
        $normBase = storage_norm_path((string) $base);
        if (!storage_path_within($target, $normBase)) {
            continue;
        }
        $realBase = realpath((string) $base);
        if ($realBase === false || storage_norm_path($realBase) !== $normBase) {
            return false;
        }
        $built = rtrim(str_replace('\\', '/', (string) $base), '/');
        $relative = trim(substr($target, strlen($normBase)), '/');
        foreach ($relative === '' ? [] : explode('/', $relative) as $segment) {
            $built .= '/' . $segment;
            if (!file_exists($built) && !is_link($built)) {
                break; // this and every deeper component do not exist yet: nothing to redirect
            }
            $real = realpath($built);
            if (is_link($built) || $real === false || storage_norm_path($real) !== storage_norm_path($built)) {
                return false;
            }
        }
        return true;
    }
    return false; // not inside the upload or avatar folder
}

/**
 * Real, normalized location(s) of the web server's document root. Throws unless at least one can be established.
 * Sources (all that are present are used, and every one that is present must resolve):
 *   - $_SERVER['DOCUMENT_ROOT'], set by Apache for web requests (empty under the PHP command line);
 *   - the process environment variable TEST_DOCUMENT_ROOT, the operator's statement of the Apache DocumentRoot
 *     (required for command-line test runs, where PHP has no document root of its own).
 * Each must be an absolute local path that exists as a directory; it is resolved with realpath().
 *
 * @return string[] normalized real paths (see storage_norm_path())
 */
function storage_document_roots(): array
{
    $fail = static function (string $why) {
        throw new RuntimeException('Test storage refused: ' . $why . ' Production storage is never used as a fallback.');
    };
    $sources = [
        'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? '',
        'TEST_DOCUMENT_ROOT' => getenv('TEST_DOCUMENT_ROOT') === false ? '' : getenv('TEST_DOCUMENT_ROOT'),
    ];
    $roots = [];
    foreach ($sources as $label => $value) {
        if (!is_string($value) || $value === '') {
            continue;
        }
        if (str_contains($value, "\0") || $value !== trim($value)) {
            $fail($label . ' contains invalid characters.');
        }
        $slashed = str_replace('\\', '/', $value);
        if (str_starts_with($slashed, '//') || preg_match('#\A(?:[A-Za-z]:)?/#', $slashed) !== 1) {
            $fail($label . ' must be a local absolute path.');
        }
        $real = realpath($slashed);
        if ($real === false || !is_dir($real)) {
            $fail($label . ' could not be resolved to an existing directory.');
        }
        $roots[storage_norm_path($real)] = true;
    }
    if ($roots === []) {
        $fail('the web server document root could not be established (no DOCUMENT_ROOT; for command-line runs set the TEST_DOCUMENT_ROOT environment variable to the Apache DocumentRoot).');
    }
    return array_keys($roots);
}

/** Validates a test storage root and returns its normalized absolute path. Throws on any doubt. */
function validate_test_storage_root(?string $root, string $appRoot): string
{
    $fail = static function (string $why) {
        throw new RuntimeException('Test storage refused: ' . $why . ' Production storage is never used as a fallback.');
    };
    if ($root === null || trim($root) === '') {
        $fail('APP_ENV=testing requires the TEST_STORAGE_ROOT environment variable.');
    }
    if (str_contains($root, "\0") || $root !== trim($root)) {
        $fail('TEST_STORAGE_ROOT contains invalid characters.');
    }
    $slashed = str_replace('\\', '/', $root);
    if (str_starts_with($slashed, '//')) {
        $fail('TEST_STORAGE_ROOT must be a local absolute path, not a network (UNC) path.');
    }
    if (preg_match('#\A(?:[A-Za-z]:)?/#', $slashed) !== 1) {
        $fail('TEST_STORAGE_ROOT must be an absolute path.');
    }
    foreach (explode('/', ltrim(preg_replace('#\A[A-Za-z]:#', '', $slashed), '/')) as $segment) {
        if ($segment === '.' || $segment === '..') {
            $fail('TEST_STORAGE_ROOT must not contain "." or ".." segments.');
        }
    }
    $slashed = rtrim($slashed, '/');
    if ($slashed === '' || preg_match('#\A[A-Za-z]:\z#', $slashed) === 1) {
        $fail('TEST_STORAGE_ROOT must be a dedicated folder, not a filesystem root.');
    }
    if (!is_dir($slashed)) {
        $fail('TEST_STORAGE_ROOT does not exist or is not a directory; create it first.');
    }
    storage_assert_no_link($slashed);
    $real = realpath($slashed);
    if ($real === false || storage_norm_path($real) !== storage_norm_path($slashed)) {
        $fail('TEST_STORAGE_ROOT does not resolve to itself (symlink, junction or alias).');
    }
    $realApp = realpath($appRoot);
    if ($realApp === false) {
        $fail('The application folder could not be resolved.');
    }
    $test = storage_norm_path($real);
    $app = storage_norm_path($realApp);
    if (storage_path_within($test, $app) || storage_path_within($app, $test)) {
        $fail('TEST_STORAGE_ROOT must be outside the application folder (and must not contain it), so it can never overlap APP_ROOT/storage.');
    }
    // The root must not be web-served: refuse it if it is, contains, or lies inside the Apache document root. Being
    // outside the project is not enough, because the document root (e.g. C:/xampp/htdocs) can be a parent of both.
    foreach (storage_document_roots() as $documentRoot) {
        if (storage_path_within($test, $documentRoot) || storage_path_within($documentRoot, $test)) {
            $fail('TEST_STORAGE_ROOT must be outside the web server document root (and must not contain it), so it can never be served over HTTP.');
        }
    }
    // APP_ROOT/storage (or one of its folders) may itself be a junction/symlink pointing outside the app folder, which
    // the check above cannot see. Compare against the real location of each production storage folder too.
    foreach (['', '/uploads', '/avatars', '/sessions', '/logs'] as $suffix) {
        $prod = $appRoot . '/storage' . $suffix;
        $prodReal = realpath($prod);
        foreach (array_filter([storage_norm_path($prod), $prodReal === false ? null : storage_norm_path($prodReal)]) as $candidate) {
            if (storage_path_within($test, $candidate) || storage_path_within($candidate, $test)) {
                $fail('TEST_STORAGE_ROOT overlaps the production storage folder (' . ($suffix === '' ? 'storage' : 'storage' . $suffix) . ').');
            }
        }
    }
    $marker = $real . '/' . TEST_STORAGE_MARKER;
    if (!is_file($marker) || is_link($marker)) {
        $fail('TEST_STORAGE_ROOT must contain a regular marker file named ' . TEST_STORAGE_MARKER . ' (create it by hand to confirm the folder is dedicated to tests).');
    }
    return str_replace('\\', '/', $real);
}

/** Creates (if needed) and re-checks one test-only subfolder; returns its path. Throws on any doubt. */
function storage_prepare_test_dir(string $root, string $name, int $mode): string
{
    $dir = $root . '/' . $name;
    if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, $mode) && !is_dir($dir))) {
        throw new RuntimeException('Test storage refused: could not create or trust the ' . $name . ' folder inside TEST_STORAGE_ROOT.');
    }
    $real = realpath($dir);
    if (is_link($dir) || $real === false || storage_norm_path($real) !== storage_norm_path($dir)) {
        throw new RuntimeException('Test storage refused: the ' . $name . ' folder inside TEST_STORAGE_ROOT is a link or alias.');
    }
    return $dir;
}

/**
 * Every runtime artifact folder for the current environment. In production these are the original
 * APP_ROOT/storage paths ('root' is null). In testing all of them are inside the validated test root.
 *
 * @return array{root: ?string, uploads: string, avatars: string, sessions: string, logs: string, tmp: ?string, test: bool}
 */
function resolve_storage_roots(string $appEnv, ?string $testStorageRoot, string $appRoot): array
{
    if ($appEnv !== 'testing') {
        return [
            'root' => null,
            'uploads' => $appRoot . '/storage/uploads',
            'avatars' => $appRoot . '/storage/avatars',
            'sessions' => $appRoot . '/storage/sessions',
            'logs' => $appRoot . '/storage/logs',
            'tmp' => null,
            'test' => false,
        ];
    }
    $root = validate_test_storage_root($testStorageRoot, $appRoot);
    $roots = ['root' => $root, 'test' => true];
    foreach (['uploads' => 0750, 'avatars' => 0750, 'sessions' => 0700, 'logs' => 0750, 'tmp' => 0700] as $name => $mode) {
        $roots[$name] = storage_prepare_test_dir($root, $name, $mode);
    }
    return $roots;
}

/**
 * True only while the active upload root is the one this file would resolve for the current environment: always
 * in production, and in testing only while the validated test root and its marker are still intact.
 */
function storage_root_still_safe(): bool
{
    if (!defined('STORAGE_IS_TEST_ROOT') || !STORAGE_IS_TEST_ROOT) {
        return true;
    }
    $root = TEST_STORAGE_ROOT_PATH;
    $marker = $root . '/' . TEST_STORAGE_MARKER;
    if (!is_file($marker) || is_link($marker)) {
        return false;
    }
    foreach ([STORAGE_PATH, AVATAR_STORAGE_PATH, SESSION_PATH, LOG_PATH, TEST_TMP_PATH] as $dir) {
        $real = realpath($dir);
        if (!is_dir($dir) || is_link($dir) || $real === false || storage_norm_path($real) !== storage_norm_path($dir)
            || !storage_path_within(storage_norm_path($real), storage_norm_path($root))) {
            return false;
        }
    }
    return storage_temp_settings_error() === null;
}

/**
 * Testing, web requests only: why PHP's temporary-file settings are not the validated <TEST_STORAGE_ROOT>/tmp folder,
 * or null if they are. Both upload_tmp_dir and sys_temp_dir must be set explicitly (process level, php -d ...), must
 * resolve to themselves (no link, junction or alias) and to exactly TEST_TMP_PATH, which must exist and be writable.
 * Nothing is created here: PHP reads these settings before any application code runs, so the operator creates the
 * folder first. There is no fallback to another temporary folder. Returns null outside testing (production unchanged)
 * and for the command line, which receives no uploads and uses TEST_TMP_PATH explicitly where it needs a temp file.
 * Limit: is_writable() on Windows reflects the read-only attribute, not the ACL, so a folder the PHP account cannot
 * write is only caught when PHP itself fails to store an upload; the operator verifies the account's rights.
 */
function storage_temp_settings_error(): ?string
{
    if (!defined('STORAGE_IS_TEST_ROOT') || !STORAGE_IS_TEST_ROOT || PHP_SAPI === 'cli') {
        return null;
    }
    if (!defined('TEST_TMP_PATH') || !is_string(TEST_TMP_PATH) || TEST_TMP_PATH === '') {
        return 'The test temporary folder (TEST_TMP_PATH) is not defined.';
    }
    $expected = realpath(TEST_TMP_PATH);
    if ($expected === false || !is_dir($expected) || is_link(TEST_TMP_PATH)
        || storage_norm_path($expected) !== storage_norm_path(TEST_TMP_PATH)) {
        return 'The test temporary folder is missing or is a link or alias; create <TEST_STORAGE_ROOT>/tmp before starting PHP.';
    }
    foreach (['upload_tmp_dir', 'sys_temp_dir'] as $name) {
        $value = ini_get($name);
        if (!is_string($value) || trim($value) === '') {
            return $name . ' is not set; start PHP with -d ' . $name . '=<TEST_STORAGE_ROOT>/tmp.';
        }
        $slashed = str_replace('\\', '/', $value);
        if ($value !== trim($value) || str_contains($value, "\0") || preg_match('#\A[A-Za-z]:/#', $slashed) !== 1) {
            return $name . ' must be an absolute local path.';
        }
        foreach (explode('/', $slashed) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return $name . ' must not contain "." or ".." segments.';
            }
        }
        $slashed = rtrim($slashed, '/');
        $real = realpath($slashed);
        if ($real === false || storage_norm_path($real) !== storage_norm_path($slashed)) {
            return $name . ' does not resolve to itself (missing, or a link, junction or alias).';
        }
        if (storage_norm_path($real) !== storage_norm_path($expected)) {
            return $name . ' is not the test temporary folder (TEST_TMP_PATH).';
        }
    }
    if (!is_writable($expected)) {
        return 'The test temporary folder is not writable.';
    }
    return null;
}
