<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/integration.php';
require_once __DIR__ . '/shared/workflow_communication_service.php';

auth_require_login(null);
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

function get_str(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function normalize_component_key(string $value): string
{
    $v = strtolower(trim($value));
    if ($v === 'identification') return 'id';
    if ($v === 'contact_information' || $v === 'contact information') return 'contact';
    if ($v === 'education_details' || $v === 'education details') return 'education';
    if ($v === 'employment_details' || $v === 'employment details') return 'employment';
    if ($v === 'education_reference' || $v === 'education reference') return 'education_reference';
    if ($v === 'employment_reference' || $v === 'employment reference') return 'employment_reference';
    if ($v === 'social_media' || $v === 'social media') return 'socialmedia';
    if ($v === 'e-court' || $v === 'e_court' || $v === 'e court') return 'ecourt';
    if ($v === 'references') return 'reference';
    return $v;
}

function infer_component_from_text(string $subject, string $body = ''): string
{
    $haystack = strtolower(trim($subject . ' ' . $body));
    if ($haystack === '') return '';
    $map = [
        'education_reference' => ['education reference', 'education_reference'],
        'employment_reference' => ['employment reference', 'employment_reference'],
        'basic' => ['basic details', 'basic'],
        'id' => ['identification', ' id '],
        'contact' => ['contact information', 'contact'],
        'education' => ['education details', 'education'],
        'employment' => ['employment details', 'employment'],
        'reference' => ['reference', 'references'],
        'socialmedia' => ['social media', 'socialmedia'],
        'ecourt' => ['e-court', 'ecourt', 'e court'],
        'reports' => ['reports', 'report'],
    ];
    foreach ($map as $componentKey => $needles) {
        foreach ($needles as $needle) {
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return $componentKey;
            }
        }
    }
    return '';
}

function row_matches_component_scope(array $row, string $componentKey): bool
{
    $rowKey = normalize_component_key((string)($row['component_key'] ?? ''));
    if ($rowKey === $componentKey) return true;
    if ($rowKey !== '' && $rowKey !== 'timeline') return false;
    $subject = (string)($row['subject'] ?? '');
    $body = (string)($row['body'] ?? $row['message'] ?? '');
    return infer_component_from_text($subject, $body) === $componentKey;
}

function session_role_norm(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $role = !empty($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') {
        $role = 'client_admin';
    }
    return $role;
}

function normalize_role_key(string $role): string
{
    $role = strtolower(trim($role));
    if ($role === 'customer_admin') return 'client_admin';
    if ($role === 'db verifier' || $role === 'db-verifier' || $role === 'db_verifier') return 'verifier';
    return $role;
}

function normalize_thread_owner_role(string $role): string
{
    $role = normalize_role_key($role);
    if ($role === 'team_lead') return 'qa';
    return $role;
}

function session_client_id(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void
{
    if (session_role_norm() !== 'client_admin') {
        return;
    }

    $cid = session_client_id();
    if ($cid <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $st = $pdo->prepare('SELECT client_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
    $st->execute([$applicationId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $appClientId = $row && isset($row['client_id']) ? (int)$row['client_id'] : 0;

    if ($appClientId !== $cid) {
        http_response_code(403);
        echo json_encode(['status' => 0, 'message' => 'Forbidden']);
        exit;
    }
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
    );
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

function load_legacy_incoming_replies(PDO $pdo, string $applicationId, int $limit = 120): array
{
    $table = wc_resolve_replies_table($pdo);
    if ($table === '') return [];
    $cols = wc_resolve_reply_columns($pdo, $table);
    if (empty($cols['ok'])) return [];

    $sql = 'SELECT `'.str_replace('`','``',$cols['sender']).'` AS sender, '
        . '`'.str_replace('`','``',$cols['message']).'` AS message, '
        . '`'.str_replace('`','``',$cols['created_at']).'` AS created_at '
        . ($cols['subject'] !== '' ? ', `'.str_replace('`','``',$cols['subject']).'` AS subject ' : ", '' AS subject ")
        . ($cols['message_id'] !== '' ? ', `'.str_replace('`','``',$cols['message_id']).'` AS message_id ' : ", '' AS message_id ")
        . ($cols['in_reply_to'] !== '' ? ', `'.str_replace('`','``',$cols['in_reply_to']).'` AS in_reply_to ' : ", '' AS in_reply_to ")
        . ($cols['references_header'] !== '' ? ', `'.str_replace('`','``',$cols['references_header']).'` AS references_header ' : ", '' AS references_header ")
        . ($cols['thread_id'] !== '' ? ', `'.str_replace('`','``',$cols['thread_id']).'` AS thread_id ' : ", '' AS thread_id ")
        . 'FROM `'.str_replace('`','``',$table).'` '
        . "WHERE REPLACE(LOWER(TRIM(application_id)), ' ', '') = REPLACE(LOWER(TRIM(?)), ' ', '') "
        . 'ORDER BY `'.str_replace('`','``',$cols['created_at']).'` DESC '
        . 'LIMIT ' . max(1, (int)$limit);
    $st = $pdo->prepare($sql);
    $st->execute([$applicationId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'communication_id' => 0,
            'component_key' => 'timeline',
            'subject' => (string)($r['subject'] ?? ''),
            'sender' => (string)($r['sender'] ?? 'Candidate'),
            'message' => (string)($r['message'] ?? ''),
            'created_at' => (string)($r['created_at'] ?? ''),
            'direction' => 'incoming',
            'delivery_status' => 'received',
            'communication_type' => 'manual_message',
            'actor_role' => 'candidate',
            'message_id' => wc_norm_msg_id((string)($r['message_id'] ?? '')),
            'in_reply_to' => wc_norm_msg_id((string)($r['in_reply_to'] ?? '')),
            'references_header' => (string)($r['references_header'] ?? ''),
            'thread_id' => (string)($r['thread_id'] ?? ''),
        ];
    }
    return $out;
}

function row_belongs_to_viewer_role(array $row, string $viewerRole, array $allowedThreadIds, array $allowedMessageIds): bool
{
    if ($viewerRole === '') {
        return true;
    }

    $direction = strtolower(trim((string)($row['direction'] ?? '')));
    $actorRole = normalize_role_key((string)($row['actor_role'] ?? ''));
    if ($direction === 'outgoing') {
        return $actorRole === $viewerRole;
    }

    if ($direction !== 'incoming') {
        return true;
    }

    $threadId = strtolower(trim((string)($row['thread_id'] ?? '')));
    if ($threadId !== '' && isset($allowedThreadIds[$threadId])) {
        return true;
    }

    $inReplyTo = wc_norm_msg_id((string)($row['in_reply_to'] ?? ''));
    if ($inReplyTo !== '' && isset($allowedMessageIds[$inReplyTo])) {
        return true;
    }

    $references = wc_extract_msg_ids((string)($row['references_header'] ?? ''));
    foreach ($references as $refId) {
        if ($refId !== '' && isset($allowedMessageIds[$refId])) {
            return true;
        }
    }

    return false;
}

function has_incoming_rows(array $rows): bool
{
    foreach ($rows as $row) {
        if (strtolower(trim((string)($row['direction'] ?? ''))) === 'incoming') {
            return true;
        }
    }
    return false;
}

function row_is_outgoing_for_viewer(array $row, string $viewerRole): bool
{
    $direction = strtolower(trim((string)($row['direction'] ?? '')));
    $actorRole = normalize_role_key((string)($row['actor_role'] ?? ''));
    return $direction === 'outgoing' && $viewerRole !== '' && $actorRole === $viewerRole;
}

function canonical_verification_projection_exists(PDO $pdo, string $applicationId, string $componentKey = ''): bool
{
    $sql = "SELECT 1
              FROM Vati_Payfiller_Workflow_Communications
             WHERE application_id = ?
               AND source_table = 'Vati_Payfiller_Verification_Communications'";
    $params = [$applicationId];
    if ($componentKey !== '') {
        $sql .= ' AND component_key = ?';
        $params[] = $componentKey;
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (bool)$st->fetchColumn();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = integration_normalize_application_id(get_str('application_id'));
    $componentKey = normalize_component_key(get_str('component_key'));
    $scope = strtolower(get_str('scope', 'case'));
    $sync = strtolower(get_str('sync', '1'));
    $viewerRole = normalize_role_key(session_role_norm());
    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }
    if (!in_array($scope, ['case', 'component'], true)) {
        $scope = 'case';
    }
    if ($scope !== 'component') {
        $componentKey = '';
    }
    $shouldSync = !in_array($sync, ['0', 'false', 'no', 'off'], true);

    $pdo = getDB();
    enforce_client_admin_application_scope($pdo, $applicationId);
    $verificationProjected = 0;
    $canonicalIncomingInserted = 0;
    if ($shouldSync) {
        $needsVerificationProjection = false;
        if ($scope === 'component' && in_array($componentKey, ['education', 'employment'], true)) {
            $needsVerificationProjection = !canonical_verification_projection_exists($pdo, $applicationId, $componentKey);
        } elseif ($scope !== 'component') {
            $needsVerificationProjection = !canonical_verification_projection_exists($pdo, $applicationId);
        }
        if ($needsVerificationProjection) {
            $verificationProjected = wc_sync_verification_communications($pdo, $applicationId);
        }
        $canonicalIncomingInserted = wc_ingest_incoming_replies($pdo, $applicationId);
    }

    $wcTable = 'Vati_Payfiller_Workflow_Communications';
    $hasDirection = table_has_column($pdo, $wcTable, 'direction');
    $hasActorRole = table_has_column($pdo, $wcTable, 'actor_role');
    $hasActorName = table_has_column($pdo, $wcTable, 'actor_name');
    $hasDelivery = table_has_column($pdo, $wcTable, 'delivery_status');
    $hasType = table_has_column($pdo, $wcTable, 'communication_type');
    $hasActionKey = table_has_column($pdo, $wcTable, 'action_key');
    $hasMessageId = table_has_column($pdo, $wcTable, 'message_id');
    $hasInReplyTo = table_has_column($pdo, $wcTable, 'in_reply_to');
    $hasReferences = table_has_column($pdo, $wcTable, 'references_header');
    $hasThreadId = table_has_column($pdo, $wcTable, 'thread_id');
    $hasThreadOwnerRole = table_has_column($pdo, $wcTable, 'thread_owner_role');
    $hasRootOutgoingCommunicationId = table_has_column($pdo, $wcTable, 'root_outgoing_communication_id');

    $sql = 'SELECT communication_id, component_key, role_key, sent_by_name, subject, body, notes, sent_at'
        . ($hasDirection ? ', direction' : ", 'outgoing' AS direction")
        . ($hasActorRole ? ', actor_role' : ', role_key AS actor_role')
        . ($hasActorName ? ', actor_name' : ', sent_by_name AS actor_name')
        . ($hasDelivery ? ', delivery_status' : ", 'sent' AS delivery_status")
        . ($hasType ? ', communication_type' : ", '' AS communication_type")
        . ($hasActionKey ? ', action_key' : ", '' AS action_key")
        . ($hasMessageId ? ', message_id' : ", '' AS message_id")
        . ($hasInReplyTo ? ', in_reply_to' : ", '' AS in_reply_to")
        . ($hasReferences ? ', references_header' : ", '' AS references_header")
        . ($hasThreadId ? ', thread_id' : ", '' AS thread_id")
        . ($hasThreadOwnerRole ? ', thread_owner_role' : ", '' AS thread_owner_role")
        . ($hasRootOutgoingCommunicationId ? ', root_outgoing_communication_id' : ", 0 AS root_outgoing_communication_id")
        . ' FROM Vati_Payfiller_Workflow_Communications';

    $where = ['application_id = ?'];
    $params = [$applicationId];
    if ($hasDirection) {
        $where[] = "direction IN ('incoming','outgoing')";
    }
    $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY sent_at DESC, communication_id DESC LIMIT 120';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $data = array_map(static function (array $row): array {
        $senderName = (string)($row['actor_name'] ?? '');
        if ($senderName === '') $senderName = (string)($row['sent_by_name'] ?? '');
        if ($senderName === '') {
            $senderName = strtolower((string)($row['direction'] ?? '')) === 'incoming' ? 'Candidate' : 'Operations';
        }
        $msg = trim((string)($row['body'] ?? ''));
        if ($msg === '') $msg = trim((string)($row['notes'] ?? ''));
        if ($msg === '') $msg = trim((string)($row['subject'] ?? ''));
        return [
            'communication_id' => (int)($row['communication_id'] ?? 0),
            'component_key' => normalize_component_key((string)($row['component_key'] ?? '')),
            'subject' => (string)($row['subject'] ?? ''),
            'sender' => $senderName,
            'message' => $msg,
            'created_at' => (string)($row['sent_at'] ?? ''),
            'direction' => (string)($row['direction'] ?? ''),
            'delivery_status' => (string)($row['delivery_status'] ?? ''),
            'communication_type' => (string)($row['communication_type'] ?? ''),
            'action_key' => (string)($row['action_key'] ?? ''),
            'actor_role' => (string)($row['actor_role'] ?? $row['role_key'] ?? ''),
            'message_id' => (string)($row['message_id'] ?? ''),
            'in_reply_to' => (string)($row['in_reply_to'] ?? ''),
            'references_header' => (string)($row['references_header'] ?? ''),
            'thread_id' => (string)($row['thread_id'] ?? ''),
            'thread_owner_role' => normalize_thread_owner_role((string)($row['thread_owner_role'] ?? '')),
            'root_outgoing_communication_id' => (int)($row['root_outgoing_communication_id'] ?? 0),
        ];
    }, $rows);

    if ($scope === 'component' && $componentKey !== '') {
        $data = array_values(array_filter($data, static function (array $row) use ($componentKey): bool {
            return row_matches_component_scope($row, $componentKey);
        }));
    }

    $viewerThreadRole = normalize_thread_owner_role($viewerRole);
    $scopedData = $data;
    $explicitOwnershipSupported = $hasThreadOwnerRole;
    $scopeHasExplicitOwnership = false;
    foreach ($scopedData as $row) {
        if (normalize_thread_owner_role((string)($row['thread_owner_role'] ?? '')) !== '') {
            $scopeHasExplicitOwnership = true;
            break;
        }
    }
    $allowedThreadIds = [];
    $allowedMessageIds = [];
    $viewerOutgoingCount = 0;
    foreach ($scopedData as $row) {
        if (!row_is_outgoing_for_viewer($row, $viewerRole)) {
            continue;
        }
        $viewerOutgoingCount++;
        $threadId = strtolower(trim((string)($row['thread_id'] ?? '')));
        if ($threadId !== '') {
            $allowedThreadIds[$threadId] = true;
        }
        $messageId = wc_norm_msg_id((string)($row['message_id'] ?? ''));
        if ($messageId !== '') {
            $allowedMessageIds[$messageId] = true;
        }
    }
    $strictData = array_values(array_filter($scopedData, static function (array $row) use ($viewerRole, $viewerThreadRole, $allowedThreadIds, $allowedMessageIds, $explicitOwnershipSupported): bool {
        $ownerRole = normalize_thread_owner_role((string)($row['thread_owner_role'] ?? ''));
        if ($explicitOwnershipSupported && $ownerRole !== '') {
            return $viewerThreadRole !== '' && $ownerRole === $viewerThreadRole;
        }
        return row_belongs_to_viewer_role($row, $viewerRole, $allowedThreadIds, $allowedMessageIds);
    }));
    $data = $strictData;

    // Fallback: if canonical has no incoming rows, surface legacy mailbox replies directly.
    $hasIncoming = has_incoming_rows($data);
    $usedFallback = false;
    $fallbackReason = '';
    if (!$hasIncoming && (!empty($allowedThreadIds) || !empty($allowedMessageIds))) {
        $legacy = load_legacy_incoming_replies($pdo, $applicationId, 120);
        if ($legacy) {
            if ($scope === 'component' && $componentKey !== '') {
                $legacy = array_values(array_filter($legacy, static function (array $row) use ($componentKey): bool {
                    return row_matches_component_scope($row, $componentKey);
                }));
            }
            $legacy = array_values(array_filter($legacy, static function (array $row) use ($viewerRole, $allowedThreadIds, $allowedMessageIds): bool {
                return row_belongs_to_viewer_role($row, $viewerRole, $allowedThreadIds, $allowedMessageIds);
            }));
            if (!empty($legacy)) {
                $usedFallback = true;
                $fallbackReason = 'legacy_thread_linked';
                $data = array_merge($legacy, $data);
                usort($data, static function (array $a, array $b): int {
                    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
                });
                if (count($data) > 120) {
                    $data = array_slice($data, 0, 120);
                }
            }
        }
    }

    // Keep backward compatibility for both response shapes consumed by UI.
    $canonicalCount = count($rows);
    $legacyCount = 0;
    $resolvedOwnerRole = '';
    foreach ($data as &$d) {
        if ((int)($d['communication_id'] ?? 0) === 0) $legacyCount++;
        if ($resolvedOwnerRole === '') {
            $resolvedOwnerRole = normalize_thread_owner_role((string)($d['thread_owner_role'] ?? ''));
        }
    }
    unset($d);
    $lastIncoming = null;
    foreach ($data as $d) {
        if (strtolower(trim((string)($d['direction'] ?? ''))) === 'incoming') {
            $lastIncoming = $d;
            break;
        }
    }
    $scopeHasThread = false;
    foreach ($scopedData as $row) {
        if (trim((string)($row['thread_id'] ?? '')) !== '' || normalize_thread_owner_role((string)($row['thread_owner_role'] ?? '')) !== '') {
            $scopeHasThread = true;
            break;
        }
    }
    $viewerThreadExists = (!empty($allowedThreadIds) || !empty($allowedMessageIds));
    $runtimeRows = [];
    try {
        if (table_exists($pdo, 'Vati_Payfiller_Workflow_Mail_Runtime_State')) {
            $runtimeStmt = $pdo->prepare(
                'SELECT source_key, last_status, last_run_at, inserted_count, duplicate_count, skipped_count, unmatched_count, note
                   FROM Vati_Payfiller_Workflow_Mail_Runtime_State
                  WHERE source_key IN (?, ?)
                  ORDER BY source_key ASC'
            );
            $runtimeStmt->execute(['legacy_reply_ingest', 'verification_sync']);
            $runtimeRows = $runtimeStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $runtimeRows = [];
    }
    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $data,
        'debug' => [
            'canonical_count' => $canonicalCount,
            'legacy_count' => $legacyCount,
            'verification_projected' => $verificationProjected,
            'canonical_incoming_inserted' => $canonicalIncomingInserted,
            'matched_application_id' => $applicationId,
            'component_key' => $componentKey,
            'scope' => $scope,
            'viewer_role' => $viewerRole,
            'sync' => $shouldSync,
            'sync_mode' => $shouldSync ? 'canonical_sync' : 'read_only_refresh',
            'last_synced_at' => date('Y-m-d H:i:s'),
            'resolved_source' => $usedFallback ? 'legacy_fallback' : 'canonical',
            'resolved_component_key' => $componentKey,
            'resolved_thread_owner_role' => $resolvedOwnerRole,
            'used_fallback' => $usedFallback,
            'fallback_reason' => $fallbackReason,
            'scope_has_thread' => $scopeHasThread,
            'viewer_thread_exists' => $viewerThreadExists,
            'strict_count' => count($strictData),
            'matched_thread_id' => (string)($lastIncoming['thread_id'] ?? ''),
            'last_in_reply_to' => (string)($lastIncoming['in_reply_to'] ?? ''),
            'last_references' => (string)($lastIncoming['references_header'] ?? ''),
            'runtime_state' => $runtimeRows,
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 0,
        'message' => 'Database error',
        'error' => $e->getMessage()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 0,
        'message' => 'Server error',
        'error' => $e->getMessage()
    ]);
}
