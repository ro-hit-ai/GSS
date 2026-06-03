<?php

final class EmploymentService
{
    public static function fetchExtras(PDO $pdo, string $applicationId): array
    {
        $stmt = $pdo->prepare("
            SELECT employment_index, employment_status, tentative_relieving_date,
                   tentative_relieving_note, gap_reason, gap_explanation, overlap_explanation
            FROM Vati_Payfiller_Candidate_Employment_details
            WHERE application_id = ?
        ");
        $stmt->execute([$applicationId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $rows[(int)($row['employment_index'] ?? 0)] = $row;
        }
        return $rows;
    }

    public static function saveExtra(PDO $pdo, string $applicationId, int $employmentIndex, array $data): void
    {
        $stmt = $pdo->prepare("
            UPDATE Vati_Payfiller_Candidate_Employment_details
            SET employment_status = ?,
                tentative_relieving_date = ?,
                tentative_relieving_note = ?,
                gap_reason = ?,
                gap_explanation = ?,
                overlap_explanation = ?
            WHERE application_id = ?
              AND employment_index = ?
        ");
        $stmt->execute([
            $data['employment_status'] ?? null,
            $data['tentative_relieving_date'] ?? null,
            $data['tentative_relieving_note'] ?? null,
            $data['gap_reason'] ?? null,
            $data['gap_explanation'] ?? null,
            $data['overlap_explanation'] ?? null,
            $applicationId,
            $employmentIndex,
        ]);
    }

    public static function cleanupRows(PDO $pdo, string $applicationId, array $processedIndexes): void
    {
        if (empty($processedIndexes)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($processedIndexes), '?'));
        $stmt = $pdo->prepare("
            DELETE FROM Vati_Payfiller_Candidate_Employment_details
            WHERE application_id = ?
              AND employment_index NOT IN ($placeholders)
        ");
        $stmt->execute(array_merge([$applicationId], array_values($processedIndexes)));
    }
}
