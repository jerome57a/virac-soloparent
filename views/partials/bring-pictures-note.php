<?php
/**
 * The 1x1 ID pictures are not uploaded: the Solo Parent brings two to the interview (MSWDO uses them for the printed ID).
 * Shown in the Documents step of a new application and a renewal, and on the Documents page.
 */
require_once APP_ROOT . '/includes/icons.php';
?>
<link rel="stylesheet" href="<?= public_asset('css/bring-note.css') ?>">
<div class="bring-note" role="note">
  <span class="bring-note-icon" aria-hidden="true"><?= ui_icon('square-user', 22) ?></span>
  <span class="bring-note-text"><b data-i18n>Bring two 1x1 ID pictures to your interview</b><small data-i18n>No upload needed. MSWDO uses them for your printed ID.</small></span>
</div>
