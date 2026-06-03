<?php

final class EducationService
{
    public static function fetchDocuments(PDO $pdo, string $applicationId, ?int $educationIndex = null): array
    {
        $sql = "
            SELECT education_index, document_slot, file_name, original_name, created_at
            FROM Vati_Payfiller_Candidate_Education_Documents
            WHERE application_id = ?
        ";
        $params = [$applicationId];
        if ($educationIndex !== null) {
            $sql .= " AND education_index = ?";
            $params[] = $educationIndex;
        }
        $sql .= " ORDER BY education_index ASC, id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function saveMeta(PDO $pdo, string $applicationId, int $educationIndex, array $data): void
    {
        $stmt = $pdo->prepare("
            UPDATE Vati_Payfiller_Candidate_Education_details
            SET ca_membership_number = ?,
                year_of_passing = ?,
                education_gap_reason = ?,
                education_gap_explanation = ?,
                education_order_explanation = ?,
                institution_id = ?,
                institution_display_name = ?,
                manual_institution_name = ?,
                institution_match_status = ?
            WHERE application_id = ? AND education_index = ?
        ");
        $stmt->execute([
            $data['ca_membership_number'] ?? null,
            $data['year_of_passing'] ?? null,
            $data['education_gap_reason'] ?? null,
            $data['education_gap_explanation'] ?? null,
            $data['education_order_explanation'] ?? null,
            $data['institution_id'] ?? null,
            $data['institution_display_name'] ?? null,
            $data['manual_institution_name'] ?? null,
            $data['institution_match_status'] ?? null,
            $applicationId,
            $educationIndex,
        ]);
    }

    public static function replaceDocuments(PDO $pdo, string $applicationId, int $educationIndex, array $documents): array
    {
        $existing = self::fetchDocuments($pdo, $applicationId, $educationIndex);
        $pdo->prepare('DELETE FROM Vati_Payfiller_Candidate_Education_Documents WHERE application_id = ? AND education_index = ?')
            ->execute([$applicationId, $educationIndex]);
        $insert = $pdo->prepare("
            INSERT INTO Vati_Payfiller_Candidate_Education_Documents
            (application_id, education_index, document_slot, file_name, original_name)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($documents as $doc) {
            $fileName = trim((string)($doc['file_name'] ?? ''));
            if ($fileName === '') {
                continue;
            }
            $insert->execute([
                $applicationId,
                $educationIndex,
                (string)($doc['document_slot'] ?? 'supporting'),
                $fileName,
                (string)($doc['original_name'] ?? $fileName),
            ]);
        }
        return $existing;
    }

    public static function cleanupRows(PDO $pdo, string $applicationId, array $processedIndexes): void
    {
        if (empty($processedIndexes)) {
            $pdo->prepare('DELETE FROM Vati_Payfiller_Candidate_Education_details WHERE application_id = ?')
                ->execute([$applicationId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($processedIndexes), '?'));
        $stmt = $pdo->prepare("
            DELETE FROM Vati_Payfiller_Candidate_Education_details
            WHERE application_id = ?
              AND education_index NOT IN ($placeholders)
        ");
        $stmt->execute(array_merge([$applicationId], array_values($processedIndexes)));
    }
}
