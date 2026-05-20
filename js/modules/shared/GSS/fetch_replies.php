<?php
require_once __DIR__ . '/../../../../config/env.php';
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../api/shared/workflow_communication_service.php';

const MAX_REPLY_MESSAGE_CHARS = 20000;
const DEBUG_MODE = true;

function cli_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function normalize_application_id(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    return preg_match('/^APP-\d+$/', $value) === 1 ? $value : '';
}

function normalize_message_id(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    $value = trim($value, "<> \t\r\n");
    return strtolower($value);
}

function extract_message_ids(string $value): array
{
    $value = trim($value);
    if ($value === '') return [];
    $out = [];
    if (preg_match_all('/<([^>]+)>/', $value, $m) === 1 && !empty($m[1])) {
        foreach ($m[1] as $id) {
            $n = normalize_message_id((string)$id);
            if ($n !== '') $out[$n] = true;
        }
    } else {
        foreach (preg_split('/\s+/', $value) as $token) {
            $n = normalize_message_id((string)$token);
            if ($n !== '') $out[$n] = true;
        }
    }
    return array_keys($out);
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
    $message = strip_reply_noise_text($message);
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

function is_reply_noise_line(string $line): bool
{
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    $lower = strtolower($line);
    if (preg_match('/^>+$/', $line) === 1) return true;
    if (preg_match('/^<https?:\/\/\S+>$/i', $line) === 1) return true;
    if (strpos($lower, 'avg.com/email-signature') !== false) return true;
    if (strpos($lower, 'virus-free.www.avg.com') !== false) return true;
    if (strpos($lower, 'utm_medium=email') !== false) return true;
    if (strpos($lower, 'utm_source=link') !== false) return true;
    if (strpos($lower, 'utm_campaign=') !== false) return true;
    if (strpos($lower, 'utm_content=') !== false) return true;
    if (strpos($lower, 'cid:') === 0) return true;
    if (strpos($lower, 'mailto:') === 0) return true;
    if (preg_match('/^https?:\/\/\S+$/i', $line) === 1) return true;
    return false;
}

function is_reply_thread_boundary(string $line): bool
{
    $line = trim($line);
    if ($line === '') {
        return false;
    }
    $lower = strtolower($line);
    if (preg_match('/^on\s.+wrote:$/i', $line) === 1) return true;
    if (preg_match('/^from:\s/i', $line) === 1) return true;
    if (preg_match('/^sent:\s/i', $line) === 1) return true;
    if (preg_match('/^to:\s/i', $line) === 1) return true;
    if (preg_match('/^subject:\s/i', $line) === 1) return true;
    if (preg_match('/^-----original message-----$/i', $line) === 1) return true;
    if (preg_match('/^_{5,}$/', $line) === 1) return true;
    if (strpos($lower, 'begin forwarded message') !== false) return true;
    return false;
}

function strip_reply_noise_text(string $message): string
{
    $message = preg_replace('/\r\n?/', "\n", $message) ?? $message;
    $lines = explode("\n", $message);
    $out = [];

    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            if (!empty($out) && end($out) !== '') {
                $out[] = '';
            }
            continue;
        }
        if (is_reply_thread_boundary($trimmed)) {
            break;
        }
        if ($trimmed[0] === '>' || is_reply_noise_line($trimmed)) {
            continue;
        }
        $out[] = $trimmed;
        if (count($out) >= 40) {
            break;
        }
    }

    $clean = trim(implode("\n", $out));
    return $clean !== '' ? $clean : trim($message);
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
    $candidates = ['GSS_Email_Replies', 'email_replies'];
    $stmt = $pdo->prepare(
        'SELECT 1 FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
    );
    foreach ($candidates as $table) {
        $stmt->execute([$table]);
        if ($stmt->fetchColumn()) {
            return $table;
        }
    }
    throw new RuntimeException('Replies table not found. Expected GSS_Email_Replies or email_replies');
}

function table_has_column(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

function resolve_application_from_thread(PDO $pdo, string $inReplyTo, string $references): array
{
    $ids = [];
    $inr = normalize_message_id($inReplyTo);
    if ($inr !== '') $ids[] = $inr;
    $ids = array_merge($ids, extract_message_ids($references));
    $ids = array_values(array_unique(array_filter($ids, static function ($x) { return $x !== ''; })));
    if (!$ids) return ['application_id' => '', 'thread_id' => '', 'case_id' => 0];

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT application_id, thread_id, case_id FROM workflow_communications WHERE message_id IN (' . $ph . ') ORDER BY communication_id DESC LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute($ids);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return ['application_id' => '', 'thread_id' => '', 'case_id' => 0];
    return [
        'application_id' => normalize_application_id((string)($row['application_id'] ?? '')),
        'thread_id' => trim((string)($row['thread_id'] ?? '')),
        'case_id' => (int)($row['case_id'] ?? 0),
    ];
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
    $repliesTable = resolve_replies_table($pdo);
    cli_log('USING REPLIES TABLE: ' . $repliesTable);

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
    $duplicates = 0;
    $unmatched = 0;

    $hasMsgId = table_has_column($pdo, $repliesTable, 'message_id');
    $hasInReplyTo = table_has_column($pdo, $repliesTable, 'in_reply_to');
    $hasReferences = table_has_column($pdo, $repliesTable, 'references_header');
    $hasMailboxUid = table_has_column($pdo, $repliesTable, 'mailbox_uid');
    $hasSubject = table_has_column($pdo, $repliesTable, 'subject');
    $hasThreadId = table_has_column($pdo, $repliesTable, 'thread_id');

    try {
        $insertCols = ['application_id', 'sender', 'message'];
        if ($hasSubject) $insertCols[] = 'subject';
        if ($hasMsgId) $insertCols[] = 'message_id';
        if ($hasInReplyTo) $insertCols[] = 'in_reply_to';
        if ($hasReferences) $insertCols[] = 'references_header';
        if ($hasMailboxUid) $insertCols[] = 'mailbox_uid';
        if ($hasThreadId) $insertCols[] = 'thread_id';
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $insert = $pdo->prepare(
            'INSERT INTO `' . str_replace('`', '``', $repliesTable) . '` (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
        );
        cli_log('PREPARE SUCCESS');
    } catch (Exception $e) {
        cli_log('PREPARE FAILED: ' . $e->getMessage());
        exit(1);
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

            $headersRaw = @imap_fetchheader($inbox, $msgNo, FT_INTERNAL);
            $headerInfo = @imap_rfc822_parse_headers((string)$headersRaw);
            $messageId = normalize_message_id((string)($headerInfo->message_id ?? ''));
            $inReplyTo = normalize_message_id((string)($headerInfo->in_reply_to ?? ''));
            $referencesHeader = trim((string)($headerInfo->references ?? ''));

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
            if (preg_match('/\bAPP-\d+\b/i', $subject . ' ' . $message, $m) === 1) {
                $applicationId = normalize_application_id($m[0]);
            }
            if ($applicationId === '') {
                $byThread = resolve_application_from_thread($pdo, $inReplyTo, $referencesHeader);
                $applicationId = (string)($byThread['application_id'] ?? '');
            }
            if ($applicationId === '') {
                cli_log('Skipping UID ' . (string)$uid . ': application_id not found in subject/body');
                $unmatched++;
                $skipped++;
                continue;
            }

            cli_log('---- EMAIL DEBUG ----');
            cli_log('Subject: ' . $subject);
            cli_log('Sender: ' . $sender);
            cli_log('Extracted APP ID: ' . $applicationId);
            cli_log('Message-ID: ' . $messageId);
            cli_log('In-Reply-To: ' . $inReplyTo);
            cli_log('References: ' . $referencesHeader);
            cli_log('Message length: ' . strlen($message));

            if ($hasMsgId && $messageId !== '') {
                $dup = $pdo->prepare('SELECT 1 FROM `' . str_replace('`', '``', $repliesTable) . '` WHERE message_id = ? LIMIT 1');
                $dup->execute([$messageId]);
                if ($dup->fetchColumn()) {
                    cli_log('Skipping duplicate by message_id: ' . $messageId);
                    $duplicates++;
                    $skipped++;
                    continue;
                }
            } elseif ($hasMailboxUid) {
                $dup = $pdo->prepare('SELECT 1 FROM `' . str_replace('`', '``', $repliesTable) . '` WHERE mailbox_uid = ? LIMIT 1');
                $dup->execute([(string)$uid]);
                if ($dup->fetchColumn()) {
                    cli_log('Skipping duplicate by mailbox_uid: ' . (string)$uid);
                    $duplicates++;
                    $skipped++;
                    continue;
                }
            }

            cli_log('REACHED INSERT BLOCK');
            try {
                $values = [
                    $applicationId,
                    str_limit($sender, 255),
                    $message
                ];
                if ($hasSubject) $values[] = str_limit($subject, 255);
                if ($hasMsgId) $values[] = $messageId !== '' ? $messageId : null;
                if ($hasInReplyTo) $values[] = $inReplyTo !== '' ? $inReplyTo : null;
                if ($hasReferences) $values[] = $referencesHeader !== '' ? str_limit($referencesHeader, 1000) : null;
                if ($hasMailboxUid) $values[] = (string)$uid;
                if ($hasThreadId) $values[] = 'app:' . strtolower($applicationId);
                $result = $insert->execute($values);

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
                'type' => 'reply',
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
                'references' => $referencesHeader
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
    wc_log_ingest_event($pdo, 'imap_poll', '', 0, 'ok', $inserted, $duplicates, $skipped, $unmatched, 'IMAP fetch_replies poll completed');
    imap_close($inbox);
} catch (Throwable $e) {
    try {
        if (isset($pdo) && $pdo instanceof PDO) {
            wc_log_ingest_event($pdo, 'imap_poll', '', 0, 'error', 0, 0, 0, 0, $e->getMessage());
        }
    } catch (Throwable $_e2) {
    }
    @imap_close($inbox);
    cli_log('Failed: ' . $e->getMessage());
    exit(1);
}
