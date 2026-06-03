<?php

require_once __DIR__ . '/CandidateServiceUtils.php';

final class BasicDetailsService
{
    public static function fetch(PDO $pdo, string $applicationId): array
    {
        $rows = CandidateServiceUtils::call($pdo, 'SP_Vati_Payfiller_get_basic_details', [$applicationId]);
        return $rows[0] ?? [];
    }
}
