<?php
/**
 * Compare workflow contract fields between:
 *  - /api/shared/reports/candidate_report_get.php
 *  - /api/shared/case_workflow_snapshot.php
 *
 * Usage:
 *   php scripts/check_workflow_contract.php APP-20260505075634567 APP-...
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

$appIds = array_slice($argv, 1);
if (count($appIds) === 0) {
    fwrite(STDERR, "Usage: php scripts/check_workflow_contract.php <APP_ID> [APP_ID...]\n");
    exit(1);
}

$base = getenv('GSS_BASE_URL');
if (!$base) {
    $base = 'http://localhost/GSS';
}
$base = rtrim($base, '/');

$apiKey = getenv('PHP_API_KEY');
if (!$apiKey) {
    $apiKey = getenv('SHARED_API_KEY');
}

function fetch_json(string $url, ?string $apiKey): array
{
    $headers = [
        'Accept: application/json',
        'X-Requested-With: XMLHttpRequest',
    ];
    if ($apiKey && trim($apiKey) !== '') {
        $headers[] = 'X-API-Key: ' . $apiKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $err) {
        return ['ok' => false, 'http' => $httpCode, 'error' => $err ?: 'curl failed', 'json' => null];
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'http' => $httpCode, 'error' => 'invalid json', 'json' => null];
    }
    return ['ok' => ($httpCode >= 200 && $httpCode < 300), 'http' => $httpCode, 'error' => null, 'json' => $json];
}

function norm_keys(array $arr): array
{
    $out = [];
    foreach ($arr as $v) {
        $k = strtolower(trim((string)$v));
        if ($k !== '') $out[$k] = true;
    }
    $keys = array_keys($out);
    sort($keys);
    return $keys;
}

function component_keys_from_assigned($arr): array
{
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $row) {
        if (!is_array($row)) continue;
        $k = strtolower(trim((string)($row['component_key'] ?? '')));
        if ($k !== '') $out[$k] = true;
    }
    $keys = array_keys($out);
    sort($keys);
    return $keys;
}

function component_keys_from_workflow($obj): array
{
    if (!is_array($obj)) return [];
    $out = [];
    foreach ($obj as $k => $_v) {
        $nk = strtolower(trim((string)$k));
        if ($nk !== '') $out[$nk] = true;
    }
    $keys = array_keys($out);
    sort($keys);
    return $keys;
}

$hasMismatch = false;

foreach ($appIds as $appId) {
    $appId = trim($appId);
    if ($appId === '') continue;

    $urlReport = $base . '/api/shared/reports/candidate_report_get.php?application_id=' . rawurlencode($appId);
    $urlSnap = $base . '/api/shared/case_workflow_snapshot.php?application_id=' . rawurlencode($appId);

    $r1 = fetch_json($urlReport, $apiKey ?: null);
    $r2 = fetch_json($urlSnap, $apiKey ?: null);

    echo "=== {$appId} ===\n";

    if (!$r1['ok']) {
        $hasMismatch = true;
        echo "candidate_report_get failed: HTTP {$r1['http']} ({$r1['error']})\n\n";
        continue;
    }
    if (!$r2['ok']) {
        $hasMismatch = true;
        echo "case_workflow_snapshot failed: HTTP {$r2['http']} ({$r2['error']})\n\n";
        continue;
    }

    $d1 = (array)($r1['json']['data'] ?? []);
    $d2 = (array)($r2['json']['data'] ?? []);

    $v1 = norm_keys((array)($d1['visible_sections'] ?? $d1['visibleSections'] ?? []));
    $v2 = norm_keys((array)($d2['visible_sections'] ?? $d2['visibleSections'] ?? []));

    $a1 = component_keys_from_assigned((array)($d1['assigned_components'] ?? $d1['assignedComponents'] ?? []));
    $a2 = component_keys_from_assigned((array)($d2['assigned_components'] ?? $d2['assignedComponents'] ?? []));

    $w1 = component_keys_from_workflow((array)($d1['component_workflow'] ?? $d1['componentWorkflow'] ?? []));
    $w2 = component_keys_from_workflow((array)($d2['component_workflow'] ?? $d2['componentWorkflow'] ?? []));

    $ok = true;
    if ($v1 !== $v2) {
        $ok = false;
        echo "Mismatch visible_sections\n";
        echo "  report : " . implode(',', $v1) . "\n";
        echo "  snap   : " . implode(',', $v2) . "\n";
    }
    if ($a1 !== $a2) {
        $ok = false;
        echo "Mismatch assigned_components keys\n";
        echo "  report : " . implode(',', $a1) . "\n";
        echo "  snap   : " . implode(',', $a2) . "\n";
    }
    if ($w1 !== $w2) {
        $ok = false;
        echo "Mismatch component_workflow keys\n";
        echo "  report : " . implode(',', $w1) . "\n";
        echo "  snap   : " . implode(',', $w2) . "\n";
    }

    if ($ok) {
        echo "OK: contracts match\n";
    } else {
        $hasMismatch = true;
    }
    echo "\n";
}

exit($hasMismatch ? 2 : 0);

