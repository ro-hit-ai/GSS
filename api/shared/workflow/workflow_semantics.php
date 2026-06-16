<?php

require_once __DIR__ . '/workflow_status_semantics.php';

function wf_sem_norm(string $v): string
{
    return strtolower(trim($v));
}

function wf_verifier_group_map(): array
{
    return [
        'BASIC' => ['basic', 'id', 'contact'],
        'EDUCATION' => ['education', 'employment', 'reference'],
        'ADDITIONAL' => ['ecourt', 'socialmedia'],
        'REPORTS' => ['reports'],
    ];
}

function wf_verifier_group_keys(): array
{
    $keys = array_keys(wf_verifier_group_map());
    $out = [];
    foreach ($keys as $key) {
        $groupKey = strtoupper(trim((string)$key));
        if ($groupKey !== '') $out[] = $groupKey;
    }
    return array_values(array_unique($out));
}

function wf_is_valid_verifier_group(string $groupKey): bool
{
    $groupKey = strtoupper(trim($groupKey));
    if ($groupKey === '') return false;
    return in_array($groupKey, wf_verifier_group_keys(), true);
}

function wf_verifier_group_components(string $groupKey): array
{
    $k = strtoupper(trim($groupKey));
    $m = wf_verifier_group_map();
    return $m[$k] ?? [];
}

function wf_verifier_groups_for_component(string $componentKey): array
{
    $component = wf_sem_norm($componentKey);
    if ($component === '') return [];

    $out = [];
    foreach (wf_verifier_group_map() as $groupKey => $components) {
        foreach ($components as $candidate) {
            if (wf_sem_norm((string)$candidate) === $component) {
                $out[] = strtoupper(trim((string)$groupKey));
                break;
            }
        }
    }
    return array_values(array_unique($out));
}

function wf_role_label_from_status(string $status, string $role): string
{
    $s = wf_sem_norm($status);
    $r = wf_sem_norm($role);
    $map = [
        'validator' => [
            'pending' => 'VA Pending',
            'in_progress' => 'Under Evaluation',
            'correction_submitted' => 'Correction Submitted',
            'approved' => 'VA Approved',
            'rejected' => 'VA Rejected',
            'hold' => 'VA Hold',
            'insufficient_documents' => 'Waiting Candidate',
            'waiting_candidate' => 'Waiting Candidate',
            'blocked' => 'Blocked',
            'reopened' => 'Decision Update',
            'invalidated_by_validator_reopen' => 'Invalidated',
            'invalidated_by_verifier_reopen' => 'Invalidated',
            'done' => 'Completed',
            'completed' => 'Completed',
            'verified' => 'Completed',
            'clear' => 'Completed',
        ],
        'verifier' => [
            'pending' => 'VE Pending',
            'in_progress' => 'Under Review',
            'correction_submitted' => 'Correction Submitted',
            'approved' => 'VE Approved',
            'rejected' => 'VE Rejected',
            'hold' => 'VE Hold',
            'insufficient_documents' => 'Waiting Candidate',
            'waiting_candidate' => 'Waiting Candidate',
            'blocked' => 'Blocked',
            'reopened' => 'Decision Update',
            'invalidated_by_validator_reopen' => 'Invalidated',
            'invalidated_by_verifier_reopen' => 'Invalidated',
            'done' => 'Completed',
            'completed' => 'Completed',
            'verified' => 'Completed',
            'clear' => 'Completed',
        ],
        'qa' => [
            'pending' => 'QA Pending',
            'in_progress' => 'Under QA Review',
            'correction_submitted' => 'Correction Submitted',
            'approved' => 'QA Approved',
            'rejected' => 'QA Rejected',
            'hold' => 'QA Hold',
            'insufficient_documents' => 'Waiting Candidate',
            'waiting_candidate' => 'Waiting Candidate',
            'blocked' => 'Blocked',
            'reopened' => 'Decision Update',
            'invalidated_by_validator_reopen' => 'Invalidated',
            'invalidated_by_verifier_reopen' => 'Invalidated',
            'done' => 'Completed',
            'completed' => 'Completed',
            'verified' => 'Completed',
            'clear' => 'Completed',
        ],
    ];
    $roleMap = $map[$r] ?? $map['validator'];
    return $roleMap[$s] ?? ($status !== '' ? $status : '-');
}
