<?php
require_once __DIR__ . '/../../../config/env.php';

class WorkflowJwtConfigurationException extends RuntimeException
{
}

function workflow_jwt_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function workflow_jwt_secret(): string
{
    return trim((string)(env_get('WORKFLOW_JWT_SECRET', '') ?? ''));
}

function workflow_jwt_ttl_seconds(): int
{
    $ttl = (int)(env_get('WORKFLOW_JWT_TTL_SECONDS', '900') ?? '900');
    if ($ttl <= 0) {
        return 900;
    }
    return $ttl;
}

function workflow_jwt_create(array $claims, string $secret): string
{
    if ($secret === '') {
        throw new WorkflowJwtConfigurationException('Workflow JWT secret is not configured');
    }

    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];

    $headerPart = workflow_jwt_base64url_encode((string)json_encode($header, JSON_UNESCAPED_SLASHES));
    $payloadPart = workflow_jwt_base64url_encode((string)json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $headerPart . '.' . $payloadPart;
    $signature = hash_hmac('sha256', $signingInput, $secret, true);

    return $signingInput . '.' . workflow_jwt_base64url_encode($signature);
}

function workflow_jwt_issue_for_session(int $phpUserId, string $phpRole, int $phpClientId, ?int $now = null): array
{
    $phpRole = strtolower(trim($phpRole));
    $now = $now ?? time();
    $ttl = workflow_jwt_ttl_seconds();

    $claims = [
        'typ' => 'workflow',
        'phpUserId' => $phpUserId,
        'phpRole' => $phpRole,
        'phpClientId' => $phpClientId,
        'iss' => 'gss-php',
        'aud' => 'ticketer-workflow',
        'iat' => $now,
        'exp' => $now + $ttl,
    ];

    return [
        'token' => workflow_jwt_create($claims, workflow_jwt_secret()),
        'claims' => $claims,
        'expiresIn' => $ttl,
    ];
}
