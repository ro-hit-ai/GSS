<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

session_start();

function str_contains_ci(string $haystack, string $needle): bool {
    return stripos($haystack, $needle) !== false;
}

function extract_required_count(array $row): int {
    $keys = [
        'required_count',
        'requiredCount',
        'verification_count',
        'no_of_verifications',
        'no_of_checks',
        'count_required'
    ];
    foreach ($keys as $k) {
        if (array_key_exists($k, $row)) {
            $v = (int)$row[$k];
            return $v > 0 ? $v : 1;
        }
    }
    return 1;
}

function map_verification_type_to_pages(string $typeName, string $typeCategory): array {
    $typeName = trim($typeName);
    $typeCategory = trim($typeCategory);

    $hay = strtolower(trim(($typeName !== '' ? $typeName : '') . ' ' . ($typeCategory !== '' ? $typeCategory : '')));
    $pages = [];

    // Identification / ID
    if (
        str_contains_ci($hay, 'identification')
        || str_contains_ci($hay, 'identity')
        || str_contains_ci($hay, 'id verification')
        || str_contains_ci($hay, 'kyc')
        || str_contains_ci($hay, 'aadhaar')
        || str_contains_ci($hay, 'aadhar')
        || str_contains_ci($hay, 'pan')
        || str_contains_ci($hay, 'passport')
        || str_contains_ci($hay, 'driving licence')
        || str_contains_ci($hay, 'driving license')
        || str_contains_ci($hay, 'voter')
        || str_contains_ci($hay, 'nric')
        || str_contains_ci($hay, 'ssn')
        || str_contains_ci($hay, 'sin')
        || str_contains_ci($hay, 'nin')
        || str_contains_ci($hay, 'national id')
    ) {
        $pages[] = 'identification';
    }

    // Education
    if (
        str_contains_ci($hay, 'education')
        || str_contains_ci($hay, 'qualification')
        || str_contains_ci($hay, 'degree')
        || str_contains_ci($hay, 'college')
        || str_contains_ci($hay, 'university')
    ) {
        $pages[] = 'education';
    }

    // Employment
    if (
        str_contains_ci($hay, 'employment')
        || str_contains_ci($hay, 'employer')
        || str_contains_ci($hay, 'experience')
        || str_contains_ci($hay, 'work history')
    ) {
        $pages[] = 'employment';
    }

    // References
    if (
        str_contains_ci($hay, 'reference')
        || str_contains_ci($hay, 'referee')
        || str_contains_ci($hay, 'ref check')
        || str_contains_ci($hay, 'ref-check')
    ) {
        $pages[] = 'reference';
    }

    // Court / Judicial searches
    if (
        str_contains_ci($hay, 'ecourt')
        || str_contains_ci($hay, 'e-court')
        || str_contains_ci($hay, 'court')
        || str_contains_ci($hay, 'judis')
        || str_contains_ci($hay, 'judicial')
        || str_contains_ci($hay, 'manupatra')
        || str_contains_ci($hay, 'litigation')
    ) {
        $pages[] = 'ecourt';
    }

    // Social / global database checks
    if (
        str_contains_ci($hay, 'social')
        || str_contains_ci($hay, 'world check')
        || str_contains_ci($hay, 'worldcheck')
        || str_contains_ci($hay, 'linkedin')
        || str_contains_ci($hay, 'facebook')
        || str_contains_ci($hay, 'instagram')
        || str_contains_ci($hay, 'twitter')
        || str_contains_ci($hay, 'x.com')
    ) {
        $pages[] = 'social';
    }

    return array_values(array_unique($pages));
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status' => 0, 'message' => 'Method not allowed']);
        exit;
    }

    $caseId = isset($_SESSION['case_id']) ? (int)$_SESSION['case_id'] : 0;
    $applicationId = isset($_SESSION['application_id']) ? (string)$_SESSION['application_id'] : '';

    if ($caseId <= 0 && $applicationId === '') {
        http_response_code(401);
        echo json_encode(['status' => 0, 'message' => 'Unauthorized']);
        exit;
    }

    $pdo = getDB();

    $case = null;
    if ($caseId > 0) {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$case && $applicationId !== '') {
        $stmt = $pdo->prepare('SELECT case_id, client_id, job_role, application_id FROM Vati_Payfiller_Cases WHERE application_id = ? LIMIT 1');
        $stmt->execute([$applicationId]);
        $case = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$case) {
        http_response_code(404);
        echo json_encode(['status' => 0, 'message' => 'Case not found']);
        exit;
    }

    $clientId = isset($case['client_id']) ? (int)$case['client_id'] : 0;
    $jobRoleName = trim((string)($case['job_role'] ?? ''));

    $jobRoleId = 0;
    if ($clientId > 0 && $jobRoleName !== '') {
        $jr = $pdo->prepare('SELECT job_role_id FROM Vati_Payfiller_Job_Roles WHERE client_id = ? AND LOWER(TRIM(role_name)) = LOWER(TRIM(?)) LIMIT 1');
        $jr->execute([$clientId, $jobRoleName]);
        $jobRoleId = (int)($jr->fetchColumn() ?: 0);
    }

    $types = [];
    if ($jobRoleId > 0) {
        try {
            $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByJobRole(?)');
            $stmt->execute([$jobRoleId]);
            $types = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            while ($stmt->nextRowset()) {
            }
        } catch (Throwable $e) {
            $types = [];
        }
    }

    $shouldFallbackToAllPages = ($jobRoleId <= 0 || count($types) === 0);

    // Common pages always present
    $enabledPages = [
        'review-confirmation',
        'basic-details',
        'identification',
        'contact',
        'reference',
        'success'
    ];

    $requiredCounts = [];

    // Fallback map for setups where SP does not return required_count.
    $requiredByTypeId = [];
    if ($jobRoleId > 0) {
        try {
            $rcStmt = $pdo->prepare(
                'SELECT verification_type_id, required_count
                 FROM Vati_Payfiller_Job_Role_Verification_Types
                 WHERE job_role_id = ?'
            );
            $rcStmt->execute([$jobRoleId]);
            $rcRows = $rcStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rcRows as $rr) {
                $vtId = isset($rr['verification_type_id']) ? (int)$rr['verification_type_id'] : 0;
                $rc = isset($rr['required_count']) ? (int)$rr['required_count'] : 1;
                if ($vtId > 0) {
                    $requiredByTypeId[$vtId] = $rc > 0 ? $rc : 1;
                }
            }
        } catch (Throwable $e) {
            $requiredByTypeId = [];
        }
    }

    foreach ($types as $t) {
        $name = (string)($t['type_name'] ?? '');
        $cat = (string)($t['type_category'] ?? '');
        $isEnabled = isset($t['is_enabled']) ? (int)$t['is_enabled'] : 1;
        if ($isEnabled !== 1) continue;

        $req = extract_required_count($t);
        $vtId = isset($t['verification_type_id']) ? (int)$t['verification_type_id'] : 0;
        if ($vtId > 0 && isset($requiredByTypeId[$vtId])) {
            $req = (int)$requiredByTypeId[$vtId];
        }
        if ($req <= 0) $req = 1;

        $pages = map_verification_type_to_pages($name, $cat);
        foreach ($pages as $p) {
            $enabledPages[] = $p;
            if (!isset($requiredCounts[$p]) || (int)$requiredCounts[$p] < $req) {
                $requiredCounts[$p] = $req;
            }
        }
    }

    $enabledPages = array_values(array_unique($enabledPages));

    // If we can't resolve job-role verification types, don't hide pages.
    // Router treats enabled_pages=null as "all pages enabled".
    if ($shouldFallbackToAllPages) {
        $enabledPages = null;
        $requiredCounts = [];
    }

    echo json_encode([
        'status' => 1,
        'message' => 'ok',
        'data' => [
            'case_id' => isset($case['case_id']) ? (int)$case['case_id'] : 0,
            'application_id' => (string)($case['application_id'] ?? ''),
            'client_id' => $clientId,
            'job_role_id' => $jobRoleId,
            'job_role' => $jobRoleName,
            'enabled_pages' => $enabledPages,
            'required_counts' => $requiredCounts
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
