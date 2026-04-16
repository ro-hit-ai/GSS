<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/component_resolver.php';

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
    $hay = strtolower(trim($typeName . ' ' . $typeCategory));
    if (!candidate_cfg_text_has_any($hay, ['address'])) {
        return [];
    }

    $out = [];
    if (candidate_cfg_text_has_any($hay, ['current', 'present'])) {
        $out[] = 'current_address';
    }
    if (candidate_cfg_text_has_any($hay, ['permanent'])) {
        $out[] = 'permanent_address';
    }

    return array_values(array_unique($out));
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

    $case = null;
    if ($caseId > 0) {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$case && $applicationId !== '') {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
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

    $jobRoleId = 0;
    if ($clientId > 0 && $jobRoleName !== '') {
        $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
        $jr->execute([$clientId, $jobRoleName]);
        $jobRoleId = (int)($jr->fetchColumn() ?: 0);
    }

    $types = [];
    if ($jobRoleId > 0) {
        try {
            $stmt = $pdo->prepare(
                'SELECT j.verification_type_id, j.is_enabled, j.sort_order, j.required_count,
                        t.type_name, t.type_category
                   FROM Vati_Payfiller_Job_Role_Verification_Types j
                   LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = j.verification_type_id
                  WHERE j.job_role_id = ?
                  ORDER BY COALESCE(j.sort_order, 0) ASC, COALESCE(t.type_name, "") ASC'
            );
            $stmt->execute([$jobRoleId]);
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            $stageStmt = $pdo->prepare(
                'SELECT s.verification_type_id, s.is_active AS is_enabled, s.execution_group AS sort_order,
                        1 AS required_count, t.type_name, t.type_category
                   FROM Vati_Payfiller_Job_Role_Stage_Steps s
                   LEFT JOIN Vati_Payfiller_Verification_Types t ON t.verification_type_id = s.verification_type_id
                  WHERE s.job_role_id = ?
                  ORDER BY COALESCE(s.stage_key, "") ASC, COALESCE(s.execution_group, 0) ASC, COALESCE(t.type_name, "") ASC'
            );
            $stageStmt->execute([$jobRoleId]);
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
    }

    $shouldFallbackToAllPages = ($jobRoleId <= 0 || count($types) === 0);

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

    // Fallback map for setups where SP does not return required_count.
    $requiredByTypeId = [];
    if ($jobRoleId > 0) {
        try {
            $rcStmt = $pdo->prepare(
                'SELECT verification_type_id, required_count
                 FROM Vati_Payfiller_Job_Role_Verification_Types
                 WHERE job_role_id = ?'
            );
            $rcStmt->execute([$jobRoleId]);
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

        $meta = resolve_component_meta($name, $cat);
        $componentKey = (string)($meta['component_key'] ?? '');
        $candidatePage = (string)($meta['candidate_page'] ?? '');
        $candidateSubsection = (string)($meta['candidate_subsection'] ?? '');
        $displayLabel = (string)($meta['display_label'] ?? $name);
        $contactKeys = detect_contact_sections($name, $cat);
        $referenceKeys = detect_reference_sections($name, $cat);
        $addedComponent = false;

        if (!empty($contactKeys)) {
            $enabledPages[] = 'contact';
            if (!isset($requiredCounts['contact']) || (int)$requiredCounts['contact'] < $req) {
                $requiredCounts['contact'] = $req;
            }
        }

        if (!empty($referenceKeys)) {
            $enabledPages[] = 'reference';
            if (!isset($requiredCounts['reference']) || (int)$requiredCounts['reference'] < $req) {
                $requiredCounts['reference'] = $req;
            }
        }

        if ($candidatePage !== '') {
            $enabledPages[] = $candidatePage;
            if (!isset($requiredCounts[$candidatePage]) || (int)$requiredCounts[$candidatePage] < $req) {
                $requiredCounts[$candidatePage] = $req;
            }
        }

        foreach ($contactKeys as $sectionKey) {
            $contactSections[$sectionKey] = true;
            if (!isset($requiredCounts[$sectionKey]) || (int)$requiredCounts[$sectionKey] < $req) {
                $requiredCounts[$sectionKey] = $req;
            }
            $components[] = [
                'verification_type_id' => $vtId,
                'type_name' => $name,
                'type_category' => $cat,
                'component_key' => $sectionKey,
                'display_label' => resolve_component_label($sectionKey, ''),
                'candidate_page' => 'contact',
                'candidate_subsection' => $sectionKey,
                'required_count' => $req,
            ];
            $addedComponent = true;
        }

        foreach ($referenceKeys as $sectionKey) {
            $referenceSections[$sectionKey] = true;
            if (!isset($requiredCounts[$sectionKey]) || (int)$requiredCounts[$sectionKey] < $req) {
                $requiredCounts[$sectionKey] = $req;
            }
            $components[] = [
                'verification_type_id' => $vtId,
                'type_name' => $name,
                'type_category' => $cat,
                'component_key' => $sectionKey,
                'display_label' => resolve_component_label($sectionKey, ''),
                'candidate_page' => 'reference',
                'candidate_subsection' => $sectionKey,
                'required_count' => $req,
            ];
            $addedComponent = true;
        }

        if ($componentKey !== '') {
            if (!isset($requiredCounts[$componentKey]) || (int)$requiredCounts[$componentKey] < $req) {
                $requiredCounts[$componentKey] = $req;
            }
        }

        if ($candidateSubsection !== '') {
            if (isset($contactSections[$candidateSubsection])) {
                $contactSections[$candidateSubsection] = true;
            }
            if (isset($referenceSections[$candidateSubsection])) {
                $referenceSections[$candidateSubsection] = true;
            }
        }

        if (!$addedComponent && ($candidatePage !== '' || $componentKey !== '')) {
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
        }
    }

    $enabledPages = array_values(array_unique($enabledPages));

    // If we can't resolve job-role verification types, don't hide pages.
    // Router treats enabled_pages=null as "all pages enabled".
    if ($shouldFallbackToAllPages) {
        $enabledPages = null;
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
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case_id' => isset($case['case_id']) ? (int)$case['case_id'] : 0,
            'application_id' => (string)($case['application_id'] ?? ''),
            'client_id' => $clientId,
            'job_role_id' => $jobRoleId,
            'job_role' => $jobRoleName,
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
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
