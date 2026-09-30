<?php
/** @var array $myNotifications */
$notifIcons = [
    'registration' => '👋', 'submission' => '📨', 'appointment_confirmation' => '📅',
    'appointment_reminder' => '⏰', 'correction' => '⚠️', 'approval' => '✅',
    'rejection' => '❌', 'renewal_reminder' => '🔔',
];
?>
      <?php if (!$myNotifications): ?>
        <div class="dash-card">
          <div style="font-size:14px;color:var(--muted)">No notifications yet. You'll see updates here when your application status changes.</div>
        </div>
      <?php else: ?>
        <?php foreach ($myNotifications as $n): ?>
          <div class="app-card" style="align-items:flex-start">
            <div>
              <div style="font-size:14px;color:var(--ink);margin-bottom:4px"><?= $notifIcons[$n['type']] ?? '🔔' ?> <?= e($n['message']) ?></div>
              <div class="app-date"><?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
