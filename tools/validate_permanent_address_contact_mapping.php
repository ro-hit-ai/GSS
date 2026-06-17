<?php
declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/config/db.php';
require_once $root . '/api/shared/case_management/case_component_binding.php';
require_once $root . '/api/shared/workflow/workflow_semantics.php';

$pdo = getDB();
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    case_component_binding_map_verification_type_to_components('Permanent Address', 'Address Verification') === ['contact'],
    'Permanent Address must resolve to contact.'
);
$assert(
    case_component_binding_contact_subsection('Permanent Address', 'Address Verification') === 'permanent_address',
    'Permanent Address must resolve to the permanent_address subsection.'
);
$assert(
    case_component_binding_contact_subsection('Current OR Permanent Address', 'Address Verification') === 'current_and_permanent_address',
    'Current OR Permanent Address must keep both address subsections.'
);
$assert(
    wf_verifier_group_components('BASIC') === ['basic', 'id', 'contact'],
    'Verifier BASIC group must remain unchanged.'
);
$assert(
    !in_array('permanent_address', wf_verifier_group_components('BASIC'), true),
    'Permanent Address must not become a standalone verifier-group component.'
);

try {
    $stmt = $pdo->query(
        "SELECT COUNT(*)
           FROM Vati_Payfiller_Job_Role_Verification_Types j
           JOIN Vati_Payfiller_Verification_Types t
             ON t.verification_type_id = j.verification_type_id
          WHERE LOWER(TRIM(t.type_name)) = 'permanent address'
            AND LOWER(TRIM(COALESCE(j.component_key, ''))) <> 'contact'"
    );
    $badMappings = (int)($stmt->fetchColumn() ?: 0);
    $assert($badMappings === 0, 'Permanent Address job-role mappings must stay on contact.');
} catch (Throwable $e) {
    $failures[] = 'Unable to validate Permanent Address job-role mappings: ' . $e->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Permanent Address semantic guard failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '- ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "Permanent Address semantic guard passed.\n");
exit(0);
