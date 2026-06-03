<?php

final class IdentificationService
{
    public static function fetchRows(PDO $pdo, string $applicationId, ?int $documentIndex = null): array
    {
        if ($documentIndex === null) {
            $stmt = $pdo->prepare(
                'SELECT proof_group, document_index, upload_document
                   FROM Vati_Payfiller_Candidate_Identification_details
                  WHERE application_id = ?
                  ORDER BY document_index ASC, proof_group ASC'
            );
            $stmt->execute([$applicationId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $stmt = $pdo->prepare(
            'SELECT proof_group, document_index, upload_document
               FROM Vati_Payfiller_Candidate_Identification_details
              WHERE application_id = ? AND document_index = ?
              ORDER BY proof_group ASC'
        );
        $stmt->execute([$applicationId, $documentIndex]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function replaceRows(PDO $pdo, string $applicationId, int $documentIndex, array $rows): array
    {
        $existingRows = self::fetchRows($pdo, $applicationId, $documentIndex);

        $deleteStmt = $pdo->prepare(
            'DELETE FROM Vati_Payfiller_Candidate_Identification_details
              WHERE application_id = ? AND document_index = ?'
        );
        $deleteStmt->execute([$applicationId, $documentIndex]);

        $insertStmt = $pdo->prepare(
            'INSERT INTO Vati_Payfiller_Candidate_Identification_details
                (application_id, document_index, proof_group, documentId_type, id_number, name, country,
                 issue_date, expiry_date, upload_document, is_complete, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        foreach ($rows as $row) {
            $insertStmt->execute(self::rowParams($applicationId, $documentIndex, $row));
        }

        return $existingRows;
    }

    public static function cleanupRows(PDO $pdo, string $applicationId, array $processedIndices): array
    {
        $existingRows = self::fetchRows($pdo, $applicationId);
        $keepIndexes = array_values(array_unique(array_map('intval', $processedIndices)));

        if ($keepIndexes === []) {
            $stmt = $pdo->prepare(
                'DELETE FROM Vati_Payfiller_Candidate_Identification_details WHERE application_id = ?'
            );
            $stmt->execute([$applicationId]);
            return $existingRows;
        }

        $placeholders = implode(',', array_fill(0, count($keepIndexes), '?'));
        $stmt = $pdo->prepare(
            "DELETE FROM Vati_Payfiller_Candidate_Identification_details
              WHERE application_id = ? AND document_index NOT IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$applicationId], $keepIndexes));

        return self::filterRemovedRows($existingRows, $keepIndexes);
    }

    private static function rowParams(string $applicationId, int $documentIndex, array $row): array
    {
        $documentType = trim((string)($row['document_type'] ?? ''));
        $idNumber = trim((string)($row['id_number'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $country = trim((string)($row['country'] ?? ''));
        $uploadDocument = trim((string)($row['upload_document'] ?? ''));

        return [
            $applicationId,
            $documentIndex,
            trim((string)($row['proof_group'] ?? '')),
            $documentType !== '' ? $documentType : null,
            $idNumber !== '' ? $idNumber : null,
            $name !== '' ? $name : null,
            $country !== '' ? $country : null,
            !empty($row['issue_date']) ? $row['issue_date'] : null,
            !empty($row['expiry_date']) ? $row['expiry_date'] : null,
            $uploadDocument !== '' ? $uploadDocument : null,
            $documentType !== '' && $idNumber !== '' && $name !== '' && $uploadDocument !== '' ? 1 : 0,
        ];
    }

    private static function filterRemovedRows(array $rows, array $keepIndexes): array
    {
        $keepLookup = array_flip(array_map('intval', $keepIndexes));
        return array_values(array_filter($rows, static function (array $row) use ($keepLookup): bool {
            return !isset($keepLookup[(int)($row['document_index'] ?? 0)]);
        }));
    }
}
