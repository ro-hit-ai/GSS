<?php
header('Content-Type: application/json');
session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . "/config/db.php";

$page = $_GET['page'] ?? '';
$application_id = $_SESSION['application_id'] ?? null;

if (!$application_id) {
    echo json_encode(['success' => false, 'message' => 'No application ID']);
    exit;
}

try {
    $pdo = getDB();
    $data = [];

    switch ($page) {
        case 'basic-details':
            $stmt = $pdo->prepare(
                "SELECT * FROM Vati_Payfiller_Candidate_Basic_details WHERE application_id = ?"
            );
            $stmt->execute([$application_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row) {
                $data = $row;
                // Split mobile number if needed
                if (!empty($row['mobile'])) {
                    $parts = explode(' ', $row['mobile'], 2);
                    $data['mobile_country_code'] = $parts[0] ?? '+91';
                    $data['mobile'] = $parts[1] ?? '';
                }
            }
            break;
            
        case 'identification':
            $stmt = $pdo->prepare(
                "SELECT * FROM Vati_Payfiller_Identification_details WHERE application_id = ? ORDER BY document_index"
            );
            $stmt->execute([$application_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;

        case 'contact':
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_contact_details(?)");
            $stmt->execute([$application_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            break;

        case 'education':
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_education_details(?)");
            $stmt->execute([$application_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            break;

        case 'employment':
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_employment_details(?)");
            $stmt->execute([$application_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            break;

        case 'reference':
            $stmt = $pdo->prepare(
                "SELECT * FROM Vati_Payfiller_Candidate_Reference_details WHERE application_id = ? ORDER BY updated_at DESC, created_at DESC LIMIT 1"
            );
            $stmt->execute([$application_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            break;

        case 'social':
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_social_media_details(?)");
            $stmt->execute([$application_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            break;

        case 'ecourt':
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_get_ecourt_details(?)");
            $stmt->execute([$application_id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
            break;

        case 'uploaded-docs':
        case 'evidence-docs':
            $stmt = $pdo->prepare(
                "SELECT doc_type, file_path, original_name, mime_type, uploaded_by_role, created_at
                 FROM Vati_Payfiller_Verification_Documents
                 WHERE application_id = ?
                 ORDER BY created_at ASC, id ASC"
            );
            $stmt->execute([$application_id]);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Unsupported page']);
            exit;
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
