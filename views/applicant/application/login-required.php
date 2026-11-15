<?php /** @var string $message */ ?>
<div class="border rounded p-4" style="background:var(--surface);font-size:14px;color:var(--body-text)">
  <p data-i18n>Log in or create an account <?= e($message) ?></p>
  <p data-i18n>Complete your personal and child information, upload the required supporting documents, then review and submit. MSWDO staff verify your files and handle interviews and ID claiming instructions.</p>
  <div class="home-actions">
    <a class="btn-navy text-decoration-none" href="<?= base_url('login.php?next=apply') ?>" data-i18n>Log In</a>
    <a class="btn-outline text-decoration-none" href="<?= base_url('register.php?next=apply') ?>" data-i18n>Create Account</a>
  </div>
</div>
