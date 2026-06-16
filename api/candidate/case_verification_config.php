<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/candidate_correction_service.php';
require_once __DIR__ . '/../shared/case_component_binding.php';

session_start();

function str_contains_ci(string $haystack, string $needle): bool {
    return stripos($haystack, $needle) !== false;
}

function extract_required_count(array $row): int {
    $keys = [
        'required_count',
        'requiredCount',
        'verification_count',
        'no_of_verifications',
        'no_of_checks',
        'count_required'
    ];
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) {
            $v = (int)$row[$k];
            return $v > 0 ? $v : 1;
        }
    }
    return 1;
}

function candidate_cfg_text_has_any(string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains_ci($haystack, (string)$needle)) {
            return true;
        }
    }
    return false;
}

function detect_reference_sections(string $typeName, string $typeCategory): array {
    $hay = strtolower(trim($typeName . ' ' . $typeCategory));
    if (!candidate_cfg_text_has_any($hay, ['reference', 'referee', 'ref check', 'ref-check'])) {
        return [];
    }

    $out = [];
    if (candidate_cfg_text_has_any($hay, ['education', 'academic', 'qualification', 'degree', 'college', 'university'])) {
        $out[] = 'education_reference';
    }
    if (candidate_cfg_text_has_any($hay, ['employment', 'employee', 'employer', 'professional', 'work', 'experience'])) {
        $out[] = 'employment_reference';
    }

    return array_values(array_unique($out));
}

function detect_contact_sections(string $typeName, string $typeCategory): array {
    return case_component_binding_detect_contact_sections($typeName, $typeCategory);
}

function candidate_page_from_component_key(string $componentKey): string {
    $k = ccs_component_norm($componentKey);
    $map = [
        'basic' => 'basic-details',
        'id' => 'identification',
        'contact' => 'contact',
        'education' => 'education',
        'employment' => 'employment',
        'reference' => 'reference',
        'education_reference' => 'reference',
        'employment_reference' => 'reference',
        'ecourt' => 'ecourt',
        'socialmedia' => 'social',
    ];
    return $map[$k] ?? '';
}

function candidate_component_gate_matches(string $componentKey, array $requiredCaseComponentSet): bool {
    if (isset($requiredCaseComponentSet[$componentKey])) {
        return true;
    }
    if ($componentKey === 'reference') {
        return isset($requiredCaseComponentSet['education_reference'])
            || isset($requiredCaseComponentSet['employment_reference']);
    }
    return false;
}

function candidate_extract_identification_requirements(array $types, string $country): array {
    $countryNorm = strtolower(trim($country));
    $keywords = [];
    foreach ($types as $type) {
        $name = strtolower(trim((string)($type['type_name'] ?? $type['raw_type_name'] ?? '')));
        $cat = strtolower(trim((string)($type['type_category'] ?? '')));
        $hay = $name . ' ' . $cat;
        if (strpos($hay, 'ssn') !== false) $keywords['SSN'] = true;
        if (strpos($hay, 'ofac') !== false) $keywords['OFAC'] = true;
        if (strpos($hay, 'nin') !== false || strpos($hay, 'national insurance') !== false) $keywords['NIN'] = true;
        if (strpos($hay, 'passport') !== false) $keywords['Passport'] = true;
        if (strpos($hay, 'aadhaar') !== false || strpos($hay, 'aadhar') !== false) $keywords['Aadhaar'] = true;
        if (strpos($hay, 'pan') !== false) $keywords['PAN'] = true;
        if (strpos($hay, 'voter') !== false) $keywords['Voter ID'] = true;
        if (strpos($hay, 'driving') !== false || strpos($hay, 'driver') !== false || strpos($hay, 'licence') !== false || strpos($hay, 'license') !== false) {
            $keywords[$countryNorm === 'usa' || $countryNorm === 'united states' ? 'Driver License' : 'Driving Licence'] = true;
        }
    }
    if ($countryNorm === 'india') {
        return [
            ['group_key' => 'core_identity', 'group_label' => 'Driving Licence / Aadhaar', 'types' => ['Driving Licence', 'Aadhaar']],
            ['group_key' => 'secondary_identity', 'group_label' => 'PAN / Passport / Voter ID', 'types' => ['PAN', 'Passport', 'Voter ID']],
        ];
    }
    $dynamic = array_values(array_keys($keywords));
    if (empty($dynamic)) {
        if ($countryNorm === 'usa' || $countryNorm === 'united states') {
            $dynamic = ['SSN', 'OFAC', 'Passport', 'Driver License'];
        } elseif ($countryNorm === 'uk' || $countryNorm === 'united kingdom') {
            $dynamic = ['Passport', 'Driving Licence', 'NIN'];
        } else {
            $dynamic = ['Passport', 'National ID'];
        }
    }
    return [[
        'group_key' => 'international_identity',
        'group_label' => 'International Identification Uploads',
        'types' => $dynamic,
    ]];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $caseId = isset($_SESSION['case_id']) ? (int)$_SESSION['case_id'] : 0;
    $applicationId = isset($_SESSION['application_id']) ? (string)$_SESSION['application_id'] : '';

    if ($caseId <= 0 && $applicationId === '') {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();
    $debugEnabled = isset($_GET['debug']) && (string)$_GET['debug'] === '1';

    $case = null;
    if ($caseId > 0) {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id, selected_level, selected_stage, case_status FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$case && $applicationId !== '') {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id, selected_level, selected_stage, case_status FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $stmt->execute([$applicationId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
    $jobRoleName = trim((string)($case['job_role'] ?? ''));
    $caseStatus = trim((string)($case['case_status'] ?? ''));
    $applicationStatus = '';
    $applicationSubmittedAt = '';
    try {
        $appStatusStmt = $pdo->prepare(
            'SELECT status, submitted_at
               FROM Vati_Payfiller_Candidate_Applications
              WHERE application_id = ?
              ORDER BY id DESC
              LIMIT 1'
        );
        $appStatusStmt->execute([(string)($case['application_id'] ?? $applicationId)]);
        $appStatusRow = $appStatusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $applicationStatus = trim((string)($appStatusRow['status'] ?? ''));
        $applicationSubmittedAt = trim((string)($appStatusRow['submitted_at'] ?? ''));
    } catch (Throwable $e) {
        $applicationStatus = '';
        $applicationSubmittedAt = '';
    }

    $jobRoleId = 0;
    if ($clientId > 0 && $jobRoleName !== '') {
        $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
        $jr->execute([$clientId, $jobRoleName]);
        $jobRoleId = (int)($jr->fetchColumn() ?: 0);
    }

    $selectedLevel = trim((string)($case['selected_level'] ?? ''));
    $selectedStageRaw = trim((string)($case['selected_stage'] ?? ''));
    $selectedStage = $selectedStageRaw;
    if (strpos($selectedStageRaw, '__') !== false) {
        $parts = explode('__', $selectedStageRaw, 2);
        $selectedStage = trim((string)($parts[0] ?? ''));
        $stageLevel = trim((string)($parts[1] ?? ''));
        if ($selectedLevel === '' && $stageLevel !== '') {
            $selectedLevel = $stageLevel;
        }
    }
    $selectedStageNorm = strtolower($selectedStage);
    if ($selectedStageNorm === 'p1') $selectedStageNorm = 'pre_interview';
    if ($selectedStageNorm === 'p2') $selectedStageNorm = 'post_interview';
    if ($selectedStageNorm === 'p3') $selectedStageNorm = 'employee_pool';
    $candidateCountry = 'India';
    try {
        $countryStmt = $pdo->prepare('SELECT country FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ? LIMIT 1');
        $countryStmt->execute([(string)($case['application_id'] ?? $applicationId)]);
        $candidateCountry = trim((string)($countryStmt->fetchColumn() ?: 'India'));
        if ($candidateCountry === '') $candidateCountry = 'India';
    } catch (Throwable $e) {
        $candidateCountry = 'India';
    }

    $types = [];
    if ($jobRoleId > 0) {
        try {
            $sql = 
                'SELECT j.verification_type_id, j.is_enabled, j.sort_order, j.required_count,
                        t.type_name, t.type_category
                   FROM Vati_Payfiller_Job_Role_Verification_Types j
                   LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                  WHERE j.job_role_id = ?';
            $params = [$jobRoleId];
            if ($selectedLevel !== '') {
                $sql .= ' AND LOWER(TRIM(COALESCE(j.level_key, ""))) = LOWER(TRIM(?))';
                $params[] = $selectedLevel;
            }
            if ($selectedStageNorm !== '') {
                $sql .= ' AND LOWER(TRIM(COALESCE(j.stage_key, ""))) = LOWER(TRIM(?))';
                $params[] = $selectedStageNorm;
            }
            $sql .= ' ORDER BY COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            error_log("TRACE_TYPES: " . json_encode(array_map(function($t) {
                return ['id' => $t['verification_type_id'], 'name' => $t['type_name'] ?? '', 'cat' => $t['type_category'] ?? '', 'count' => (int)($t['required_count'] ?? 1)];
            }, $types)));
        } catch (Throwable $e) {
            try {
                $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByJobRole(?)');
                $stmt->execute([$jobRoleId]);
                $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                while ($stmt->nextRowset()) {
                }
            } catch (Throwable $e2) {
                $types = [];
            }
        }

        try {
            $stageSql =
                'SELECT s.verification_type_id, s.is_active AS is_enabled, s.execution_group AS sort_order,
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

            if (!empty($stageTypes)) {
                $existingByTypeId = [];
                foreach ($types as $existingType) {
                    $existingVtId = isset($existingType['verification_type_id']) ? (int)$existingType['verification_type_id'] : 0;
                    if ($existingVtId > 0) {
                        $existingByTypeId[$existingVtId] = true;
                    }
                }

                foreach ($stageTypes as $stageType) {
                    $stageVtId = isset($stageType['verification_type_id']) ? (int)$stageType['verification_type_id'] : 0;
                    if ($stageVtId <= 0 || isset($existingByTypeId[$stageVtId])) {
                        continue;
                    }
                    $types[] = $stageType;
                    $existingByTypeId[$stageVtId] = true;
                }
            }
        } catch (Throwable $e) {
        }
        error_log("TRACE_AFTER_MERGE: types=" . json_encode(array_map(function($t) {
            return ['id' => $t['verification_type_id'], 'name' => $t['type_name'] ?? '', 'count' => (int)($t['required_count'] ?? 1)];
        }, $types ?? [])));
    }

    $shouldFallbackToAllPages = ($jobRoleId <= 0 || count($types) === 0);
    $requiredCaseComponentSet = [];
    try {
        $cc = $pdo->prepare("SELECT LOWER(TRIM(component_key)) AS component_key
                               FROM Vati_Payfiller_Case_Components
                              WHERE case_id = ?
                                AND is_required = 1");
        $cc->execute([(int)($case['case_id'] ?? 0)]);
        $ccRows = $cc->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($ccRows as $ccr) {
            $k = ccs_component_norm((string)($ccr['component_key'] ?? ''));
            if ($k !== '' && $k !== 'reports') $requiredCaseComponentSet[$k] = true;
        }
    } catch (Throwable $e) {
        $requiredCaseComponentSet = [];
    }
    error_log("TRACE_COMPONENTS: requiredCaseComponentSet=" . json_encode(array_keys($requiredCaseComponentSet)));

    $enabledPages = [
        'review-confirmation',
        'basic-details',
        'success'
    ];

    $requiredCounts = [];
    $components = [];
    $contactSections = [
        'current_address' => false,
        'permanent_address' => false,
    ];
    $referenceSections = [
        'education_reference' => false,
        'employment_reference' => false,
    ];
    $mappingDebug = [];
    $enabledPagesSkipped = [];
    $identificationRequirements = [];

    // Fallback map for setups where SP does not return required_count.
    $requiredByTypeId = [];
    if ($jobRoleId > 0) {
        try {
            $rcSql =
                'SELECT verification_type_id, required_count
                 FROM Vati_Payfiller_Job_Role_Verification_Types
                 WHERE job_role_id = ?';
            $rcParams = [$jobRoleId];
            if ($selectedLevel !== '') {
                $rcSql .= ' AND LOWER(TRIM(COALESCE(level_key, ""))) = LOWER(TRIM(?))';
                $rcParams[] = $selectedLevel;
            }
            if ($selectedStageNorm !== '') {
                $rcSql .= ' AND LOWER(TRIM(COALESCE(stage_key, ""))) = LOWER(TRIM(?))';
                $rcParams[] = $selectedStageNorm;
            }
            $rcStmt = $pdo->prepare($rcSql);
            $rcStmt->execute($rcParams);
            $rcRows = $rcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rcRows as $rr) {
                $vtId = isset($rr['verification_type_id']) ? (int)$rr['verification_type_id'] : 0;
                $rc = isset($rr['required_count']) ? (int)$rr['required_count'] : 1;
                if ($vtId > 0) {
                    $requiredByTypeId[$vtId] = $rc > 0 ? $rc : 1;
                }
            }
        } catch (Throwable $e) {
            $requiredByTypeId = [];
        }
    }

    foreach ($types as $t) {
        $name = (string)($t['type_name'] ?? '');
        $cat = (string)($t['type_category'] ?? '');
        $isEnabled = isset($t['is_enabled']) ? (int)$t['is_enabled'] : 1;
        if ($isEnabled !== 1) continue;

        $req = extract_required_count($t);
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        if ($vtId > 0 && isset($requiredByTypeId[$vtId])) {
            $req = (int)$requiredByTypeId[$vtId];
        }
        if ($req <= 0) $req = 1;

        $resolvedComponents = case_component_binding_map_verification_type_to_components($name, $cat);
        $resolvedComponents = array_values(array_unique(array_filter(array_map(function ($k) {
            return ccs_component_norm((string)$k);
        }, $resolvedComponents), function ($k) {
            return $k !== '';
        })));

        if (empty($resolvedComponents)) {
            $mappingDebug[] = [
                'verification_type_id' => $vtId,
                'raw_type_name' => $name,
                'resolved_component_key' => '',
                'candidate_page' => '',
                'mapping_source' => 'case_component_binding_map_verification_type_to_components',
                'resolver_owner' => 'case_component_binding',
                'heuristic_used' => false,
                'skipped_reason' => 'no_deterministic_mapping'
            ];
            continue;
        }

        if ($requiredCaseComponentSet) {
            $hasGateMatch = false;
            foreach ($resolvedComponents as $gk) {
                if (candidate_component_gate_matches($gk, $requiredCaseComponentSet)) {
                    $hasGateMatch = true;
                    break;
                }
            }
            if (!$hasGateMatch) {
                error_log("TRACE_GATE_SKIP: vtId=$vtId name=$name components=" . json_encode($resolvedComponents) . " available=" . json_encode(array_keys($requiredCaseComponentSet)) . " reqCount=" . ($reqCount ?? 1));
                $enabledPagesSkipped[] = [
                    'verification_type_id' => $vtId,
                    'raw_type_name' => $name,
                    'resolved_components' => $resolvedComponents,
                    'skipped_reason' => 'not_in_required_case_components'
                ];
                continue;
            }
        }

        foreach ($resolvedComponents as $componentKey) {
            $candidatePage = candidate_page_from_component_key($componentKey);
            if ($candidatePage === '') {
                $enabledPagesSkipped[] = [
                    'verification_type_id' => $vtId,
                    'raw_type_name' => $name,
                    'resolved_components' => $resolvedComponents,
                    'skipped_reason' => 'no_candidate_page_for_component'
                ];
                continue;
            }

            $candidateSubsection = '';
            $displayLabel = $componentKey;

            if ($componentKey === 'contact') {
                $detectedContactSections = detect_contact_sections($name, $cat);
                if (empty($detectedContactSections)) {
                    $detectedContactSections = ['current_address'];
                }
                foreach ($detectedContactSections as $sectionKey) {
                    if (array_key_exists($sectionKey, $contactSections)) {
                        $contactSections[$sectionKey] = true;
                    }
                }
                $candidateSubsection = case_component_binding_contact_subsection($name, $cat);
                $displayLabel = case_component_binding_contact_display_label($name, $cat);
            }
            if ($componentKey === 'reference' || $componentKey === 'education_reference' || $componentKey === 'employment_reference') {
                $nameNorm = strtolower(trim($name));
                if ($componentKey === 'education_reference' || $nameNorm === 'education reference') {
                    $referenceSections['education_reference'] = true;
                } elseif ($componentKey === 'employment_reference' || $nameNorm === 'employment reference') {
                    $referenceSections['employment_reference'] = true;
                } else {
                    $referenceSections['education_reference'] = true;
                    $referenceSections['employment_reference'] = true;
                }
            }

            $enabledPages[] = $candidatePage;
            if (!isset($requiredCounts[$componentKey]) || (int)$requiredCounts[$componentKey] < $req) {
                $requiredCounts[$componentKey] = $req;
            }
            error_log("TRACE_COUNT_ADDED: componentKey=$componentKey name=$name req=$req current=" . json_encode($requiredCounts));

            $components[] = [
                'verification_type_id' => $vtId,
                'type_name' => $name,
                'type_category' => $cat,
                'component_key' => $componentKey,
                'display_label' => $displayLabel,
                'candidate_page' => $candidatePage,
                'candidate_subsection' => $candidateSubsection,
                'required_count' => $req,
            ];

            $mappingDebug[] = [
                'verification_type_id' => $vtId,
                'raw_type_name' => $name,
                'resolved_component_key' => $componentKey,
                'candidate_page' => $candidatePage,
                'mapping_source' => 'case_component_binding_map_verification_type_to_components',
                'resolver_owner' => 'case_component_binding',
                'heuristic_used' => false
            ];
        }
    }

    if ($requiredCaseComponentSet) {
        $referenceFromCase = false;
        if (isset($requiredCaseComponentSet['education_reference'])) {
            $referenceSections['education_reference'] = true;
            $referenceFromCase = true;
        }
        if (isset($requiredCaseComponentSet['employment_reference'])) {
            $referenceSections['employment_reference'] = true;
            $referenceFromCase = true;
        }
        if (isset($requiredCaseComponentSet['reference'])) {
            $referenceSections['education_reference'] = true;
            $referenceSections['employment_reference'] = true;
            $referenceFromCase = true;
        }
        if ($referenceFromCase) {
            $enabledPages[] = 'reference';
            if (!isset($requiredCounts['reference'])) {
                $requiredCounts['reference'] = 1;
            }
        }
    }

    // Correction mode override: candidate should only access targeted pages.
    ccs_ensure_candidate_correction_mode(
        $pdo,
        (int)($case['case_id'] ?? $caseId),
        (string)($case['application_id'] ?? $applicationId)
    );
    $correctionMode = !empty($_SESSION['candidate_correction_mode']);
    if ($correctionMode) {
        $rawPages = (string)($_SESSION['candidate_correction_allowed_pages'] ?? '');
        $parsed = json_decode($rawPages, true);
        if (is_array($parsed) && !empty($parsed)) {
            $clean = [];
            foreach ($parsed as $p) {
                $v = strtolower(trim((string)$p));
                if (in_array($v, ['review', 'review-confirmation', 'authorization'], true)) continue;
                if ($v !== '') $clean[$v] = true;
            }
            $enabledPages = array_values(array_keys($clean));
            if (!in_array('success', $enabledPages, true)) $enabledPages[] = 'success';
        }
    }
    $submittedLocked = !$correctionMode && (
        in_array(strtolower($applicationStatus), ['submitted', 'verified', 'approved', 'completed'], true)
        || in_array(strtoupper($caseStatus), ['PENDING_VALIDATION', 'PENDING_VERIFICATION', 'PENDING_QA', 'APPROVED', 'COMPLETED', 'CLEAR'], true)
    );

    $correctionContext = null;
    if ($correctionMode && !empty($_SESSION['candidate_correction_session_id'])) {
        try {
            ccs_ensure_table($pdo);
            $cid = (int)$_SESSION['candidate_correction_session_id'];
            $cs = $pdo->prepare('SELECT correction_session_id, requested_by_name, requested_role, correction_reason, expires_at, allowed_components_json, status FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE correction_session_id = ? LIMIT 1');
            $cs->execute([$cid]);
            $cr = $cs->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($cr) {
                $arr = json_decode((string)($cr['allowed_components_json'] ?? '[]'), true);
                if (!is_array($arr)) $arr = [];
                $correctionContext = [
                    'correction_session_id' => (int)$cr['correction_session_id'],
                    'requested_by_name' => (string)($cr['requested_by_name'] ?? ''),
                    'requested_role' => (string)($cr['requested_role'] ?? ''),
                    'reason' => (string)($cr['correction_reason'] ?? ''),
                    'expires_at' => (string)($cr['expires_at'] ?? ''),
                    'components' => array_values($arr),
                    'status' => (string)($cr['status'] ?? '')
                ];
            }
        } catch (Throwable $e) {}
    }

    $enabledPages = array_values(array_unique($enabledPages));

    // Regression guard: Case_Components and enabled_pages must stay in sync.
    $componentPageSet = [];
    foreach ($components as $c) {
        $pg = strtolower(trim((string)($c['candidate_page'] ?? '')));
        if ($pg !== '') $componentPageSet[$pg] = true;
    }
    $enabledPageSet = [];
    foreach ($enabledPages as $p) {
        $pp = strtolower(trim((string)$p));
        if ($pp !== '') $enabledPageSet[$pp] = true;
    }
    $missingInEnabled = array_values(array_diff(array_keys($componentPageSet), array_keys($enabledPageSet)));
    $extraInEnabled = array_values(array_diff(array_keys($enabledPageSet), array_keys($componentPageSet)));
    if (!empty($missingInEnabled)) {
        error_log('CASE_CFG_DIVERGENCE_MISSING_ENABLED_PAGES: ' . json_encode([
            'case_id' => (int)($case['case_id'] ?? 0),
            'application_id' => (string)($case['application_id'] ?? ''),
            'missing_pages' => $missingInEnabled
        ]));
    }

    // Strict safe fallback: if job-role mapping cannot be resolved, never expose
    // the full candidate laundry list. Keep only minimal onboarding/review pages.
    if ($shouldFallbackToAllPages && !$correctionMode) {
        $enabledPages = [
            'review-confirmation',
            'basic-details',
            'review',
            'success'
        ];
        $requiredCounts = [];
        $components = [];
        $contactSections = [
            'current_address' => false,
            'permanent_address' => false,
        ];
        $referenceSections = [
            'education_reference' => false,
            'employment_reference' => false,
        ];
        $identificationRequirements = candidate_extract_identification_requirements([], $candidateCountry);
    }
    if (empty($identificationRequirements)) {
        $identificationRequirements = candidate_extract_identification_requirements($types, $candidateCountry);
    }
    $response = [
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case_id' => isset($case['case_id']) ? (int)$case['case_id'] : 0,
            'application_id' => (string)($case['application_id'] ?? ''),
            'client_id' => $clientId,
            'job_role_id' => $jobRoleId,
            'job_role' => $jobRoleName,
            'case_status' => $caseStatus,
            'application_status' => $applicationStatus,
            'application_submitted_at' => $applicationSubmittedAt,
            'submitted_locked' => $submittedLocked ? 1 : 0,
            'login_marker' => (string)($_SESSION['candidate_login_marker'] ?? ''),
            'enabled_pages' => $enabledPages,
            'pages' => $enabledPages,
            'components' => $components,
            'required_counts' => $requiredCounts,
            'sections' => [
                'contact' => $contactSections,
                'reference' => $referenceSections,
            ],
            'contact_sections' => $contactSections,
            'reference_sections' => $referenceSections
            ,
            'identification_requirements' => $identificationRequirements,
            'candidate_country' => $candidateCountry,
            'correction_mode' => $correctionMode ? 1 : 0
            ,
            'correction_context' => $correctionContext
        ]
    ];

    if ($debugEnabled) {
        $generatedComponents = array_values(array_unique(array_map(function ($c) {
            return (string)($c['component_key'] ?? '');
        }, $components)));
        $unknownTypes = array_values(array_map(function ($m) {
            return [
                'verification_type_id' => (int)($m['verification_type_id'] ?? 0),
                'raw_type_name' => (string)($m['raw_type_name'] ?? ''),
                'skipped_reason' => (string)($m['skipped_reason'] ?? '')
            ];
        }, array_values(array_filter($mappingDebug, function ($m) {
            return (string)($m['resolved_component_key'] ?? '') === '';
        }))));
        $response['debug'] = [
            'resolver_owner' => 'case_component_binding',
            'mapping_source' => 'case_component_binding_map_verification_type_to_components',
            'snapshot_generation_source' => 'Vati_Payfiller_Case_Components',
            'selected_level' => $selectedLevel,
            'selected_stage_raw' => $selectedStageRaw,
            'selected_stage_normalized' => $selectedStageNorm,
            'generated_components' => $generatedComponents,
            'generated_pages' => $enabledPages,
            'skipped_mappings' => $enabledPagesSkipped,
            'unknown_types' => $unknownTypes,
            'mapping_rows' => $mappingDebug,
        ];
    }

    error_log("TRACE_FINAL: required_counts=" . json_encode($requiredCounts) . " enabled_pages=" . json_encode($enabledPages) . " components=" . json_encode(array_map(function($c) { return $c['component_key'] . ':' . ($c['required_count'] ?? '?'); }, $components)));
    echo json_encode($response);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
