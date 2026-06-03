<?php

require_once __DIR__ . '/workflow_semantics.php';
require_once __DIR__ . '/verifier_routing.php';

function verifier_case_queue_registered_components(): array
{
    return ['basic', 'id', 'contact', 'education', 'education_reference', 'employment', 'employment_reference', 'reference', 'socialmedia', 'ecourt', 'reports'];
}

function verifier_case_queue_norm_component(string $componentKey): string
{
    $k = strtolower(trim($componentKey));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    if ($k === 'educationreference') return 'education_reference';
    if ($k === 'employmentreference') return 'employment_reference';
    return $k;
}

function verifier_case_queue_ensure_table(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS Vati_Payfiller_Verifier_Case_Queue (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                case_id BIGINT NOT NULL,
                client_id BIGINT NULL,
                application_id VARCHAR(191) NOT NULL,
                status VARCHAR(64) NOT NULL DEFAULT 'pending',
                assigned_user_id BIGINT NULL,
                claimed_at DATETIME NULL,
                completed_at DATETIME NULL,
                followup_at DATETIME NULL,
                workflow_mode VARCHAR(32) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_verifier_case_queue_case_id (case_id),
                KEY idx_verifier_case_queue_status (status),
                KEY idx_verifier_case_queue_assigned (assigned_user_id),
                KEY idx_verifier_case_queue_application (application_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    } catch (Throwable $e) {
    }
}

function verifier_case_queue_is_case_model(PDO $pdo, int $caseId = 0, string $applicationId = ''): bool
{
    verifier_case_queue_ensure_table($pdo);
    try {
        if ($caseId > 0) {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1");
            $st->execute([$caseId]);
            return strtolower(trim((string)($st->fetchColumn() ?: 'validator_first'))) === 'verifier_first';
        }
        if (trim($applicationId) !== '') {
            $st = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1");
            $st->execute([trim($applicationId)]);
            return strtolower(trim((string)($st->fetchColumn() ?: 'validator_first'))) === 'verifier_first';
        }
    } catch (Throwable $e) {
    }
    return false;
}

function verifier_case_queue_required_components(PDO $pdo, int $caseId): array
{
    if ($caseId <= 0) return [];

    $allowed = array_flip(verifier_case_queue_registered_components());
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT LOWER(TRIM(component_key)) AS component_key
               FROM Vati_Payfiller_Case_Components
              WHERE case_id = ?
                AND is_required = 1"
        );
        $st->execute([$caseId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $k = verifier_case_queue_norm_component((string)($row['component_key'] ?? ''));
            if ($k === '' || !isset($allowed[$k])) {
                continue;
            }
            $out[$k] = true;
        }
        return array_values(array_keys($out));
    } catch (Throwable $e) {
        return [];
    }
}

function verifier_case_queue_component_summary(PDO $pdo, int $caseId): array
{
    $components = verifier_case_queue_required_components($pdo, $caseId);
    $labels = [
        'basic' => 'Basic',
        'id' => 'ID',
        'contact' => 'Contact',
        'education' => 'Education',
        'education_reference' => 'Education Reference',
        'employment' => 'Employment',
        'employment_reference' => 'Employment Reference',
        'reference' => 'Reference',
        'socialmedia' => 'Social Media',
        'ecourt' => 'E-Court',
        'reports' => 'Reports',
    ];
    $out = [];
    foreach ($components as $componentKey) {
        $out[] = [
            'component_key' => $componentKey,
            'label' => $labels[$componentKey] ?? ucfirst($componentKey),
            'family_key' => verifier_case_queue_family_key($componentKey),
        ];
    }
    return $out;
}

function verifier_case_queue_family_key(string $componentKey): string
{
    $k = verifier_case_queue_norm_component($componentKey);
    if (in_array($k, ['basic', 'id', 'contact'], true)) return 'basic';
    if (in_array($k, ['education', 'education_reference', 'employment', 'employment_reference', 'reference'], true)) return 'education';
    if (in_array($k, ['ecourt', 'socialmedia', 'reports'], true)) return 'additional';
    return 'all';
}

function verifier_case_queue_load_case(PDO $pdo, int $caseId): ?array
{
    if ($caseId <= 0) return null;
    $st = $pdo->prepare(
        "SELECT case_id, client_id, application_id,
                COALESCE(NULLIF(TRIM(workflow_mode), ''), 'validator_first') AS workflow_mode,
                candidate_first_name, candidate_last_name, created_at, case_status
           FROM Vati_Payfiller_Cases
          WHERE case_id = ?
          LIMIT 1"
    );
    $st->execute([$caseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function verifier_case_queue_status_is_final(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['approved', 'rejected', 'hold', 'insufficient_documents', 'completed', 'clear', 'verified'], true);
}

function verifier_case_queue_status_is_followup(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['reopened', 'blocked', 'followup', 'hold'], true);
}

function verifier_case_queue_status_is_waiting_candidate(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['waiting_candidate', 'insufficient_documents'], true);
}

function verifier_case_queue_clear_db_verifier_owners(PDO $pdo, int $caseId = 0): void
{
    $caseFilter = $caseId > 0 ? ' AND q.case_id = ?' : '';
    $params = $caseId > 0 ? [$caseId] : [];

    try {
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Group_Queue q
              JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
               SET q.assigned_user_id = NULL,
                   q.claimed_at = NULL,
                   q.status = CASE
                       WHEN q.completed_at IS NULL
                        AND COALESCE(LOWER(TRIM(q.status)), '') = 'in_progress' THEN 'pending'
                       ELSE q.status
                   END
             WHERE q.completed_at IS NULL
               AND LOWER(TRIM(COALESCE(u.role, ''))) = 'db_verifier'" . $caseFilter
        );
        $st->execute($params);
    } catch (Throwable $e) {
    }

    try {
        verifier_case_queue_ensure_table($pdo);
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Case_Queue q
              JOIN Vati_Payfiller_Users u ON u.user_id = q.assigned_user_id
               SET q.assigned_user_id = NULL,
                   q.claimed_at = NULL,
                   q.status = CASE
                       WHEN q.completed_at IS NULL
                        AND COALESCE(LOWER(TRIM(q.status)), '') = 'in_progress' THEN 'pending'
                       ELSE q.status
                   END,
                   q.updated_at = NOW()
             WHERE q.completed_at IS NULL
               AND LOWER(TRIM(COALESCE(u.role, ''))) = 'db_verifier'" . $caseFilter
        );
        $st->execute($params);
    } catch (Throwable $e) {
    }
}

function verifier_case_queue_compute_status(PDO $pdo, int $caseId): string
{
    if ($caseId <= 0) return 'pending';

    $components = verifier_case_queue_required_components($pdo, $caseId);
    if (!$components) {
        return 'pending';
    }

    $ph = implode(',', array_fill(0, count($components), '?'));
    $params = array_merge([$caseId], $components);
    try {
        $st = $pdo->prepare(
            "SELECT LOWER(TRIM(c.component_key)) AS component_key,
                    COALESCE(LOWER(TRIM(w.status)), 'pending') AS verifier_status
               FROM Vati_Payfiller_Case_Components c
               LEFT JOIN Vati_Payfiller_Case_Component_Workflow w
                 ON w.case_id = c.case_id
                AND LOWER(TRIM(w.component_key)) = LOWER(TRIM(c.component_key))
                AND LOWER(TRIM(w.stage)) = 'verifier'
              WHERE c.case_id = ?
                AND LOWER(TRIM(c.component_key)) IN ($ph)
                AND c.is_required = 1"
        );
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return 'pending';
    }

    if (!$rows) {
        return 'pending';
    }

    $finalCount = 0;
    $hasWaitingCandidate = false;
    $hasFollowup = false;
    $hasInProgress = false;

    foreach ($rows as $row) {
        $status = strtolower(trim((string)($row['verifier_status'] ?? 'pending')));
        if (verifier_case_queue_status_is_final($status)) {
            $finalCount++;
            if ($status === 'insufficient_documents') {
                $hasWaitingCandidate = true;
            }
            if ($status === 'hold') {
                $hasFollowup = true;
            }
            continue;
        }
        if (verifier_case_queue_status_is_waiting_candidate($status)) {
            $hasWaitingCandidate = true;
            continue;
        }
        if (verifier_case_queue_status_is_followup($status)) {
            $hasFollowup = true;
            continue;
        }
        if (in_array($status, ['in_progress', 'pending', 'submitted', ''], true)) {
            $hasInProgress = true;
        }
    }

    if ($finalCount === count($rows) && !$hasWaitingCandidate) {
        return 'completed';
    }
    if ($hasWaitingCandidate) {
        return 'waiting_candidate';
    }
    if ($hasFollowup) {
        return 'followup';
    }
    if ($hasInProgress) {
        return 'in_progress';
    }
    return 'pending';
}

function verifier_case_queue_ensure_row(PDO $pdo, int $caseId): ?array
{
    verifier_case_queue_ensure_table($pdo);
    $case = verifier_case_queue_load_case($pdo, $caseId);
    if (!$case) return null;

    $workflowMode = strtolower(trim((string)($case['workflow_mode'] ?? 'validator_first')));
    $status = verifier_case_queue_compute_status($pdo, $caseId);
    if ($status === 'completed') {
        $completedAtExpr = 'NOW()';
    } else {
        $completedAtExpr = 'NULL';
    }

    try {
        $st = $pdo->prepare(
            "INSERT INTO Vati_Payfiller_Verifier_Case_Queue
                (case_id, client_id, application_id, status, assigned_user_id, claimed_at, completed_at, followup_at, workflow_mode)
             VALUES (?, ?, ?, ?, NULL, NULL, " . $completedAtExpr . ", NULL, ?)
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                application_id = VALUES(application_id),
                workflow_mode = VALUES(workflow_mode),
                status = VALUES(status),
                completed_at = CASE
                    WHEN VALUES(status) = 'completed' THEN COALESCE(Vati_Payfiller_Verifier_Case_Queue.completed_at, NOW())
                    WHEN VALUES(status) IN ('followup','waiting_candidate','pending','in_progress') THEN NULL
                    ELSE Vati_Payfiller_Verifier_Case_Queue.completed_at
                END,
                followup_at = CASE
                    WHEN VALUES(status) = 'followup' THEN COALESCE(Vati_Payfiller_Verifier_Case_Queue.followup_at, NOW())
                    ELSE Vati_Payfiller_Verifier_Case_Queue.followup_at
                END,
                updated_at = NOW()"
        );
        $st->execute([
            $caseId,
            isset($case['client_id']) ? (int)$case['client_id'] : null,
            (string)($case['application_id'] ?? ''),
            $status,
            $workflowMode,
        ]);
    } catch (Throwable $e) {
        return null;
    }

    return verifier_case_queue_load_row($pdo, $caseId);
}

function verifier_case_queue_load_row(PDO $pdo, int $caseId): ?array
{
    verifier_case_queue_ensure_table($pdo);
    if ($caseId <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM Vati_Payfiller_Verifier_Case_Queue WHERE case_id = ? LIMIT 1");
    $st->execute([$caseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function verifier_case_queue_assign_components(PDO $pdo, int $caseId, int $assignedUserId, ?array $componentKeys = null): void
{
    if ($caseId <= 0) return;
    $components = $componentKeys === null ? verifier_case_queue_required_components($pdo, $caseId) : $componentKeys;
    $components = array_values(array_filter(array_map(static function ($key) {
        $key = verifier_routing_normalize_component((string)$key);
        return verifier_routing_is_routeable($key) ? $key : '';
    }, $components)));
    if (!$components) return;
    $ph = implode(',', array_fill(0, count($components), '?'));
    $params = array_merge([$assignedUserId > 0 ? $assignedUserId : null, $caseId], $components);
    $st = $pdo->prepare(
        "UPDATE Vati_Payfiller_Case_Components
            SET assigned_role = 'verifier',
                assigned_user_id = ?,
                updated_at = NOW()
          WHERE case_id = ?
            AND LOWER(TRIM(component_key)) IN ($ph)"
    );
    $st->execute($params);
}

function verifier_case_queue_sync_legacy_group_rows(PDO $pdo, int $caseId, int $assignedUserId = 0, ?string $status = null): void
{
    if ($caseId <= 0) return;
    try {
        if ($assignedUserId > 0) {
            $st = $pdo->prepare(
                "UPDATE Vati_Payfiller_Verifier_Group_Queue
                    SET assigned_user_id = ?,
                        claimed_at = COALESCE(claimed_at, NOW()),
                        status = CASE
                            WHEN completed_at IS NOT NULL THEN status
                            WHEN ? IS NOT NULL AND ? <> '' THEN ?
                            WHEN COALESCE(LOWER(TRIM(status)), '') = 'followup' THEN status
                            ELSE 'in_progress'
                        END
                  WHERE case_id = ?"
            );
            $st->execute([$assignedUserId, $status, $status, $status, $caseId]);
        }

        if ($status === 'completed') {
            $st = $pdo->prepare(
                "UPDATE Vati_Payfiller_Verifier_Group_Queue
                    SET status = 'done',
.                        completed_at = COALESCE(completed_at, NOW()),
                        claimed_at = COALESCE(claimed_at, NOW()),
                        assigned_user_id = COALESCE(?, assigned_user_id)
                  WHERE case_id = ?
                    AND completed_at IS NULL"
            );
            $st->execute([$assignedUserId > 0 ? $assignedUserId : null, $caseId]);
        }
    } catch (Throwable $e) {
    }
}

function verifier_case_queue_pick_auto_user_id(PDO $pdo): int
{
    return 0;
}

function verifier_case_queue_claim(PDO $pdo, int $caseId, int $userId, bool $forceAssign = false): array
{
    verifier_case_queue_ensure_row($pdo, $caseId);
    verifier_case_queue_clear_db_verifier_owners($pdo, $caseId);
    $row = verifier_case_queue_load_row($pdo, $caseId);
    if (!$row) {
        return ['ok' => false, 'message' => 'Verifier case queue row not found'];
    }
    if (trim((string)($row['completed_at'] ?? '')) !== '') {
        return ['ok' => false, 'message' => 'Case already completed'];
    }

    $matchingComponents = $forceAssign
        ? array_values(array_filter(verifier_case_queue_required_components($pdo, $caseId), static function ($key) {
            return verifier_routing_is_routeable((string)$key);
        }))
        : verifier_routing_claimable_components_for_case($pdo, $caseId, $userId);
    if (!$matchingComponents) {
        return ['ok' => false, 'message' => 'No currently claimable verifier components for this case'];
    }

    if ($forceAssign) {
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Case_Queue
                SET assigned_user_id = ?,
                    claimed_at = COALESCE(claimed_at, NOW()),
                    status = CASE
                        WHEN LOWER(TRIM(COALESCE(status,''))) IN ('followup','waiting_candidate') THEN status
                        ELSE 'in_progress'
                    END,
                    updated_at = NOW()
              WHERE case_id = ?
                AND completed_at IS NULL"
        );
        $st->execute([$userId, $caseId]);
        verifier_case_queue_assign_components($pdo, $caseId, $userId, $matchingComponents);
        verifier_case_queue_sync_legacy_group_rows($pdo, $caseId, $userId);
        verifier_case_queue_sync($pdo, $caseId, $userId);
        return ['ok' => true, 'message' => 'claimed', 'components' => $matchingComponents];
    }

    $assignedUserId = (int)($row['assigned_user_id'] ?? 0);
    if ($assignedUserId <= 0 || $assignedUserId === $userId) {
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Case_Queue
                SET assigned_user_id = COALESCE(NULLIF(assigned_user_id, 0), ?),
                    claimed_at = COALESCE(claimed_at, NOW()),
                    status = CASE
                        WHEN LOWER(TRIM(COALESCE(status,''))) IN ('followup','waiting_candidate') THEN status
                        ELSE 'in_progress'
                    END,
                    updated_at = NOW()
              WHERE case_id = ?
                AND completed_at IS NULL"
        );
        $st->execute([$userId, $caseId]);
    } else {
        $st = $pdo->prepare(
            "UPDATE Vati_Payfiller_Verifier_Case_Queue
                SET status = CASE
                        WHEN LOWER(TRIM(COALESCE(status,''))) IN ('followup','waiting_candidate') THEN status
                        ELSE 'in_progress'
                    END,
                    updated_at = NOW()
              WHERE case_id = ?
                AND completed_at IS NULL"
        );
        $st->execute([$caseId]);
    }

    verifier_case_queue_assign_components($pdo, $caseId, $userId, $matchingComponents);
    verifier_case_queue_sync_legacy_group_rows($pdo, $caseId, $assignedUserId > 0 ? $assignedUserId : $userId);
    verifier_case_queue_sync($pdo, $caseId, $assignedUserId > 0 ? $assignedUserId : $userId);
    return ['ok' => true, 'message' => 'claimed', 'components' => $matchingComponents];
}

function verifier_case_queue_sync(PDO $pdo, int $caseId, int $userId = 0): ?array
{
    verifier_case_queue_ensure_row($pdo, $caseId);
    $row = verifier_case_queue_load_row($pdo, $caseId);
    if (!$row) return null;

    $status = verifier_case_queue_compute_status($pdo, $caseId);
    $sql =
        "UPDATE Vati_Payfiller_Verifier_Case_Queue
            SET status = ?,
                completed_at = CASE
                    WHEN ? = 'completed' THEN COALESCE(completed_at, NOW())
                    WHEN ? IN ('followup','waiting_candidate','pending','in_progress') THEN NULL
                    ELSE completed_at
                END,
                followup_at = CASE
                    WHEN ? = 'followup' THEN COALESCE(followup_at, NOW())
                    ELSE followup_at
                END,
                assigned_user_id = CASE
                    WHEN COALESCE(assigned_user_id, 0) = 0 AND ? > 0 THEN ?
                    ELSE assigned_user_id
                END,
                claimed_at = CASE
                    WHEN (? > 0 OR COALESCE(assigned_user_id, 0) > 0) THEN COALESCE(claimed_at, NOW())
                    ELSE claimed_at
                END,
                updated_at = NOW()
          WHERE case_id = ?";
    $st = $pdo->prepare($sql);
    $st->execute([$status, $status, $status, $status, $userId, $userId, $userId, $caseId]);

    $ownerId = (int)($row['assigned_user_id'] ?? 0);
    if ($ownerId <= 0 && $userId > 0) {
        $ownerId = $userId;
    }
    verifier_case_queue_sync_legacy_group_rows($pdo, $caseId, $ownerId, $status === 'completed' ? 'completed' : null);

    $fresh = verifier_case_queue_load_row($pdo, $caseId);

    if ($fresh && strtolower(trim((string)($fresh['status'] ?? ''))) === 'completed') {
        try {
            $st = $pdo->prepare(
                "UPDATE Vati_Payfiller_Cases
                    SET case_status = 'PENDING_QA',
                        updated_at = NOW()
                  WHERE case_id = ?
                    AND UPPER(TRIM(COALESCE(case_status,''))) NOT IN ('REJECTED','STOP_BGV','APPROVED','COMPLETED','CLEAR')"
            );
            $st->execute([$caseId]);
        } catch (Throwable $e) {
        }
    }

    return $fresh ?: $row;
}

function verifier_case_queue_can_open(PDO $pdo, int $caseId, int $userId): bool
{
    if ($caseId <= 0 || $userId <= 0) return false;
    try {
        $st = $pdo->prepare(
            "SELECT 1
               FROM Vati_Payfiller_Case_Components
              WHERE case_id = ?
                AND LOWER(TRIM(COALESCE(assigned_role,''))) = 'verifier'
                AND assigned_user_id = ?
              LIMIT 1"
        );
        $st->execute([$caseId, $userId]);
        if ($st->fetchColumn()) return true;
    } catch (Throwable $e) {
    }

    $row = verifier_case_queue_load_row($pdo, $caseId);
    if (!$row) return false;
    if (trim((string)($row['completed_at'] ?? '')) !== '') {
        try {
            $st = $pdo->prepare(
                "SELECT 1
                   FROM Vati_Payfiller_Workflow_Transitions
                  WHERE case_id = ?
                    AND actor_user_id = ?
                    AND LOWER(TRIM(COALESCE(actor_role,''))) IN ('verifier','db_verifier','component verifier','component_verifier')
                  LIMIT 1"
            );
            $st->execute([$caseId, $userId]);
            if ($st->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }
    return false;
}

function verifier_case_queue_sync_from_group_rows(PDO $pdo, int $caseId): ?array
{
    if ($caseId <= 0) return null;
    verifier_case_queue_ensure_table($pdo);
    verifier_case_queue_clear_db_verifier_owners($pdo, $caseId);

    $case = verifier_case_queue_load_case($pdo, $caseId);
    if (!$case) return null;

    try {
        $st = $pdo->prepare(
            "SELECT assigned_user_id, claimed_at, completed_at, COALESCE(LOWER(TRIM(status)), 'pending') AS status
               FROM Vati_Payfiller_Verifier_Group_Queue
              WHERE case_id = ?
              ORDER BY id ASC"
        );
        $st->execute([$caseId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return null;
    }
    if (!$rows) return verifier_case_queue_ensure_row($pdo, $caseId);

    $assignedUserId = 0;
    $claimedAt = null;
    $completedAt = null;
    $hasOpen = false;
    $hasWaiting = false;
    $hasFollowup = false;
    $hasInProgress = false;

    foreach ($rows as $row) {
        $uid = (int)($row['assigned_user_id'] ?? 0);
        if ($uid > 0 && $assignedUserId <= 0) {
            $assignedUserId = $uid;
        }
        $claimed = trim((string)($row['claimed_at'] ?? ''));
        if ($claimed !== '' && ($claimedAt === null || strcmp($claimed, (string)$claimedAt) < 0)) {
            $claimedAt = $claimed;
        }
        if (trim((string)($row['completed_at'] ?? '')) === '') {
            $hasOpen = true;
        } else {
            $comp = (string)($row['completed_at'] ?? '');
            if ($completedAt === null || strcmp($comp, (string)$completedAt) > 0) {
                $completedAt = $comp;
            }
        }
        $status = strtolower(trim((string)($row['status'] ?? 'pending')));
        if (verifier_case_queue_status_is_waiting_candidate($status)) {
            $hasWaiting = true;
        } elseif (verifier_case_queue_status_is_followup($status)) {
            $hasFollowup = true;
        } elseif (in_array($status, ['pending', 'in_progress'], true)) {
            $hasInProgress = true;
        }
    }

    $status = 'completed';
    if ($hasOpen) {
        if ($hasWaiting) {
            $status = 'waiting_candidate';
        } elseif ($hasFollowup) {
            $status = 'followup';
        } elseif ($hasInProgress) {
            $status = 'in_progress';
        } else {
            $status = 'pending';
        }
        $completedAt = null;
    }

    try {
        $st = $pdo->prepare(
            "INSERT INTO Vati_Payfiller_Verifier_Case_Queue
                (case_id, client_id, application_id, status, assigned_user_id, claimed_at, completed_at, workflow_mode)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                application_id = VALUES(application_id),
                status = VALUES(status),
                assigned_user_id = CASE
                    WHEN COALESCE(VALUES(assigned_user_id), 0) > 0 THEN VALUES(assigned_user_id)
                    ELSE assigned_user_id
                END,
                claimed_at = COALESCE(VALUES(claimed_at), claimed_at),
                completed_at = VALUES(completed_at),
                workflow_mode = VALUES(workflow_mode),
                updated_at = NOW()"
        );
        $st->execute([
            $caseId,
            isset($case['client_id']) ? (int)$case['client_id'] : null,
            (string)($case['application_id'] ?? ''),
            $status,
            $assignedUserId > 0 ? $assignedUserId : null,
            $claimedAt,
            $completedAt,
            (string)($case['workflow_mode'] ?? 'validator_first'),
        ]);
    } catch (Throwable $e) {
        return null;
    }

    return verifier_case_queue_load_row($pdo, $caseId);
}
