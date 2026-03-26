<?php

function verifier_allowed_sections_set_from_session(?PDO $pdo = null): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $raw = isset($_SESSION['auth_allowed_sections']) ? (string)$_SESSION['auth_allowed_sections'] : '';
    if ($pdo) {
        try {
            $uid = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
            if ($uid > 0) {
                $st = $pdo->prepare('SELECT allowed_sections FROM Vati_Payfiller_Users WHERE user_id = ? LIMIT 1');
                $st->execute([$uid]);
                $dbRaw = (string)($st->fetchColumn() ?: '');
                $raw = $dbRaw;
                $_SESSION['auth_allowed_sections'] = $dbRaw;
            }
        } catch (Throwable $e) {
            // fallback to session
        }
    }
    $raw = strtolower(trim($raw));
    if ($raw === '*') return ['*' => true];
    if ($raw === '') return [];
    $parts = preg_split('/[\s,|]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $k = strtolower(trim((string)$p));
        if ($k === '') continue;
        $out[$k] = true;
    }
    return $out;
}

function verifier_group_components(string $groupKey): array {
    $g = strtoupper(trim($groupKey));
    if ($g === 'BASIC') return ['basic', 'id', 'contact', 'address', 'socialmedia', 'ecourt', 'reports'];
    if ($g === 'EDUCATION') return ['education', 'employment', 'reference'];
    return [];
}

function verifier_norm_component_key(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'driving' || $k === 'driving_license') return 'driving_licence';
    return $k;
}

function verifier_can_group_by_sections(array $allowedSet, string $groupKey): bool {
    if (isset($allowedSet['*'])) return true;
    $need = verifier_group_components($groupKey);
    foreach ($need as $k) {
        if (isset($allowedSet[$k])) return true;
    }
    return false;
}

function verifier_filter_actionable_queue_rows(PDO $pdo, array $rows, array $allowedSet): array {
    if (!$rows) return [];

    $appIds = [];
    foreach ($rows as $r) {
        $appId = trim((string)($r['application_id'] ?? ''));
        if ($appId !== '') {
            $appIds[$appId] = true;
        }
    }
    if (!$appIds) {
        return $rows;
    }

    $appList = array_keys($appIds);
    $placeholders = implode(',', array_fill(0, count($appList), '?'));
    $componentRows = [];
    $caseStatusByApp = [];

    try {
        $stCase = $pdo->prepare(
            'SELECT application_id, UPPER(TRIM(COALESCE(case_status, \'\'))) AS case_status '
            . 'FROM Vati_Payfiller_Cases '
            . 'WHERE application_id IN (' . $placeholders . ')'
        );
        $stCase->execute($appList);
        $caseRows = $stCase->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($caseRows as $cr) {
            $appId = trim((string)($cr['application_id'] ?? ''));
            if ($appId === '') continue;
            $caseStatusByApp[$appId] = (string)($cr['case_status'] ?? '');
        }

        $sql = 'SELECT cc.application_id, LOWER(TRIM(cc.component_key)) AS component_key, '
            . "COALESCE(LOWER(TRIM(vw.status)), '') AS validator_status, "
            . "COALESCE(LOWER(TRIM(cc.status)), '') AS component_status "
            . 'FROM Vati_Payfiller_Case_Components cc '
            . 'LEFT JOIN Vati_Payfiller_Case_Component_Workflow vw '
            . 'ON vw.application_id = cc.application_id '
            . 'AND LOWER(TRIM(vw.component_key)) = LOWER(TRIM(cc.component_key)) '
            . "AND LOWER(TRIM(vw.stage)) = 'validator' "
            . 'WHERE cc.application_id IN (' . $placeholders . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($appList);
        $componentRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return $rows;
    }

    $byApp = [];
    foreach ($componentRows as $cr) {
        $appId = trim((string)($cr['application_id'] ?? ''));
        $ck = verifier_norm_component_key((string)($cr['component_key'] ?? ''));
        if ($appId === '' || $ck === '') continue;
        if (!isset($byApp[$appId])) $byApp[$appId] = [];
        $validatorStatus = strtolower(trim((string)($cr['validator_status'] ?? '')));
        if ($validatorStatus === '') {
            $validatorStatus = strtolower(trim((string)($cr['component_status'] ?? '')));
        }
        $byApp[$appId][$ck] = $validatorStatus;
    }

    $out = [];
    foreach ($rows as $r) {
        $groupKey = (string)($r['group_key'] ?? '');
        if (!verifier_can_group_by_sections($allowedSet, $groupKey)) {
            continue;
        }

        $appId = trim((string)($r['application_id'] ?? ''));
        $rowCaseStatus = strtoupper(trim((string)($r['case_status'] ?? '')));
        $caseStatus = $rowCaseStatus !== '' ? $rowCaseStatus : strtoupper(trim((string)($caseStatusByApp[$appId] ?? '')));
        if ($caseStatus === 'STOP_BGV') {
            continue;
        }
        $groupComponents = verifier_group_components($groupKey);
        if (!$groupComponents) {
            continue;
        }

        $candidateComponents = [];
        foreach ($groupComponents as $k) {
            if (isset($allowedSet['*']) || isset($allowedSet[$k])) {
                $candidateComponents[] = $k;
            }
        }
        if (!$candidateComponents) {
            continue;
        }

        // Legacy fallback: if no component rows are present, keep queue row.
        if (!isset($byApp[$appId])) {
            $out[] = $r;
            continue;
        }

        $statusMap = $byApp[$appId];
        $seenGroupComponent = false;
        foreach ($candidateComponents as $k) {
            if (!array_key_exists($k, $statusMap)) continue;
            $seenGroupComponent = true;
        }

        // Keep queue rows visible even when validator rejected all group components.
        // Verifier may still need to review/override with explicit reason.
        $out[] = $r;
    }

    return $out;
}
