<?php
// Keep old bookmarks and historical notification links out of the retired chat feature.
header('Location: dashboard.php', true, 302);
exit;
