<?php
/**
 * Offline test of the automated verification rules. No database, no network, no application files: the rules are run on
 * plain arrays and the image check on small images drawn into a temporary folder.
 *
 *   php database/test_auto_verification.php [temp-dir]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../services/AutoVerificationService.php';

$failures = 0;
$checks = 0;
function check(bool $ok, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; fwrite(STDERR, "FAIL: $message\n"); }
}

$today = '2026-11-18';
$none = fn(array $doc): ?array => ['issues' => []];
$app = ['type' => 'new', 'user_id' => 1, 'full_name' => 'Maria Santos', 'birthdate' => '1990-05-10', 'mobile' => '09171234567',
        'emergency_contact_name' => 'Ana Santos', 'emergency_contact_phone' => '09181234567', 'eligibility_status' => 'Eligible'];
$kids = [['full_name' => 'Juan', 'birthdate' => '2015-03-02']];
$row = fn(string $key, bool $required, ?array $upload) => ['doc_key' => $key, 'label' => strtoupper($key), 'is_required' => $required ? 1 : 0] + ($upload ? ['uploaded_document' => $upload] : []);
$file = fn(string $sha) => ['sha256' => $sha, 'mime' => 'image/jpeg', 'stored_path' => 'x.jpg'];
$none_dup = ['sameApplication' => [], 'otherApplications' => [], 'samePerson' => 0];
$verdict = fn(array $a, array $c, array $l, ?array $d = null, ?callable $q = null) => AutoVerificationService::evaluate($a, $c, $l, $d ?? $none_dup, $q ?? $none, $today);
$results = fn(array $r, string $key) => array_column(array_filter($r['checks'], fn($c) => $c['key'] === $key), 'result');

// A clean application is ready.
$r = $verdict($app, $kids, [$row('id', true, $file('a')), $row('cert', true, $file('b'))]);
check($r['verdict'] === 'ready' && $r['counts']['fail'] === 0 && $r['counts']['flag'] === 0, 'clean application is ready');

// Missing required document fails; a missing optional one does not.
$r = $verdict($app, $kids, [$row('id', true, $file('a')), $row('cert', true, null), $row('extra', false, null)]);
check($r['verdict'] === 'fix' && in_array('fail', $results($r, 'required_documents'), true), 'missing required document fails');
check(count(array_filter($r['checks'], fn($c) => $c['result'] === 'fail')) === 1, 'optional missing document is not a failure');

// Renewal: verified evidence carried over from the earlier application needs no new upload; unverified required ones still do.
$carried = ['doc_key' => 'brgy', 'label' => 'BRGY', 'is_required' => 1, 'verification_status' => 'Verified'];
check($verdict(['type' => 'renewal'] + $app, [['full_name' => 'X', 'birthdate' => '2015-03-02']], [$carried, $row('id', true, $file('a'))])['verdict'] === 'ready', 'renewal: carried-over verified evidence is not missing');
check($verdict(['type' => 'renewal'] + $app, [], [['verification_status' => 'Pending'] + $carried])['verdict'] === 'fix', 'renewal: unverified required document without a file is still missing');

// Older applications (before uploads existed): a required document declared on paper is neither missing nor failed.
$paper = ['doc_key' => 'brgy', 'label' => 'BRGY', 'is_required' => 1, 'applicant_has' => 1, 'verification_status' => 'Pending'];
$r = $verdict($app, $kids, [$paper, ['doc_key' => 'id', 'label' => 'ID', 'is_required' => 1, 'applicant_has' => 1, 'verification_status' => 'Pending']]);
check($r['verdict'] === 'ready' && $r['counts']['fail'] === 0, 'paper-declared required documents are not failed');
check(in_array('paper_documents', array_column($r['checks'], 'key'), true), 'paper-declared documents are mentioned for staff');
check($verdict($app, $kids, [['applicant_has' => null] + $paper])['verdict'] === 'fix', 'a required document that is neither uploaded nor declared is still missing');
check($verdict($app, $kids, [['is_required' => 0] + $paper])['counts']['fail'] === 0, 'an optional paper document is ignored');

// Birthdate rules.
check($verdict(['birthdate' => '2030-01-01'] + $app, $kids, [])['verdict'] === 'fix', 'future applicant birthdate fails');
check($verdict(['birthdate' => 'nonsense'] + $app, $kids, [])['verdict'] === 'fix', 'unreadable birthdate fails');
check($verdict(['birthdate' => '2012-01-01'] + $app, [], [])['counts']['flag'] >= 1, 'under-18 applicant is flagged');
check($verdict(['birthdate' => '1900-01-01'] + $app, $kids, [])['verdict'] === 'review', 'over-100 applicant is flagged, not failed');
check($verdict(['birthdate' => '2026-11-18'] + $app, $kids, [])['counts']['fail'] === 0, 'birthdate today is not in the future');

// Children.
check($verdict($app, [], [])['verdict'] === 'review', 'new application with no children is flagged');
check($verdict(['type' => 'renewal', 'renewal_simplified' => 1] + $app, [], [])['verdict'] === 'ready', 'an old documents-only renewal without children is not flagged');
check($verdict(['type' => 'renewal', 'renewal_simplified' => 0] + $app, [], [])['verdict'] === 'review', 'a renewal (with interview) without children is flagged');
// RA 11861: 22 still qualifies; 23 qualifies only with a disability.
$ageOn = fn(int $years) => date('Y-m-d', strtotime('-' . $years . ' years -10 days'));
check($verdict($app, [['full_name' => 'X', 'birthdate' => $ageOn(22)]], [])['verdict'] === 'ready', 'a child aged 22 still qualifies');
check($verdict($app, [['full_name' => 'X', 'birthdate' => $ageOn(23)]], [])['verdict'] === 'review', 'a child aged 23 without a disability is flagged');
check(count(array_filter($verdict($app, [['full_name' => 'X', 'birthdate' => $ageOn(23)]], [])['checks'], fn($c) => $c['key'] === 'qualifying_child')) === 1, 'no qualifying child is flagged');
check(count(array_filter($verdict($app, [['full_name' => 'X', 'birthdate' => $ageOn(30), 'has_disability' => 1]], [])['checks'], fn($c) => $c['key'] === 'qualifying_child')) === 0, 'a child over 22 with a disability qualifies');
check(count(array_filter($verdict(['type' => 'renewal', 'civil_status' => 'Married', 'previous_civil_status' => 'Single'] + $app, [['full_name' => 'X', 'birthdate' => '2015-03-02']], [])['checks'], fn($c) => $c['key'] === 'civil_status')) === 1, 'a renewal that changed to Married is flagged');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '2030-01-01']], [])['verdict'] === 'fix', 'child born in the future fails');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '1985-01-01']], [])['verdict'] === 'review', 'child older than the parent is flagged');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '1999-01-01']], [])['verdict'] === 'review', 'parent under 12 at the birth is flagged');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '2004-01-01']], [])['verdict'] === 'ready', 'RA 11861: a dependent aged 22 still qualifies');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '2003-01-01']], [])['verdict'] === 'review', 'RA 11861: a dependent aged 23 without a disability is flagged');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '2004-11-19']], [])['verdict'] === 'ready', 'dependent aged 21 is accepted');
check($verdict($app, [['full_name' => 'X', 'birthdate' => '2003-11-18']], [])['verdict'] === 'review', 'dependent who just turned 23 is flagged');

// Contact, duplicate person and eligibility.
check($verdict(['mobile' => '12345'] + $app, $kids, [])['verdict'] === 'review', 'invalid mobile is flagged');
check($verdict(['mobile' => '+63 917 123 4567'] + $app, $kids, [])['verdict'] === 'ready', 'formatted +63 mobile is accepted');
check($verdict(['emergency_contact_name' => ''] + $app, $kids, [])['verdict'] === 'review', 'missing emergency contact is flagged');
check($verdict($app, $kids, [], ['samePerson' => 1] + $none_dup)['verdict'] === 'review', 'same person on another account is flagged');
check($verdict(['eligibility_status' => 'Not Eligible'] + $app, $kids, [])['verdict'] === 'review', 'Not Eligible assessment is flagged');
check($verdict(['eligibility_status' => 'Not Assessed'] + $app, $kids, [])['verdict'] === 'ready', 'Not Assessed is not flagged');

// Files: duplicates and quality.
$same = ['sameApplication' => ['s' => ['id', 'cert']]] + $none_dup;
$r = $verdict($app, $kids, [$row('id', true, $file('s')), $row('cert', true, $file('s'))], $same);
check($r['verdict'] === 'review' && count(array_filter($r['checks'], fn($c) => $c['key'] === 'file' && $c['result'] === 'flag')) === 2, 'one file used for two documents is flagged on both');
$other = ['otherApplications' => ['s' => 1]] + $none_dup;
check($verdict($app, $kids, [$row('id', true, $file('s'))], $other)['verdict'] === 'review', 'file shared with another applicant is flagged');
$blurry = fn(array $doc): ?array => ['issues' => ['The image looks blurry.']];
$r = $verdict($app, $kids, [$row('id', true, $file('a'))], null, $blurry);
check($r['verdict'] === 'review' && str_contains(implode(' ', array_column($r['checks'], 'message')), 'blurry'), 'image-quality issue is flagged with its reason');
check($verdict($app, $kids, [$row('id', true, $file('a'))], null, fn($d) => null)['verdict'] === 'ready', 'unmeasurable file (null) is not penalised');
check($verdict($app, $kids, [$row('id', true, $file(''))])['verdict'] === 'ready', 'file without a checksum is not treated as a duplicate');

// A failure outranks a flag.
$r = $verdict(['mobile' => '1'] + $app, $kids, [$row('id', true, null)]);
check($r['verdict'] === 'fix' && $r['counts']['flag'] >= 1 && $r['counts']['fail'] >= 1, 'a failure outranks a flag');

// OCR name matching (the text-reading itself needs Tesseract; the matching does not).
$ocrText = "REPUBLIKA NG PILIPINAS\nCERTIFICATE OF LIVE BIRTH\nName of child: JUAN DELA CRUZ SANTOS\nMother: MARIA SANTOS née REYES";
check(AutoVerificationService::normalizeText("Dela Cruz, María-José") === 'DELA CRUZ MARIA JOSE', 'text is normalised to plain upper-case words');
check(AutoVerificationService::nameScore($ocrText, 'Maria Santos') === 1.0, 'a full name on the document scores 1');
check(AutoVerificationService::nameScore($ocrText, 'María Santos') === 1.0, 'accents are ignored');
check(AutoVerificationService::nameScore($ocrText, 'Maria Santoz') >= 0.5, 'one misread letter in a long word still matches');
check(AutoVerificationService::nameScore($ocrText, 'Maria Reyez Lopez') > 0.5 && AutoVerificationService::nameScore($ocrText, 'Maria Reyez Lopez') < 1.0, 'part of a name gives a partial score');
check(AutoVerificationService::nameScore($ocrText, 'Pedro Garcia') === 0.0, 'a different person scores 0');
check(AutoVerificationService::nameScore($ocrText, 'Jr de la') >= 0.0 && AutoVerificationService::nameScore($ocrText, '') === 0.0, 'an empty or filler-only name does not crash');
check(AutoVerificationService::nameScore('', 'Maria Santos') === 0.0, 'empty text scores 0');
$ocrRead = fn(string $t) => fn(array $doc): ?string => $t;
$withOcr = fn(string $text, ?array $kidsIn = null) => AutoVerificationService::evaluate($app, $kidsIn ?? $kids, [$row('id', true, $file('a'))], $none_dup, $none, $today, $ocrRead($text));
check($withOcr('Name: MARIA SANTOS born 1990-05-10 Davao City barangay certificate')['verdict'] === 'ready', 'ocr: the applicant\'s name on the document passes');
$r = $withOcr('Name: PEDRO GARCIA born 1980 Davao City barangay certificate');
check($r['verdict'] === 'review' && in_array('ocr_name', array_column($r['checks'], 'key'), true), 'ocr: a different name is flagged');
check($withOcr('Name: JUAN of Davao City certificate', [['full_name' => 'Juan Santos', 'birthdate' => '2015-03-02']])['verdict'] === 'ready', 'ocr: a child\'s name also passes');
check($withOcr('..  --')['verdict'] === 'review', 'ocr: almost no readable text is flagged');
check(AutoVerificationService::evaluate($app, $kids, [$row('id', true, $file('a'))], $none_dup, $none, $today, fn($d) => null)['verdict'] === 'ready', 'ocr: unavailable (null) is not penalised');
$base = AutoVerificationService::evaluate($app, $kids, [$row('id', true, $file('a'))], $none_dup, $none, $today);
$saved = [['key' => 'ocr_name', 'scope' => 'id', 'result' => 'flag', 'label' => 'ID (name)', 'message' => 'x', 'sha' => 'a'], ['key' => 'ocr_name', 'scope' => 'id', 'result' => 'flag', 'label' => 'old', 'message' => 'x', 'sha' => 'zzz'], ['key' => 'file', 'scope' => 'id', 'result' => 'fail', 'label' => 'ignored', 'message' => 'x']];
$merged = AutoVerificationService::withSavedOcr($base, $saved, [$row('id', true, $file('a'))]);
check($merged['verdict'] === 'review' && count($merged['checks']) === count($base['checks']) + 1, 'saved ocr result is merged only for the current file and only ocr checks');
check(AutoVerificationService::withSavedOcr($base, [], [])['verdict'] === 'ready', 'merging nothing changes nothing');
// ID pictures (the 1x1 pictures) have no name or text to read: the text check must skip them, but still run for documents.
$noText = $ocrRead('..  --');
foreach (['photo1x1', 'photo'] as $pictureKey) {
    $r = AutoVerificationService::evaluate($app, $kids, [$row($pictureKey, true, $file('p'))], $none_dup, $none, $today, $noText);
    check($r['verdict'] === 'ready' && !in_array('ocr_name', array_column($r['checks'], 'key'), true), "ocr: the '$pictureKey' picture is not flagged for having no text");
}
$r = AutoVerificationService::evaluate($app, $kids, [$row('photo1x1', true, $file('p')), $row('id', true, $file('a'))], $none_dup, $none, $today, $noText);
check($r['verdict'] === 'review' && array_column(array_filter($r['checks'], fn($c) => $c['key'] === 'ocr_name'), 'scope') === ['id'], 'ocr: with a picture and a document, only the document is checked for a name');
$passedFlags = [];
$spy = function (array $doc, bool $isPicture = false) use (&$passedFlags): ?array { $passedFlags[] = $isPicture; return ['issues' => []]; };
AutoVerificationService::evaluate($app, $kids, [$row('photo1x1', true, $file('p')), $row('id', true, $file('a'))], $none_dup, $spy, $today);
check($passedFlags === [true, false], 'the picture rules are applied to the picture only');

// Image quality on real pixels.
$dir = rtrim($argv[1] ?? sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'virac-av-test-' . getmypid();
mkdir($dir);
define('STORAGE_PATH', $dir);
$make = function (string $name, int $w, int $h, callable $paint) use ($dir): array {
    $im = imagecreatetruecolor($w, $h);
    $paint($im, $w, $h);
    imagejpeg($im, $dir . DIRECTORY_SEPARATOR . $name, 95);
    return ['mime' => 'image/jpeg', 'stored_path' => $name, 'sha256' => hash('sha256', $name)];
};
$text = function ($im, $w, $h) { // white page with many sharp black bars (a sharp, readable document)
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 245, 245, 245));
    $black = imagecolorallocate($im, 10, 10, 10);
    for ($y = 20; $y < $h - 20; $y += 14) { for ($x = 20; $x < $w - 60; $x += 18) { imagefilledrectangle($im, $x, $y, $x + 9, $y + 5, $black); } }
};
$sharp = $make('sharp.jpg', 900, 1200, $text);
$small = $make('small.jpg', 300, 400, $text);
$dark = $make('dark.jpg', 900, 1200, function ($im, $w, $h) { imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 8, 8, 8)); });
$blank = $make('blank.jpg', 900, 1200, function ($im, $w, $h) { imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 252, 252, 252)); });
$blur = $make('blur.jpg', 900, 1200, function ($im, $w, $h) use ($text) { $text($im, $w, $h); for ($i = 0; $i < 60; $i++) { imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR); } });
$measure = new ReflectionMethod(AutoVerificationService::class, 'imageQuality');
$issues = fn(array $doc) => implode(' ', $measure->invoke(new AutoVerificationService(), $doc)['issues'] ?? ['(null)']);
check($issues($sharp) === '', 'a sharp, large, normal image has no issues: ' . $issues($sharp));
check(str_contains($issues($small), 'Low resolution'), 'a small image is flagged for resolution');
check(str_contains($issues($dark), 'dark'), 'a very dark image is flagged');
check(str_contains($issues($blank), 'washed out'), 'a blank white image is flagged');
check(str_contains($issues($blur), 'blurry'), 'a blurred image is flagged: ' . $issues($blur));
// A 1x1 inch ID picture is about 300 pixels wide at 300 dpi: it is not "low resolution", unless it is really tiny.
$tiny = $make('tiny.jpg', 200, 260, $text);
$pictureIssues = fn(array $doc) => implode(' ', $measure->invoke(new AutoVerificationService(), $doc, true)['issues'] ?? ['(null)']);
check($pictureIssues($small) === '', 'a 300x400 ID picture has no size issue: ' . $pictureIssues($small));
check(str_contains($pictureIssues($tiny), 'Small picture'), 'a 200x260 ID picture is flagged as too small');
check(str_contains($issues($small), 'Low resolution'), 'the same 300x400 image is still low resolution when it is a document');
check(str_contains($pictureIssues($dark), 'dark') && str_contains($pictureIssues($blank), 'washed out'), 'a dark or washed-out ID picture is still flagged');
check($measure->invoke(new AutoVerificationService(), ['mime' => 'application/pdf', 'stored_path' => 'a.pdf']) === null, 'a PDF is not measured');
check(str_contains($issues(['mime' => 'image/jpeg', 'stored_path' => '../escape.jpg']), 'could not be found'), 'a path outside the upload folder is not opened');
// The applicant's draft (review step): staged files in <upload root>/drafts/<id>/, no database.
mkdir($dir . '/drafts'); mkdir($dir . '/drafts/7');
copy($dir . '/sharp.jpg', $dir . '/drafts/7/aaaa.jpg');
copy($dir . '/dark.jpg', $dir . '/drafts/7/bbbb.jpg');
$draft = ['fullName' => 'Maria Santos', 'birthdate' => '1990-05-10', 'contact' => '09171234567', 'emergencyName' => 'Ana', 'emergencyPhone' => '09181234567',
          'children' => [['name' => 'Juan', 'birthdate' => '2015-03-02'], ['name' => '', 'birthdate' => '']],
          'documents' => [
              'id' => ['path' => STORAGE_PATH . '/drafts/7/aaaa.jpg', 'mime' => 'image/jpeg', 'sha256' => 'h1'],
              'cert' => ['path' => STORAGE_PATH . '/drafts/7/bbbb.jpg', 'mime' => 'image/jpeg', 'sha256' => 'h2'],
              'evil' => ['path' => '/etc/passwd', 'mime' => 'image/jpeg', 'sha256' => 'h3'],
          ]];
$defs = [['doc_key' => 'id', 'label' => 'ID', 'is_required' => 1], ['doc_key' => 'cert', 'label' => 'Certificate', 'is_required' => 1], ['doc_key' => 'photo', 'label' => 'Photo', 'is_required' => 1], ['doc_key' => 'evil', 'label' => 'Evil', 'is_required' => 0]];
$r = (new AutoVerificationService())->forDraft(7, $draft, $defs);
$byKey = fn(string $scope) => array_values(array_filter($r['checks'], fn($c) => $c['scope'] === $scope && $c['key'] === 'file'));
check($byKey('id')[0]['result'] === 'pass', 'draft: a sharp staged file passes');
check($byKey('cert')[0]['result'] === 'flag' && str_contains($byKey('cert')[0]['message'], 'dark'), 'draft: a dark staged file is flagged');
check(!$byKey('photo') && in_array('Missing: Photo.', array_column($r['checks'], 'message'), true), 'draft: a document with no file is reported missing');
check(!$byKey('evil'), 'draft: a staged path outside the upload folder is ignored');
check($r['verdict'] === 'fix', 'draft: a missing required document gives the fix verdict');
check(count(array_filter($r['checks'], fn($c) => $c['key'] === 'children')) === 1, 'draft: the blank child row is skipped');
foreach (['/drafts/7/aaaa.jpg', '/drafts/7/bbbb.jpg'] as $f) { unlink($dir . $f); }
rmdir($dir . '/drafts/7'); rmdir($dir . '/drafts');
// The measurement cache (written under the test's storage folder) is removed with it.
foreach (glob($dir . '/cache/image-quality/*') ?: [] as $f) { unlink($f); }
@rmdir($dir . '/cache/image-quality'); @rmdir($dir . '/cache');
foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) { unlink($f); }
rmdir($dir);

// ---- The staff page must not read documents again on every open ----
$sha = fn(string $x) => hash('sha256', $x);
$checklist = [
    ['doc_key' => 'birthCert', 'uploaded_document' => ['sha256' => $sha('a'), 'mime' => 'image/jpeg']],
    ['doc_key' => 'photo1x1', 'uploaded_document' => ['sha256' => $sha('b'), 'mime' => 'image/jpeg']],
    ['doc_key' => 'photo', 'uploaded_document' => ['sha256' => $sha('c'), 'mime' => 'image/png']],
    ['doc_key' => 'scan', 'uploaded_document' => ['sha256' => $sha('d'), 'mime' => 'application/pdf']],
    ['doc_key' => 'noFile', 'uploaded_document' => null],
];
check(array_keys(AutoVerificationService::imagesNeedingOcr($checklist, [])) === [$sha('a')], 'unread: only a document image counts (not the ID pictures, not a PDF, not a missing file)');
check(AutoVerificationService::imagesNeedingOcr($checklist, [['key' => 'ocr_name', 'sha' => $sha('a')]]) === [], 'unread: once the document has its saved reading, an application with an ID picture has nothing left to read');
check(array_keys(AutoVerificationService::imagesNeedingOcr($checklist, [['key' => 'ocr_name', 'sha' => $sha('zzz')]])) === [$sha('a')], 'unread: a reading saved for another file does not count');

$cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'imgq' . bin2hex(random_bytes(4));
$img = $cache . '-src.jpg';
$g = imagecreatetruecolor(800, 600);
imagefilledrectangle($g, 0, 0, 800, 600, imagecolorallocate($g, 200, 190, 180));
imagejpeg($g, $img, 90);
$h = hash_file('sha256', $img);
$first = AutoVerificationService::measureImageCached($img, $h, $cache);
check(is_array($first) && $first['width'] === 800 && is_file($cache . '/v1-' . $h . '.json'), 'measure cache: the measurement is saved under the file checksum');
unlink($img);
$second = AutoVerificationService::measureImageCached($img, $h, $cache);
check($second == $first, 'measure cache: the second call comes from the saved measurement (the file is not needed)');
check(AutoVerificationService::measureImageCached($img, '', $cache) === null, 'measure cache: without a valid checksum nothing is saved or used');
check(count(glob($cache . '/*')) === 1, 'measure cache: one small file for one image');
foreach (glob($cache . '/*') as $x) { unlink($x); }
rmdir($cache);
echo $failures ? "FAILED: $failures of $checks checks.\n" : "PASS: $checks automated verification checks (rules and image quality). No database, no network.\n";
exit($failures ? 1 : 0);
