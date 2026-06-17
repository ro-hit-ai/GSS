<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integration.php';

integration_bootstrap_json_api();
auth_session_start();
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

integration_resolve_actor(true);

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function get_int(string $key, int $default = 0): int {
    return isset($_GET[$key]) && $_GET[$key] !== '' ? (int)$_GET[$key] : $default;
}

function session_role_norm(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $role = !empty($_SESSION['auth_moduleAccess']) ? strtolower(trim((string)$_SESSION['auth_moduleAccess'])) : '';
    if ($role === 'customer_admin') $role = 'client_admin';
    return $role;
}

function session_client_id(): int {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
}

function timeline_meta_array($raw): ?array {
    if (!is_string($raw) || trim($raw) === '') return null;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function timeline_actor_label(array $row, string $fallbackRole = ''): string {
    $actorName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
    if ($actorName !== '') return $actorName;
    $username = trim((string)($row['username'] ?? ''));
    if ($username !== '') return $username;
    $role = trim((string)($row['actor_role'] ?? $fallbackRole));
    return $role !== '' ? strtoupper($role) : 'System';
}

function timeline_governance_view(array $row, ?array $meta): ?array {
    $eventType = strtolower(trim((string)($row['event_type'] ?? '')));
    $message = trim((string)($row['message'] ?? ''));
    $actor = timeline_actor_label($row);
    $section = trim((string)($row['section_key'] ?? ''));

    if ($eventType === 'workflow.reopen') {
        $reason = trim((string)($meta['reason'] ?? ''));
        $targetStage = trim((string)($meta['target_stage'] ?? ''));
        $downstreamAware = !empty($meta['downstream_aware_reopen']);
        return [
            'kind' => 'decision_change',
            'tone' => $downstreamAware ? 'amber' : 'blue',
            'title' => 'Decision Update Initiated',
            'summary' => $downstreamAware
                ? ($actor . ' initiated a decision update and triggered downstream re-review.')
                : ($actor . ' initiated a decision update for re-review.'),
            'reason' => $reason !== '' ? $reason : null,
            'lineage' => $targetStage !== '' ? ('Stage: ' . strtoupper($targetStage)) : null,
        ];
    }

    if ($eventType === 'workflow.decision_change') {
        $reason = trim((string)($meta['reason'] ?? ''));
        $stage = trim((string)($meta['stage'] ?? ''));
        return [
            'kind' => 'decision_change',
            'tone' => 'blue',
            'title' => 'Decision Updated',
            'summary' => $message !== '' ? $message : ($actor . ' changed a workflow decision.'),
            'reason' => $reason !== '' ? $reason : null,
            'lineage' => $stage !== '' ? ('Stage: ' . strtoupper($stage)) : null,
        ];
    }

    if ($eventType === 'workflow.decision') {
        $lowerMessage = strtolower($message);
        $tone = 'blue';
        if (strpos($lowerMessage, 'rejected') !== false) {
            $tone = 'red';
        } elseif (strpos($lowerMessage, 'hold') !== false || strpos($lowerMessage, 'insufficient_documents') !== false) {
            $tone = 'amber';
        } elseif (strpos($lowerMessage, 'approved') !== false) {
            $tone = 'green';
        }
        return [
            'kind' => 'decision_change',
            'tone' => $tone,
            'title' => 'Decision Recorded',
            'summary' => $message !== '' ? $message : ($actor . ' recorded a workflow decision.'),
            'reason' => null,
            'lineage' => $section !== '' ? ('Section: ' . $section) : null,
        ];
    }

    if ($eventType === 'workflow.invalidation') {
        $reason = trim((string)($meta['reason'] ?? ''));
        $sourceStage = trim((string)($meta['source_stage'] ?? ''));
        $targetStage = trim((string)($meta['target_stage'] ?? ''));
        return [
            'kind' => 'invalidation',
            'tone' => 'red',
            'title' => 'Downstream Work Invalidated',
            'summary' => $actor . ' invalidated stale downstream work after a decision change.',
            'reason' => $reason !== '' ? $reason : null,
            'lineage' => ($sourceStage !== '' || $targetStage !== '')
                ? ('Source: ' . strtoupper($sourceStage !== '' ? $sourceStage : '-') . ' -> Target: ' . strtoupper($targetStage !== '' ? $targetStage : '-'))
                : null,
        ];
    }

    if ($eventType === 'workflow.relock') {
        $relockedStage = trim((string)($meta['stage'] ?? ''));
        return [
            'kind' => 'decision_change',
            'tone' => 'green',
            'title' => 'Decision Finalized',
            'summary' => $actor . ' finalized the updated workflow decision.',
            'reason' => null,
            'lineage' => $relockedStage !== '' ? ('Stage: ' . strtoupper($relockedStage)) : null,
        ];
    }

    if ($eventType === 'candidate_correction') {
        return [
            'kind' => 'correction',
            'tone' => 'amber',
            'title' => 'Candidate Correction Loop',
            'summary' => $message !== '' ? $message : ($actor . ' triggered a candidate correction cycle.'),
            'reason' => null,
            'lineage' => $section !== '' ? ('Section: ' . $section) : null,
        ];
    }

    if (strpos($message, 'Candidate Access Resent') !== false || strpos($message, 'Verification mail') !== false) {
        return [
            'kind' => 'communication',
            'tone' => 'blue',
            'title' => 'Workflow Communication',
            'summary' => $message !== '' ? $message : ($actor . ' sent workflow communication.'),
            'reason' => null,
            'lineage' => $section !== '' ? ('Section: ' . $section) : null,
        ];
    }

    return null;
}

function enforce_client_admin_application_scope(PDO $pdo, string $applicationId): void {
    $role = session_role_norm();
    if ($role !== 'client_admin') return;

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

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $applicationId = integration_normalize_application_id(get_str('application_id', ''));
    if ($applicationId === '') {
        integration_json_error(400, 'application_id is required', [], 'integration_failures');
    }

    $limit = get_int('limit', 200);
    if ($limit <= 0) $limit = 200;
    if ($limit > 500) $limit = 500;

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

    $sql = 'SELECT t.timeline_id, t.application_id, t.actor_user_id, t.actor_role, t.event_type, t.section_key, t.message, t.meta_json, t.created_at, '
        . 'u.username, u.first_name, u.last_name '
        . 'FROM Vati_Payfiller_Case_Timeline t '
        . 'LEFT JOIN Vati_Payfiller_Users u ON u.user_id = t.actor_user_id '
        . 'WHERE t.application_id = ? '
        . 'ORDER BY t.created_at DESC, t.timeline_id DESC '
        . 'LIMIT ' . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$applicationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $events = [];
    foreach ($rows as $row) {
        $actorName = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $metadata = timeline_meta_array((string)($row['meta_json'] ?? ''));
        $governance = timeline_governance_view($row, $metadata);
        $events[] = [
            'timelineId' => isset($row['timeline_id']) ? (int)$row['timeline_id'] : null,
            'applicationId' => integration_normalize_application_id((string)($row['application_id'] ?? $applicationId)),
            'eventType' => integration_nullable_string($row['event_type'] ?? null),
            'eventTimestamp' => integration_iso_datetime($row['created_at'] ?? null),
            'sectionKey' => integration_nullable_string($row['section_key'] ?? null),
            'componentKey' => integration_nullable_string($row['section_key'] ?? null),
            'message' => integration_nullable_string($row['message'] ?? null),
            'metadata' => $metadata,
            'governance' => $governance,
            'isGovernanceEvent' => $governance ? 1 : 0,
            'displayTitle' => $governance['title'] ?? null,
            'displaySummary' => $governance['summary'] ?? null,
            'displayTone' => $governance['tone'] ?? null,
            'actor' => [
                'userId' => isset($row['actor_user_id']) && (int)$row['actor_user_id'] > 0 ? (int)$row['actor_user_id'] : null,
                'role' => integration_nullable_string($row['actor_role'] ?? null),
                'username' => integration_nullable_string($row['username'] ?? null),
                'name' => integration_nullable_string($actorName),
            ],
            // Backward-compatible keys expected by legacy candidate_report.js
            'timeline_id' => isset($row['timeline_id']) ? (int)$row['timeline_id'] : null,
            'application_id' => integration_normalize_application_id((string)($row['application_id'] ?? $applicationId)),
            'actor_user_id' => isset($row['actor_user_id']) && (int)$row['actor_user_id'] > 0 ? (int)$row['actor_user_id'] : null,
            'actor_role' => integration_nullable_string($row['actor_role'] ?? null),
            'event_type' => integration_nullable_string($row['event_type'] ?? null),
            'section_key' => integration_nullable_string($row['section_key'] ?? null),
            'meta_json' => integration_nullable_string($row['meta_json'] ?? null),
            'created_at' => integration_nullable_string($row['created_at'] ?? null),
            'username' => integration_nullable_string($row['username'] ?? null),
            'first_name' => integration_nullable_string($row['first_name'] ?? null),
            'last_name' => integration_nullable_string($row['last_name'] ?? null),
        ];
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $events]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
