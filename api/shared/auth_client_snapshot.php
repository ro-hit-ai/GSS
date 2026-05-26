<?php

function auth_client_snapshot_hydrate(PDO $pdo): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = isset($_SESSION['auth_user_id']) ? (int)$_SESSION['auth_user_id'] : 0;
    $sessionRole = strtolower(trim((string)($_SESSION['auth_moduleAccess'] ?? '')));
    $sessionClientId = isset($_SESSION['auth_client_id']) ? (int)$_SESSION['auth_client_id'] : 0;
    $sessionAllowed = trim((string)($_SESSION['auth_allowed_sections'] ?? ''));

    if ($userId <= 0) {
        return [
            'user_id' => 0,
            'role' => $sessionRole,
            'client_id' => $sessionClientId,
            'allowed_sections' => $sessionAllowed,
            'hydrated' => false,
        ];
    }

    $dbRole = '';
    $dbClientId = 0;
    $dbAllowed = '';

    try {
        $stmt = $pdo->prepare(
            'SELECT LOWER(TRIM(role)) AS role, client_id, COALESCE(allowed_sections, "") AS allowed_sections
               FROM Vati_Payfiller_Users
              WHERE user_id = ?
              LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $dbRole = strtolower(trim((string)($row['role'] ?? '')));
        $dbClientId = isset($row['client_id']) ? (int)$row['client_id'] : 0;
        $dbAllowed = trim((string)($row['allowed_sections'] ?? ''));
    } catch (Throwable $e) {
        return [
            'user_id' => $userId,
            'role' => $sessionRole !== '' ? $sessionRole : $dbRole,
            'client_id' => $sessionClientId,
            'allowed_sections' => $sessionAllowed,
            'hydrated' => false,
        ];
    }

    $effectiveRole = $sessionRole !== '' ? $sessionRole : $dbRole;
    $effectiveClientId = $sessionClientId > 0 ? $sessionClientId : $dbClientId;
    $effectiveAllowed = $sessionAllowed !== '' ? $sessionAllowed : $dbAllowed;

    if ($effectiveRole !== '' && empty($_SESSION['auth_moduleAccess'])) {
        $_SESSION['auth_moduleAccess'] = $effectiveRole;
        $_SESSION['auth_all_moduleAccess'] = $effectiveRole;
    }
    if ($effectiveClientId > 0 && $sessionClientId <= 0) {
        $_SESSION['auth_client_id'] = $effectiveClientId;
    }
    if ($effectiveAllowed !== '' && $sessionAllowed === '') {
        $_SESSION['auth_allowed_sections'] = $effectiveAllowed;
    }

    return [
        'user_id' => $userId,
        'role' => $effectiveRole,
        'client_id' => $effectiveClientId,
        'allowed_sections' => $effectiveAllowed,
        'hydrated' => ($effectiveClientId > 0 || $effectiveAllowed !== ''),
    ];
}

function auth_client_snapshot_resolve_client_id_or_401(PDO $pdo, string $message = 'Unauthorized'): int
{
    $snapshot = auth_client_snapshot_hydrate($pdo);
    $clientId = isset($snapshot['client_id']) ? (int)$snapshot['client_id'] : 0;
    if ($clientId > 0) {
        return $clientId;
    }

    http_response_code(401);
    echo json_encode(['status' => 0, 'message' => $message]);
    exit;
}
