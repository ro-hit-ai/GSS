<?php

function wf_valid_application_statuses(): array
{
    return ['draft', 'submitted', 'verified', 'rejected'];
}

function wf_assert_valid_application_status(string $status, string $source = 'unknown'): string
{
    $normalized = strtolower(trim($status));
    if (!in_array($normalized, wf_valid_application_statuses(), true)) {
        throw new InvalidArgumentException('Invalid Candidate_Applications.status write from ' . $source . ': ' . $status);
    }
    return $normalized;
}

