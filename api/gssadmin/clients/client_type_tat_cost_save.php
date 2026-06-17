<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function normalize_unit(string $u): string {
    $raw = strtolower(trim($u));
    if ($raw === 'hour' || $raw === 'hours' || $raw === 'hr' || $raw === 'hrs') return 'hours';
    return 'days';
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $data = read_json_body();
    if (!$data) {
        $data = $_POST;
    }

    $clientId = isset($data['client_id']) ? (int)$data['client_id'] : 0;
    $levelKey = isset($data['level_key']) ? trim((string)$data['level_key']) : '';
    $jobRoleId = isset($data['job_role_id']) ? (int)$data['job_role_id'] : 0;
    $items = $data['items'] ?? [];
    if (!is_array($items)) $items = [];

    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }
    if ($levelKey === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'level_key is required']);
        exit;
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $del = $pdo->prepare('CALL SP_Vati_Payfiller_DeleteClientTypeTatCost(?, ?, ?)');
    $del->execute([$clientId, $levelKey, $jobRoleId]);
    while ($del->nextRowset()) {}

    $up = $pdo->prepare('CALL SP_Vati_Payfiller_UpsertClientTypeTatCost(?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $saved = 0;

    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $vtId = isset($it['verification_type_id']) ? (int)$it['verification_type_id'] : 0;
        if ($vtId <= 0) continue;

        $internalTatValue = $it['internal_tat_value'] ?? ($it['tat_value'] ?? null);
        if ($internalTatValue === '') $internalTatValue = null;
        if ($internalTatValue !== null) {
            $internalTatValue = (float)$internalTatValue;
        }

        $internalTatUnit = normalize_unit((string)($it['internal_tat_unit'] ?? ($it['tat_unit'] ?? 'days')));

        $externalTatValue = $it['external_tat_value'] ?? null;
        if ($externalTatValue === '') $externalTatValue = null;
        if ($externalTatValue !== null) {
            $externalTatValue = (float)$externalTatValue;
        }

        $externalTatUnit = normalize_unit((string)($it['external_tat_unit'] ?? 'days'));

        $cost = $it['cost_inr'] ?? null;
        if ($cost === '') $cost = null;
        if ($cost !== null) {
            $cost = (float)$cost;
        }

        if ($internalTatValue === null && $externalTatValue === null && $cost === null) {
            continue;
        }

        $up->execute([$clientId, $levelKey, $jobRoleId, $vtId, $internalTatValue, $internalTatUnit, $externalTatValue, $externalTatUnit, $cost]);
        while ($up->nextRowset()) {}
        $saved++;
    }

    $pdo->commit();

    echo json_encode(['status' => 1, 'message' => 'Saved', 'data' => ['saved' => $saved]]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
