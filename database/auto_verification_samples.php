<?php
/**
 * Tunes the automated image checks on real photos. Reads a folder of JPEG/PNG sample photos you collected (not applicant
 * uploads), prints each photo's measurements and what the checks say, and, when the file names start with a label, how
 * well the current limits separate good photos from bad ones and what sharpness limit would work best.
 *
 *   php database/auto_verification_samples.php <folder>
 *
 * Label a photo by starting its file name with: good_  blurry_  dark_  bright_  small_   (e.g. good_id_front.jpg, blurry_cert.jpg).
 * Unlabelled photos are measured but not scored. No database, no network; the photos are only read. Use made-up or
 * consenting people's documents, and keep the folder out of the project.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../services/AutoVerificationService.php';

$dir = $argv[1] ?? '';
if ($dir === '' || !is_dir($dir)) {
    fwrite(STDERR, "Usage: php database/auto_verification_samples.php <folder of sample photos>\n");
    exit(2);
}
$files = array_values(array_filter(scandir($dir) ?: [], fn($f) => preg_match('/\.(jpe?g|png)$/i', $f) === 1));
if (!$files) {
    fwrite(STDERR, "No JPG or PNG files found in that folder.\n");
    exit(2);
}

$expectIssue = ['good' => null, 'blurry' => 'blurry', 'dark' => 'dark', 'bright' => 'washed out', 'small' => 'Low resolution'];
$rows = [];
printf("%-34s %9s %7s %10s  %s\n", 'File', 'Size', 'Bright', 'Sharpness', 'Checks say');
foreach ($files as $file) {
    $m = AutoVerificationService::measureImage(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $file);
    if ($m === null) {
        printf("%-34s %s\n", mb_substr($file, 0, 34), 'could not be read');
        continue;
    }
    $issues = AutoVerificationService::imageIssues($m);
    $label = strtolower(strtok($file, '_'));
    $label = array_key_exists($label, $expectIssue) ? $label : '';
    $rows[] = ['file' => $file, 'm' => $m, 'issues' => $issues, 'label' => $label];
    printf("%-34s %9s %7s %10s  %s\n", mb_substr($file, 0, 34), $m['width'] . '×' . $m['height'],
        $m['brightness'] === null ? '-' : number_format($m['brightness'], 0), $m['sharpness'] === null ? '-' : number_format($m['sharpness'], 0),
        $issues ? implode(' ', $issues) : 'OK');
}

$labelled = array_filter($rows, fn($r) => $r['label'] !== '');
if (!$labelled) {
    echo "\nNo labelled photos (good_, blurry_, dark_, bright_, small_), so nothing was scored.\n";
    exit(0);
}
echo "\nScoring against the labels in the file names (current limits: sharpness " . AutoVerificationService::MIN_SHARPNESS
    . ', brightness ' . AutoVerificationService::MIN_BRIGHTNESS . '-' . AutoVerificationService::MAX_BRIGHTNESS
    . ', shortest side ' . AutoVerificationService::MIN_IMAGE_SIDE . "px)\n";
$wrong = 0;
foreach ($expectIssue as $label => $needle) {
    $set = array_filter($labelled, fn($r) => $r['label'] === $label);
    if (!$set) { continue; }
    $correct = count(array_filter($set, fn($r) => $needle === null ? !$r['issues'] : str_contains(implode(' ', $r['issues']), $needle)));
    $wrong += count($set) - $correct;
    printf("  %-7s %d of %d handled correctly%s\n", $label, $correct, count($set), $needle === null ? ' (no false alarms)' : " (flagged '" . $needle . "')");
    foreach ($set as $r) {
        if (!($needle === null ? !$r['issues'] : str_contains(implode(' ', $r['issues']), $needle))) {
            echo '          missed: ' . $r['file'] . "\n";
        }
    }
}

// Where would the sharpness limit sit best? Between the sharpest blurry photo and the least sharp good one.
$blur = array_column(array_column(array_filter($labelled, fn($r) => $r['label'] === 'blurry'), 'm'), 'sharpness');
$good = array_column(array_column(array_filter($labelled, fn($r) => $r['label'] === 'good'), 'm'), 'sharpness');
$blur = array_filter($blur, 'is_numeric');
$good = array_filter($good, 'is_numeric');
if ($blur && $good) {
    $maxBlur = max($blur);
    $minGood = min($good);
    echo "\nSharpness: blurriest-photo ceiling " . number_format($maxBlur, 0) . ', least-sharp good photo ' . number_format($minGood, 0) . ".\n";
    if ($maxBlur < $minGood) {
        printf("  They separate. A limit near %d would catch every blurry sample without a false alarm (set MIN_SHARPNESS in services/AutoVerificationService.php).\n", (int) round(sqrt($maxBlur * $minGood)));
    } else {
        echo "  They overlap: no single limit separates these samples. Retake the borderline photos or accept some staff review.\n";
    }
}
exit($wrong ? 1 : 0);
