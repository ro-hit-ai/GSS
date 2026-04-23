<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/db.php';

const MAX_REPLY_MESSAGE_CHARS = 20000;
const DEBUG_MODE = true;

function cli_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function normalize_application_id(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_match('/^APP-\d+$/', $value) === 1 ? $value : '';
}

function decode_mime_header_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $parts = @imap_mime_header_decode($value);
    if (!is_array($parts) || !$parts) {
        return $value;
    }

    $out = '';
    foreach ($parts as $part) {
        $text = isset($part->text) ? (string)$part->text : '';
        $charset = isset($part->charset) ? strtoupper((string)$part->charset) : 'UTF-8';
        if ($charset !== '' && $charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if (is_string($converted) && $converted !== '') {
                $text = $converted;
            }
        }
        $out .= $text;
    }

    return trim($out);
}

function decode_body_content(string $body, int $encoding): string
{
    if ($body === '') {
        return '';
    }

    switch ($encoding) {
        case 3:
            $decoded = base64_decode($body, true);
            return is_string($decoded) ? $decoded : '';
        case 4:
            return quoted_printable_decode($body);
        default:
            return $body;
    }
}

function flatten_plain_body($inbox, int $msgNo, $structure, string $partNo = ''): ?string
{
    if (!is_object($structure)) {
        return null;
    }

    $type = isset($structure->type) ? (int)$structure->type : 0;
    $subtype = strtoupper((string)($structure->subtype ?? ''));

    if ($type === 0 && $subtype === 'PLAIN') {
        $section = $partNo !== '' ? $partNo : '1';
        $raw = @imap_fetchbody($inbox, $msgNo, $section);
        if (!is_string($raw) || $raw === '') {
            $raw = @imap_body($inbox, $msgNo);
        }
        return decode_body_content((string)$raw, (int)($structure->encoding ?? 0));
    }

    if (!empty($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $sub) {
            $nextPart = $partNo === '' ? (string)($index + 1) : ($partNo . '.' . ($index + 1));
            $found = flatten_plain_body($inbox, $msgNo, $sub, $nextPart);
            if (is_string($found) && trim($found) !== '') {
                return $found;
            }
        }
    }

    return null;
}

function flatten_html_body($inbox, int $msgNo, $structure, string $partNo = ''): ?string
{
    if (!is_object($structure)) {
        return null;
    }

    $type = isset($structure->type) ? (int)$structure->type : 0;
    $subtype = strtoupper((string)($structure->subtype ?? ''));

    if ($type === 0 && $subtype === 'HTML') {
        $section = $partNo !== '' ? $partNo : '1';
        $raw = @imap_fetchbody($inbox, $msgNo, $section);
        if (!is_string($raw) || $raw === '') {
            $raw = @imap_body($inbox, $msgNo);
        }
        return decode_body_content((string)$raw, (int)($structure->encoding ?? 0));
    }

    if (!empty($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $index => $sub) {
            $nextPart = $partNo === '' ? (string)($index + 1) : ($partNo . '.' . ($index + 1));
            $found = flatten_html_body($inbox, $msgNo, $sub, $nextPart);
            if (is_string($found) && trim($found) !== '') {
                return $found;
            }
        }
    }

    return null;
}

function extract_text_body($inbox, int $msgNo): string
{
    $structure = @imap_fetchstructure($inbox, $msgNo);

    $plain = flatten_plain_body($inbox, $msgNo, $structure);
    if (is_string($plain) && trim($plain) !== '') {
        return sanitize_message_text($plain);
    }

    $html = flatten_html_body($inbox, $msgNo, $structure);
    if (is_string($html) && trim($html) !== '') {
        return sanitize_message_text(sanitize_html_to_text($html));
    }

    $fallback = @imap_body($inbox, $msgNo);
    if (!is_string($fallback)) {
        return '';
    }

    return sanitize_message_text(sanitize_html_to_text($fallback));
}

function sanitize_message_text(string $message): string
{
    $message = str_replace("\0", '', $message);
    $message = preg_replace('/\r\n?/', "\n", $message) ?? $message;
    $message = preg_replace('/\n{3,}/', "\n\n", $message) ?? $message;
    if (function_exists('mb_substr')) {
        $message = mb_substr($message, 0, MAX_REPLY_MESSAGE_CHARS);
    } else {
        $message = substr($message, 0, MAX_REPLY_MESSAGE_CHARS);
    }
    return trim($message);
}

function str_limit(string $value, int $max): string
{
    if ($max <= 0) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function sanitize_html_to_text(string $html): string
{
    $withoutScripts = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $withoutStyles = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $withoutScripts) ?? $withoutScripts;
    return strip_tags($withoutStyles);
}

function resolve_replies_table(PDO $pdo): string
{
    $candidates = ['GSS_Email_Replies'];
    foreach ($candidates as $table) {
        $st = $pdo->prepare('SHOW TABLES LIKE ?');
        $st->execute([$table]);
        if ($st->fetchColumn()) {
            return $table;
        }
    }
    throw new RuntimeException('Replies table not found. Expected GSSEMAILREPLIES or GSS_Email_Replies');
}

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

if (!function_exists('imap_open')) {
    cli_log('IMAP extension is not enabled in PHP.');
    exit(1);
}

$imapMailbox = trim((string)(env_get('MAIL_REPLY_IMAP_MAILBOX', '{imap.gmail.com:993/imap/ssl}INBOX') ?? ''));
$imapUser = trim((string)(env_get('MAIL_REPLY_IMAP_USER', '') ?? ''));
$imapPass = (string)(env_get('MAIL_REPLY_IMAP_PASS', '') ?? '');

if ($imapUser === '' || $imapPass === '') {
    cli_log('MAIL_REPLY_IMAP_USER or MAIL_REPLY_IMAP_PASS is missing in .env');
    exit(1);
}

$inbox = @imap_open($imapMailbox, $imapUser, $imapPass);
if (!$inbox) {
    die('IMAP Error: ' . (imap_last_error() ?: 'Unknown IMAP connection error'));
}

try {
    $pdo = getDB();
    cli_log('CURRENT DB: ' . $pdo->query("SELECT DATABASE()")->fetchColumn());
    cli_log('DB CONNECTED');

    $check = $pdo->query("SHOW TABLES LIKE 'GSS_Email_Replies'");
    cli_log('TABLE EXISTS: ' . ($check->rowCount() > 0 ? 'YES' : 'NO'));

    $uids = @imap_search($inbox, 'ALL', SE_UID);
    $emailCount = is_array($uids) ? count($uids) : 0;
    cli_log('Emails found: ' . $emailCount);
    if (!is_array($uids) || !$uids) {
        cli_log('No reply emails found in inbox.');
        imap_close($inbox);
        exit(0);
    }
    rsort($uids, SORT_NUMERIC);
    $uids = array_slice($uids, 0, 300);

    $inserted = 0;
    $skipped = 0;

    try {
        $insert = $pdo->prepare(
            'INSERT INTO GSS_Email_Replies (application_id, sender, message) VALUES (?, ?, ?)'
        );
        cli_log('PREPARE SUCCESS');
    } catch (Exception $e) {
        cli_log('PREPARE FAILED: ' . $e->getMessage());
        exit(1);
    }

    try {
        $pdo->exec(
            "INSERT INTO GSS_Email_Replies (application_id, sender, message) "
            . "VALUES ('TEST123', 'debug@test.com', 'manual insert test')"
        );
        cli_log('MANUAL INSERT DONE');
    } catch (Exception $e) {
        cli_log('MANUAL INSERT FAILED: ' . $e->getMessage());
    }

    $mailLogInsert = $pdo->prepare(
        'INSERT INTO GSS_Mail_Logs ('
        . 'status, driver, mail_from, mail_to, subject, application_id, user_id, user_name, client_id, created_at, meta_json'
        . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)'
    );

    foreach ($uids as $uid) {
        try {
            cli_log('Processing email...');
            $msgNo = (int)imap_msgno($inbox, (int)$uid);
            if ($msgNo <= 0) {
                $skipped++;
                continue;
            }

            $overviewRows = @imap_fetch_overview($inbox, (string)$uid, FT_UID);
            $overview = is_array($overviewRows) && isset($overviewRows[0]) ? $overviewRows[0] : null;
            if (!$overview) {
                cli_log('WARNING: Overview not found for UID ' . (string)$uid . '; using safe defaults');
            }

            $rawSubject = ($overview && isset($overview->subject)) ? (string)$overview->subject : '';
            $subject = decode_mime_header_value($rawSubject);
            cli_log('Subject: ' . $subject);

            $rawFrom = ($overview && isset($overview->from)) ? (string)$overview->from : '';
            $sender = decode_mime_header_value($rawFrom);
            if ($sender === '') {
                $sender = 'Unknown';
            }
            cli_log('From: ' . $sender);

            $message = extract_text_body($inbox, $msgNo);
            if ($message === '') {
                $rawBody = @imap_body($inbox, $msgNo);
                $message = is_string($rawBody) ? sanitize_message_text(sanitize_html_to_text($rawBody)) : '';
            }
            if (!$message || trim($message) === '') {
                cli_log('Empty message -> storing as empty text');
                $message = '';
            }

            $applicationId = '';
            $m = [];
            if (preg_match('/APP-\d+/i', $subject . ' ' . $message, $m) === 1) {
                $applicationId = normalize_application_id($m[0]);
            }
            if ($applicationId === '') {
                $applicationId = 'UNKNOWN';
            }

            cli_log('---- EMAIL DEBUG ----');
            cli_log('Subject: ' . $subject);
            cli_log('Sender: ' . $sender);
            cli_log('Extracted APP ID: ' . $applicationId);
            cli_log('Message length: ' . strlen($message));

            cli_log('REACHED INSERT BLOCK');
            try {
                $result = $insert->execute([
                    $applicationId,
                    str_limit($sender, 255),
                    $message
                ]);

                if ($result) {
                    cli_log('INSERT SUCCESS');
                } else {
                    $err = $insert->errorInfo();
                    cli_log('INSERT FAILED: ' . json_encode($err));
                    $skipped++;
                    continue;
                }
            } catch (Exception $e) {
                cli_log('EXECUTE ERROR: ' . $e->getMessage());
                $skipped++;
                continue;
            }

            $inserted++;
            cli_log('Inserted successfully for APP: ' . $applicationId);
            $subjectForLog = (string)($subject ?? '');
            if (function_exists('mb_substr')) {
                $subjectForLog = mb_substr($subjectForLog, 0, 255);
            } else {
                $subjectForLog = substr($subjectForLog, 0, 255);
            }
            $metaJson = json_encode([
                'source' => 'imap',
                'type' => 'reply'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($metaJson) || $metaJson === '') {
                $metaJson = '{"source":"imap","type":"reply"}';
            }
            try {
                $mailLogInsert->execute([
                    'received',
                    'imap',
                    (string)$sender,
                    null,
                    (string)$subjectForLog,
                    (string)$applicationId,
                    0,
                    'system',
                    0,
                    $metaJson
                ]);
            } catch (Throwable $mailLogEx) {
                cli_log('MAIL LOG INSERT FAILED: ' . $mailLogEx->getMessage());
            }
            if (!DEBUG_MODE) {
                @imap_setflag_full($inbox, (string)$uid, '\\Seen', ST_UID);
            }
        } catch (Throwable $mailEx) {
            $skipped++;
            cli_log('Skipped UID ' . (string)$uid . ': ' . $mailEx->getMessage());
        }
    }

    cli_log('Fetch completed. Inserted=' . $inserted . ', skipped=' . $skipped);
    imap_close($inbox);
} catch (Throwable $e) {
    @imap_close($inbox);
    cli_log('Failed: ' . $e->getMessage());
    exit(1);
}
