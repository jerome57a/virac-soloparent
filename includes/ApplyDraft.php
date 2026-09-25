<?php
/**
 * Session-backed draft for the 4-step Apply Online form, plus the
 * server-side validation that mirrors the design handoff's client rules.
 * The draft (and any uploaded-but-not-yet-submitted files) live only in
 * the applicant's session until final submission creates real DB rows.
 */

function apply_fresh_draft(array $user): array
{
    return [
        'step' => 1,
        'fullName' => $user['full_name'] ?? '',
        'barangay' => '',
        'address' => '',
        'contact' => '',
        'email' => $user['email'] ?? '',
        'birthdate' => '',
        'sex' => '',
        'civilStatus' => '',
        'categoryId' => null,
        'children' => [['name' => '', 'birthdate' => '']],
        'documents' => [],
        'docErrors' => [],
        'consent' => false,
    ];
}

function apply_get_draft(array $user): array
{
    if (empty($_SESSION['apply_draft'])) {
        $_SESSION['apply_draft'] = apply_fresh_draft($user);
    }
    return $_SESSION['apply_draft'];
}

function apply_save_draft(array $draft): void
{
    $_SESSION['apply_draft'] = $draft;
}

function apply_clear_draft(): void
{
    unset($_SESSION['apply_draft']);
    apply_delete_tmp_dir();
}

function apply_tmp_dir(): string
{
    return STORAGE_PATH . '/tmp/' . session_id();
}

function apply_delete_tmp_dir(): void
{
    $dir = apply_tmp_dir();
    if (!is_dir($dir)) {
        return;
    }
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

/** Merge the fields belonging to $step out of $post into the draft (no validation). */
function apply_collect_step_input(int $step, array $draft, array $post): array
{
    if ($step === 1) {
        foreach (['fullName', 'barangay', 'address', 'contact', 'email', 'birthdate', 'sex', 'civilStatus'] as $f) {
            $draft[$f] = trim((string) ($post[$f] ?? ''));
        }
        $draft['categoryId'] = $post['categoryId'] !== '' && isset($post['categoryId']) ? (int) $post['categoryId'] : null;
    }
    if ($step === 2) {
        $children = [];
        $names = $post['child_name'] ?? [];
        $dates = $post['child_birthdate'] ?? [];
        $count = max(count($names), count($dates));
        for ($i = 0; $i < $count; $i++) {
            $children[] = [
                'name' => trim((string) ($names[$i] ?? '')),
                'birthdate' => trim((string) ($dates[$i] ?? '')),
            ];
        }
        $draft['children'] = $children ?: [['name' => '', 'birthdate' => '']];
    }
    if ($step === 4) {
        $draft['consent'] = !empty($post['consent']);
    }
    return $draft;
}

function apply_doc_defs(PDO $pdo, ?int $categoryId): array
{
    return get_document_requirements($pdo, $categoryId);
}

function apply_category_only_defs(PDO $pdo, ?int $categoryId): array
{
    return get_category_only_requirements($pdo, $categoryId);
}

function apply_validate_step(PDO $pdo, int $step, array $draft): array
{
    $errors = [];
    $today = today_iso();

    if ($step === 1) {
        $fullName = trim($draft['fullName']);
        if ($fullName === '') {
            $errors['fullName'] = 'Enter your full name.';
        } elseif (count(preg_split('/\s+/', $fullName)) < 2) {
            $errors['fullName'] = 'Enter your first and last name.';
        }
        if (trim($draft['barangay']) === '') {
            $errors['barangay'] = 'Enter your barangay.';
        }
        if (trim($draft['address']) === '') {
            $errors['address'] = 'Enter your complete address.';
        }
        $phone = normalize_mobile($draft['contact']);
        if ($phone === '') {
            $errors['contact'] = 'Enter your mobile number.';
        } elseif (!is_valid_ph_mobile($phone)) {
            $errors['contact'] = 'Use a valid PH mobile number, e.g. 09171234567.';
        }
        if (trim($draft['email']) === '') {
            $errors['email'] = 'Enter your email.';
        } elseif (!filter_var(trim($draft['email']), FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($draft['birthdate'] === '') {
            $errors['birthdate'] = 'Select your birthdate.';
        } elseif ($draft['birthdate'] > $today) {
            $errors['birthdate'] = 'Birthdate cannot be in the future.';
        }
        if (!in_array($draft['sex'], ['Male', 'Female'], true)) {
            $errors['sex'] = 'Select an option.';
        }
        if (!in_array($draft['civilStatus'], CIVIL_STATUS_OPTIONS, true)) {
            $errors['civilStatus'] = 'Select your civil status.';
        }
        if (!$draft['categoryId']) {
            $errors['circumstance'] = 'Select your solo parent category.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM categories WHERE id = ?');
            $stmt->execute([$draft['categoryId']]);
            if (!$stmt->fetch()) {
                $errors['circumstance'] = 'Select your solo parent category.';
            }
        }
    }

    if ($step === 2) {
        foreach ($draft['children'] as $i => $c) {
            if (trim($c['name']) === '') {
                $errors['cn' . $i] = "Enter the child's full name.";
            }
            if ($c['birthdate'] === '') {
                $errors['cd' . $i] = 'Select a birthdate.';
            } elseif ($c['birthdate'] > $today) {
                $errors['cd' . $i] = 'Birthdate cannot be in the future.';
            }
        }
    }

    if ($step === 3) {
        $docDefs = apply_doc_defs($pdo, $draft['categoryId']);
        foreach ($docDefs as $d) {
            if (!$d['is_required']) {
                continue;
            }
            if (empty($draft['documents'][$d['doc_key']])) {
                $errors['doc_' . $d['doc_key']] = 'This document is required.';
            }
        }
    }

    if ($step === 4 && empty($draft['consent'])) {
        $errors['consent'] = 'Please confirm to submit.';
    }

    return $errors;
}
