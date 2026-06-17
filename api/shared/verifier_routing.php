<?php
require_once __DIR__ . '/case_management/case_component_binding.php';
require_once __DIR__ . '/case_management/reference_component_compat.php';
require_once __DIR__ . '/workflow/workflow_snapshot_service.php';

function verifier_routing_components(): array
{
    return [
        'education' => 'Education',
        'education_reference' => 'Education Reference',
        'employment' => 'Employment',
        'employment_reference' => 'Employment Reference',
        'id' => 'Identification',
        'contact' => 'Address',
        'ecourt' => 'E-Court',
        'socialmedia' => 'Social Media',
    ];
}

function verifier_routing_normalize_component(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace(['-', ' '], '_', $key);
    if ($key === 'identification') return 'id';
    if ($key === 'address') return 'contact';
    if ($key === 'social_media') return 'socialmedia';
    if ($key === 'e_court') return 'ecourt';
    if ($key === 'educationreference') return 'education_reference';
    if ($key === 'employmentreference') return 'employment_reference';
    return $key;
}

function verifier_routing_is_routeable(string $componentKey): bool
{
    $key = verifier_routing_normalize_component($componentKey);
    if ($key === 'basic' || $key === 'reports' || $key === 'timeline' || $key === '') {
        return false;
    }
    return isset(verifier_routing_components()[$key]) || $key === 'reference';
}

function verifier_routing_component_matches(string $capabilityKey, string $caseComponentKey): bool
{
    $capabilityKey = verifier_routing_normalize_component($capabilityKey);
    $caseComponentKey = verifier_routing_normalize_component($caseComponentKey);
    if ($caseComponentKey === 'basic') return false;
    if ($capabilityKey === '*' && verifier_routing_is_routeable($caseComponentKey)) return true;
    if ($capabilityKey === $caseComponentKey) return true;
    if ($caseComponentKey === 'reference') {
        return in_array($capabilityKey, ['reference', 'education_reference', 'employment_reference'], true);
    }
    if ($capabilityKey === 'reference') {
        return in_array($caseComponentKey, ['education_reference', 'employment_reference'], true);
    }
    return false;
}

function verifier_routing_allowed_sections_to_capabilities(string $allowedSections): array
{
    $raw = preg_split('/[\s,|]+/', strtolower(trim($allowedSections))) ?: [];
    $out = [];
    foreach ($raw as $item) {
        $key = verifier_routing_normalize_component((string)$item);
        if ($key === '') continue;
        if ($key === '*') {
            foreach (array_keys(verifier_routing_components()) as $routeKey) {
                $out[$routeKey] = ['component_key' => $routeKey, 'routing_priority' => 1, 'is_enabled' => 1];
            }
            continue;
        }
        if ($key === 'reference') {
            $out['education_reference'] = ['component_key' => 'education_reference', 'routing_priority' => 1, 'is_enabled' => 1];
            $out['employment_reference'] = ['component_key' => 'employment_reference', 'routing_priority' => 1, 'is_enabled' => 1];
            continue;
        }
        if ($key === 'education') {
            $out['education'] = ['component_key' => 'education', 'routing_priority' => 1, 'is_enabled' => 1];
            $out['education_reference'] = ['component_key' => 'education_reference', 'routing_priority' => 2, 'is_enabled' => 1];
            continue;
        }
        if ($key === 'employment') {
            $out['employment'] = ['component_key' => 'employment', 'routing_priority' => 1, 'is_enabled' => 1];
            $out['employment_reference'] = ['component_key' => 'employment_reference', 'routing_priority' => 2, 'is_enabled' => 1];
            continue;
        }
        if (isset(verifier_routing_components()[$key])) {
            $out[$key] = ['component_key' => $key, 'routing_priority' => 1, 'is_enabled' => 1];
        }
    }
    return array_values($out);
}

function verifier_routing_fetch_user_capabilities(PDO $pdo, int $userId): array
{
    if ($userId <= 0) return [];
    try {
        $st = $pdo->prepare(
            "SELECT component_key, routing_priority, is_enabled
               FROM Vati_Payfiller_Verifier_Component_Capabilities
              WHERE user_id = ?
                AND is_enabled = 1
           ORDER BY routing_priority ASC, component_key ASC"
        );
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows) {
            $out = [];
            foreach ($rows as $row) {
                $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
                if (!isset(verifier_routing_components()[$key])) continue;
                $out[$key] = [
                    'component_key' => $key,
                    'routing_priority' => max(1, min(3, (int)($row['routing_priority'] ?? 1))),
                    'is_enabled' => 1,
                ];
            }
            return array_values($out);
        }
    } catch (Throwable $e) {
    }

    try {
        $st = $pdo->prepare("SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1");
        $st->execute([$userId]);
        $allowed = (string)($st->fetchColumn() ?: '');
        return verifier_routing_allowed_sections_to_capabilities($allowed);
    } catch (Throwable $e) {
        return [];
    }
}

function verifier_routing_save_user_capabilities(PDO $pdo, int $userId, array $capabilities): void
{
    if ($userId <= 0) return;
    $clean = [];
    foreach ($capabilities as $row) {
        if (!is_array($row)) continue;
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if (!isset(verifier_routing_components()[$key])) continue;
        $priority = max(1, min(3, (int)($row['routing_priority'] ?? 1)));
        $clean[$key] = ['component_key' => $key, 'routing_priority' => $priority, 'is_enabled' => 1];
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM Vati_Payfiller_Verifier_Component_Capabilities WHERE user_id = ?");
        $del->execute([$userId]);
        if ($clean) {
            $ins = $pdo->prepare(
                "INSERT INTO Vati_Payfiller_Verifier_Component_Capabilities
                    (user_id, component_key, routing_priority, is_enabled)
                 VALUES (?, ?, ?, 1)"
            );
            foreach ($clean as $row) {
                $ins->execute([$userId, $row['component_key'], $row['routing_priority']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function verifier_routing_parse_capability_payload(string $json): array
{
    $json = trim($json);
    if ($json === '') return [];
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) return [];
    return $decoded;
}

function verifier_routing_capabilities_to_allowed_sections(array $capabilities): string
{
    $out = [];
    foreach ($capabilities as $row) {
        if (!is_array($row)) continue;
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if (!isset(verifier_routing_components()[$key])) continue;
        $out[$key] = true;
    }
    return implode(',', array_keys($out));
}

function verifier_routing_role_uses_capabilities(string $role): bool
{
    return in_array(strtolower(trim($role)), ['verifier', 'db_verifier', 'validator'], true);
}

function verifier_routing_component_label(string $componentKey): string
{
    $key = verifier_routing_normalize_component($componentKey);
    if ($key === 'basic') return 'Basic Details';
    if ($key === 'id') return 'Identification';
    if ($key === 'contact') return 'Address';
    if ($key === 'reference') return 'Reference';
    $labels = verifier_routing_components();
    return $labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

function verifier_routing_reason_label(string $reasonCode): string
{
    $reasonCode = strtolower(trim($reasonCode));
    $labels = [
        'context' => 'Shared context',
        'assigned_to_you' => 'Assigned to you',
        'completed' => 'Completed',
        'already_assigned' => 'Already assigned',
        'case_gate_incomplete' => 'Waiting for higher-priority case components',
        'higher_priority_bucket_pending' => 'Higher-priority bucket pending',
        'eligible_to_claim' => 'Eligible to claim',
        'no_capability' => 'No verifier capability',
    ];
    return $labels[$reasonCode] ?? ucfirst(str_replace('_', ' ', $reasonCode));
}

function verifier_routing_status_is_complete(string $status): bool
{
    $status = strtolower(trim($status));
    return in_array($status, ['approved', 'rejected', 'completed', 'clear', 'verified'], true);
}

function verifier_routing_capability_priority_map(array $capabilities): array
{
    $map = [];
    foreach ($capabilities as $row) {
        if (!is_array($row)) continue;
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if ($key === '') continue;
        $priority = max(1, min(3, (int)($row['routing_priority'] ?? 1)));
        if (!isset($map[$key]) || $priority < $map[$key]) {
            $map[$key] = $priority;
        }
    }
    if (isset($map['education_reference']) && (!isset($map['education']) || $map['education_reference'] < $map['education'])) {
        $map['education'] = $map['education_reference'];
    }
    if (isset($map['employment_reference']) && (!isset($map['employment']) || $map['employment_reference'] < $map['employment'])) {
        $map['employment'] = $map['employment_reference'];
    }
    return $map;
}

function verifier_routing_priority_for_component(array $priorityMap, string $componentKey): int
{
    $key = verifier_routing_normalize_component($componentKey);
    if (isset($priorityMap[$key])) return (int)$priorityMap[$key];
    if ($key === 'reference') {
        $priorities = [];
        foreach (['education_reference', 'employment_reference'] as $refKey) {
            if (isset($priorityMap[$refKey])) $priorities[] = (int)$priorityMap[$refKey];
        }
        return $priorities ? min($priorities) : 99;
    }
    return 99;
}

function verifier_routing_reference_targets_for_case(PDO $pdo, int $caseId, array $capabilities): array
{
    $targets = [];
    try {
        $config = case_component_binding_build_for_case($pdo, $caseId, '');
        foreach (($config['required_components'] ?? []) as $componentKey) {
            $key = verifier_routing_normalize_component((string)$componentKey);
            if ($key === 'education_reference' || $key === 'employment_reference') {
                $targets[$key] = true;
            }
        }
    } catch (Throwable $e) {
    }

    if (!$targets) {
        foreach ($capabilities as $capability) {
            if (!is_array($capability)) continue;
            $key = verifier_routing_normalize_component((string)($capability['component_key'] ?? ''));
            if ($key === 'education_reference' || $key === 'employment_reference') {
                $targets[$key] = true;
            }
        }
    }

    return array_keys($targets);
}

function verifier_routing_expand_reference_rows(PDO $pdo, int $caseId, array $rows, array $capabilities): array
{
    $hasSplitReference = false;
    $genericRows = [];
    $out = [];

    foreach ($rows as $row) {
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if ($key === 'education_reference' || $key === 'employment_reference') {
            $hasSplitReference = true;
        }
    }

    foreach ($rows as $row) {
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if ($key === 'reference') {
            $genericRows[] = $row;
            if ($hasSplitReference) continue;
        }
        $out[] = $row;
    }

    if (!$hasSplitReference && $genericRows) {
        $targets = verifier_routing_reference_targets_for_case($pdo, $caseId, $capabilities);
        if ($targets) {
            foreach ($genericRows as $genericRow) {
                foreach ($targets as $targetKey) {
                    $row = $genericRow;
                    $row['component_key'] = $targetKey;
                    $out[] = $row;
                }
            }
            $out = array_values(array_filter($out, function ($row) {
                return verifier_routing_normalize_component((string)($row['component_key'] ?? '')) !== 'reference';
            }));
        }
    }

    return reference_compat_filter_rows($out, 'component_key');
}

function verifier_routing_apply_resolved_workflow_statuses(PDO $pdo, int $caseId, array $rows): array
{
    if ($caseId <= 0 || !$rows) return $rows;
    $workflowByComponent = ws_fetch_workflow_by_component($pdo, '', $caseId);
    if (!$workflowByComponent) return $rows;

    foreach ($rows as $idx => $row) {
        if (!is_array($row)) continue;
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if ($key === '') continue;
        $fallback = strtolower(trim((string)($row['verifier_status'] ?? 'pending')));
        $rows[$idx]['verifier_status'] = ws_resolved_stage_status($workflowByComponent, $key, 'verifier', $fallback);
    }

    return $rows;
}

function verifier_routing_case_component_rows(PDO $pdo, int $caseId, array $capabilities = []): array
{
    if ($caseId <= 0) return [];
    try {
        $st = $pdo->prepare(
            "SELECT LOWER(TRIM(c.component_key)) AS component_key,
                    COALESCE(c.is_required, 1) AS is_required,
                    LOWER(TRIM(COALESCE(c.assigned_role, ''))) AS assigned_role,
                    COALESCE(c.assigned_user_id, 0) AS assigned_user_id,
                    COALESCE(LOWER(TRIM(w.status)), LOWER(TRIM(COALESCE(c.status, 'pending'))), 'pending') AS verifier_status
               FROM Vati_Payfiller_Case_Components c
          LEFT JOIN Vati_Payfiller_Case_Component_Workflow w
                 ON w.case_id = c.case_id
                AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key))
                AND LOWER(TRIM(w.stage)) = 'verifier'
              WHERE c.case_id = ?
                AND COALESCE(c.is_required, 1) = 1"
        );
        $st->execute([$caseId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rows = verifier_routing_apply_resolved_workflow_statuses($pdo, $caseId, $rows);
        return verifier_routing_expand_reference_rows($pdo, $caseId, $rows, $capabilities);
    } catch (Throwable $e) {
        return [];
    }
}

function verifier_routing_component_has_capability(array $capabilities, string $componentKey): bool
{
    foreach ($capabilities as $capability) {
        if (!is_array($capability)) continue;
        if (verifier_routing_component_matches((string)($capability['component_key'] ?? ''), $componentKey)) {
            return true;
        }
    }
    return false;
}

function verifier_routing_case_gate_passes(array $caseRows, array $priorityMap, int $targetPriority): bool
{
    if ($targetPriority <= 1) return true;
    foreach ($caseRows as $row) {
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if (!verifier_routing_is_routeable($key)) continue;
        $priority = verifier_routing_priority_for_component($priorityMap, $key);
        if ($priority >= $targetPriority || $priority <= 0 || $priority >= 99) continue;
        $status = (string)($row['verifier_status'] ?? 'pending');
        if (!verifier_routing_status_is_complete($status)) return false;
    }
    return true;
}

function verifier_routing_pending_higher_priority_count(PDO $pdo, int $userId, int $targetPriority, int $excludeCaseId = 0): int
{
    if ($userId <= 0 || $targetPriority <= 1) return 0;
    $capabilities = verifier_routing_fetch_user_capabilities($pdo, $userId);
    $priorityMap = verifier_routing_capability_priority_map($capabilities);
    if (!$priorityMap) return 0;

    try {
        $sql =
            "SELECT c.case_id,
                    LOWER(TRIM(c.component_key)) AS component_key,
                    LOWER(TRIM(COALESCE(c.assigned_role, ''))) AS assigned_role,
                    COALESCE(c.assigned_user_id, 0) AS assigned_user_id,
                    COALESCE(LOWER(TRIM(w.status)), LOWER(TRIM(COALESCE(c.status, 'pending'))), 'pending') AS verifier_status
               FROM Vati_Payfiller_Case_Components c
               JOIN Vati_Payfiller_Cases ca ON ca.case_id = c.case_id
          LEFT JOIN Vati_Payfiller_Case_Component_Workflow w
                 ON w.case_id = c.case_id
                AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key))
                AND LOWER(TRIM(w.stage)) = 'verifier'
              WHERE COALESCE(c.is_required, 1) = 1
                AND UPPER(TRIM(COALESCE(ca.case_status, ''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')";
        $st = $pdo->query($sql);
        $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return 0;
    }

    $count = 0;
    $rowsByCase = [];
    foreach ($rows as $row) {
        $caseId = (int)($row['case_id'] ?? 0);
        if ($caseId <= 0) continue;
        $rowsByCase[$caseId][] = $row;
    }

    foreach ($rowsByCase as $caseId => $caseRows) {
        $caseId = (int)$caseId;
        if ($excludeCaseId > 0 && $caseId === $excludeCaseId) continue;
        $caseRows = verifier_routing_apply_resolved_workflow_statuses($pdo, $caseId, $caseRows);
        foreach (verifier_routing_expand_reference_rows($pdo, $caseId, $caseRows, $capabilities) as $row) {
            $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
            if (!verifier_routing_is_routeable($key)) continue;
            if (!verifier_routing_component_has_capability($capabilities, $key)) continue;
            $priority = verifier_routing_priority_for_component($priorityMap, $key);
            if ($priority <= 0 || $priority >= $targetPriority) continue;
            if (verifier_routing_status_is_complete((string)($row['verifier_status'] ?? ''))) continue;
            $assignedRole = strtolower(trim((string)($row['assigned_role'] ?? '')));
            $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
            if ($assignedUserId > 0 && !($assignedRole === 'verifier' && $assignedUserId === $userId)) continue;
            $count++;
        }
    }
    return $count;
}

function verifier_routing_case_state(PDO $pdo, int $caseId, int $userId): array
{
    $capabilities = verifier_routing_fetch_user_capabilities($pdo, $userId);
    $priorityMap = verifier_routing_capability_priority_map($capabilities);
    $caseRows = verifier_routing_case_component_rows($pdo, $caseId, $capabilities);
    $components = [];
    $ownedActive = [];
    $claimableNext = [];
    $lockedFuture = [];
    $hiddenUnrelated = [];
    $completed = [];
    $visibleSections = ['basic'];
    $bucketPendingByPriority = [1 => 0, 2 => 0, 3 => 0];

    $components['basic'] = [
        'component_key' => 'basic',
        'label' => verifier_routing_component_label('basic'),
        'priority' => null,
        'state' => 'context',
        'reason_code' => 'context',
        'reason' => verifier_routing_reason_label('context'),
        'status' => 'context',
        'assigned_role' => '',
        'assigned_user_id' => 0,
        'case_gate_passed' => 1,
        'bucket_pending_higher_count' => 0,
    ];

    foreach ([1, 2, 3] as $priority) {
        $bucketPendingByPriority[$priority] = verifier_routing_pending_higher_priority_count($pdo, $userId, $priority + 1, 0);
    }

    foreach ($caseRows as $row) {
        $key = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if ($key === '' || $key === 'basic' || !verifier_routing_is_routeable($key)) continue;

        $hasCapability = verifier_routing_component_has_capability($capabilities, $key);
        $priority = verifier_routing_priority_for_component($priorityMap, $key);
        $status = strtolower(trim((string)($row['verifier_status'] ?? 'pending')));
        $assignedRole = strtolower(trim((string)($row['assigned_role'] ?? '')));
        $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
        $isComplete = verifier_routing_status_is_complete($status);
        $state = 'hidden_unrelated';
        $reasonCode = 'no_capability';
        $caseGatePasses = $hasCapability ? verifier_routing_case_gate_passes($caseRows, $priorityMap, $priority) : false;
        $bucketPending = 0;

        if (!$hasCapability) {
            $hiddenUnrelated[] = $key;
        } elseif ($assignedRole === 'verifier' && $assignedUserId === $userId) {
            if ($isComplete) {
                $state = 'completed';
                $reasonCode = 'completed';
                $completed[] = $key;
            } else {
                $state = 'owned_active';
                $reasonCode = 'assigned_to_you';
                $ownedActive[] = $key;
            }
            $visibleSections[] = $key;
        } elseif ($assignedUserId > 0) {
            $state = 'locked_future';
            $reasonCode = 'already_assigned';
            $lockedFuture[] = $key;
        } elseif ($isComplete) {
            $state = 'completed';
            $reasonCode = 'completed';
            $completed[] = $key;
        } elseif (!$caseGatePasses) {
            $state = 'locked_future';
            $reasonCode = 'case_gate_incomplete';
            $lockedFuture[] = $key;
        } else {
            $state = 'claimable_next';
            $reasonCode = 'eligible_to_claim';
            $claimableNext[] = $key;
        }

        $components[$key] = [
            'component_key' => $key,
            'label' => verifier_routing_component_label($key),
            'priority' => $priority >= 99 ? null : $priority,
            'state' => $state,
            'reason_code' => $reasonCode,
            'reason' => verifier_routing_reason_label($reasonCode),
            'status' => $status,
            'assigned_role' => $assignedRole,
            'assigned_user_id' => $assignedUserId,
            'case_gate_passed' => $caseGatePasses ? 1 : 0,
            'bucket_pending_higher_count' => $bucketPending,
        ];
    }

    return reference_compat_apply_to_routing_state([
        'components' => $components,
        'visible_sections' => array_values(array_unique($visibleSections)),
        'owned_active_components' => array_values(array_unique($ownedActive)),
        'claimable_next_components' => array_values(array_unique($claimableNext)),
        'locked_future_components' => array_values(array_unique($lockedFuture)),
        'hidden_unrelated_components' => array_values(array_unique($hiddenUnrelated)),
        'completed_components' => array_values(array_unique($completed)),
        'bucket_pending_by_priority' => $bucketPendingByPriority,
        'can_open' => !empty($ownedActive) || !empty($completed),
        'can_claim' => !empty($claimableNext),
    ]);
}

function verifier_routing_claimable_components_for_case(PDO $pdo, int $caseId, int $userId): array
{
    $state = verifier_routing_case_state($pdo, $caseId, $userId);
    return $state['claimable_next_components'] ?? [];
}

function verifier_routing_user_matching_components(PDO $pdo, int $caseId, int $userId): array
{
    if ($caseId <= 0 || $userId <= 0) return [];
    $caps = verifier_routing_fetch_user_capabilities($pdo, $userId);
    if (!$caps) return [];

    $caseRows = verifier_routing_case_component_rows($pdo, $caseId, $caps);
    $out = [];
    foreach ($caseRows as $row) {
        $caseKey = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if (!verifier_routing_is_routeable($caseKey)) continue;
        foreach ($caps as $capability) {
            if (verifier_routing_component_matches((string)$capability['component_key'], $caseKey)) {
                $out[$caseKey] = true;
                break;
            }
        }
    }
    return array_keys($out);
}

function verifier_routing_best_priority_for_case(PDO $pdo, int $caseId, int $userId): int
{
    if ($caseId <= 0 || $userId <= 0) return 99;
    $caps = verifier_routing_fetch_user_capabilities($pdo, $userId);
    if (!$caps) return 99;
    $caseRows = verifier_routing_case_component_rows($pdo, $caseId, $caps);
    $best = 99;
    foreach ($caseRows as $row) {
        $caseKey = verifier_routing_normalize_component((string)($row['component_key'] ?? ''));
        if (!verifier_routing_is_routeable($caseKey)) continue;
        foreach ($caps as $capability) {
            if (verifier_routing_component_matches((string)$capability['component_key'], $caseKey)) {
                $best = min($best, max(1, min(3, (int)($capability['routing_priority'] ?? 1))));
            }
        }
    }
    return $best;
}

function verifier_routing_user_can_claim_case(PDO $pdo, int $caseId, int $userId): bool
{
    return !empty(verifier_routing_user_matching_components($pdo, $caseId, $userId));
}
