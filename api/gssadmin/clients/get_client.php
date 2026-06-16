<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/db.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    if ($clientId <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'client_id is required']);
        exit;
    }

    $pdo = getDB();

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientById(?)');
    $stmt->execute([$clientId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    while ($stmt->nextRowset()) {
    }

    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Client not found']);
        exit;
    }

    // Prefer normalized multi-location data from locations table.
    // Return as newline-joined string in data.location for the client edit form.
    try {
        $locStmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetClientLocationsByClient(?)');
        $locStmt->execute([$clientId]);
        $locRows = $locStmt->fetchAll(PDO::FETCH_ASSOC);
        while ($locStmt->nextRowset()) {
        }

        $names = [];
        foreach ($locRows as $lr) {
            $n = trim((string)($lr['location_name'] ?? ''));
            if ($n === '') continue;
            $names[] = $n;
        }

        if (!empty($names)) {
            $row['location'] = implode("\n", $names);
        }
    } catch (Throwable $e) {
        // ignore and fall back to the existing single location field
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => $row
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
