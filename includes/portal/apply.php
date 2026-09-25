<?php
/** @var PDO $pdo */
require_once __DIR__ . '/../ApplyDraft.php';
require_once __DIR__ . '/../uploads.php';
require_once __DIR__ . '/../ApplySubmit.php';

if (!$user) {
    ?>
    <div class="border rounded p-4" style="background:var(--surface);font-size:14px;color:var(--body-text)">
      Please <a href="<?= base_url('portal.php?tab=login') ?>">log in or create an account</a> to start your application.
    </div>
    <?php
    return;
}

$draft = apply_get_draft($user);
$errors = [];
$submitError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';
    $step = (int) $draft['step'];

    if ($action === 'continue') {
        $draft = apply_collect_step_input($step, $draft, $_POST);
        $errors = apply_validate_step($pdo, $step, $draft);
        if (!$errors) {
            $draft['step'] = min(4, $step + 1);
        }
        apply_save_draft($draft);
        if (!$errors) {
            redirect(base_url('portal.php?tab=apply'));
        }
    } elseif ($action === 'back') {
        $draft = apply_collect_step_input($step, $draft, $_POST);
        $draft['step'] = max(1, $step - 1);
        apply_save_draft($draft);
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'goto') {
        $n = (int) ($_POST['n'] ?? 0);
        if ($n >= 1 && $n < $step) {
            $draft['step'] = $n;
            apply_save_draft($draft);
        }
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'add_child') {
        $draft = apply_collect_step_input(2, $draft, $_POST);
        $draft['children'][] = ['name' => '', 'birthdate' => ''];
        apply_save_draft($draft);
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'remove_child') {
        $draft = apply_collect_step_input(2, $draft, $_POST);
        $idx = (int) ($_POST['idx'] ?? -1);
        if (count($draft['children']) > 1 && isset($draft['children'][$idx])) {
            array_splice($draft['children'], $idx, 1);
        }
        apply_save_draft($draft);
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'upload_doc') {
        $docKey = (string) ($_POST['doc_key'] ?? '');
        $result = handle_doc_upload($_FILES['file'] ?? []);
        if ($result['ok']) {
            if (!empty($draft['documents'][$docKey]['path']) && is_file($draft['documents'][$docKey]['path'])) {
                @unlink($draft['documents'][$docKey]['path']);
            }
            $draft['documents'][$docKey] = $result['meta'];
            unset($draft['docErrors'][$docKey]);
        } else {
            $draft['docErrors'][$docKey] = $result['error'];
        }
        apply_save_draft($draft);
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'remove_doc') {
        $docKey = (string) ($_POST['doc_key'] ?? '');
        if (!empty($draft['documents'][$docKey]['path']) && is_file($draft['documents'][$docKey]['path'])) {
            @unlink($draft['documents'][$docKey]['path']);
        }
        unset($draft['documents'][$docKey], $draft['docErrors'][$docKey]);
        apply_save_draft($draft);
        redirect(base_url('portal.php?tab=apply'));
    } elseif ($action === 'submit') {
        $draft = apply_collect_step_input(4, $draft, $_POST);
        $firstInvalidStep = null;
        $allErrors = [];
        for ($s = 1; $s <= 4; $s++) {
            $stepErrors = apply_validate_step($pdo, $s, $draft);
            if ($stepErrors && $firstInvalidStep === null) {
                $firstInvalidStep = $s;
                $allErrors = $stepErrors;
            }
        }
        if ($firstInvalidStep !== null) {
            $draft['step'] = $firstInvalidStep;
            $errors = $allErrors;
            apply_save_draft($draft);
        } else {
            try {
                $refNumber = apply_finalize_submission($pdo, $user, $draft);
                redirect(base_url('slip.php?ref=' . urlencode($refNumber)));
            } catch (Throwable $e) {
                $submitError = 'Something went wrong while submitting your application. Please try again.';
                apply_save_draft($draft);
            }
        }
    }
}

$step = (int) $draft['step'];
$categories = get_categories($pdo);
$categoryMap = [];
foreach ($categories as $c) {
    $categoryMap[$c['id']] = $c['name'];
}
$docDefs = apply_doc_defs($pdo, $draft['categoryId']);
$catOnlyDefs = apply_category_only_defs($pdo, $draft['categoryId']);
$catOnlyKeys = array_column($catOnlyDefs, 'doc_key');

$stepMeta = [
    1 => ['Personal Info', 'Tell us about yourself. Fields marked * are required.'],
    2 => ['Children', 'List each child in your sole care.'],
    3 => ['Documents', 'Upload clear copies of your requirements.'],
    4 => ['Review', 'Check your details before submitting.'],
];

$errCount = count($errors);
$stepError = $errCount === 1 ? 'Please fix the highlighted field before continuing.'
    : ($errCount > 1 ? "Please fix the $errCount highlighted fields before continuing." : '');
?>
<div style="max-width:720px">

<div class="step-bar">
  <?php for ($n = 1; $n <= 4; $n++): $isDone = $step > $n; $isActive = $step === $n; ?>
    <?php if ($n < $step): ?>
      <form method="post" class="step-item done">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="goto">
        <input type="hidden" name="n" value="<?= $n ?>">
        <button type="submit" style="border:none;background:none;padding:0;width:100%;font-family:inherit;cursor:pointer">
          <div class="step-circle">✓</div>
          <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
          <div class="step-progress"></div>
        </button>
      </form>
    <?php else: ?>
      <div class="step-item <?= $isActive ? 'active' : '' ?>">
        <div class="step-circle"><?= $n ?></div>
        <div class="step-label"><?= e($stepMeta[$n][0]) ?></div>
        <div class="step-progress"></div>
      </div>
    <?php endif; ?>
  <?php endfor; ?>
</div>

<div class="step-title"><?= e($stepMeta[$step][0]) ?></div>
<div class="step-desc"><?= e($stepMeta[$step][1]) ?></div>

<?php if ($submitError): ?><div class="step-alert"><?= e($submitError) ?></div><?php endif; ?>
<?php if ($stepError): ?><div class="step-alert"><?= e($stepError) ?></div><?php endif; ?>

<?php
switch ($step) {
    case 1:
        require __DIR__ . '/apply_step1.php';
        break;
    case 2:
        require __DIR__ . '/apply_step2.php';
        break;
    case 3:
        require __DIR__ . '/apply_step3.php';
        break;
    case 4:
        require __DIR__ . '/apply_step4.php';
        break;
}
?>

</div>
