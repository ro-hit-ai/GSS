<?php
require_once __DIR__ . '/../../../config/db.php';

function tmpl_registry(): array {
    // Canonical deterministic template keys.
    return [
        'education_verification' => [
            'communication_mode' => 'verification',
            'active_usage_owner' => 'verification_mail',
            'allowed_placeholders' => ['candidate_name', 'client_name', 'application_id', 'component_key', 'recipient_name', 'sender_role', 'remarks', 'organization_name', 'actor_name'],
        ],
        'employment_verification' => [
            'communication_mode' => 'verification',
            'active_usage_owner' => 'verification_mail',
            'allowed_placeholders' => ['candidate_name', 'client_name', 'application_id', 'component_key', 'recipient_name', 'sender_role', 'remarks', 'organization_name', 'actor_name'],
        ],
        'candidate_missing_docs' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'insufficiency_list', 'deadline', 'validator_name', 'verifier_name'],
        ],
        'clarification_required' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'deadline', 'validator_name', 'verifier_name'],
        ],
        'verification_hold' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'deadline', 'validator_name', 'verifier_name'],
        ],
        'verification_rejected' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'validator_name', 'verifier_name'],
        ],
        'candidate_correction' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'deadline', 'validator_name', 'verifier_name'],
        ],
        'verification_completed' => [
            'communication_mode' => 'workflow',
            'active_usage_owner' => 'workflow_comm',
            'allowed_placeholders' => ['candidate_name', 'application_id', 'component_name', 'validator_name', 'verifier_name'],
        ],
    ];
}

function tmpl_normalize_key(string $key): string {
    return strtolower(trim($key));
}

function tmpl_is_allowed_key(string $key): bool {
    $reg = tmpl_registry();
    return isset($reg[tmpl_normalize_key($key)]);
}

function tmpl_registry_item(string $key): ?array {
    $reg = tmpl_registry();
    $k = tmpl_normalize_key($key);
    return isset($reg[$k]) ? $reg[$k] : null;
}

function tmpl_normalize_placeholder_dialect(string $text): string {
    // Backward-compat: allow legacy {{key}} while standardizing to {key}.
    return preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '{$1}', (string)$text) ?? (string)$text;
}

function tmpl_collect_placeholders(string $text): array {
    $out = [];
    if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', (string)$text, $m) && !empty($m[1])) {
        foreach ($m[1] as $k) {
            $key = trim((string)$k);
            if ($key !== '') $out[$key] = true;
        }
    }
    return array_keys($out);
}

function tmpl_render_text(string $template, array $context, array &$meta = []): string {
    $template = tmpl_normalize_placeholder_dialect($template);
    $rendered = $template;
    $used = tmpl_collect_placeholders($template);
    $missing = [];
    foreach ($used as $k) {
        $val = array_key_exists($k, $context) ? (string)$context[$k] : null;
        if ($val === null) {
            $missing[] = $k;
            continue;
        }
        $rendered = str_replace('{' . $k . '}', $val, $rendered);
    }
    $meta = [
        'used' => $used,
        'missing' => $missing,
    ];
    return $rendered;
}

function tmpl_log_warning(string $message, array $context = []): void {
    $payload = [
        'scope' => 'template_governance',
        'message' => $message,
        'context' => $context,
    ];
    error_log('[template_governance] ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function tmpl_validate_placeholders_for_key(string $templateKey, string $subject, string $body): array {
    $item = tmpl_registry_item($templateKey);
    if (!$item) {
        return [
            'valid' => false,
            'invalid_placeholders' => [],
            'used_placeholders' => array_values(array_unique(array_merge(
                tmpl_collect_placeholders($subject),
                tmpl_collect_placeholders($body)
            ))),
            'allowed_placeholders' => [],
        ];
    }
    $allowed = array_values(array_unique(array_map('strval', (array)($item['allowed_placeholders'] ?? []))));
    $allowedMap = [];
    foreach ($allowed as $k) $allowedMap[$k] = true;
    $used = array_values(array_unique(array_merge(
        tmpl_collect_placeholders(tmpl_normalize_placeholder_dialect($subject)),
        tmpl_collect_placeholders(tmpl_normalize_placeholder_dialect($body))
    )));
    $invalid = [];
    foreach ($used as $k) {
        if (!isset($allowedMap[$k])) $invalid[] = $k;
    }
    return [
        'valid' => true,
        'invalid_placeholders' => $invalid,
        'used_placeholders' => $used,
        'allowed_placeholders' => $allowed,
    ];
}

function tmpl_health_check(PDO $pdo): array {
    $registry = tmpl_registry();
    $st = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplates_List(?, ?)');
    $st->execute(['email', null]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($st->nextRowset()) {}

    $activeByKey = [];
    foreach ($rows as $r) {
        $k = tmpl_normalize_key((string)($r['template_name'] ?? ''));
        if ($k === '' || !isset($registry[$k])) continue;
        if ((int)($r['is_active'] ?? 0) !== 1) continue;
        if (!isset($activeByKey[$k])) $activeByKey[$k] = [];
        $activeByKey[$k][] = $r;
    }

    $missing = [];
    $inactive = [];
    $duplicates = [];
    foreach (array_keys($registry) as $k) {
        $allRows = array_values(array_filter($rows, static function ($r) use ($k) {
            return tmpl_normalize_key((string)($r['template_name'] ?? '')) === $k;
        }));
        if (!$allRows) {
            $missing[] = $k;
            continue;
        }
        $hasActive = false;
        foreach ($allRows as $r) {
            if ((int)($r['is_active'] ?? 0) === 1) {
                $hasActive = true;
                break;
            }
        }
        if (!$hasActive) $inactive[] = $k;
        if (isset($activeByKey[$k]) && count($activeByKey[$k]) > 1) {
            $duplicates[$k] = array_map(static function ($r) {
                return (int)($r['template_id'] ?? 0);
            }, $activeByKey[$k]);
        }
    }
    return [
        'missing_keys' => $missing,
        'inactive_keys' => $inactive,
        'duplicate_active_keys' => $duplicates,
        'registry_count' => count($registry),
    ];
}

function tmpl_fetch_active_template_by_key(PDO $pdo, string $templateKey, string $templateType = 'email'): ?array {
    $key = tmpl_normalize_key($templateKey);
    if ($key === '') return null;

    $stmt = $pdo->prepare('CALL SP_Vati_Payfiller_MailTemplates_List(?, ?)');
    $stmt->execute([$templateType !== '' ? strtolower(trim($templateType)) : null, 1]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while ($stmt->nextRowset()) {}

    foreach ($rows as $r) {
        $name = tmpl_normalize_key((string)($r['template_name'] ?? ''));
        if ($name === $key) {
            return $r;
        }
    }
    return null;
}
