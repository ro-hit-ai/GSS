<?php
// Canonical fallback entrypoint for reply projection.
// The old cron path is kept stable, but the missing nested parser is no longer required.
// This projects already-ingested legacy reply rows into Vati_Payfiller_Workflow_Communications.

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');

require_once __DIR__ . '/api/shared/workflow_communication_service.php';

function fr_arg(string $name): string
{
    if (PHP_SAPI !== 'cli') {
        return trim((string)($_GET[$name] ?? ''));
    }

    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return trim(substr($arg, strlen($name) + 3));
        }
    }

    return '';
}

function fr_reply_application_ids(PDO $pdo, int $limit = 500): array
{
    $table = wc_resolve_replies_table($pdo);
    if ($table === '') {
        return [];
    }

    $cols = wc_resolve_reply_columns($pdo, $table);
    if (empty($cols['ok'])) {
        return [];
    }

    $tableSql = str_replace('`', '``', $table);
    $sql = "SELECT DISTINCT UPPER(TRIM(application_id)) AS application_id
              FROM `$tableSql`
             WHERE COALESCE(TRIM(application_id), '') <> ''
             ORDER BY application_id DESC
             LIMIT " . max(1, (int)$limit);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $row) {
        $id = strtoupper(trim((string)($row['application_id'] ?? '')));
        if ($id !== '') {
            $ids[$id] = true;
        }
    }

    return array_keys($ids);
}

try {
    $pdo = getDB();
    wc_ensure_tables($pdo);

    $applicationId = strtoupper(trim(fr_arg('application_id') ?: fr_arg('app') ?: fr_arg('sourceCaseId')));
    $ids = $applicationId !== '' ? [$applicationId] : fr_reply_application_ids($pdo);

    $total = 0;
    $results = [];
    foreach ($ids as $id) {
        $inserted = wc_ingest_incoming_replies($pdo, $id);
        $verificationProjected = wc_sync_verification_communications($pdo, $id);
        $total += $inserted + $verificationProjected;
        $results[] = [
            'application_id' => $id,
            'incoming_inserted' => $inserted,
            'verification_projected' => $verificationProjected,
        ];
    }

    $payload = [
        'status' => 1,
        'message' => 'Reply projection completed',
        'processed_applications' => count($ids),
        'inserted_or_projected' => $total,
        'data' => $results,
        'note' => 'This fallback projects existing reply rows; live mailbox fetching remains owned by Node IMAP or another ingest process.',
    ];

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} catch (Throwable $e) {
    $payload = [
        'status' => 0,
        'message' => $e->getMessage(),
    ];

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        fwrite(STDERR, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}
