<?php
session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../api/shared/candidate_correction_service.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    echo 'Invalid correction link.';
    exit;
}

try {
    $pdo = getDB();
    ccs_ensure_table($pdo);
    $st = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Correction_Sessions WHERE token = ? LIMIT 1');
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        throw new Exception('Invalid correction link.');
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    if ($status !== 'active') {
        throw new Exception('This correction session is no longer active.');
    }
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
        $u = $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Correction_Sessions SET status = 'expired', updated_at = NOW() WHERE correction_session_id = ?");
        $u->execute([(int)$row['correction_session_id']]);
        throw new Exception('Correction session expired.');
    }

    $caseId = (int)($row['case_id'] ?? 0);
    $applicationId = (string)($row['application_id'] ?? '');
    if ($caseId <= 0 || $applicationId === '') {
        throw new Exception('Invalid correction session context.');
    }
    $allowed = json_decode((string)($row['allowed_components_json'] ?? '[]'), true);
    if (!is_array($allowed) || !$allowed) {
        throw new Exception('No correction components configured.');
    }

    $map = [
        'basic' => 'basic-details',
        'id' => 'identification',
        'contact' => 'contact',
        'socialmedia' => 'social',
        'ecourt' => 'ecourt',
        'education' => 'education',
        'employment' => 'employment',
        'reference' => 'reference',
        'education_reference' => 'reference',
        'employment_reference' => 'reference',
    ];
    $allowedPages = [];
    foreach ($allowed as $c) {
        $k = ccs_component_norm((string)$c);
        if (isset($map[$k])) $allowedPages[] = $map[$k];
    }
    $allowedPages = array_values(array_unique($allowedPages));
    $allowedPages[] = 'success';

    $_SESSION['case_id'] = $caseId;
    $_SESSION['application_id'] = $applicationId;
    $_SESSION['logged_in'] = true;
    $_SESSION['candidate_correction_mode'] = 1;
    $_SESSION['candidate_correction_session_id'] = (int)$row['correction_session_id'];
    $_SESSION['candidate_correction_token'] = $token;
    $_SESSION['candidate_correction_allowed_components'] = json_encode(array_values($allowed), JSON_UNESCAPED_UNICODE);
    $_SESSION['candidate_correction_allowed_pages'] = json_encode($allowedPages, JSON_UNESCAPED_UNICODE);
    try {
        $_SESSION['candidate_login_marker'] = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        $_SESSION['candidate_login_marker'] = (string)time();
    }

    $go = $allowedPages[0] ?? 'success';
    header('Location: ' . app_url('/modules/candidate/index.php?page=' . urlencode($go)));
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    echo htmlspecialchars($e->getMessage());
    exit;
}
