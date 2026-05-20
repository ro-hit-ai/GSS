<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../shared/case_component_binding.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function norm_component_key_local(string $key): string {
    $k = strtolower(trim($key));
    if ($k === 'identification') return 'id';
    if ($k === 'social_media' || $k === 'social-media') return 'socialmedia';
    return $k;
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
    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_GetVerificationTypesByClient(?)');
    $stmt->execute([$clientId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    while ($stmt->nextRowset()) {
    }

    if (!$rows || count($rows) === 0) {
        $stmt2 = $pdo->prepare('CALL SP_Vati_Payfiller_GetAllActiveVerificationTypes()');
        $stmt2->execute();
        $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        while ($stmt2->nextRowset()) {
        }
    }

    $out = [];
    $unknownTypes = [];
    $pageByComponent = [
        'basic' => 'basic-details',
        'id' => 'identification',
        'contact' => 'contact',
        'education' => 'education',
        'employment' => 'employment',
        'reference' => 'reference',
        'ecourt' => 'ecourt',
        'socialmedia' => 'social',
    ];
    foreach ($rows as $r) {
        $typeId = isset($r['verification_type_id']) ? (int)$r['verification_type_id'] : 0;
        $typeNameRaw = (string)($r['type_name'] ?? '');
        $typeCategory = (string)($r['type_category'] ?? '');
        $sortOrder = isset($r['sort_order']) ? (int)$r['sort_order'] : 0;

        $typeNameNorm = trim($typeNameRaw);
        $typeNameLower = strtolower($typeNameNorm);

        // UI requirement: Employer 1/2 not required. Show only a single "Employment" item.
        if ($typeNameLower === 'employer 2' || $typeNameLower === 'employer2') {
            continue;
        }
        if ($typeNameLower === 'employer 1' || $typeNameLower === 'employer1') {
            $typeNameNorm = 'Employment';
        }

        // UI requirement: rename "Highest Qualification" to "Education"
        if ($typeNameLower === 'highest qualification' || $typeNameLower === 'highestqualification') {
            $typeNameNorm = 'Education';
        }

        $resolved = case_component_binding_map_verification_type_to_components($typeNameNorm, $typeCategory);
        $resolved = array_values(array_unique(array_filter(array_map(function ($k) {
            return norm_component_key_local((string)$k);
        }, $resolved), function ($k) {
            return $k !== '';
        })));
        $componentKey = $resolved[0] ?? '';
        $candidatePage = ($componentKey !== '' && isset($pageByComponent[$componentKey])) ? $pageByComponent[$componentKey] : '';
        $candidateSubsection = '';
        $displayLabel = $componentKey !== '' ? $componentKey : $typeNameNorm;
        if ($componentKey === 'contact') {
            $candidateSubsection = case_component_binding_contact_subsection($typeNameNorm, $typeCategory);
            $displayLabel = case_component_binding_contact_display_label($typeNameNorm, $typeCategory);
        }
        if ($componentKey === '') {
            $unknownTypes[] = [
                'verification_type_id' => $typeId,
                'type_name' => $typeNameNorm,
                'type_category' => $typeCategory
            ];
        }

        $out[] = [
            'verification_type_id' => $typeId,
            'type_name' => $typeNameNorm,
            'type_category' => $typeCategory,
            'component_key' => $componentKey,
            'display_label' => $displayLabel,
            'candidate_page' => $candidatePage,
            'candidate_subsection' => $candidateSubsection,
            'sort_order' => $sortOrder,
        ];
    }

    if (!empty($unknownTypes)) {
        error_log('VERIFICATION_TYPES_UNKNOWN_MAP: ' . json_encode($unknownTypes));
    }

    echo json_encode(['status' => 1, 'message' => 'ok', 'data' => $out]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 0, 'message' => 'Database error. Please try again.']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
