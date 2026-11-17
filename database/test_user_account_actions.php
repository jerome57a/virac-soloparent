<?php
/** CLI only. All writes are confined to connection-local temporary tables. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/tool_safety.php';
require_isolated_database_tool_environment();
require __DIR__ . '/../config/database.php';
require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/csrf.php';
require APP_ROOT . '/services/UserManagementService.php';
function action_check(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
try {
    foreach (['users', 'applicants', 'audit_logs'] as $table) {
        $sql = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $sql = preg_replace('/^CREATE TABLE/', 'CREATE TEMPORARY TABLE', $sql);
        $sql = preg_replace('/,\s*CONSTRAINT\s+[^\n]+/', '', $sql);
        $pdo->exec($sql);
    }
    $pdo->exec('CREATE TEMPORARY TABLE applications (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL)');
    $pdo->exec('CREATE TEMPORARY TABLE appointment_slots (created_by INT NOT NULL)');
    $pdo->exec('CREATE TEMPORARY TABLE appointments (scheduled_by INT NULL)');
    $model = new User($pdo);
    $service = new UserManagementService($pdo);
    $hash = password_hash('FixtureOnly123', PASSWORD_DEFAULT);
    $adminId = $model->createWithRole('Test Admin', '', 'test.admin', $hash, 'admin');
    $staff = $admin = $model->findById($adminId);
    $parentId = $model->createApplicant('Parent with records', '', null, $hash, '09171234567');
    $inactiveId = $model->createApplicant('Inactive parent', '', null, $hash, '09181234567');
    $emptyId = $model->createApplicant('Account without records', '', null, $hash, '09191234567');
    $slotStaffId = $model->createWithRole('Slot creator', '', 'slot.staff', $hash, 'mswd_social_worker');
    $interviewStaffId = $model->createWithRole('Interview scheduler', '', 'interview.staff', $hash, 'mswd_social_worker');
    $pdo->prepare('INSERT INTO applications (user_id) VALUES (?), (?)')->execute([$parentId, $inactiveId]);
    $pdo->prepare('INSERT INTO appointment_slots (created_by) VALUES (?)')->execute([$slotStaffId]);
    $pdo->prepare('INSERT INTO appointments (scheduled_by) VALUES (?)')->execute([$interviewStaffId]);
    $model->setActive($inactiveId, false);

    $renderActions = function () use ($service, $staff): array {
        $result = $service->list('', '', '', 1);
        $users = $result['rows']; $pager = $result;
        $error = $success = ''; $form = [];
        $filters = ['role' => '', 'status' => '', 'q' => ''];
        $roleLabels = User::ROLE_LABELS;
        $statusFilters = UserManagementService::STATUS_FILTERS;
        ob_start();
        require APP_ROOT . '/views/admin/users/index.php';
        $html = ob_get_clean();
        $actions = [];
        foreach ($users as $user) {
            foreach (preg_split('~<tr>~', $html) as $row) {
                $row = explode('</tr>', $row)[0];
                if (!str_contains($row, 'user_edit.php?id=' . $user['id'] . '"')) { continue; }
                preg_match_all('~name="action" value="([^"]+)"~', $row, $values);
                $actions[$user['id']] = $values[1];
                break;
            }
        }
        return $actions;
    };
    $actions = $renderActions();
    action_check($actions[$adminId] === [], 'Own account exposes a destructive action.');
    foreach ([$parentId, $slotStaffId, $interviewStaffId] as $id) {
        action_check($actions[$id] === ['deactivate'], 'Protected active account must offer Deactivate, not Delete.');
    }
    action_check($actions[$inactiveId] === ['activate'], 'Protected inactive account must offer Activate.');
    action_check($actions[$emptyId] === ['delete'], 'Account without linked records should offer Delete.');
    [$error] = $service->delete($admin, $model->findById($parentId));
    action_check($error !== '' && $model->findById($parentId) !== null, 'Server allowed deletion of application history.');
    [$error] = $service->setActive($admin, $model->findById($parentId), false);
    action_check($error === '' && !$model->findById($parentId)['is_active'], 'Deactivate failed.');
    action_check((int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() === 2, 'Deactivation removed application records.');
    action_check($renderActions()[$parentId] === ['activate'], 'Deactivated account does not offer Activate.');
    [$error] = $service->setActive($admin, $model->findById($parentId), true);
    action_check($error === '' && $model->findById($parentId)['is_active'], 'Activate failed.');
    [$error] = $service->setActive($admin, $admin, false);
    action_check($error !== '', 'Self-deactivation was allowed.');
    [$error] = $service->delete($admin, $model->findById($emptyId));
    action_check($error === '' && $model->findById($emptyId) === null, 'Unlinked account could not be deleted.');
    echo "PASS: protected account actions, slot/interview references, deactivate/reactivate, retained applications, own-account protection and eligible deletion. No live accounts changed.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE IF EXISTS users, applicants, audit_logs, applications, appointment_slots, appointments');
}
