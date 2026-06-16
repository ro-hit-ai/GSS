<?php
/**
 * Runtime tracer for required_count lifecycle.
 * Direct PHP — does NOT require HTTP session.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/api/shared/case_component_binding.php';
require_once __DIR__ . '/api/shared/candidate_correction_service.php';

$pdo = getDB();
$targetApplicationId = 'APP-20260612103142613';

if (!function_exists('ccs_component_norm')) {
    function ccs_component_norm(string $k): string {
        $k = strtolower(trim($k));
        $k = str_replace(['-', ' '], '_', $k);
        if ($k === 'basic_details' || $k === 'basic_detail') return 'basic';
        if ($k === 'identification') return 'id';
        if ($k === 'contact_information' || $k === 'contact_details' || $k === 'contact_detail') return 'contact';
        if ($k === 'education_details' || $k === 'education_detail') return 'education';
        if ($k === 'employment_details' || $k === 'employment_detail') return 'employment';
        if ($k === 'references') return 'reference';
        if ($k === 'e_court' || $k === 'ecourt_check') return 'ecourt';
        if ($k === 'social' || $k === 'social_media') return 'socialmedia';
        return $k;
    }
}

function trace_gate_match(string $componentKey, array $requiredCaseComponentSet): bool {
    if (isset($requiredCaseComponentSet[$componentKey])) return true;
    if ($componentKey === 'reference') {
        return isset($requiredCaseComponentSet['education_reference'])
            || isset($requiredCaseComponentSet['employment_reference']);
    }
    return false;
}

// ============================================================
// STEP 1: Find a real case with multiple education/employment types
// ============================================================
echo "=== STEP 1: Find Case ===\n";
$caseStmt = $pdo->prepare("
    SELECT c.case_id, c.application_id, c.client_id, c.job_role,
           COALESCE(c.selected_level,'') AS selected_level,
           COALESCE(c.selected_stage,'') AS selected_stage
    FROM Vati_Payfiller_Cases c
    WHERE c.application_id = ?
    LIMIT 1
");
$caseStmt->execute([$targetApplicationId]);
$cases = $caseStmt->fetchAll(PDO::FETCH_ASSOC);

$targetCase = null;
foreach ($cases as $c) {
    echo "  case_id={$c['case_id']} app={$c['application_id']} role={$c['job_role']} level={$c['selected_level']} stage={$c['selected_stage']}\n";
    if (!$targetCase) $targetCase = $c;
}

if (!$targetCase) { echo "No cases found.\n"; exit; }
echo "\nUsing: case_id={$targetCase['case_id']} application_id={$targetCase['application_id']}\n";

$caseId = (int)$targetCase['case_id'];
$applicationId = $targetCase['application_id'];
$clientId = (int)$targetCase['client_id'];
$jobRoleName = $targetCase['job_role'];
$selectedLevel = $targetCase['selected_level'];
$selectedStage = $targetCase['selected_stage'];

// ============================================================
// STEP 2: Resolve job_role_id
// ============================================================
echo "\n=== STEP 2: Resolve Job Role ===\n";
$jr = $pdo->prepare("SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1");
$jr->execute([$clientId, $jobRoleName]);
$jobRoleId = (int)($jr->fetchColumn() ?: 0);
echo "  job_role_id={$jobRoleId} role_name={$jobRoleName}\n";

if ($jobRoleId <= 0) { echo "Job role not found.\n"; exit; }

// ============================================================
// STEP 3: ALL types for this job_role (GSS Admin config)
// ============================================================
echo "\n=== STEP 3: ALL Verification Types (GSS Admin Config) ===\n";
$allTypes = $pdo->prepare("
    SELECT j.verification_type_id, j.is_enabled, j.sort_order, j.required_count,
           j.level_key, j.stage_key, COALESCE(NULLIF(TRIM(j.component_key), ''), '') AS component_key,
           t.type_name, t.type_category
    FROM Vati_Payfiller_Job_Role_Verification_Types j
    LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
    WHERE j.job_role_id = ?
    ORDER BY j.level_key, j.stage_key, j.sort_order
");
$allTypes->execute([$jobRoleId]);
$rows = $allTypes->fetchAll(PDO::FETCH_ASSOC);
echo "  Count: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  [{$r['verification_type_id']}] {$r['type_name']} (cat={$r['type_category']})"
        . " | level={$r['level_key']} stage={$r['stage_key']}"
        . " | required_count={$r['required_count']}"
        . " | component_key={$r['component_key']}"
        . " | is_enabled={$r['is_enabled']}\n";
}

// ============================================================
// STEP 4: case_verification_config.php style query
// replicated exactly from case_verification_config.php lines 198-244
// ============================================================
echo "\n=== STEP 4: case_verification_config.php style query ===\n";
echo "  selected_level='$selectedLevel' selected_stage='$selectedStage'\n";

$selectedStageNorm = strtolower($selectedStage);
if ($selectedStageNorm === 'p1') $selectedStageNorm = 'pre_interview';
if ($selectedStageNorm === 'p2') $selectedStageNorm = 'post_interview';
if ($selectedStageNorm === 'p3') $selectedStageNorm = 'employee_pool';

$sql = 'SELECT j.verification_type_id, j.is_enabled, j.sort_order, j.required_count,
               t.type_name, t.type_category
          FROM Vati_Payfiller_Job_Role_Verification_Types j
          LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
         WHERE j.job_role_id = ?';
$params = [$jobRoleId];

if ($selectedLevel !== '') {
    $sql .= ' AND LOWER(TRIM(COALESCE(j.level_key, ""))) = LOWER(TRIM(?))';
    $params[] = $selectedLevel;
    echo "  Level filter APPLIED: $selectedLevel\n";
} else {
    echo "  Level filter SKIPPED (empty)\n";
}

if ($selectedStageNorm !== '') {
    $sql .= ' AND LOWER(TRIM(COALESCE(j.stage_key, ""))) = LOWER(TRIM(?))';
    $params[] = $selectedStageNorm;
    echo "  Stage filter APPLIED: $selectedStageNorm\n";
} else {
    echo "  Stage filter SKIPPED (empty)\n";
}

$sql .= ' ORDER BY COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$configTypes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
echo "  Matching types: " . count($configTypes) . "\n";
$configRequiredCounts = [];
foreach ($configTypes as $t) {
    $name = $t['type_name'] ?? '';
    $cat = $t['type_category'] ?? '';
    $req = (int)($t['required_count'] ?? 1);
    if ($req <= 0) $req = 1;
    
    // Map like case_component_binding_map_verification_type_to_components
    $nameNorm = strtolower(trim($name));
    $catNorm = strtolower(trim($cat));
    
    echo "  [{$t['verification_type_id']}] $name (cat=$cat) required_count=$req\n";
    
    $exactTypeMap = [
        'education' => 'education', 'employment' => 'employment',
        'employement' => 'employment',
        'education reference' => 'education_reference',
        'employment reference' => 'employment_reference',
        'identity proof' => 'id', 'current address' => 'contact',
        'permanent address' => 'contact',
        'current or permanent address' => 'contact',
        'ecourt' => 'ecourt', 'e-court' => 'ecourt',
        'social media' => 'socialmedia',
    ];
    $exactCategoryMap = [
        'education' => 'education', 'employment' => 'employment',
        'reference' => 'reference', 'identity' => 'id',
        'identification' => 'id', 'address' => 'contact',
        'address verification' => 'contact', 'contact' => 'contact',
        'ecourt' => 'ecourt', 'social media' => 'socialmedia',
    ];
    
    $components = [];
    if ($nameNorm !== '' && isset($exactTypeMap[$nameNorm])) {
        $components = [$exactTypeMap[$nameNorm]];
    } elseif ($catNorm !== '' && isset($exactCategoryMap[$catNorm])) {
        $components = [$exactCategoryMap[$catNorm]];
    }
    
    echo "    mapped to component(s): " . json_encode($components) . "\n";
    
    foreach ($components as $ck) {
        if (!isset($configRequiredCounts[$ck]) || $configRequiredCounts[$ck] < $req) {
            $configRequiredCounts[$ck] = $req;
        }
    }
}

echo "  === FINAL required_counts ===\n";
echo "  " . json_encode($configRequiredCounts, JSON_PRETTY_PRINT) . "\n";

// ============================================================
// STEP 5: case_component_binding_fetch_types() style query
// replicated from case_component_binding.php lines 230-329
// ============================================================
echo "\n=== STEP 5: case_component_binding_fetch_types() style query ===\n";

[$selectedStageParsed, $selectedLevelParsed] = case_component_binding_parse_stage_level($selectedStage, $selectedLevel);
echo "  Parsed: level='$selectedLevelParsed' stage='$selectedStageParsed'\n";

if ($selectedLevelParsed === '' || $selectedStageParsed === '') {
    echo "  STRICT MODE: empty filter -> no types\n";
    $bindingTypes = [];
} else {
    try {
        $bindSql = 'SELECT j.verification_type_id, j.is_enabled, t.type_name, t.type_category, j.level_key, j.stage_key,
                           j.required_count,
                           COALESCE(NULLIF(TRIM(j.component_key), \'\'), \'\') AS component_key
                      FROM Vati_Payfiller_Job_Role_Verification_Types j
                 LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                     WHERE j.job_role_id = ?
                       AND (
                            LOWER(TRIM(COALESCE(j.level_key, ""))) = LOWER(?)
                            OR (
                                TRIM(COALESCE(j.level_key, "")) = ""
                                AND LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE CONCAT("%__", LOWER(?))
                            )
                       )
                       AND (
                            CASE
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("pre_interview", "p1") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "pre_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p1__%" THEN "pre_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("post_interview", "p2") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "post_interview__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p2__%" THEN "post_interview"
                                WHEN LOWER(TRIM(COALESCE(j.stage_key, ""))) IN ("employee_pool", "p3") OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "employee_pool__%" OR LOWER(TRIM(COALESCE(j.stage_key, ""))) LIKE "p3__%" THEN "employee_pool"
                                ELSE LOWER(TRIM(COALESCE(j.stage_key, "")))
                            END
                       ) = ?
                  ORDER BY COALESCE(j.stage_key, "") ASC, COALESCE(j.level_key, "") ASC, COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
        $bindStmt = $pdo->prepare($bindSql);
        $bindStmt->execute([$jobRoleId, $selectedLevelParsed, $selectedLevelParsed, $selectedStageParsed]);
        $bindingTypes = $bindStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
        $bindingTypes = [];
    }
}

echo "  Binding matching types: " . count($bindingTypes) . "\n";
foreach ($bindingTypes as $bt) {
    $dbKey = $bt['component_key'] ?? '';
    $components = [];
    if ($dbKey !== '') {
        $components[] = case_component_binding_norm_component_key($dbKey);
    } else {
        $components = case_component_binding_map_verification_type_to_components(
            (string)($bt['type_name'] ?? ''), (string)($bt['type_category'] ?? '')
        );
    }
    echo "  [{$bt['verification_type_id']}] {$bt['type_name']} required_count={$bt['required_count']} component_key='{$bt['component_key']}' resolved=" . json_encode($components) . "\n";
}

// ============================================================
// STEP 6: Case_Components actual content
// ============================================================
echo "\n=== STEP 6: Vati_Payfiller_Case_Components ===\n";
$cc = $pdo->prepare("SELECT * FROM Vati_Payfiller_Case_Components WHERE application_id = ? ORDER BY component_key");
$cc->execute([$applicationId]);
$ccRows = $cc->fetchAll(PDO::FETCH_ASSOC);
echo "  Count: " . count($ccRows) . "\n";
$requiredCaseComponentSet = [];
foreach ($ccRows as $r) {
    $k = ccs_component_norm((string)($r['component_key'] ?? ''));
    if ((int)($r['is_required'] ?? 0) === 1 && $k !== '' && $k !== 'reports') $requiredCaseComponentSet[$k] = true;
    echo "  " . json_encode($r, JSON_UNESCAPED_SLASHES) . "\n";
}
echo "  Normalized set: " . json_encode(array_keys($requiredCaseComponentSet)) . "\n";

// ============================================================
// STEP 7: Gate check simulation
// ============================================================
echo "\n=== STEP 7: Gate check simulation ===\n";
if (!empty($requiredCaseComponentSet)) {
    foreach ($configTypes as $t) {
        $name = $t['type_name'] ?? '';
        $cat = $t['type_category'] ?? '';
        $req = (int)($t['required_count'] ?? 1);
        
        $nameNorm = strtolower(trim($name));
        $catNorm = strtolower(trim($cat));
        
        $exactTypeMap = ['education' => 'education', 'employment' => 'employment'];
        $exactCategoryMap = ['education' => 'education', 'employment' => 'employment'];
        
        $components = [];
        if ($nameNorm !== '' && isset($exactTypeMap[$nameNorm])) {
            $components = [$exactTypeMap[$nameNorm]];
        } elseif ($catNorm !== '' && isset($exactCategoryMap[$catNorm])) {
            $components = [$exactCategoryMap[$catNorm]];
        }
        
        $hasGateMatch = false;
        foreach ($components as $gk) {
            if (isset($requiredCaseComponentSet[$gk]) || 
                ($gk === 'reference' && (isset($requiredCaseComponentSet['education_reference']) || isset($requiredCaseComponentSet['employment_reference'])))) {
                $hasGateMatch = true;
                echo "  [{$t['verification_type_id']}] $name → $gk → GATE PASS (required_count=$req)\n";
                break;
            }
        }
        if (!$hasGateMatch) {
            echo "  [{$t['verification_type_id']}] $name → " . json_encode($components) . " → GATE SKIP ✗ (NOT in CaseComponents)\n";
        }
    }
} else {
    echo "  CaseComponents set is empty — gate bypassed, all types pass\n";
}

echo "\n=== STEP 7B: case_verification_config.php gate trace fields ===\n";
if (!empty($requiredCaseComponentSet)) {
    foreach ($configTypes as $t) {
        $name = (string)($t['type_name'] ?? '');
        $cat = (string)($t['type_category'] ?? '');
        $req = (int)($t['required_count'] ?? 1);
        if ($req <= 0) $req = 1;

        $resolvedComponents = case_component_binding_map_verification_type_to_components($name, $cat);
        $resolvedComponents = array_values(array_unique(array_filter(array_map(function ($k) {
            return ccs_component_norm((string)$k);
        }, $resolvedComponents), function ($k) {
            return $k !== '';
        })));

        $hasGateMatch = false;
        foreach ($resolvedComponents as $gk) {
            if (trace_gate_match($gk, $requiredCaseComponentSet)) {
                $hasGateMatch = true;
                break;
            }
        }

        $isEducationOrEmployment = in_array('education', $resolvedComponents, true)
            || in_array('employment', $resolvedComponents, true)
            || strtolower(trim($name)) === 'education'
            || strtolower(trim($name)) === 'employment'
            || strtolower(trim($cat)) === 'education'
            || strtolower(trim($cat)) === 'employment';

        if (!$isEducationOrEmployment) {
            continue;
        }

        foreach ($resolvedComponents as $componentKey) {
            echo "  GATE_TRACE " . json_encode([
                'verification_type_id' => (int)($t['verification_type_id'] ?? 0),
                'type_name' => $name,
                'type_category' => $cat,
                'resolvedComponents' => $resolvedComponents,
                'requiredCaseComponentSet' => array_keys($requiredCaseComponentSet),
                'hasGateMatch' => $hasGateMatch,
                'required_count' => $req,
                'componentKey' => $componentKey,
                'would_skip_at_continue' => !$hasGateMatch,
            ], JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
} else {
    echo "  requiredCaseComponentSet empty; gate bypassed.\n";
}

// ============================================================
// STEP 8: Stage steps merge simulation
// ============================================================
echo "\n=== STEP 8: Stage Steps Merge ===\n";
try {
    $stageSql = 'SELECT s.verification_type_id, s.is_active AS is_enabled, s.execution_group AS sort_order,
                        1 AS required_count, t.type_name, t.type_category
                   FROM Vati_Payfiller_Job_Role_Stage_Steps s
                   LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = s.verification_type_id
                  WHERE s.job_role_id = ?';
    $stageParams = [$jobRoleId];
    if ($selectedStageNorm !== '') {
        $stageSql .= ' AND LOWER(TRIM(COALESCE(s.stage_key, ""))) = LOWER(TRIM(?))';
        $stageParams[] = $selectedStageNorm;
    }
    $stageSql .= ' ORDER BY COALESCE(s.stage_key, "") ASC, COALESCE(s.execution_group, 0) ASC, COALESCE(t.type_name, "") ASC';
    $stageStmt = $pdo->prepare($stageSql);
    $stageStmt->execute($stageParams);
    $stageTypes = $stageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo "  Stage step types: " . count($stageTypes) . "\n";
    foreach ($stageTypes as $st) {
        $alreadyFound = false;
        foreach ($configTypes as $ct) {
            if ((int)($ct['verification_type_id'] ?? 0) === (int)($st['verification_type_id'] ?? 0)) {
                $alreadyFound = true;
                break;
            }
        }
        echo "  [{$st['verification_type_id']}] {$st['type_name']} required_count={$st['required_count']}" . ($alreadyFound ? " (DUPLICATE, skipped)" : " (NEW, would MERGE)") . "\n";
    }
} catch (Throwable $e) {
    echo "  Error: " . $e->getMessage() . "\n";
}

echo "\n=== DONE ===\n";
echo "Expected required_counts: " . json_encode($configRequiredCounts) . "\n";
