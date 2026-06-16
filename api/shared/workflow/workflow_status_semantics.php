<?php

function wf_is_evaluated_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['approved', 'rejected', 'hold', 'insufficient_documents', 'completed', 'verified', 'clear'], true);
}

function wf_is_invalidated_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['invalidated_by_validator_reopen', 'invalidated_by_verifier_reopen'], true);
}

function wf_is_operationally_active_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['correction_submitted', 'waiting_candidate', 'reopened', 'blocked'], true);
}

function wf_is_resolved_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['approved', 'rejected', 'completed', 'verified', 'clear'], true);
}

function wf_is_active_queue_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['pending', 'in_progress', 'correction_submitted', 'waiting_candidate', 'reopened', 'blocked', 'followup'], true);
}

function wf_should_remain_visible_to_role(string $status): bool
{
    $s = strtolower(trim($status));
    if ($s === '') return true;
    return wf_is_active_queue_status($s) || wf_is_evaluated_status($s) || wf_is_invalidated_status($s) || in_array($s, ['done', 'completed'], true);
}

function wf_is_visible_historical_status(string $status): bool
{
    $s = strtolower(trim($status));
    return wf_is_evaluated_status($s) || wf_is_invalidated_status($s) || in_array($s, ['done', 'completed'], true);
}

function wf_is_closed_hidden_status(string $status): bool
{
    $s = strtolower(trim($status));
    return in_array($s, ['archived', 'terminated', 'deleted', 'retention_expired'], true);
}
