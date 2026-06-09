<?php
header("Content-Type: application/json");
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/candidate_correction_service.php';

function validator_activation_debug_log(string $message): void
{
    $root = realpath(__DIR__ . '/../..');
    $logFile = $root ? ($root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'validator_activation_debug.log') : '';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\r\n";
    if ($logFile !== '') {
        @file_put_contents($logFile, $line, FILE_APPEND);
        return;
    }
    @error_log($line);
}

try {
    $application_id =
        $_SESSION['application_id']
        ?? $_POST['application_id']
        ?? null;

    if (!$application_id) {
        throw new Exception("Application session expired.");
    }

    ccs_guard_correction_component_or_json('authorization');

    if (empty($_POST['agree_check'])) {
        throw new Exception("You must agree to the authorization terms.");
    }

    $signature = trim($_POST['digital_signature'] ?? '');
    if (strlen($signature) < 3) {
        throw new Exception("Digital signature must be at least 3 characters.");
    }

    $pdo = getDB();
    if (!$pdo) {
        throw new Exception("Database connection failed.");
    }

  
    $check = $pdo->prepare("
        SELECT id
        FROM Vati_Payfiller_Candidate_Authorization_documents
        WHERE application_id = ?
    ");
    $check->execute([$application_id]);

    if ($check->fetch()) {
        $stmt = $pdo->prepare("
            UPDATE Vati_Payfiller_Candidate_Authorization_documents
               SET digital_signature = ?,
                   uploaded_at = NOW()
             WHERE application_id = ?
        ");
        $stmt->execute([$signature, $application_id]);
    } else {
        $fileName = "authorization_" . $application_id . "_" . date('Ymd_His');

        $stmt = $pdo->prepare("
            INSERT INTO Vati_Payfiller_Candidate_Authorization_documents
                (application_id, file_name, digital_signature, uploaded_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$application_id, $fileName, $signature]);
    }

    validator_activation_debug_log(
        'source=store_authorization'
        . ' application_id=' . $application_id
        . ' final_submit_completed=0 authorization_completed=1 queue_created=0 queue_seed_reason=none'
    );

    echo json_encode([
        "success" => true,
        "message" => "Authorization saved successfully. Please submit your application."
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error occurred."
    ]);
    exit;
}
