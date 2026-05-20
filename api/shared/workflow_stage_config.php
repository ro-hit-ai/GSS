<?php

function wf_stage_config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    // Stage-driven default map (current behavior preserved).
    $cfg = [
        [
            'stage_key' => 'validator',
            'stage_order' => 1,
            'stage_type' => 'evaluation',
            'assigned_role' => 'validator',
            'role_aliases' => ['validator'],
            'ui_label' => 'Validator',
            'case_status_when_complete' => 'PENDING_VERIFIER',
        ],
        [
            'stage_key' => 'verifier',
            'stage_order' => 2,
            'stage_type' => 'review',
            'assigned_role' => 'verifier',
            'role_aliases' => ['verifier', 'db_verifier'],
            'ui_label' => 'Verifier',
            'case_status_when_complete' => 'PENDING_QA',
        ],
        [
            'stage_key' => 'qa',
            'stage_order' => 3,
            'stage_type' => 'qa',
            'assigned_role' => 'qa',
            'role_aliases' => ['qa', 'team_lead'],
            'ui_label' => 'QA',
            'case_status_when_complete' => 'APPROVED',
        ],
    ];

    usort($cfg, function (array $a, array $b): int {
        return (int)($a['stage_order'] ?? 0) <=> (int)($b['stage_order'] ?? 0);
    });
    return $cfg;
}

function wf_stage_keys(): array
{
    $out = [];
    foreach (wf_stage_config() as $s) {
        $k = strtolower(trim((string)($s['stage_key'] ?? '')));
        if ($k !== '') $out[] = $k;
    }
    return array_values(array_unique($out));
}

function wf_previous_stage(string $stageKey): string
{
    $stageKey = strtolower(trim($stageKey));
    $keys = wf_stage_keys();
    $idx = array_search($stageKey, $keys, true);
    if ($idx === false || $idx <= 0) return '';
    return (string)$keys[$idx - 1];
}

function wf_next_stage(string $stageKey): string
{
    $stageKey = strtolower(trim($stageKey));
    $keys = wf_stage_keys();
    $idx = array_search($stageKey, $keys, true);
    if ($idx === false || $idx >= (count($keys) - 1)) return '';
    return (string)$keys[$idx + 1];
}

function wf_final_stage(): string
{
    $keys = wf_stage_keys();
    return $keys ? (string)$keys[count($keys) - 1] : '';
}

function wf_role_to_stage_key(string $role): string
{
    $r = strtolower(trim($role));
    foreach (wf_stage_config() as $s) {
        $aliases = isset($s['role_aliases']) && is_array($s['role_aliases']) ? $s['role_aliases'] : [];
        foreach ($aliases as $a) {
            if ($r === strtolower(trim((string)$a))) {
                return strtolower(trim((string)($s['stage_key'] ?? '')));
            }
        }
    }
    return $r;
}

function wf_stage_ui_label(string $stageKey): string
{
    $k = strtolower(trim($stageKey));
    foreach (wf_stage_config() as $s) {
        if (strtolower(trim((string)($s['stage_key'] ?? ''))) === $k) {
            $lbl = trim((string)($s['ui_label'] ?? ''));
            return $lbl !== '' ? $lbl : ucfirst($k);
        }
    }
    return ucfirst($k);
}

function wf_stage_assigned_roles(): array
{
    $out = [];
    foreach (wf_stage_config() as $s) {
        $aliases = isset($s['role_aliases']) && is_array($s['role_aliases']) ? $s['role_aliases'] : [];
        foreach ($aliases as $a) {
            $k = strtolower(trim((string)$a));
            if ($k !== '') $out[$k] = true;
        }
    }
    return array_values(array_keys($out));
}

