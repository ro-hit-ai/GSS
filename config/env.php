<?php

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        if ($needle == '') return true;
        return substr($haystack, 0, strlen($needle)) === $needle;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle == '') return true;
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

function env_load(string $envFilePath): array {
    $vars = [];
    if (!is_file($envFilePath)) return $vars;

    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) return $vars;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($key === '') continue;

        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

function env_get(string $key, ?string $default = null): ?string {
    static $cache = null;

    if (!is_array($cache)) {
        $root = realpath(__DIR__ . '/..');
        $envFile = $root ? ($root . DIRECTORY_SEPARATOR . '.env') : null;
        $cache = $envFile ? env_load($envFile) : [];
    }

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    return $default;
}

function app_base_url(): string {
    $v = env_get('APP_BASE_URL');
    if (is_string($v) && $v !== '') {
        $v = rtrim($v, '/');
        if ($v === '') return '';

        if (preg_match('~^https?://~i', $v)) {
            return $v;
        }

        if ($v[0] !== '/') $v = '/' . $v;

        $forwardedProto = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && is_string($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $forwardedProto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0] ?? ''));
        }
        $forwardedHost = '';
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && is_string($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $forwardedHost = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_HOST'])[0] ?? '');
        }

        $rawHost = $forwardedHost !== ''
            ? $forwardedHost
            : trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
        if ($rawHost === '' || $rawHost === ':' || preg_match('~^https?://~i', $rawHost)) {
            $rawHost = 'localhost';
        }

        $isHttps = ($forwardedProto === 'https')
            || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $isHttps ? 'https' : 'http';
        return $scheme . '://' . $rawHost . $v;
    }

    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    $folderName = basename(app_base_path());
    if ($scriptName !== '' && $folderName !== '') {
        $pos = strpos($scriptName, '/' . $folderName . '/');
        if ($pos !== false) {
            return substr($scriptName, 0, $pos + strlen('/' . $folderName));
        }
        if (str_ends_with($scriptName, '/' . $folderName)) {
            return $scriptName;
        }
    }

    $fallback = '/GSS';
    return rtrim($fallback, '/');
}

function app_base_path(): string {
    $v = env_get('APP_BASE_PATH');
    if ($v && $v !== '') {
        return rtrim(str_replace('\\', '/', $v), '/');
    }

    $root = realpath(__DIR__ . '/..');
    return $root ? rtrim(str_replace('\\', '/', $root), '/') : '';
}

function app_url(string $path): string {
    $base = app_base_url();
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function app_path(string $relativePath): string {
    $base = app_base_path();
    $relativePath = '/' . ltrim($relativePath, '/');
    return $base . $relativePath;
}
