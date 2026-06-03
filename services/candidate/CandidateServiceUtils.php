<?php

final class CandidateServiceUtils
{
    public static function call(PDO $pdo, string $procedure, array $params = []): array
    {
        $placeholders = implode(',', array_fill(0, count($params), '?'));
        $stmt = $pdo->prepare("CALL {$procedure}({$placeholders})");
        $stmt->execute(array_values($params));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        while ($stmt->nextRowset()) {
        }
        $stmt->closeCursor();
        return $rows;
    }

    public static function callNoResult(PDO $pdo, string $procedure, array $params = []): void
    {
        self::call($pdo, $procedure, $params);
    }

    public static function isMissingProcedure(Throwable $e): bool
    {
        return stripos($e->getMessage(), 'PROCEDURE') !== false
            && stripos($e->getMessage(), 'does not exist') !== false;
    }
}
