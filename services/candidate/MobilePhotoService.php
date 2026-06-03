<?php

final class MobilePhotoService
{
    public static function createSession(PDO $pdo, string $applicationId, string $token): string
    {
        $pdo->prepare("
            UPDATE Vati_Payfiller_Candidate_Mobile_Photo_Sessions
            SET status = 'expired', updated_at = NOW()
            WHERE application_id = ?
              AND status = 'pending'
              AND expires_at < NOW()
        ")->execute([$applicationId]);

        $pdo->prepare("
            INSERT INTO Vati_Payfiller_Candidate_Mobile_Photo_Sessions
            (application_id, token, status, expires_at, created_at, updated_at)
            VALUES (?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 10 MINUTE), NOW(), NOW())
        ")->execute([$applicationId, $token]);

        $stmt = $pdo->prepare('SELECT expires_at FROM Vati_Payfiller_Candidate_Mobile_Photo_Sessions WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    public static function fetchByToken(PDO $pdo, string $token): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM Vati_Payfiller_Candidate_Mobile_Photo_Sessions WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function markExpired(PDO $pdo, int $sessionId): void
    {
        $pdo->prepare("UPDATE Vati_Payfiller_Candidate_Mobile_Photo_Sessions SET status = 'expired', updated_at = NOW() WHERE session_id = ?")
            ->execute([$sessionId]);
    }

    public static function complete(PDO $pdo, string $token, string $photoPath): int
    {
        $stmt = $pdo->prepare("
            UPDATE Vati_Payfiller_Candidate_Mobile_Photo_Sessions
            SET status = 'uploaded',
                photo_path = ?,
                uploaded_at = NOW(),
                updated_at = NOW()
            WHERE token = ?
              AND status = 'pending'
              AND expires_at >= NOW()
              AND photo_path IS NULL
        ");
        $stmt->execute([$photoPath, $token]);
        return $stmt->rowCount();
    }

    public static function attachToBasic(PDO $pdo, string $applicationId, string $photoPath): void
    {
        $pdo->prepare("
            INSERT INTO Vati_Payfiller_Candidate_Basic_details
            (application_id, photo_path, created_at, updated_at)
            VALUES (?, ?, NOW(), NOW())
            ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path), updated_at = NOW()
        ")->execute([$applicationId, $photoPath]);
    }
}
