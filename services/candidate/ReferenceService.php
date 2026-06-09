<?php

final class ReferenceService
{
    public static function fetch(PDO $pdo, string $applicationId): array
    {
        return self::fetchLegacy($pdo, $applicationId);
    }

    public static function fetchGrouped(PDO $pdo, string $applicationId): array
    {
        $grouped = [
            'education' => [],
            'employment' => [],
            'legacy' => self::fetchLegacy($pdo, $applicationId),
        ];

        try {
            $stmt = $pdo->prepare("CALL SP_Vati_Payfiller_candidate_reference_list(?)");
            $stmt->execute([$applicationId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $stmt->closeCursor();
        } catch (Throwable $e) {
            return self::withLegacyFallback($grouped);
        }

        foreach ($rows as $row) {
            $type = strtolower((string)($row['reference_type'] ?? ''));
            if (!isset($grouped[$type])) {
                continue;
            }

            $grouped[$type][] = [
                'reference_index' => (int)($row['reference_index'] ?? (count($grouped[$type]) + 1)),
                'name' => $row['reference_name'] ?? '',
                'designation' => $row['designation'] ?? '',
                'company' => $row['company'] ?? '',
                'relationship' => $row['relationship'] ?? '',
                'years_known' => (string)($row['years_known'] ?? ''),
                'mobile' => $row['mobile'] ?? '',
                'email' => $row['email'] ?? '',
            ];
        }

        return self::withLegacyFallback($grouped);
    }

    public static function replaceGrouped(PDO $pdo, string $applicationId, array $educationRows, array $employmentRows): array
    {
        return self::replaceGroupedScoped($pdo, $applicationId, $educationRows, $employmentRows, true, true);
    }

    public static function replaceGroupedScoped(PDO $pdo, string $applicationId, array $educationRows, array $employmentRows, bool $replaceEducation, bool $replaceEmployment): array
    {
        try {
            $existing = self::fetchGrouped($pdo, $applicationId);
            $mergedEducationRows = $replaceEducation ? $educationRows : ($existing['education'] ?? []);
            $mergedEmploymentRows = $replaceEmployment ? $employmentRows : ($existing['employment'] ?? []);

            if ($replaceEducation) {
                self::replaceType($pdo, $applicationId, 'education', $educationRows);
            }
            if ($replaceEmployment) {
                self::replaceType($pdo, $applicationId, 'employment', $employmentRows);
            }
        } catch (Throwable $e) {
            $existing = self::fetchGrouped($pdo, $applicationId);
            $mergedEducationRows = $replaceEducation ? $educationRows : ($existing['education'] ?? []);
            $mergedEmploymentRows = $replaceEmployment ? $employmentRows : ($existing['employment'] ?? []);
            self::upsertLegacy($pdo, $applicationId, $mergedEducationRows[0] ?? [], $mergedEmploymentRows[0] ?? []);
            return self::fetchGrouped($pdo, $applicationId);
        }

        self::upsertLegacy($pdo, $applicationId, $mergedEducationRows[0] ?? [], $mergedEmploymentRows[0] ?? []);
        return self::fetchGrouped($pdo, $applicationId);
    }

    private static function replaceType(PDO $pdo, string $applicationId, string $type, array $rows): void
    {
        $deleteStmt = $pdo->prepare("CALL SP_Vati_Payfiller_candidate_reference_delete_type(?, ?)");
        $deleteStmt->execute([$applicationId, $type]);
        $deleteStmt->closeCursor();

        $insertStmt = $pdo->prepare("CALL SP_Vati_Payfiller_candidate_reference_upsert(?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach (array_values($rows) as $index => $row) {
            if (self::isEmptyRow($row)) {
                continue;
            }

            $insertStmt->execute([
                $applicationId,
                $type,
                $index + 1,
                trim((string)($row['name'] ?? '')),
                trim((string)($row['designation'] ?? '')),
                trim((string)($row['company'] ?? '')),
                trim((string)($row['relationship'] ?? '')),
                (int)($row['years_known'] ?? 0),
                trim((string)($row['mobile'] ?? '')),
                trim((string)($row['email'] ?? '')),
            ]);
            $insertStmt->closeCursor();
        }
    }

    private static function fetchLegacy(PDO $pdo, string $applicationId): array
    {
        $stmt = $pdo->prepare("
            SELECT *
            FROM Vati_Payfiller_Candidate_Reference_details
            WHERE application_id = ?
            ORDER BY updated_at DESC, created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$applicationId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private static function upsertLegacy(PDO $pdo, string $applicationId, array $educationData, array $employmentData): array
    {
        $primary = array_filter($employmentData, fn($value) => trim((string)$value) !== '') ? $employmentData : $educationData;
        $payload = [
            'reference_name' => $primary['name'] ?? '',
            'reference_designation' => $primary['designation'] ?? '',
            'reference_company' => $primary['company'] ?? '',
            'reference_mobile' => $primary['mobile'] ?? '',
            'reference_email' => $primary['email'] ?? '',
            'relationship' => $primary['relationship'] ?? '',
            'years_known' => $primary['years_known'] ?? '',
            'education_reference_name' => $educationData['name'] ?? '',
            'education_reference_designation' => $educationData['designation'] ?? '',
            'education_reference_company' => $educationData['company'] ?? '',
            'education_reference_mobile' => $educationData['mobile'] ?? '',
            'education_reference_email' => $educationData['email'] ?? '',
            'education_reference_relationship' => $educationData['relationship'] ?? '',
            'education_reference_years_known' => $educationData['years_known'] ?? '',
            'employment_reference_name' => $employmentData['name'] ?? '',
            'employment_reference_designation' => $employmentData['designation'] ?? '',
            'employment_reference_company' => $employmentData['company'] ?? '',
            'employment_reference_mobile' => $employmentData['mobile'] ?? '',
            'employment_reference_email' => $employmentData['email'] ?? '',
            'employment_reference_relationship' => $employmentData['relationship'] ?? '',
            'employment_reference_years_known' => $employmentData['years_known'] ?? '',
        ];

        $existing = self::fetchLegacy($pdo, $applicationId);
        if ($existing) {
            $set = implode(', ', array_map(fn($field) => "{$field} = ?", array_keys($payload)));
            $stmt = $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Reference_details SET {$set}, updated_at = NOW() WHERE application_id = ?");
            $stmt->execute(array_merge(array_values($payload), [$applicationId]));
        } else {
            $fields = array_merge(['application_id'], array_keys($payload), ['created_at', 'updated_at']);
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $stmt = $pdo->prepare("INSERT INTO Vati_Payfiller_Candidate_Reference_details (" . implode(', ', $fields) . ") VALUES ({$placeholders})");
            $stmt->execute(array_merge([$applicationId], array_values($payload), [date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]));
        }

        return self::fetchLegacy($pdo, $applicationId);
    }

    private static function withLegacyFallback(array $grouped): array
    {
        if (!$grouped['education'] && !empty($grouped['legacy']['education_reference_name'])) {
            $grouped['education'][] = [
                'reference_index' => 1,
                'name' => $grouped['legacy']['education_reference_name'] ?? '',
                'designation' => $grouped['legacy']['education_reference_designation'] ?? '',
                'company' => $grouped['legacy']['education_reference_company'] ?? '',
                'relationship' => $grouped['legacy']['education_reference_relationship'] ?? '',
                'years_known' => (string)($grouped['legacy']['education_reference_years_known'] ?? ''),
                'mobile' => $grouped['legacy']['education_reference_mobile'] ?? '',
                'email' => $grouped['legacy']['education_reference_email'] ?? '',
            ];
        }

        if (!$grouped['employment']) {
            $name = $grouped['legacy']['employment_reference_name'] ?? ($grouped['legacy']['reference_name'] ?? '');
            if ($name !== '') {
                $grouped['employment'][] = [
                    'reference_index' => 1,
                    'name' => $name,
                    'designation' => $grouped['legacy']['employment_reference_designation'] ?? ($grouped['legacy']['reference_designation'] ?? ''),
                    'company' => $grouped['legacy']['employment_reference_company'] ?? ($grouped['legacy']['reference_company'] ?? ''),
                    'relationship' => $grouped['legacy']['employment_reference_relationship'] ?? ($grouped['legacy']['relationship'] ?? ''),
                    'years_known' => (string)($grouped['legacy']['employment_reference_years_known'] ?? ($grouped['legacy']['years_known'] ?? '')),
                    'mobile' => $grouped['legacy']['employment_reference_mobile'] ?? ($grouped['legacy']['reference_mobile'] ?? ''),
                    'email' => $grouped['legacy']['employment_reference_email'] ?? ($grouped['legacy']['reference_email'] ?? ''),
                ];
            }
        }

        return $grouped;
    }

    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }
}
