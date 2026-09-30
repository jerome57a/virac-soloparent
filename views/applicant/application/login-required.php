<?php
/**
 * @var string $message   what logging in lets them do, e.g. "to start your application."
 * @var string $linkText
 */
$linkText = $linkText ?? 'log in or create an account';
?>
    <div class="border rounded p-4" style="background:var(--surface);font-size:14px;color:var(--body-text)">
      Please <a href="<?= base_url('portal.php?tab=login') ?>"><?= e($linkText) ?></a> <?= e($message) ?>
    </div>
