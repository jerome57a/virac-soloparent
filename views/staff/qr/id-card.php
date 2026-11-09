<?php
/** Staff QR verification shows the ID once release is scheduled or confirmed. */
if (($a['status'] ?? '') !== 'ID Released'
    && !(($a['status'] ?? '') === 'Approved' && !empty($a['id_release_schedule']))) {
    return;
}
require APP_ROOT . '/views/partials/solo-parent-id-card.php';
