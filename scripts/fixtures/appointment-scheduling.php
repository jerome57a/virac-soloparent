<?php
/** CLI-only presentation fixture; synthetic applicant and slots, no database. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__,2);
function e($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function base_url(string $path): string { return 'http://localhost/virac-soloparent/'.$path; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="demo-only">'; }
$state = $argv[1] ?? 'available';
$ivStatus = 'NONE';
$interview = null;
$app = ['id'=>1,'full_name'=>'Demo Solo Parent','reference_no'=>'DEMO-APPOINTMENT-001'];
$uncheckedRequirements = [];
$date = date('Y-m-d',strtotime('+3 days'));
$interviewSlots = $state === 'empty' ? [] : [
    ['id'=>10,'slot_date'=>$date,'start_time'=>'09:00:00','end_time'=>'09:30:00','remaining'=>2],
    ['id'=>11,'slot_date'=>$date,'start_time'=>'10:00:00','end_time'=>'10:30:00','remaining'=>1],
];
$interviewForm = ['slot_id'=>'','location'=>'MSWDO Demo Office','sms_message_type'=>$state === 'error' ? 'custom' : 'automatic','custom_message'=>$state === 'error' ? 'Preserve this draft after validation.' : ''];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Appointment scheduling demo</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<?php foreach (['style','personnel-portal','preferences-surfaces','preferences'] as $css): ?><style><?= file_get_contents($root.'/public/css/'.$css.'.css') ?></style><?php endforeach; ?>
</head><body class="portal-body personnel-portal staff-portal"><main class="portal-main"><div class="portal-container" style="max-width:760px"><h1 class="page-title mb-3">Appointment scheduling demo</h1><div class="review-card"><div class="review-card-head"><div class="title">Demo Solo Parent · Interview</div></div>
<?php require $root.'/views/staff/appointments/schedule-form.php'; ?>
</div></div></main></body></html>
