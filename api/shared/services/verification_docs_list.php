<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../config/env.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/integration.php';

auth_require_login(null);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function get_str(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
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

    $applicationId = integration_normalize_application_id(get_str('application_id'));
    $docType = get_str('doc_type');

    if ($applicationId === '') {
        http_response_code(400);
        echo json_encode(['status' => 0, 'message' => 'application_id is required']);
        exit;
    }

    $pdo = getDB();

    enforce_client_admin_application_scope($pdo, $applicationId);

 $sql = "SELECT * FROM (
        SELECT d.id, d.application_id, d.doc_type, d.file_path, d.original_name, d.mime_type, d.uploaded_by_user_id, d.uploaded_by_role, d.created_at, 
               TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) AS uploaded_by_name, 
               u.username AS uploaded_by_username 
        FROM Vati_Payfiller_Verification_Documents d 
        LEFT JOIN Vati_Payfiller_Users u ON u.user_id = d.uploaded_by_user_id 
        WHERE d.application_id = ?
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'id' AS doc_type, upload_document AS file_path, COALESCE(name, 'ID Document') AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Identification_details 
        WHERE application_id = ? AND NULLIF(upload_document, '') IS NOT NULL
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'contact' AS doc_type, COALESCE(NULLIF(current_proof_file, ''), proof_file) AS file_path, COALESCE(current_proof_original_name, 'Address Proof') AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Contact_details 
        WHERE application_id = ? AND (NULLIF(current_proof_file, '') IS NOT NULL OR NULLIF(proof_file, '') IS NOT NULL)
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'contact' AS doc_type, permanent_proof_file AS file_path, COALESCE(permanent_proof_original_name, 'Permanent Address Proof') AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Contact_details 
        WHERE application_id = ? AND NULLIF(permanent_proof_file, '') IS NOT NULL
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'education' AS doc_type, marksheet_file AS file_path, 'Marksheet' AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Education_details 
        WHERE application_id = ? AND NULLIF(marksheet_file, '') IS NOT NULL AND marksheet_file != 'INSUFFICIENT_DOCUMENTS'
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'education' AS doc_type, degree_file AS file_path, 'Degree' AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Education_details 
        WHERE application_id = ? AND NULLIF(degree_file, '') IS NOT NULL AND degree_file != 'INSUFFICIENT_DOCUMENTS'
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'education' AS doc_type, file_name AS file_path, COALESCE(original_name, 'Education Document') AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Education_Documents 
        WHERE application_id = ? AND NULLIF(file_name, '') IS NOT NULL
        
        UNION ALL 
        SELECT 0 AS id, application_id, 'employment' AS doc_type, employment_doc AS file_path, 'Employment Proof' AS original_name, '' AS mime_type, 0 AS uploaded_by_user_id, 'candidate' AS uploaded_by_role, created_at, 'Candidate' AS uploaded_by_name, '' AS uploaded_by_username 
        FROM Vati_Payfiller_Candidate_Employment_details 
        WHERE application_id = ? AND NULLIF(employment_doc, '') IS NOT NULL AND employment_doc != 'INSUFFICIENT_DOCUMENTS'
    ) AS all_docs WHERE application_id = ?";
    
    $params = array_fill(0, 9, $applicationId);
 

    if ($docType !== '') {
        $sql .= ' AND doc_type = ?';
        $params[] = $docType;
    }

    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $rows]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
