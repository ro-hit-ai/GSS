<?php
declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';

use setasign\Fpdi\Tcpdf\Fpdi as TcpdfFpdi;

auth_require_login('gss_admin');
if (session_status() === PHP_SESSION_NONE) session_start();

@ini_set('memory_limit', '1024M');
@ini_set('pcre.backtrack_limit', '10000000');
@ini_set('display_errors', '0');
@set_time_limit(300);

const GSS_MAX_TOTAL_EVIDENCE_PAGES = 140;
const GSS_MAX_SOURCE_PDF_PAGES = 40;
const GSS_MAX_FILE_BYTES = 25 * 1024 * 1024;
const GSS_SAFE_IMAGE_MAX_WIDTH = 1400;
const GSS_SAFE_JPEG_QUALITY = 82;
const GSS_GHOSTSCRIPT_EXE_DEFAULT = 'C:\\Program Files\\gs\\gs10.03.0\\bin\\gswin64c.exe';
const GSS_SKIP_ALPHA_PNG_IF_NO_LIB = true;
const GSS_SECTION_SPACING = 8;
const GSS_TABLE_SPACING = 5;
const GSS_TABLE_TOP_GAP = 16;
const GSS_HEADER_LOGO_WIDTH = 19.0;
const GSS_HEADER_LOGO_HEIGHT = 19.0;
const GSS_HEADER_WEBSITE = 'www.globalscreeningservices.com';
const GSS_APPENDIX_IMAGE_TRIM_MM = 1.2; // ~4px safety trim to avoid tiny overflow to next page
const GSS_APPENDIX_IMAGE_BOTTOM_RESERVE_MM = 2.5; // extra bottom reserve to prevent 1-2 line spillover
define('UPLOAD_ROOT', $_SERVER['DOCUMENT_ROOT'] . '/GSS/uploads/');

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && strpos((string)$haystack, (string)$needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') return true;
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

class GSSReportPDF extends TcpdfFpdi {
    private $showDecorators = true;
    private $showWatermark = true;
    private $headerLogoPath = '';

    public function setDecorators(bool $showDecorators, bool $showWatermark = true): void {
        $this->showDecorators = $showDecorators;
        $this->showWatermark = $showWatermark;
    }

    public function setHeaderBrand(string $logoPath, string $title): void {
        $this->headerLogoPath = $logoPath;
    }

    public function Header(): void {
        $left = 12.0;
        $top = 5.0;
        $logoW = GSS_HEADER_LOGO_WIDTH;
        $logoH = GSS_HEADER_LOGO_HEIGHT;
        // Draw logo on every page
        if ($this->headerLogoPath !== '' && is_file($this->headerLogoPath)) {
            $ext = strtolower((string)pathinfo($this->headerLogoPath, PATHINFO_EXTENSION));
            if ($ext === 'svg') {
                try {
                    $this->ImageSVG($this->headerLogoPath, $left, $top, $logoW, $logoH, '', '', '', 0, true);
                } catch (Throwable $e) {}
            } else {
                try {
                    $this->Image($this->headerLogoPath, $left, $top, $logoW, $logoH, '', '', '', false, 150, '', false, false, 0, false, false, false);
                } catch (Throwable $e) {
                    $svgFallback = str_replace('\\', '/', __DIR__ . '/../../assets/img/gss-logo.svg');
                    if (is_file($svgFallback)) {
                        try {
                            $this->ImageSVG($svgFallback, $left, $top, $logoW, $logoH, '', '', '', 0, true);
                        } catch (Throwable $_) {}
                    }
                }
            }
        }
        // Keep title/subtitle only on first page; logo+website on all pages
        if ($this->getPage() === 1) {
            $this->SetFont('helvetica', 'B', 18);
            $this->SetTextColor(15, 58, 102);
            $this->SetXY($left + $logoW + 8, $top + 1);
            $this->Cell(0, 10, 'Background Verification Report', 0, 1, 'L');
            $this->SetFont('helvetica', '', 11);
            $this->SetTextColor(75, 93, 115);
            $this->SetX($left + $logoW + 8);
            $this->Cell(0, 7, 'Professional Screening & Evidence Summary', 0, 1, 'L');
        } else {
        $this->SetY(24.0);
        }
        $this->SetFont('helvetica', '', 9);
        $this->SetTextColor(11, 34, 57);
        $this->SetXY(10, 10);
        $this->Cell(0, 5, GSS_HEADER_WEBSITE, 0, 0, 'R');
        $this->SetDrawColor(180, 180, 180);
        $this->SetLineWidth(0.35);
        $this->Line(10.0, 24.0, $this->getPageWidth() - 10.0, 24.0);
        $this->SetY(26.0);
        // Watermark on all pages if enabled
        if ($this->showWatermark) {
            $this->StartTransform();
            $this->SetFont('helvetica', 'B', 38);
            $this->SetTextColor(220, 230, 241);
            $cx = $this->getPageWidth() / 2;
            $cy = $this->getPageHeight() / 2;
            $this->Rotate(35, $cx, $cy);
            $this->Text($cx - 60, $cy + 10, 'CONFIDENTIAL');
            $this->StopTransform();
            $this->SetTextColor(11, 34, 57);
        }
    }
    public function Footer(): void {
        $this->SetY(-12);
        $this->SetFont('helvetica', 'I', 9);
        $this->SetTextColor(75, 93, 115);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . ' of ' . $this->getAliasNbPages() . ' | Confidential - For authorized use only', 0, 0, 'C');
    }

    public function checkPageBreakForContent($contentHeight) {
        $currentY = $this->GetY();
        $pageHeight = $this->getPageHeight();
        $bottomMargin = 20;
        if ($currentY + $contentHeight > $pageHeight - $bottomMargin) {
            $this->AddPage();
            return true;
        }
        return false;
    }
}

function q(string $key, string $default = ''): string {
    return trim((string)($_GET[$key] ?? $default));
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function log_pdf(string $msg): void {
    error_log('[GSS_PDF][' . date('Y-m-d H:i:s') . '] ' . $msg);
}

function normalize_component(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'identification') return 'id';
    if ($k === 'address') return 'contact';
    if ($k === 'social_media' || $k === 'social-media') return 'social';
    if ($k === '') return 'general';
    return $k;
}

function classify_from_filename(string $name): string {
    $n = strtolower($name);
    if ($n === '') return 'general';
    foreach (['aadhaar','aadhar','uid','pan','passport','voter','dl','license','licence','id'] as $t) if (strpos($n, $t) !== false) return 'id';
    foreach (['electricity','water','gas','bill','rent','utility','ration','address'] as $t) if (strpos($n, $t) !== false) return 'contact';
    foreach (['degree','marksheet','certificate','university','college','transcript'] as $t) if (strpos($n, $t) !== false) return 'education';
    foreach (['offer','appointment','salary','payslip','experience','relieving','company'] as $t) if (strpos($n, $t) !== false) return 'employment';
    return 'general';
}

function infer_component(array $doc): string {
    $explicit = normalize_component((string)($doc['doc_type'] ?? ''));
    if (in_array($explicit, ['basic','contact','id','employment','education','reference','social','ecourt','reports','database'], true)) return $explicit;
    return classify_from_filename((string)($doc['original_name'] ?? $doc['file_path'] ?? ''));
}

function api_get_json(string $relativePath): array {
    $url = app_url($relativePath);
    $cookie = session_name() . '=' . session_id();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'Cookie: ' . $cookie],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false || $code >= 400) throw new RuntimeException('API fetch failed: ' . $relativePath . ' ' . ($err !== '' ? $err : ('HTTP ' . $code)));
    $json = json_decode($raw, true);
    if (!is_array($json)) throw new RuntimeException('Invalid API JSON: ' . $relativePath);
    return $json;
}

function value_text($v): string {
    if ($v === null) return '-';
    if (is_scalar($v)) {
        $s = trim((string)$v);
        if ($s === '') return '-';
        $s = preg_replace('/\s+/u', ' ', strip_tags($s)) ?? $s;
        $s = htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > 500) return mb_substr($s, 0, 500, 'UTF-8') . '...';
        if (strlen($s) > 500) return substr($s, 0, 500) . '...';
        return $s;
    }
    if (is_array($v)) return '[Array]';
    return '[Object]';
}

function is_document_field_name(string $field): bool {
    $f = strtolower(trim($field));
    foreach ([
        'upload_document','document_file','marksheet_file','degree_file','employment_doc','evidence_doc',
        'photo','attachment','proof_file','address_proof_file','authorization_file','file_name','file_path'
    ] as $needle) {
        if ($f === $needle || str_contains($f, $needle)) return true;
    }
    return false;
}

function upload_roots(): array {
    $envBasePath = trim((string)env_get('APP_BASE_PATH', ''));
    $envBasePath = str_replace('\\', '/', $envBasePath);
    $roots = [
        $envBasePath !== '' ? rtrim($envBasePath, '/\\') . '/uploads' : '',
        rtrim((string)app_path('/uploads'), '/\\'),
        rtrim((string)UPLOAD_ROOT, '/\\'),
        rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/uploads',
        str_replace('\\', '/', __DIR__ . '/../../uploads'),
        str_replace('\\', '/', __DIR__ . '/../../../uploads'),
    ];
    $out = [];
    $seen = [];
    foreach ($roots as $r) {
        $r = str_replace('\\', '/', (string)$r);
        if ($r === '' || isset($seen[$r])) continue;
        $seen[$r] = true;
        $out[] = $r;
    }
    return $out;
}

function looks_like_file_ref(string $v): bool {
    $s = strtolower(trim($v));
    if ($s === '') return false;
    if (preg_match('~^https?://~i', $s)) {
        $p = parse_url($s);
        $path = strtolower((string)($p['path'] ?? ''));
        if ($path === '') return false;
        if (str_contains($path, '/uploads/')) return true;
        return (bool)preg_match('/\.(pdf|png|jpe?g|gif|webp|bmp|tiff?)$/i', $path);
    }
    if (str_contains($s, '/uploads/')) return true;
    if (str_contains($s, 'uploads/')) return true;
    return (bool)preg_match('/\.(pdf|png|jpe?g|gif|webp|bmp|tiff?)($|\?)/i', $s);
}

function is_probably_remote_non_upload_url(string $v): bool {
    $s = trim($v);
    if (!preg_match('~^https?://~i', $s)) return false;
    $parts = parse_url($s);
    $path = strtolower((string)($parts['path'] ?? ''));
    if (str_contains($path, '/uploads/')) return false;
    $baseUrlPath = parse_url((string)env_get('APP_BASE_URL', '/GSS'), PHP_URL_PATH);
    $baseUrlPath = is_string($baseUrlPath) ? rtrim(strtolower($baseUrlPath), '/') : '';
    if ($baseUrlPath !== '' && str_starts_with($path, $baseUrlPath . '/uploads/')) return false;
    return true;
}

function expected_upload_subdirs(array $context = []): array {
    $out = [''];
    $docType = normalize_component((string)($context['doc_type'] ?? $context['__component'] ?? ''));
    $sourceField = strtolower((string)($context['source_field'] ?? ''));

    $map = [
        'id' => 'identification/',
        'contact' => 'address/',
        'education' => 'education/',
        'employment' => 'employment/',
        'basic' => 'candidate_photos/',
        'ecourt' => 'ecourt/',
        'reference' => 'reference/',
        'reports' => 'verification/',
    ];
    if (isset($map[$docType])) $out[] = $map[$docType];

    if (str_contains($sourceField, 'photo')) $out[] = 'candidate_photos/';
    if (str_contains($sourceField, 'mark') || str_contains($sourceField, 'degree')) $out[] = 'education/';
    if (str_contains($sourceField, 'employment')) $out[] = 'employment/';
    if (str_contains($sourceField, 'proof')) $out[] = 'address/';

    foreach (['identification/','address/','education/','employment/','verification/','candidate_photos/','ecourt/','photos/','documents/'] as $dir) {
        $out[] = $dir;
    }
    return array_values(array_unique($out));
}

function resolveUploadFile($file, array $context = []): ?string {
    $raw = str_replace('\\', '/', trim((string)$file));
    if ($raw === '') return null;
    $raw = preg_replace('/[?#].*$/', '', $raw) ?? $raw;
    // Decode web-encoded paths like ".../my%20file.pdf" before filesystem checks.
    $raw = rawurldecode($raw);
    if ($raw === '') return null;

    // If it's a URL, decide whether to handle it
    if (preg_match('~^https?://~i', $raw)) {
        if (is_probably_remote_non_upload_url($raw)) {
            log_pdf('resolveUploadFile skip remote non-upload url=' . $raw);
            return null;
        }
        $parts = parse_url($raw);
        $raw = str_replace('\\', '/', (string)($parts['path'] ?? ''));
    }

    // Remove leading /GSS if present (web path)
    $raw = ltrim($raw, '/');
    if (str_starts_with($raw, 'GSS/')) $raw = substr($raw, 4);
    $raw = ltrim($raw, '/');

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $envBasePath = trim((string)env_get('APP_BASE_PATH', ''));
    $envBasePath = str_replace('\\', '/', $envBasePath);
    if ($envBasePath !== '') {
        $uploadBase = rtrim($envBasePath, '/\\') . '/uploads/';
    } else {
        $uploadBase = str_replace('\\', '/', $docRoot . '/GSS/uploads/');
    }
    $baseName = basename($raw);
    $component = normalize_component((string)($context['doc_type'] ?? $context['__component'] ?? ''));
    $sourceField = strtolower((string)($context['source_field'] ?? ''));

    log_pdf('resolveUploadFile try raw=' . $raw . ' basename=' . $baseName . ' component=' . $component . ' source=' . $sourceField);

    // 1) Absolute path straight from DB.
    if ((preg_match('/^[A-Za-z]:[\/\\\\]/', $raw) || str_starts_with($raw, '/')) && is_file($raw)) {
        $hit = str_replace('\\', '/', $raw);
        log_pdf('resolveUploadFile hit absolute=' . $hit);
        return $hit;
    }

    $candidates = [];

    // 2) If DB path already contains uploads/, strip to suffix and resolve under upload root.
    $uploadsPos = stripos($raw, 'uploads/');
    if ($uploadsPos !== false) {
        $suffix = ltrim(substr($raw, $uploadsPos + 8), '/');
        $candidates[] = $uploadBase . $suffix;
    }

    // 3) Try exact relative path under app root.
    $candidates[] = str_replace('\\', '/', app_path('/' . $raw));

    // 4) Try under document root directly (without /GSS prefix) as fallback
    if ($docRoot !== '') {
        $candidates[] = str_replace('\\', '/', $docRoot . '/' . $raw);
    }

    // 5) Known upload subdirectories.
    $subdirs = ['identification/','address/','education/','employment/','candidate_photos/','verification/','ecourt/','documents/','photos/',''];
    if ($component === 'id') array_unshift($subdirs, 'identification/');
    if ($component === 'contact') array_unshift($subdirs, 'address/');
    if ($component === 'education') array_unshift($subdirs, 'education/');
    if ($component === 'employment') array_unshift($subdirs, 'employment/');
    if ($component === 'basic') array_unshift($subdirs, 'candidate_photos/');
    if (str_contains($sourceField, 'proof')) array_unshift($subdirs, 'address/');
    if (str_contains($sourceField, 'photo')) array_unshift($subdirs, 'candidate_photos/');

    foreach (array_values(array_unique($subdirs)) as $sub) {
        $candidates[] = $uploadBase . $sub . $baseName;
    }

    // 6) Extensionless fallback for values like authorization_APP_... (no .pdf)
    if (!preg_match('/\.[A-Za-z0-9]{2,5}$/', $baseName)) {
        foreach (array_values(array_unique($subdirs)) as $sub) {
            $pattern = $uploadBase . $sub . $baseName . '*';
            $matches = glob($pattern) ?: [];
            foreach ($matches as $m) {
                $candidates[] = str_replace('\\', '/', $m);
            }
        }
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        $candidate = str_replace('\\', '/', (string)$candidate);
        if ($candidate === '' || isset($seen[$candidate])) continue;
        $seen[$candidate] = true;
        log_pdf('resolveUploadFile check=' . $candidate);
        if (is_file($candidate)) {
            log_pdf('resolveUploadFile hit=' . $candidate);
            return $candidate;
        }
    }

    log_pdf('Could not resolve file: ' . $raw);
    return null;
}

function t_styles(): array {
    return [
        'section_title' => 'background-color:#2b4c7e;color:#ffffff;font-weight:900;padding:8px 14px;border:1px solid #1d3557;font-size:12.5pt;margin-bottom:10px;letter-spacing:.08em;text-transform:uppercase;',
        'section_title_dark' => 'background-color:#0f2f57;color:#ffffff;font-weight:900;padding:4px 8px;border:1px solid #0f2f57;border-radius:6px;font-family:helvetica,sans-serif;font-size:9.5pt;text-transform:uppercase;letter-spacing:0.4px;line-height:1.25;',
        'table' => 'border-collapse:collapse;width:100%;font-family:helvetica,sans-serif;font-size:10pt;color:#0b2239;margin-bottom:8mm;border-radius:8px;box-shadow:0 1px 4px #edf2f7;',
        'th' => 'border:1px solid #cfd8e3;padding:8px 12px;background-color:#f4f7fb;color:#0f3a66;font-weight:900;text-transform:uppercase;font-size:9pt;letter-spacing:.05em;',
        'td' => 'border:1px solid #dde5ee;padding:7px 10px;line-height:1.35;background-color:#ffffff;font-size:9.5pt;',
        'td_key' => 'border:1px solid #dde5ee;padding:7px 10px;line-height:1.35;background-color:#f8fbff;color:#334155;font-size:9.5pt;',
        'box' => 'margin-bottom:8px;',
        'meta_table' => 'border-collapse:collapse;width:100%;font-family:helvetica,sans-serif;font-size:9.2pt;color:#334155;',
        'meta_td' => 'border:1px solid #e2e8f0;padding:6px 8px;background-color:#ffffff;font-size:9pt;',
        'meta_key_td' => 'border:1px solid #dbe3ed;padding:6px 8px;background-color:#f8fbff;color:#334155;font-size:9pt;',
        'chip_ok' => 'background-color:#e8f8ef;color:#1a7f45;border:1px solid #bde5cc;padding:3px 9px;font-weight:bold;border-radius:12px;font-size:8.6pt;line-height:1.2;',
        'chip_bad' => 'background-color:#fdebec;color:#b42318;border:1px solid #f6c7cb;padding:3px 9px;font-weight:bold;border-radius:12px;font-size:8.6pt;line-height:1.2;',
        'chip_wait' => 'background-color:#f4f5f7;color:#475467;border:1px solid #d0d5dd;padding:3px 9px;font-weight:bold;border-radius:12px;font-size:8.6pt;line-height:1.2;',
        'report_head' => 'border:1px solid #cfd8e3;border-radius:8px;background-color:#ffffff;padding:8px 12px;',
        'report_badge' => 'display:inline-block;background-color:#2f5fd0;color:#ffffff;padding:4px 10px;border-radius:10px;font-weight:bold;font-size:9.5pt;text-transform:uppercase;letter-spacing:.06em;',
        'report_title' => 'font-size:14pt;color:#0b2239;font-weight:900;margin-top:0;',
        'report_sub' => 'font-size:9.5pt;color:#4b5d73;margin-top:2px;',
        'sheet' => 'border:1px solid #cfd8e3;border-radius:8px;background-color:#ffffff;padding:0px 1px;margin-bottom:7mm;',
        'panel' => 'border:1px solid #d4dde8;padding:6px 8px;margin-bottom:8mm;',
        'muted' => 'color:#4b5d73;font-size:8.8pt;',
    ];
}

function add_section_spacing(TCPDF $pdf, float $mm = GSS_TABLE_TOP_GAP): void {
    $pdf->Ln($mm);
}

function add_table_spacing(TCPDF $pdf): void {
    $pdf->Ln(4);
}

function report_header_html(string $applicationId, string $caseId): string {
    $s = t_styles();
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['meta_table'] . '">'
        . '<tr><td style="' . $s['report_head'] . '">'
        . '<span style="' . $s['report_badge'] . '">GSS REPORT</span>'
        . '<div style="' . $s['report_title'] . '">Candidate Verification Report</div>'
        . '<div style="' . $s['report_sub'] . '">Application ID: ' . h($applicationId) . ' | Case ID: ' . h($caseId !== '' ? $caseId : '-') . '</div>'
        . '</td></tr></table>';
}

function sec_title_html(string $title): string {
    $s = t_styles();
    return '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="' . $s['box'] . '"><tr><td style="background-color:#0f3a66;color:#fff;font-weight:900;padding:12px 16px;border-radius:8px;font-size:14.5pt;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;">' . h($title) . '</td></tr></table>';
}

function sec_title_dark_html(string $title): string {
    $s = t_styles();
    return '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="' . $s['box'] . '"><tr><td style="background-color:#2b4c7e;color:#fff;font-weight:900;padding:9px 14px;border-radius:8px;font-size:12pt;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">' . h($title) . '</td></tr></table>';
}

function panel_html(string $title, string $content): string {
    $s = t_styles();
    return sec_title_html($title)
        . '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['meta_table'] . ' margin-bottom: ' . GSS_TABLE_SPACING . 'mm;">'
        . '<tr><td style="' . $s['panel'] . '">' . $content . '</td></tr>'
        . '</table>';
}

function status_chip_html(string $status): string {
    $s = t_styles();
    $u = strtoupper(trim($status));
    if ($u === 'VERIFIED' || $u === 'COMPLETED' || $u === 'APPROVED') {
        return '<span style="' . $s['chip_ok'] . '">' . h($u) . '</span>';
    }
    if ($u === 'FAILED' || $u === 'REJECTED') {
        return '<span style="' . $s['chip_bad'] . '">' . h($u) . '</span>';
    }
    return '<span style="' . $s['chip_wait'] . '">' . h($u !== '' ? $u : 'PENDING') . '</span>';
}

function render_summary_block_html(array $summary): string {
    $s = t_styles();
    $rows = '';
    foreach ($summary as $k => $v) {
        $rows .= '<tr>'
            . '<td width="30%" style="' . $s['meta_key_td'] . '"><b>' . h((string)$k) . '</b></td>'
            . '<td style="' . $s['meta_td'] . '">' . h((string)$v) . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td style="' . $s['meta_td'] . '" colspan="2">No summary data available.</td></tr>';
    }
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['meta_table'] . '"><tbody>' . $rows . '</tbody></table>';
}

function table_from_assoc(array $data): string {
    $s = t_styles();
    if (!$data) {
        return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><tbody><tr><td style="' . $s['td'] . '">No data available.</td></tr></tbody></table>';
    }
    $rows = '';
    $alt = false;
foreach ($data as $k => $v) {
$bg = $alt ? '#f7f7f7' : '#ffffff';
$alt = !$alt;
        if (strtolower((string)$k) === 'id') continue;
        $valHtml = value_text($v);
        if (is_document_field_name((string)$k) && is_scalar($v)) {
            $raw = trim((string)$v);
            if ($raw === '' || strtoupper($raw) === 'INSUFFICIENT_DOCUMENTS') {
                $valHtml = value_text($raw);
            } else {
                $valHtml = '<span>Attached (see appendix)</span>';
            }
        }
        $rows .= '<tr><td width="%25" style="' . $s['td_key'] . '"><b>' . h(ucwords(str_replace('_', ' ', (string)$k))) . '</b></td><td style="' . $s['td'] . '">' . $valHtml . '</td></tr>';
    }
    if ($rows === '') $rows = '<tr><td style="' . $s['td'] . '" colspan="2">No data available.</td></tr>';
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><tbody>' . $rows . '</tbody></table>';
}

// ***** MISSING FUNCTION ADDED *****
function table_from_rows(array $rows): string {
    if (!$rows) return table_from_assoc([]);
    $html = '';
    $i = 1;
    $count = count($rows);
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $html .= '<div style="margin-bottom:2mm;"><span style="' . t_styles()['muted'] . 'font-weight:bold;">Record ' . $i . '</span></div>' . table_from_assoc($r);
        if ($i < $count) {
            $html .= '<div style="height:4mm;"></div>';
        }
        $i++;
    }
    return $html === '' ? table_from_assoc([]) : $html;
}

function workflow_component_table(array $wf): string {
    $s = t_styles();
    $rows = '';
    foreach (['candidate','validator','verifier','qa'] as $role) {
        $n = is_array($wf[$role] ?? null) ? $wf[$role] : [];
        $status = strtoupper((string)($n['status'] ?? 'PENDING'));
        $rows .= '<tr>'
            . '<td style="' . $s['td'] . '">' . h(ucfirst($role)) . '</td>'
            . '<td style="' . $s['td'] . '">' . status_chip_html($status) . '</td>'
            . '<td style="' . $s['td'] . '">' . h((string)($n['completed_at'] ?? $n['updated_at'] ?? '-')) . '</td>'
            . '</tr>';
    }
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><thead><tr><th style="' . $s['th'] . '">Role</th><th style="' . $s['th'] . '">Status</th><th style="' . $s['th'] . '">Completed Date</th></tr></thead><tbody>' . $rows . '</tbody></table>';
}

function workflow_summary_table_html(array $workflow): string {
    $s = t_styles();
    if (!$workflow) {
        return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><tbody><tr><td style="' . $s['td'] . '" colspan="4">No workflow data available.</td></tr></tbody></table>';
    }

    $rows = '';
    foreach ($workflow as $component => $node) {
        $node = is_array($node) ? $node : [];
        foreach (['candidate','validator','verifier','qa'] as $role) {
            $stage = is_array($node[$role] ?? null) ? $node[$role] : [];
            $status = strtoupper((string)($stage['status'] ?? 'PENDING'));
            $completed = (string)($stage['completed_at'] ?? $stage['updated_at'] ?? '-');
            if ($completed === '') $completed = '-';
            $rows .= '<tr>'
                . '<td style="' . $s['td'] . '">' . h(ucwords(str_replace('_', ' ', normalize_component((string)$component)))) . '</td>'
                . '<td style="' . $s['td'] . '">' . h(ucfirst($role)) . '</td>'
                . '<td style="' . $s['td'] . '">' . status_chip_html($status) . '</td>'
                . '<td style="' . $s['td'] . '">' . h($completed) . '</td>'
                . '</tr>';
        }
    }
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '">'
        . '<thead><tr><th style="' . $s['th'] . '">Component</th><th style="' . $s['th'] . '">Role</th><th style="' . $s['th'] . '">Status</th><th style="' . $s['th'] . '">Completed Date</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';
}

function remarks_table(array $rows): string {
    $s = t_styles();
    if (!$rows) return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><tbody><tr><td style="' . $s['td'] . '" colspan="3">No remarks.</td></tr></tbody></table>';
    $body = '';
    foreach ($rows as $r) {
        if (!is_array($r)) continue;
        $body .= '<tr>'
            . '<td style="' . $s['td'] . '">' . value_text($r['created_at'] ?? '') . '</td>'
            . '<td style="' . $s['td'] . '">' . h(strtoupper((string)($r['actor_role'] ?? '-'))) . '</td>'
            . '<td style="' . $s['td'] . '">' . value_text($r['message'] ?? '') . '</td>'
            . '</tr>';
    }
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '"><thead><tr><th style="' . $s['th'] . '">Time</th><th style="' . $s['th'] . '">Role</th><th style="' . $s['th'] . '">Remark</th></tr></thead><tbody>' . $body . '</tbody></table>';
}

function component_status(array $workflow, string $component): string {
    $c = normalize_component($component);
    $w = is_array($workflow[$c] ?? null) ? $workflow[$c] : [];
    $qa = strtolower((string)($w['qa']['status'] ?? ''));
    $ve = strtolower((string)($w['verifier']['status'] ?? ''));
    $va = strtolower((string)($w['validator']['status'] ?? ''));
    $ca = strtolower((string)($w['candidate']['status'] ?? ''));
    if ($qa === 'approved') return 'VERIFIED';
    if ($qa === 'rejected' || $ve === 'rejected' || $va === 'rejected' || $ca === 'rejected') return 'FAILED';
    return 'PENDING';
}

function component_section_html(string $title, array $entered, array $wf, array $remarks, array $compDocs): string {
    $enteredHtml = (isset($entered[0]) && is_array($entered[0])) ? table_from_rows($entered) : table_from_assoc($entered);
    $status = component_status([$title => $wf, normalize_component($title) => $wf], normalize_component($title));
    $styles = t_styles();

    $docsHtml = '';
    if (!empty($compDocs)) {
        $rows = '';
        foreach ($compDocs as $doc) {
            if (!is_array($doc)) continue;
            $fname = trim((string)($doc['display_name'] ?? ''));
            if ($fname === '') $fname = 'Document';
            $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
            if ($uploadedBy === '') $uploadedBy = 'Candidate';
            $ts = trim((string)($doc['timestamp'] ?? ''));
            $imgPath = trim((string)($doc['file_path'] ?? ''));
            $imgHtml = '';
            if ($imgPath !== '' && preg_match('/\.(jpg|jpeg|png|webp)$/i', $imgPath)) {
                $imgHtml = '<div style="margin-top:8px; text-align:center;"><img src="' . h($imgPath) . '" style="max-width:420px; max-height:260px; border-radius:8px; box-shadow:0 2px 8px #eaf1fb; border:1px solid #dbe3ed;" alt="Evidence Image"></div>';
            }
            $rows .= '<tr>'
                . '<td style="' . $styles['td'] . '">' . h($fname) . $imgHtml . '</td>'
                . '<td style="' . $styles['td'] . '">' . h($uploadedBy) . '</td>'
                . '<td style="' . $styles['td'] . '">' . h($ts !== '' ? $ts : '-') . '</td>'
                . '</tr>';
        }
        if ($rows !== '') {
            $docsHtml = panel_html('3. Uploaded Documents / Proof Images',
                '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $styles['table'] . '">'
                . '<thead><tr>'
                . '<th style="' . $styles['th'] . '">Document</th>'
                . '<th style="' . $styles['th'] . '">Uploaded By</th>'
                . '<th style="' . $styles['th'] . '">Timestamp</th>'
                . '</tr></thead><tbody>' . $rows . '</tbody></table>');
        }
    }

    return ''
        . panel_html('1. Candidate Entered Details', $enteredHtml)
        . panel_html('2. Component Workflow', workflow_component_table($wf))
        . $docsHtml
        . panel_html('4. Agent Remarks / Verification Notes', remarks_table($remarks))
        . panel_html('5. Final Status', '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="' . $styles['table'] . '"><tr><td style="padding:4px 2px;">' . status_chip_html($status) . '</td></tr></table>');
}

function resolve_local_path(array $doc): ?string {
    $file = trim((string)($doc['file_path'] ?? ''));
    if ($file === '') return null;
    if (is_probably_remote_non_upload_url($file)) return null;

    $full = resolveUploadFile($file, $doc);
    if ($full && is_file((string)$full)) return (string)$full;
    return null;
}

function is_pdf_file(string $path, array $doc): bool {
    $mime = strtolower((string)($doc['mime_type'] ?? ''));
    return str_contains($mime, 'pdf') || (bool)preg_match('/\.pdf$/i', $path);
}

function is_image_file(string $path, array $doc): bool {
    $mime = strtolower((string)($doc['mime_type'] ?? ''));
    return str_starts_with($mime, 'image/') || (bool)preg_match('/\.(png|jpe?g|gif|webp|bmp|tiff?)$/i', $path);
}

function register_tmp_cleanup_once(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    if (!isset($GLOBALS['gss_mpdf_tmp']) || !is_array($GLOBALS['gss_mpdf_tmp'])) $GLOBALS['gss_mpdf_tmp'] = [];
    register_shutdown_function(static function (): void {
        $tmp = $GLOBALS['gss_mpdf_tmp'] ?? [];
        foreach ((array)$tmp as $f) if (is_string($f) && is_file($f)) @unlink($f);
    });
}

function add_tmp_file(string $p): void {
    register_tmp_cleanup_once();
    $GLOBALS['gss_mpdf_tmp'][] = $p;
}

function png_has_alpha_channel(string $filePath): bool {
    $fh = @fopen($filePath, 'rb');
    if (!$fh) return false;
    $header = @fread($fh, 33);
    if (!is_string($header) || strlen($header) < 33) {
        @fclose($fh);
        return false;
    }
    if (substr($header, 0, 8) !== "\x89PNG\x0d\x0a\x1a\x0a" || substr($header, 12, 4) !== 'IHDR') {
        @fclose($fh);
        return false;
    }
    $colorType = ord($header[25]);
    if ($colorType === 4 || $colorType === 6) {
        @fclose($fh);
        return true;
    }
    @fseek($fh, 33);
    for ($i = 0; $i < 20; $i++) {
        $lenRaw = @fread($fh, 4);
        $type = @fread($fh, 4);
        if (!is_string($lenRaw) || strlen($lenRaw) < 4 || !is_string($type) || strlen($type) < 4) break;
        $len = unpack('N', $lenRaw);
        $chunkLen = (int)($len[1] ?? 0);
        if ($type === 'tRNS') {
            @fclose($fh);
            return true;
        }
        if ($chunkLen < 0 || $chunkLen > (50 * 1024 * 1024)) break;
        @fseek($fh, $chunkLen + 4, SEEK_CUR);
        if ($type === 'IDAT' || $type === 'IEND') break;
    }
    @fclose($fh);
    return false;
}

function convert_image_to_jpeg_imagick(string $sourcePath, bool $needResize, int $maxWidth): ?string {
    if (!class_exists('Imagick')) return null;
    try {
        $im = new Imagick($sourcePath);
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
        $im->setImageBackgroundColor('white');
        $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        if ($needResize) {
            $w = (int)$im->getImageWidth();
            if ($w > $maxWidth) {
                $im->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1);
            }
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(GSS_SAFE_JPEG_QUALITY);
        $tmp = tempnam(sys_get_temp_dir(), 'gss_tcpdf_im_');
        if (!$tmp) {
            $im->clear();
            $im->destroy();
            return null;
        }
        $jpg = $tmp . '.jpg';
        @unlink($tmp);
        if (!$im->writeImage($jpg)) {
            $im->clear();
            $im->destroy();
            return null;
        }
        $im->clear();
        $im->destroy();
        add_tmp_file($jpg);
        return $jpg;
    } catch (Throwable $e) {
        log_pdf('Imagick image conversion failed: ' . basename($sourcePath) . ' err=' . $e->getMessage());
        return null;
    }
}

function convert_image_to_jpeg_ghostscript(string $sourcePath): ?string {
    if (!is_file($sourcePath)) return null;
    $gsExe = ghostscript_executable_path();
    $isDirectFile = ($gsExe !== '' && is_file($gsExe));
    if ($gsExe === '' || (!$isDirectFile && !shell_cmd_available($gsExe))) return null;
    if (!shell_exec_enabled()) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'gss_tcpdf_gsimg_');
    if ($tmp === false) return null;
    $jpg = $tmp . '.jpg';
    @unlink($tmp);

    $gsQuoted = $isDirectFile ? ('"' . str_replace('"', '\"', $gsExe) . '"') : escapeshellcmd($gsExe);
    $cmd = $gsQuoted
        . ' -dSAFER -dBATCH -dNOPAUSE'
        . ' -sDEVICE=jpeg'
        . ' -dJPEGQ=' . (int)GSS_SAFE_JPEG_QUALITY
        . ' -dFirstPage=1 -dLastPage=1'
        . ' -sOutputFile=' . escapeshellarg($jpg)
        . ' ' . escapeshellarg($sourcePath)
        . ' 2>&1';

    $run = run_command_with_timeout($cmd, 45);
    if (!is_file($jpg) || (int)@filesize($jpg) <= 0) {
        @unlink($jpg);
        log_pdf('Ghostscript image conversion failed: ' . basename($sourcePath) . ' code=' . (int)($run['code'] ?? -1) . ' output=' . trim((string)($run['output'] ?? '')));
        return null;
    }
    add_tmp_file($jpg);
    log_pdf('image converted via Ghostscript: ' . basename($sourcePath));
    return $jpg;
}

function safe_image_path(string $sourcePath): string {
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        log_pdf('Image not readable: ' . $sourcePath);
        return '';
    }
    if (!isset($GLOBALS['gss_mpdf_img_cache']) || !is_array($GLOBALS['gss_mpdf_img_cache'])) $GLOBALS['gss_mpdf_img_cache'] = [];
    $key = @md5_file($sourcePath) ?: sha1($sourcePath);
    if (isset($GLOBALS['gss_mpdf_img_cache'][$key]) && is_file($GLOBALS['gss_mpdf_img_cache'][$key])) {
        log_pdf('duplicate image reused: ' . basename($sourcePath));
        return $GLOBALS['gss_mpdf_img_cache'][$key];
    }

    $imgInfo = @getimagesize($sourcePath);
    if (!is_array($imgInfo)) {
        log_pdf('image skipped: getimagesize failed file=' . basename($sourcePath));
        $GLOBALS['gss_mpdf_img_cache'][$key] = '';
        return '';
    }
    [$w, $h, $type] = $imgInfo;
    $ext = strtolower((string)pathinfo($sourcePath, PATHINFO_EXTENSION));
    if (($ext === 'jpg' || $ext === 'jpeg') && (int)$w <= GSS_SAFE_IMAGE_MAX_WIDTH) {
        $GLOBALS['gss_mpdf_img_cache'][$key] = $sourcePath;
        return $sourcePath;
    }
    $out = $sourcePath;
    $isPng = $ext === 'png' || $type === IMAGETYPE_PNG;
    $pngAlpha = $isPng ? png_has_alpha_channel($sourcePath) : false;
    $mustConvertForTcpdf = ($isPng && $pngAlpha) || in_array($ext, ['webp','gif'], true);
    $needResize = $w > GSS_SAFE_IMAGE_MAX_WIDTH;
    $gdAvailable = function_exists('imagecreatetruecolor');

    if ($isPng && !$pngAlpha && !$needResize) {
        log_pdf('image keep-as-is: png without alpha ' . basename($sourcePath));
        $GLOBALS['gss_mpdf_img_cache'][$key] = $out;
        return $out;
    }

    if (($mustConvertForTcpdf || $needResize) && $gdAvailable) {
        $src = null;
        if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) $src = @imagecreatefromjpeg($sourcePath);
        elseif ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) $src = @imagecreatefrompng($sourcePath);
        elseif ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) $src = @imagecreatefromwebp($sourcePath);
        elseif ($type === IMAGETYPE_GIF && function_exists('imagecreatefromgif')) $src = @imagecreatefromgif($sourcePath);

        if ($src !== false && $src !== null) {
            $ratio = $needResize ? min(GSS_SAFE_IMAGE_MAX_WIDTH / max(1, $w), 1.0) : 1.0;
            $nw = max(1, (int)floor(max(1, $w) * $ratio));
            $nh = max(1, (int)floor(max(1, $h) * $ratio));
            $dst = @imagecreatetruecolor($nw, $nh);
            if ($dst !== false) {
                $white = imagecolorallocate($dst, 255, 255, 255);
                @imagefill($dst, 0, 0, $white);
                @imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, max(1, (int)$w), max(1, (int)$h));
                $tmp = tempnam(sys_get_temp_dir(), 'gss_tcpdf_img_');
                if ($tmp) {
                    $jpg = $tmp . '.jpg';
                    @unlink($tmp);
                    if (@imagejpeg($dst, $jpg, GSS_SAFE_JPEG_QUALITY)) {
                        $out = $jpg;
                        add_tmp_file($jpg);
                        if ($mustConvertForTcpdf) log_pdf('alpha/risky image converted to jpeg: ' . basename($sourcePath));
                        elseif ($needResize) log_pdf('image resized: ' . basename($sourcePath));
                    }
                }
                @imagedestroy($dst);
            }
            @imagedestroy($src);
        }
    } elseif (($mustConvertForTcpdf || $needResize) && class_exists('Imagick')) {
        $img = convert_image_to_jpeg_imagick($sourcePath, $needResize, GSS_SAFE_IMAGE_MAX_WIDTH);
        if ($img !== null && is_file($img)) {
            $out = $img;
            log_pdf('image converted via Imagick: ' . basename($sourcePath));
        } elseif ($mustConvertForTcpdf && GSS_SKIP_ALPHA_PNG_IF_NO_LIB) {
            log_pdf('skipping alpha/risky image (Imagick convert failed): ' . basename($sourcePath) . ' reason=TCPDF requires GD/Imagick for alpha PNG');
            $GLOBALS['gss_mpdf_img_cache'][$key] = '';
            return '';
        }
    } elseif (($mustConvertForTcpdf || $needResize)) {
        $img = convert_image_to_jpeg_ghostscript($sourcePath);
        if ($img !== null && is_file($img)) {
            $out = $img;
        } elseif ($mustConvertForTcpdf && GSS_SKIP_ALPHA_PNG_IF_NO_LIB) {
            log_pdf('skipping alpha/risky image (no GD/Imagick/Ghostscript conversion): ' . basename($sourcePath));
            $GLOBALS['gss_mpdf_img_cache'][$key] = '';
            return '';
        }
    } elseif ($mustConvertForTcpdf) {
        if (GSS_SKIP_ALPHA_PNG_IF_NO_LIB) {
            log_pdf('skipping alpha/risky image (GD unavailable): ' . basename($sourcePath) . ' reason=TCPDF requires GD/Imagick for alpha PNG');
            $GLOBALS['gss_mpdf_img_cache'][$key] = '';
            return '';
        }
    }

    $GLOBALS['gss_mpdf_img_cache'][$key] = $out;
    return $out;
}

function image_resource_from_path(string $path) {
    $info = @getimagesize($path);
    if (!is_array($info)) return null;
    $type = (int)($info[2] ?? 0);
    if ($type === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) return @imagecreatefromjpeg($path);
    if ($type === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) return @imagecreatefrompng($path);
    if ($type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) return @imagecreatefromwebp($path);
    return null;
}

function crop_sparse_whitespace_image(string $path): ?string {
    if (!is_file($path) || !is_readable($path) || !function_exists('imagecolorat') || !function_exists('imagecrop')) return null;
    $img = image_resource_from_path($path);
    if ($img === null) return null;

    $w = (int)imagesx($img);
    $h = (int)imagesy($img);
    if ($w <= 0 || $h <= 0) {
        imagedestroy($img);
        return null;
    }

    $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
    $step = 3;
    $threshold = 246;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $rgb = (int)imagecolorat($img, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            if ($r < $threshold || $g < $threshold || $b < $threshold) {
                if ($x < $minX) $minX = $x;
                if ($y < $minY) $minY = $y;
                if ($x > $maxX) $maxX = $x;
                if ($y > $maxY) $maxY = $y;
            }
        }
    }

    if ($maxX < 0 || $maxY < 0) {
        imagedestroy($img);
        return null;
    }

    $pad = 8;
    $minX = max(0, $minX - $pad);
    $minY = max(0, $minY - $pad);
    $maxX = min($w - 1, $maxX + $pad);
    $maxY = min($h - 1, $maxY + $pad);
    $cropW = ($maxX - $minX + 1);
    $cropH = ($maxY - $minY + 1);
    if ($cropW <= 0 || $cropH <= 0) {
        imagedestroy($img);
        return null;
    }

    $crop = @imagecrop($img, ['x' => $minX, 'y' => $minY, 'width' => $cropW, 'height' => $cropH]);
    imagedestroy($img);
    if ($crop === false || $crop === null) return null;

    $tmp = tempnam(sys_get_temp_dir(), 'gss_crop_');
    if ($tmp === false) {
        imagedestroy($crop);
        return null;
    }
    $out = $tmp . '.jpg';
    @unlink($tmp);
    if (!@imagejpeg($crop, $out, GSS_SAFE_JPEG_QUALITY)) {
        imagedestroy($crop);
        return null;
    }
    imagedestroy($crop);
    add_tmp_file($out);
    return $out;
}

function merge_images_vertical(string $firstPath, string $secondPath): ?string {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecopy')) return null;
    $img1 = image_resource_from_path($firstPath);
    $img2 = image_resource_from_path($secondPath);
    if ($img1 === null || $img2 === null) {
        if ($img1 !== null) imagedestroy($img1);
        if ($img2 !== null) imagedestroy($img2);
        return null;
    }

    $w1 = (int)imagesx($img1); $h1 = (int)imagesy($img1);
    $w2 = (int)imagesx($img2); $h2 = (int)imagesy($img2);
    if ($w1 <= 0 || $h1 <= 0 || $w2 <= 0 || $h2 <= 0) {
        imagedestroy($img1); imagedestroy($img2);
        return null;
    }

    $targetW = max($w1, $w2);
    $gap = 20;
    $targetH = $h1 + $gap + $h2;
    $canvas = imagecreatetruecolor($targetW, $targetH);
    if ($canvas === false) {
        imagedestroy($img1); imagedestroy($img2);
        return null;
    }

    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $img1, (int)(($targetW - $w1) / 2), 0, 0, 0, $w1, $h1);
    imagecopy($canvas, $img2, (int)(($targetW - $w2) / 2), $h1 + $gap, 0, 0, $w2, $h2);

    $tmp = tempnam(sys_get_temp_dir(), 'gss_merge_');
    if ($tmp === false) {
        imagedestroy($canvas); imagedestroy($img1); imagedestroy($img2);
        return null;
    }
    $out = $tmp . '.jpg';
    @unlink($tmp);
    $ok = @imagejpeg($canvas, $out, GSS_SAFE_JPEG_QUALITY);
    imagedestroy($canvas);
    imagedestroy($img1);
    imagedestroy($img2);
    if (!$ok) return null;
    add_tmp_file($out);
    return $out;
}

function shell_cmd_available(string $cmd): bool {
    $check = stripos(PHP_OS, 'WIN') === 0 ? ('where ' . escapeshellarg($cmd)) : ('command -v ' . escapeshellarg($cmd));
    $out = @shell_exec($check . ' 2>&1');
    return is_string($out) && trim($out) !== '';
}

function shell_exec_enabled(): bool {
    if (!function_exists('shell_exec')) return false;
    $disabled = (string)ini_get('disable_functions');
    if ($disabled === '') return true;
    $items = array_map('trim', explode(',', strtolower($disabled)));
    return !in_array('shell_exec', $items, true);
}

function run_command_with_timeout(string $cmd, int $timeoutSec = 60): array {
    if (!function_exists('proc_open')) {
        $out = (string)@shell_exec($cmd);
        return ['code' => 0, 'output' => $out, 'timed_out' => false];
    }

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['code' => 1, 'output' => 'proc_open failed', 'timed_out' => false];
    }
    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $start = microtime(true);
    $output = '';
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);
        $output .= (string)stream_get_contents($pipes[1]);
        $output .= (string)stream_get_contents($pipes[2]);

        if (!$status['running']) {
            break;
        }
        if ((microtime(true) - $start) >= $timeoutSec) {
            $timedOut = true;
            @proc_terminate($proc, 9);
            break;
        }
        usleep(150000);
    }

    $output .= (string)stream_get_contents($pipes[1]);
    $output .= (string)stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $code = (int)proc_close($proc);

    return ['code' => $code, 'output' => $output, 'timed_out' => $timedOut];
}

function ghostscript_executable_path(): string {
    $cfg = trim((string)env_get('GHOSTSCRIPT_EXE', ''));
    if ($cfg !== '' && is_file($cfg)) return $cfg;
    if ($cfg !== '' && shell_cmd_available($cfg)) return $cfg;

    // If ghostscript is available on PATH, prefer that portable command.
    foreach (['gs', 'gswin64c', 'gswin32c', 'gswin64c.exe', 'gswin32c.exe'] as $cmd) {
        if (shell_cmd_available($cmd)) return $cmd;
    }

    $patterns = [
        'C:\\Program Files\\gs\\*\\bin\\gswin64c.exe',
        'C:\\Program Files\\gs\\*\\bin\\gswin32c.exe',
        'C:\\Program Files (x86)\\gs\\*\\bin\\gswin64c.exe',
        'C:\\Program Files (x86)\\gs\\*\\bin\\gswin32c.exe',
    ];
    $found = [];
    foreach ($patterns as $pat) {
        $matches = glob($pat) ?: [];
        foreach ($matches as $m) {
            if (is_file($m)) $found[] = $m;
        }
    }
    if ($found) {
        natsort($found);
        $latest = end($found);
        if (is_string($latest) && $latest !== '') return $latest;
    }

    return GSS_GHOSTSCRIPT_EXE_DEFAULT;
}

function convert_pdf_via_ghostscript(string $pdfPath): array {
    if (!is_file($pdfPath)) return [];

    $gsExe = ghostscript_executable_path();
    log_pdf('Ghostscript resolved executable: ' . $gsExe);
    $isDirectFile = ($gsExe !== '' && is_file($gsExe));
    if ($gsExe === '' || (!$isDirectFile && !shell_cmd_available($gsExe))) {
        log_pdf('Ghostscript executable not found: ' . $gsExe);
        return [];
    }
    if (!shell_exec_enabled()) {
        log_pdf('shell_exec is disabled; cannot convert PDF: ' . basename($pdfPath));
        return [];
    }

    $tmp = tempnam(sys_get_temp_dir(), 'gss_gs_');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create temp file for Ghostscript conversion.');
    }
    @unlink($tmp);
    $prefix = $tmp . '_page';
    $outPattern = $prefix . '-%03d.jpg';

    $gsQuoted = $isDirectFile ? ('"' . str_replace('"', '\"', $gsExe) . '"') : escapeshellcmd($gsExe);
    $cmd = $gsQuoted
        . ' -dSAFER -dBATCH -dNOPAUSE'
        . ' -sDEVICE=jpeg'
        . ' -dJPEGQ=' . (int)GSS_SAFE_JPEG_QUALITY
        . ' -r150'
        . ' -dFirstPage=1'
        . ' -dLastPage=' . (int)GSS_MAX_SOURCE_PDF_PAGES
        . ' -sOutputFile=' . escapeshellarg($outPattern)
        . ' ' . escapeshellarg($pdfPath)
        . ' 2>&1';

    log_pdf('Ghostscript command: ' . $cmd);
    $run = run_command_with_timeout($cmd, 60);
    $debug = (string)($run['output'] ?? '');
    $exitCode = (int)($run['code'] ?? -1);
    $timedOut = (bool)($run['timed_out'] ?? false);
    $files = glob($prefix . '-*.jpg') ?: [];
    natsort($files);

    if (!$files) {
        log_pdf('Ghostscript conversion failed for ' . basename($pdfPath) . ($timedOut ? ' (timeout)' : '') . ' code=' . $exitCode . ' output=' . trim($debug));
        return [];
    }

    $out = [];
    foreach ($files as $f) {
        if (!is_file($f)) continue;
        $out[] = $f;
        add_tmp_file($f);
    }
    log_pdf('Ghostscript converted ' . basename($pdfPath) . ' -> ' . count($out) . ' page image(s), code=' . $exitCode);
    return $out;
}

function convert_pdf_to_images(string $pdfPath): array {
    return convert_pdf_via_ghostscript($pdfPath);
}

function convertPdfToImages(string $pdfPath): array {
    return convert_pdf_to_images($pdfPath);
}

function doc_merge_key(array $r): string {
    $id = trim((string)($r['id'] ?? ''));
    if ($id !== '' && $id !== '0') return 'id:' . $id;
    $parts = [
        strtolower(trim((string)($r['application_id'] ?? ''))),
        strtolower(trim((string)($r['doc_type'] ?? $r['__component'] ?? ''))),
        strtolower(trim((string)($r['file_path'] ?? ''))),
        strtolower(trim((string)($r['original_name'] ?? ''))),
        strtolower(trim((string)($r['uploaded_by_role'] ?? ''))),
        strtolower(trim((string)($r['uploaded_by_name'] ?? $r['uploaded_by_username'] ?? ''))),
        trim((string)($r['created_at'] ?? '')),
        strtolower(trim((string)($r['source_field'] ?? ''))),
    ];
    return 'row:' . implode('|', $parts);
}

function merge_docs(array $a, array $b): array {
    $out = [];
    $seen = [];
    foreach ([$a, $b] as $rows) {
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $k = doc_merge_key($r);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $r;
        }
    }
    log_pdf('merge_docs result count=' . count($out));
    return $out;
}

function is_placeholder_doc_value(string $v): bool {
    $s = strtolower(trim($v));
    return in_array($s, ['file path', 'original name', 'document', 'document name', 'name', 'path'], true);
}

function is_invalid_doc_row(array $doc): bool {
    $fp = trim((string)($doc['file_path'] ?? ''));
    $on = trim((string)($doc['original_name'] ?? ''));
    if ($fp === '' && $on === '') return true;
    if (is_placeholder_doc_value($fp) || is_placeholder_doc_value($on)) return true;
    // CSV/header-like rows
    if (strtolower($fp) === 'file_path' || strtolower($on) === 'original_name') return true;
    return false;
}

function normalize_doc_path_for_dedupe(array $doc): string {
    $fp = str_replace('\\', '/', trim((string)($doc['file_path'] ?? '')));
    if ($fp === '') return '';
    if (preg_match('~^https?://~i', $fp)) {
        $parts = parse_url($fp);
        $fp = str_replace('\\', '/', (string)($parts['path'] ?? ''));
    }
    $fp = preg_replace('/[?#].*$/', '', $fp) ?? $fp;
    $fp = ltrim($fp, '/');
    if ($fp === '') return '';
    $resolved = resolveUploadFile($fp, $doc);
    if (is_string($resolved) && is_file($resolved)) return strtolower(str_replace('\\', '/', $resolved));
    return strtolower($fp);
}

function prune_unknown_shadow_docs(array $docs): array {
    $preferredByPath = [];
    foreach ($docs as $doc) {
        if (!is_array($doc)) continue;
        if (is_invalid_doc_row($doc)) continue;
        $norm = normalize_doc_path_for_dedupe($doc);
        if ($norm === '') continue;
        $role = strtolower(trim((string)($doc['uploaded_by_role'] ?? '')));
        $source = strtolower(trim((string)($doc['source_field'] ?? '')));
        $isStrong = ($role !== '' && $role !== 'unknown') || ($source !== 'fs_fallback');
        if ($isStrong) $preferredByPath[$norm] = true;
    }

    $out = [];
    foreach ($docs as $doc) {
        if (!is_array($doc)) continue;
        if (is_invalid_doc_row($doc)) {
            log_pdf('doc dropped: invalid/header-like row file_path="' . (string)($doc['file_path'] ?? '') . '" original="' . (string)($doc['original_name'] ?? '') . '"');
            continue;
        }

        $norm = normalize_doc_path_for_dedupe($doc);
        $role = strtolower(trim((string)($doc['uploaded_by_role'] ?? '')));
        $source = strtolower(trim((string)($doc['source_field'] ?? '')));
        $isUnknownFallback = ($role === '' || $role === 'unknown') && $source === 'fs_fallback';

        if ($isUnknownFallback && $norm !== '' && isset($preferredByPath[$norm])) {
            log_pdf('doc dropped: unknown fallback duplicate of known record path=' . $norm);
            continue;
        }
        $out[] = $doc;
    }
    log_pdf('prune_unknown_shadow_docs before=' . count($docs) . ' after=' . count($out));
    return $out;
}

function appendix_doc_key(array $doc, ?string $resolvedPath = null): string {
    $id = trim((string)($doc['id'] ?? ''));
    if ($id !== '' && $id !== '0') return 'id:' . $id;
    $parts = [
        strtolower(trim((string)($doc['application_id'] ?? ''))),
        strtolower(trim((string)($doc['doc_type'] ?? $doc['__component'] ?? ''))),
        strtolower(trim((string)($doc['file_path'] ?? ''))),
        strtolower(trim((string)($doc['original_name'] ?? ''))),
        strtolower(trim((string)($doc['uploaded_by_role'] ?? ''))),
        strtolower(trim((string)($doc['uploaded_by_name'] ?? $doc['uploaded_by_username'] ?? ''))),
        trim((string)($doc['created_at'] ?? '')),
        strtolower(trim((string)($resolvedPath ?? ''))),
    ];
    return 'row:' . implode('|', $parts);
}

function derive_docs(array $d, string $applicationId): array {
    $out = [];
    $add = static function (string $type, string $path, string $name, string $createdAt, string $sourceField) use (&$out, $applicationId): void {
        $path = trim($path);
        if ($path === '') return;
        $out[] = [
            'id' => 0,
            'application_id' => $applicationId,
            'doc_type' => $type,
            'file_path' => $path,
            'original_name' => $name,
            'mime_type' => '',
            'uploaded_by_role' => 'candidate',
            'uploaded_by_name' => '',
            'uploaded_by_username' => '',
            'created_at' => $createdAt,
            'source_field' => $sourceField,
        ];
    };
    foreach ((array)($d['identification'] ?? []) as $row) if (is_array($row)) $add('id', (string)($row['upload_document'] ?? $row['document_file'] ?? $row['file_path'] ?? ''), (string)($row['documentId_type'] ?? 'Identification Document'), (string)($row['created_at'] ?? ''), 'upload_document');
    foreach ((array)($d['education'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $q = (string)($row['qualification'] ?? 'Education');
        $add('education', (string)($row['marksheet_file'] ?? ''), trim($q . ' Marksheet'), (string)($row['created_at'] ?? ''), 'marksheet_file');
        $add('education', (string)($row['degree_file'] ?? ''), trim($q . ' Degree'), (string)($row['created_at'] ?? ''), 'degree_file');
    }
    foreach ((array)($d['employment'] ?? []) as $row) if (is_array($row)) {
        $n = (string)($row['employer_name'] ?? 'Employment');
        $add('employment', (string)($row['employment_doc'] ?? $row['document_file'] ?? $row['proof_file'] ?? ''), trim($n . ' Employment Proof'), (string)($row['created_at'] ?? ''), 'employment_doc');
    }
    $contact = is_array($d['contact'] ?? null) ? $d['contact'] : [];
    $add('contact', (string)($contact['proof_file'] ?? $contact['address_proof_file'] ?? $contact['address_proof'] ?? $contact['proof_document'] ?? ''), 'Address Proof', (string)($contact['created_at'] ?? ''), 'proof_file');
    $auth = is_array($d['authorization'] ?? null) ? $d['authorization'] : [];
    $add('reports', (string)($auth['file_name'] ?? $auth['authorization_file_name'] ?? $auth['auth_file_name'] ?? ''), 'Authorization', (string)($auth['uploaded_at'] ?? $auth['created_at'] ?? ''), 'authorization_file');
    $basic = is_array($d['basic'] ?? null) ? $d['basic'] : [];
    $add('basic', (string)($basic['profile_photo'] ?? $basic['photo_path'] ?? $basic['photo'] ?? $basic['candidate_photo'] ?? ''), 'Profile Photo', (string)($basic['created_at'] ?? ''), 'photo_path');

    $scanNode = static function ($node, string $section, string $createdAt = '', string $labelPrefix = '') use (&$scanNode, &$add): void {
        if (is_array($node)) {
            $nodeCreated = $createdAt;
            if (isset($node['created_at']) && is_scalar($node['created_at'])) {
                $nodeCreated = (string)$node['created_at'];
            }
            foreach ($node as $k => $v) {
                $key = (string)$k;
                if (is_array($v) || is_object($v)) {
                    $scanNode($v, $section, $nodeCreated, $labelPrefix);
                    continue;
                }
                if (!is_scalar($v)) continue;
                $sv = trim((string)$v);
                if ($sv === '' || strtoupper($sv) === 'INSUFFICIENT_DOCUMENTS') continue;
                // Only derive from known document-bearing fields.
                // Prevents noisy entries from metadata labels like "Original Name" / "File Path".
                if (!is_document_field_name($key)) continue;
                if (!looks_like_file_ref($sv)) continue;

                $title = trim(($labelPrefix !== '' ? $labelPrefix . ' ' : '') . ucwords(str_replace('_', ' ', $key)));
                if ($title === '') $title = 'Document';
                $add(normalize_component($section), $sv, $title, $nodeCreated, $key);
            }
        } elseif (is_object($node)) {
            $scanNode((array)$node, $section, $createdAt, $labelPrefix);
        }
    };

    foreach ($d as $section => $payload) {
        if (!is_array($payload) && !is_object($payload)) continue;
        $normalizedSection = normalize_component((string)$section);
        // Skip sections that are already explicit document collections/system blocks.
        if (in_array($normalizedSection, ['uploaded_docs', 'component_workflow', 'workflow', 'case', 'application', 'timeline'], true)) {
            continue;
        }
        $scanNode($payload, normalize_component((string)$section));
    }

    log_pdf('derive_docs result count=' . count($out));
    return $out;
}

function infer_component_from_path(string $path): string {
    $p = strtolower(str_replace('\\', '/', $path));
    if (str_contains($p, '/uploads/identification/')) return 'id';
    if (str_contains($p, '/uploads/address/')) return 'contact';
    if (str_contains($p, '/uploads/education/')) return 'education';
    if (str_contains($p, '/uploads/employment/')) return 'employment';
    if (str_contains($p, '/uploads/candidate_photos/')) return 'basic';
    if (str_contains($p, '/uploads/ecourt/')) return 'ecourt';
    if (str_contains($p, '/uploads/verification/')) return 'reports';
    return 'general';
}

function discover_docs_from_filesystem(string $applicationId): array {
    $app = trim($applicationId);
    if ($app === '') return [];
    $roots = upload_roots();
    $out = [];
    $seen = [];
    $subDirs = ['identification','address','education','employment','candidate_photos','verification','ecourt','documents','photos'];

    foreach ($roots as $root) {
        foreach ($subDirs as $sub) {
            $dir = rtrim($root, '/') . '/' . $sub;
            if (!is_dir($dir)) continue;
            $files = glob($dir . '/*' . $app . '*') ?: [];
            foreach ($files as $f) {
                $f = str_replace('\\', '/', $f);
                if (!is_file($f)) continue;
                $k = strtolower($f);
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $rel = str_replace('\\', '/', str_replace(rtrim((string)app_path('/'), '/\\') . '/', '', $f));
                $out[] = [
                    'id' => 0,
                    'application_id' => $app,
                    'doc_type' => infer_component_from_path($f),
                    'file_path' => $rel !== '' ? $rel : $f,
                    'original_name' => basename($f),
                    'mime_type' => (string)(@mime_content_type($f) ?: ''),
                    'uploaded_by_role' => 'unknown',
                    'uploaded_by_name' => '',
                    'uploaded_by_username' => '',
                    'created_at' => @date('Y-m-d H:i:s', (int)(@filemtime($f) ?: time())),
                    'source_field' => 'fs_fallback',
                ];
            }
        }
    }
    log_pdf('discover_docs_from_filesystem app=' . $app . ' count=' . count($out));
    return $out;
}

function doc_basename(array $doc): string {
    $fp = trim((string)($doc['file_path'] ?? ''));
    $name = trim((string)($doc['original_name'] ?? ''));
    $base = $fp !== '' ? basename(str_replace('\\', '/', $fp)) : $name;
    return strtolower(trim($base));
}

function remarks_for_component(array $timelineRows, string $component): array {
    $c = normalize_component($component);
    return array_values(array_filter($timelineRows, static function ($r) use ($c) {
        if (!is_array($r)) return false;
        return normalize_component((string)($r['section_key'] ?? '')) === $c;
    }));
}

function component_docs(array $docs, string $component): array {
    $c = normalize_component($component);
    return array_values(array_filter($docs, static function ($d) use ($c) {
        return is_array($d) && normalize_component((string)($d['component'] ?? $d['__component'] ?? '')) === $c;
    }));
}

function component_title(string $k): string {
    return match (normalize_component($k)) {
        'basic' => 'Basic Verification',
        'contact' => 'Address Verification',
        'id' => 'Identification Verification',
        'employment' => 'Employment Verification',
        'education' => 'Education Verification',
        'reference' => 'Reference Verification',
        'social' => 'Social Media Verification',
        'ecourt', 'database' => 'eCourt / Database Verification',
        'reports' => 'Reports Verification',
        default => ucwords(str_replace('_', ' ', normalize_component($k))),
    };
}

function looks_like_hashish_name(string $name): bool {
    $n = trim($name);
    if ($n === '') return false;
    $base = pathinfo($n, PATHINFO_FILENAME);
    return (bool)preg_match('/^[a-f0-9]{32,}$/i', $base);
}

function canonical_component_doc_label(string $component): string {
    $label = trim(component_title($component));
    if ($label === '') $label = 'Document';
    return $label . ' Document';
}

function normalize_uploader_string(array $doc): string {
    $roleRaw = trim((string)($doc['uploaded_by_role'] ?? ''));
    if (strtolower($roleRaw) === 'unknown') $roleRaw = '';
    $name = trim((string)($doc['uploaded_by_name'] ?? ''));
    $role = $roleRaw !== '' ? ucwords(str_replace('_', ' ', strtolower($roleRaw))) : '';
    if ($role !== '' && $name !== '') return $role . ' (' . $name . ')';
    if ($role !== '') return $role;
    return 'Candidate';
}

function normalize_timestamp_string(array $doc): string {
    $raw = trim((string)($doc['created_at'] ?? ''));
    if ($raw === '') $raw = trim((string)($doc['uploaded_at'] ?? ''));
    if ($raw === '') return '-';
    $ts = @strtotime($raw);
    if ($ts === false) return $raw;
    return date('d-M-Y h:i A', $ts);
}

function normalize_display_name(array $doc, string $component): string {
    $filePath = trim((string)($doc['file_path'] ?? ''));
    $candidates = [
        trim((string)($doc['document_name'] ?? '')),
        trim((string)($doc['file_name'] ?? '')),
        trim((string)($doc['label'] ?? '')),
        trim((string)($doc['proof_type'] ?? '')),
        trim((string)($doc['title'] ?? '')),
        trim((string)($doc['original_name'] ?? '')),
        $filePath !== '' ? basename(str_replace('\\', '/', $filePath)) : '',
    ];
    foreach ($candidates as $name) {
        if ($name === '') continue;
        if (looks_like_hashish_name($name)) continue;
        return $name;
    }
    return canonical_component_doc_label($component);
}

function normalize_document_row(array $doc): array {
    $component = normalize_component((string)($doc['component'] ?? $doc['__component'] ?? $doc['doc_type'] ?? 'general'));
    if ($component === '') $component = 'general';
    $displayName = normalize_display_name($doc, $component);
    $uploadedBy = normalize_uploader_string($doc);
    $timestamp = normalize_timestamp_string($doc);
    $absolutePath = resolve_local_path($doc);

    $doc['component'] = $component;
    $doc['display_name'] = $displayName;
    $doc['uploaded_by'] = $uploadedBy;
    $doc['timestamp'] = $timestamp;
    $doc['absolute_path'] = $absolutePath !== null ? $absolutePath : '';
    return $doc;
}

function normalize_documents(array $docs): array {
    $out = [];
    foreach ($docs as $doc) {
        if (!is_array($doc)) continue;
        $out[] = normalize_document_row($doc);
    }
    return $out;
}

function build_document_title(array $doc, string $filePath): string {
    $componentKey = (string)($doc['component'] ?? $doc['__component'] ?? $doc['doc_type'] ?? 'general');
    $name = basename((string)$filePath);
    if ($name === '') $name = trim((string)($doc['display_name'] ?? 'Document'));
    return component_title($componentKey) . ' - ' . $name;
}

function build_document_metadata(array $doc): string {
    $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
    if ($uploadedBy === '') $uploadedBy = normalize_uploader_string($doc);
    $timestamp = trim((string)($doc['timestamp'] ?? ''));
    if ($timestamp === '') $timestamp = normalize_timestamp_string($doc);
    $meta = 'Uploaded By: ' . $uploadedBy;
    $meta .= ' | Timestamp: ' . ($timestamp !== '' ? $timestamp : '-');
    return $meta;
}

function render_cover_page(GSSReportPDF $pdf, string $applicationId, string $candidateName): void {
    $pdf->setDecorators(false, false);
    $pdf->AddPage();

    $logoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/gss-logo.svg');
    if (!is_file($logoPath)) {
        $logoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/vatih.png');
    }
    if (!is_file($logoPath)) {
        $logoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/vati.png');
    }
    if (!is_file($logoPath)) {
        $logoPath = str_replace('\\', '/', __DIR__ . '/../../uploads/logo.png');
    }
    if (is_file($logoPath)) {
        $logoW = 34.0;
        $x = ($pdf->getPageWidth() - $logoW) / 2;
        $ext = strtolower((string)pathinfo($logoPath, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            $pdf->ImageSVG($logoPath, $x, 20, $logoW, 0, '', '', '', 0, true);
        } else {
            $pdf->Image($logoPath, $x, 20, $logoW, 0, '', '', '', false, 150, '', false, false, 0, false, false, false);
        }
    }

    $pdf->SetY(54);
    $pdf->SetFont('helvetica', 'B', 22);
    $pdf->SetTextColor(15, 58, 102);
    $pdf->Cell(0, 10, 'Candidate Verification Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 11);
    $pdf->SetTextColor(75, 93, 115);
    $pdf->Cell(0, 6, 'Professional Screening & Evidence Summary', 0, 1, 'C');

    $pdf->Ln(10);
    $meta = '<table border="1" cellpadding="8" cellspacing="0" width="78%" align="center" style="font-family:helvetica,sans-serif;font-size:10pt;color:#0b2239;border-color:#d9e2ec;background-color:#f8fbff;">'
        . '<tr><td width="38%" style="color:#4b5d73;"><b>Application ID</b></td><td>' . h($applicationId) . '</td></tr>'
        . '<tr><td width="38%" style="color:#4b5d73;"><b>Candidate Name</b></td><td>' . h($candidateName !== '' ? $candidateName : '-') . '</td></tr>'
        . '<tr><td width="38%" style="color:#4b5d73;"><b>Generated Date</b></td><td>' . h(date('Y-m-d H:i:s')) . '</td></tr>'
        . '</table>';
    safeWriteHTML($pdf, $meta);

    $pdf->Ln(6);
    $pdf->SetFont('helvetica', 'I', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 6, 'Confidential – For authorized use only', 0, 1, 'C');

    $pdf->setDecorators(true, true);
}

function workflow_node_status(array $node): string {
    $qa = strtolower((string)($node['qa']['status'] ?? ''));
    $ve = strtolower((string)($node['verifier']['status'] ?? ''));
    $va = strtolower((string)($node['validator']['status'] ?? ''));
    $ca = strtolower((string)($node['candidate']['status'] ?? ''));
    if ($qa === 'approved') return 'VERIFIED';
    if ($qa === 'rejected' || $ve === 'rejected' || $va === 'rejected' || $ca === 'rejected') return 'FAILED';
    return 'PENDING';
}

function verification_summary_table_html(array $workflow): string {
    $s = t_styles();
    $rows = '';
    foreach ($workflow as $component => $node) {
        if (!is_array($node)) continue;
        $status = workflow_node_status($node);
        $rows .= '<tr>'
            . '<td style="' . $s['td'] . '">' . h(component_title((string)$component)) . '</td>'
            . '<td style="' . $s['td'] . '">' . status_chip_html($status) . '</td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td style="' . $s['td'] . '" colspan="2">No workflow data available.</td></tr>';
    }
    return '<table border="1" cellpadding="0" cellspacing="0" width="100%" style="' . $s['table'] . '">'
        . '<thead><tr><th style="' . $s['th'] . '">Component</th><th style="' . $s['th'] . '">Status</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>';
}

function render_signature_page(GSSReportPDF $pdf): void {
    $pdf->AddPage();
    $s = t_styles();

    $html = sec_title_dark_html('Final Attestation')
        . '<div style="' . $s['panel'] . '">'
        . '<table border="0" cellpadding="6" cellspacing="0" width="100%" style="' . $s['table'] . '">'
        . '<tr><td width="35%" style="' . $s['td_key'] . '"><b>Verified By</b></td><td style="' . $s['td'] . '">_____________________</td></tr>'
        . '<tr><td width="35%" style="' . $s['td_key'] . '"><b>Authorized Signature</b></td><td style="' . $s['td'] . '">_____________________</td></tr>'
        . '<tr><td width="35%" style="' . $s['td_key'] . '"><b>Date</b></td><td style="' . $s['td'] . '">_____________________</td></tr>'
        . '<tr><td width="35%" style="' . $s['td_key'] . '"><b>Company Seal</b></td><td style="' . $s['td'] . '">&nbsp;</td></tr>'
        . '</table>'
        . '</div>';
    safeWriteHTML($pdf, $html);

    $cx = $pdf->getPageWidth() / 2;
    $cy = $pdf->GetY() + 20;
    if ($cy > ($pdf->getPageHeight() - 18)) {
        $pdf->AddPage();
        $cy = 90;
    }
    $pdf->SetDrawColor(148, 163, 184);
    $pdf->SetLineWidth(0.5);
    $pdf->Ellipse($cx, $cy, 28, 12, 0, 0, 360);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($cx - 24, $cy - 3);
    $pdf->Cell(48, 6, 'VERIFIED DOCUMENT', 0, 0, 'C');
    $pdf->SetTextColor(11, 34, 57);
}

function safeWriteHTML(TCPDF $pdf, string $html): void {
    $html = trim($html);
    if ($html === '') return;
    $pdf->writeHTML($html, true, false, true, false, '');
}

function write_section(TCPDF $pdf, string $title, string $html, bool $forceNewPage = true, bool $darkTitle = false): void {
    if (!$forceNewPage && $pdf->getPage() > 0 && $pdf->GetY() > 30) {
        add_section_spacing($pdf, GSS_TABLE_TOP_GAP);
    }

    if ($forceNewPage || $pdf->getPage() <= 0) {
        $pdf->AddPage();
    } else {
        add_table_spacing($pdf);
    }

    if ($pdf->GetY() < 20.0) {
        $pdf->SetY(20.0);
    }

    $title = trim($title);
    $titleHtml = '';
    if ($title !== '') {
        $titleHtml = $darkTitle ? sec_title_dark_html($title) : sec_title_html($title);
        safeWriteHTML($pdf, $titleHtml);
        $pdf->Ln(3);
    }

    $block = '<div style="' . t_styles()['sheet'] . '">' . $html . '</div>';
    safeWriteHTML($pdf, $block);
    add_table_spacing($pdf);
}

function write_appendix_header($pdf, $counter, $component, $filePath, $uploadedBy, $timestamp, $pageNum = null) {
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(11, 34, 57);
    $header = 'Appendix A' . $counter . ' | ' . $component;
    if ($pageNum) $header .= ' | Page ' . $pageNum;
    $pdf->SetFillColor(236, 243, 250);
    $pdf->SetDrawColor(186, 200, 214);
    $pdf->Cell(0, 7, $header, 1, 1, 'L', true);

    $uploadedBy = trim((string)$uploadedBy);
    if ($uploadedBy === '') $uploadedBy = 'Candidate';
    $ts = trim((string)$timestamp);
    if ($ts === '') {
        $ts = '-';
    } else {
        $parsed = strtotime($ts);
        if ($parsed !== false) $ts = date('d M Y H:i', $parsed);
    }

    $pdf->SetFont('helvetica', '', 7.8);
    $pdf->SetTextColor(90, 105, 125);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->Cell(0, 5, 'Uploaded By: ' . $uploadedBy . ' | Uploaded On: ' . $ts, 1, 1, 'L', true);
    $pdf->SetTextColor(11, 34, 57);
    $pdf->Ln(0.8);
}

function render_appendix_image_page(TCPDF $pdf, string $imgPath, array $doc, int $appendixCounter, string $component): void {
    if (!is_file($imgPath)) {
        log_pdf('render_appendix_image_page skipped: file not found: ' . $imgPath);
        return;
    }

    $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
    if ($uploadedBy === '') $uploadedBy = 'Candidate';
    $uploadedAt = trim((string)($doc['timestamp'] ?? ''));
    if ($uploadedAt === '') $uploadedAt = '-';

    $fileLabel = basename((string)($doc['file_path'] ?? $imgPath));
    if ($fileLabel === '') $fileLabel = basename($imgPath);
    if (strlen($fileLabel) > 60) {
        $fileLabel = substr($fileLabel, 0, 57) . '...';
    }

    $pdf->AddPage();
    $pdf->SetMargins(12, 24, 12);
    $pdf->SetY(24);
    write_appendix_header($pdf, $appendixCounter, component_title($component), $fileLabel, $uploadedBy, $uploadedAt);
    if ($pdf->GetY() > ($pdf->getPageHeight() - 50)) {
        $pdf->AddPage();
        $pdf->SetMargins(12, 24, 12);
        $pdf->SetY(24);
        write_appendix_header($pdf, $appendixCounter, component_title($component), $fileLabel, $uploadedBy, $uploadedAt);
    }

    $margins = $pdf->getMargins();
    $left = (float)($margins['left'] ?? 10);
    $right = (float)($margins['right'] ?? 10);
    $x = $left;
    $y = $pdf->GetY();
    $edgeTrim = GSS_APPENDIX_IMAGE_TRIM_MM;
    $maxW = (float)$pdf->getPageWidth() - $left - $right - $edgeTrim;
    $maxH = (float)$pdf->getPageHeight() - $y - 12 - $edgeTrim - GSS_APPENDIX_IMAGE_BOTTOM_RESERVE_MM;
    if ($maxW <= 0 || $maxH <= 0) {
        return;
    }

    $size = @getimagesize($imgPath);
    if (!is_array($size) || (int)($size[0] ?? 0) <= 0 || (int)($size[1] ?? 0) <= 0) {
        try {
            $pdf->Image($imgPath, $x, $y, $maxW, 0, '', '', '', false, 150, '', false, false, 0, false, false, false);
            log_pdf('render_appendix_image_page: getimagesize failed, attempted fallback embed for ' . $imgPath);
        } catch (Throwable $e) {
            log_pdf('render_appendix_image_page failed: ' . basename($imgPath) . ' err=' . $e->getMessage());
            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 5, 'Image could not be embedded: ' . basename($imgPath), 0, 'L', false, 1);
        }
        return;
    }

    $iw = (float)$size[0];
    $ih = (float)$size[1];
    $scale = min($maxW / $iw, $maxH / $ih, 1.0);
    $drawW = max(20.0, floor($iw * $scale) - $edgeTrim);
    $drawH = max(20.0, floor($ih * $scale) - $edgeTrim);
    $drawX = $left + (($maxW - $drawW) / 2);

    try {
        $pdf->Image($imgPath, $drawX, $y, $drawW, $drawH, '', '', '', false, 150, '', false, false, 0, false, false, false);
    } catch (Throwable $e) {
        log_pdf('render_appendix_image_page failed: ' . basename($imgPath) . ' err=' . $e->getMessage());
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(0, 5, 'Image could not be embedded: ' . basename($imgPath), 0, 'L', false, 1);
    }
}

function render_appendix_skip_page(TCPDF $pdf, string $title, string $metadata, string $reason, string $filePath, int $appendixCounter, string $component, array $doc = []): void {
    $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
    if ($uploadedBy === '') $uploadedBy = 'Candidate';
    $uploadedAt = trim((string)($doc['timestamp'] ?? ''));
    if ($uploadedAt === '') $uploadedAt = '-';
    $fileLabel = basename($filePath);
    if ($fileLabel === '') $fileLabel = trim((string)($doc['display_name'] ?? 'Document'));
    if (strlen($fileLabel) > 60) {
        $fileLabel = substr($fileLabel, 0, 57) . '...';
    }

    $pdf->AddPage();
    $pdf->SetMargins(12, 24, 12);
    $pdf->SetY(24);
    write_appendix_header($pdf, $appendixCounter, component_title($component), $fileLabel, $uploadedBy, $uploadedAt);

    $s = t_styles();

    $html = '<table border="1" cellpadding="4" cellspacing="0" width="100%" style="' . $s['table'] . '">'
        . '<tr><td style="' . $s['td_key'] . '"><b>Document Not Embedded</b></td></tr>'
        . '<tr><td style="' . $s['td'] . '">' . h($reason) . '</td></tr>';
    if ($metadata !== '') {
        $html .= '<tr><td style="' . $s['td'] . '"><b>Metadata:</b> ' . h($metadata) . '</td></tr>';
    }
    if ($filePath !== '') {
        $html .= '<tr><td style="' . $s['td'] . '"><b>Stored File Path:</b> ' . h($filePath) . '</td></tr>';
    }
    $html .= '</table>';
    safeWriteHTML($pdf, $html);
}

function render_appendix_attachment(TCPDF $pdf, string $title, string $filePath, array $doc, int $appendixCounter, string $component): int {
    $added = 0;
    $metadata = build_document_metadata($doc);
    $storedPath = (string)($doc['file_path'] ?? '');
    $uploadedBy = trim((string)($doc['uploaded_by'] ?? ''));
    if ($uploadedBy === '') $uploadedBy = 'Candidate';
    $uploadedAt = trim((string)($doc['timestamp'] ?? ''));
    if ($uploadedAt === '') $uploadedAt = '-';
    $fileLabel = basename($filePath);
    if ($fileLabel === '') $fileLabel = trim((string)($doc['display_name'] ?? 'Document'));
    if (strlen($fileLabel) > 60) {
        $fileLabel = substr($fileLabel, 0, 57) . '...';
    }
    $renderDocHeader = function (?int $pageNum = null) use ($pdf, $appendixCounter, $component, $fileLabel, $uploadedBy, $uploadedAt): void {
        $pdf->SetMargins(12, 24, 12);
        $pdf->SetY(24);
        write_appendix_header($pdf, $appendixCounter, component_title($component), $fileLabel, $uploadedBy, $uploadedAt, $pageNum);
        if ($pdf->GetY() > ($pdf->getPageHeight() - 50)) {
            $pdf->AddPage();
            $pdf->SetMargins(12, 24, 12);
            $pdf->SetY(24);
            write_appendix_header($pdf, $appendixCounter, component_title($component), $fileLabel, $uploadedBy, $uploadedAt, $pageNum);
        }
    };

    if (is_pdf_file($filePath, $doc)) {
        $renderPdfFallback = function () use ($pdf, $filePath, $doc, $appendixCounter, $component, &$added): bool {
            $pages = convertPdfToImages($filePath);
            if (!$pages) {
                return false;
            }
            $idx = 1;
            $embedded = false;
            foreach ($pages as $pageImgPath) {
                $safe = safe_image_path((string)$pageImgPath);
                if (!is_file($safe)) {
                    $idx++;
                    continue;
                }
                render_appendix_image_page($pdf, $safe, $doc, $appendixCounter, $component);
                $added++;
                $embedded = true;
                $idx++;
            }
            return $embedded;
        };

        if (!method_exists($pdf, 'setSourceFile') || !method_exists($pdf, 'importPage') || !method_exists($pdf, 'useTemplate')) {
            if ($renderPdfFallback()) {
                return $added;
            }
            render_appendix_skip_page($pdf, $title, $metadata, 'Document could not be embedded.', $storedPath, $appendixCounter, $component, $doc);
            return $added + 1;
        }

        try {
            $fileContent = @file_get_contents($filePath, false, null, 0, 1024);
            if (!is_string($fileContent) || strpos($fileContent, '%PDF') === false) {
                log_pdf('Invalid PDF header: ' . $filePath);
                return 0;
            }
            $pageCount = (int)$pdf->setSourceFile($filePath);
            if ($pageCount <= 0) {
                if ($renderPdfFallback()) {
                    return $added;
                }
                render_appendix_skip_page($pdf, $title, $metadata, 'Document could not be embedded.', $storedPath, $appendixCounter, $component, $doc);
                return $added + 1;
            }
            if ($pageCount === 2) {
                $jpgPages = convertPdfToImages($filePath);
                if (is_array($jpgPages) && count($jpgPages) >= 2) {
                    $p1 = safe_image_path((string)$jpgPages[0]);
                    $p2 = safe_image_path((string)$jpgPages[1]);
                    if (is_file($p1) && is_file($p2)) {
                        $dim1 = @getimagesize($p1);
                        $cropped2 = crop_sparse_whitespace_image($p2);
                        if (is_file((string)$cropped2)) {
                            $dim2c = @getimagesize($cropped2);
                            $h1 = (int)($dim1[1] ?? 0);
                            $h2c = (int)($dim2c[1] ?? 0);
                            // If page 2 is only a tiny tail, merge it into page 1 and keep one appendix page.
                            if ($h1 > 0 && $h2c > 0 && $h2c <= (int)($h1 * 0.22)) {
                                $merged = merge_images_vertical($p1, $cropped2);
                                if (is_file((string)$merged)) {
                                    render_appendix_image_page($pdf, $merged, $doc, $appendixCounter, $component);
                                    return $added + 1;
                                }
                            }
                        }
                        // Fallback when merge/crop is unavailable: if page 2 is a sparse tail, keep only page 1.
                        $s1 = (int)@filesize($p1);
                        $s2 = (int)@filesize($p2);
                        if ($s1 > 0 && $s2 > 0) {
                            $ratio = $s2 / max(1, $s1);
                            if ($ratio <= 0.33) {
                                log_pdf('tail page suppressed for 2-page PDF: ' . basename($filePath) . ' ratio=' . round($ratio, 3));
                                render_appendix_image_page($pdf, $p1, $doc, $appendixCounter, $component);
                                return $added + 1;
                            }
                        }
                    }
                }
                // Final deterministic fallback: avoid spill-page behavior by rendering only page 1.
                try {
                    $tplId = $pdf->importPage(1);
                    $tplSize = $pdf->getTemplateSize($tplId);
                    $srcW = (float)($tplSize['width'] ?? 0);
                    $srcH = (float)($tplSize['height'] ?? 0);
                    if ($srcW > 0 && $srcH > 0) {
                        $pdf->AddPage('P', 'A4');
                        $pdf->SetMargins(12, 24, 12);
                        $renderDocHeader(1);
                        $added++;
                        $m = $pdf->getMargins();
                        $left = (float)($m['left'] ?? 12);
                        $right = (float)($m['right'] ?? 12);
                        $bottom = (float)($m['bottom'] ?? 12);
                        $x = $left;
                        $y = (float)$pdf->GetY();
                        $edgeTrim = GSS_APPENDIX_IMAGE_TRIM_MM;
                        $maxW = (float)$pdf->getPageWidth() - $left - $right - $edgeTrim;
                        $maxH = (float)$pdf->getPageHeight() - $bottom - $y - $edgeTrim - GSS_APPENDIX_IMAGE_BOTTOM_RESERVE_MM;
                        if ($maxW > 0 && $maxH > 0) {
                            $scale = min($maxW / $srcW, $maxH / $srcH, 1.0);
                            if ($scale > 0) {
                                $drawW = max(20.0, ($srcW * $scale) - $edgeTrim);
                                $drawH = max(20.0, ($srcH * $scale) - $edgeTrim);
                                $drawX = $x + (($maxW - $drawW) / 2);
                                $drawY = $y;
                                $pdf->useTemplate($tplId, $drawX, $drawY, $drawW, $drawH, false);
                                log_pdf('forced first-page-only render for 2-page PDF: ' . basename($filePath));
                                return $added;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    log_pdf('first-page-only fallback failed for 2-page PDF: ' . basename($filePath) . ' err=' . $e->getMessage());
                }
            }
            if ($pageCount === 1) {
                $jpgPages = convertPdfToImages($filePath);
                if (is_array($jpgPages) && isset($jpgPages[0])) {
                    $jpgPath = safe_image_path((string)$jpgPages[0]);
                    if (is_file($jpgPath)) {
                        render_appendix_image_page($pdf, $jpgPath, $doc, $appendixCounter, $component);
                        return $added + 1;
                    }
                }
            }

            $m = $pdf->getMargins();
            $left = (float)($m['left'] ?? 12);
            $right = (float)($m['right'] ?? 12);
            $bottom = (float)($m['bottom'] ?? 12);

            for ($idx = 1; $idx <= $pageCount; $idx++) {
                $tplId = $pdf->importPage($idx);
                $tplSize = $pdf->getTemplateSize($tplId);
                $srcW = (float)($tplSize['width'] ?? 0);
                $srcH = (float)($tplSize['height'] ?? 0);
                if ($srcW <= 0 || $srcH <= 0) {
                    continue;
                }

                $pdf->AddPage('P', 'A4');
                $pdf->SetMargins(12, 24, 12);
                $renderDocHeader($idx);
                $added++;
                $x = $left;
                $y = (float)$pdf->GetY();
                $edgeTrim = GSS_APPENDIX_IMAGE_TRIM_MM;
                $maxW = (float)$pdf->getPageWidth() - $left - $right - $edgeTrim;
                $maxH = (float)$pdf->getPageHeight() - $bottom - $y - $edgeTrim - GSS_APPENDIX_IMAGE_BOTTOM_RESERVE_MM;

                if ($maxW <= 0 || $maxH <= 0) {
                    continue;
                }

                $scale = min($maxW / $srcW, $maxH / $srcH, 1.0);
                if ($scale <= 0) {
                    continue;
                }
                $drawW = max(20.0, ($srcW * $scale) - $edgeTrim);
                $drawH = max(20.0, ($srcH * $scale) - $edgeTrim);
                $drawX = $x + (($maxW - $drawW) / 2);
                $drawY = $y;

                $pdf->useTemplate($tplId, $drawX, $drawY, $drawW, $drawH, false);
            }

            return $added;
        } catch (Throwable $e) {
            if ($renderPdfFallback()) {
                return $added;
            }
            render_appendix_skip_page($pdf, $title, $metadata, 'Document could not be embedded.', $storedPath, $appendixCounter, $component, $doc);
            return $added + 1;
        }
    }

    if (is_image_file($filePath, $doc)) {
        if (!is_readable($filePath)) {
            render_appendix_skip_page($pdf, $title, $metadata, 'Image file is not readable by server.', $storedPath, $appendixCounter, $component, $doc);
            return $added + 1;
        }

        $safe = safe_image_path($filePath);
        if (!is_file($safe)) {
            render_appendix_skip_page($pdf, $title, $metadata, 'Image conversion not possible on server.', $storedPath, $appendixCounter, $component, $doc);
            return $added + 1;
        }

        $size = @getimagesize($safe);
        if (!$size || !isset($size[0], $size[1]) || (float)$size[0] <= 0 || (float)$size[1] <= 0) {
            render_appendix_skip_page($pdf, $title, $metadata, 'Image dimensions could not be read.', $storedPath, $appendixCounter, $component, $doc);
            return $added + 1;
        }

        $pdf->AddPage();
        $pdf->SetMargins(12, 24, 12);
        $renderDocHeader();
        $added++;

        $m = $pdf->getMargins();
        $left = (float)($m['left'] ?? 12);
        $right = (float)($m['right'] ?? 12);
        $top = (float)$pdf->GetY();
        $bottom = (float)($m['bottom'] ?? 12);

        $pageW = (float)$pdf->getPageWidth();
        $pageH = (float)$pdf->getPageHeight();
        $edgeTrim = GSS_APPENDIX_IMAGE_TRIM_MM;
        $maxW = $pageW - $left - $right - $edgeTrim;
        $maxH = $pageH - $top - $bottom - $edgeTrim - GSS_APPENDIX_IMAGE_BOTTOM_RESERVE_MM;
        if ($maxW <= 0 || $maxH <= 0) {
            return $added;
        }

        $iw = (float)$size[0];
        $ih = (float)$size[1];
        $scale = min($maxW / $iw, $maxH / $ih, 1.0);
        $drawW = max(20.0, ($iw * $scale) - $edgeTrim);
        $drawH = max(20.0, ($ih * $scale) - $edgeTrim);
        $drawX = $left + (($maxW - $drawW) / 2);
        $drawY = $top + (($maxH - $drawH) / 2);

        $pdf->Image($safe, $drawX, $drawY, $drawW, $drawH, '', '', '', false, 150, '', false, false, 0, false, false, false);
        return $added;
    }

    render_appendix_skip_page($pdf, $title, $metadata, 'Unsupported evidence format for embedding.', $storedPath, $appendixCounter, $component, $doc);
    return $added + 1;
}

function sort_docs_for_appendix(array $docs): array {
    $compOrder = [
        'basic' => 0,
        'id' => 1,
        'contact' => 2,
        'education' => 3,
        'employment' => 4,
        'ecourt' => 5,
        'reference' => 6,
        'reports' => 7,
    ];
    $roleOrder = [
        'candidate' => 0,
        'validator' => 1,
        'verifier' => 2,
        'qa' => 3,
    ];

    usort($docs, static function ($a, $b) use ($compOrder, $roleOrder) {
        $compA = normalize_component((string)($a['__component'] ?? $a['doc_type'] ?? 'general'));
        $compB = normalize_component((string)($b['__component'] ?? $b['doc_type'] ?? 'general'));
        $compScoreA = $compOrder[$compA] ?? 99;
        $compScoreB = $compOrder[$compB] ?? 99;
        if ($compScoreA !== $compScoreB) return $compScoreA <=> $compScoreB;

        $roleA = strtolower(trim((string)($a['uploaded_by_role'] ?? '')));
        $roleB = strtolower(trim((string)($b['uploaded_by_role'] ?? '')));
        $roleScoreA = $roleOrder[$roleA] ?? 99;
        $roleScoreB = $roleOrder[$roleB] ?? 99;
        if ($roleScoreA !== $roleScoreB) return $roleScoreA <=> $roleScoreB;

        $tsA = strtotime((string)($a['created_at'] ?? ''));
        $tsB = strtotime((string)($b['created_at'] ?? ''));
        if ($tsA === false && $tsB === false) return 0;
        if ($tsA === false) return 1;
        if ($tsB === false) return -1;
        return $tsA <=> $tsB;
    });
    return $docs;
}

try {
    $applicationId = q('application_id');
    $clientId = (int)q('client_id', '0');
    $inline = q('inline', '0') === '1';
    if ($applicationId === '') throw new InvalidArgumentException('application_id is required');

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $qsClient = $clientId > 0 ? ('&client_id=' . urlencode((string)$clientId)) : '';
    $reportPayload = api_get_json('/api/shared/candidate_report_get.php?role=gss_admin&application_id=' . urlencode($applicationId) . $qsClient);
    $docsPayload = api_get_json('/api/shared/verification_docs_list.php?application_id=' . urlencode($applicationId));
    $timelinePayload = api_get_json('/api/shared/case_timeline_list.php?application_id=' . urlencode($applicationId) . '&limit=200' . $qsClient);

    if ((int)($reportPayload['status'] ?? 0) !== 1 || !is_array($reportPayload['data'] ?? null)) {
        throw new RuntimeException((string)($reportPayload['message'] ?? 'Unable to load report data'));
    }

    $data = $reportPayload['data'];
    $reportDocs = is_array($data['uploaded_docs'] ?? null) ? $data['uploaded_docs'] : [];
    $verificationDocs = ((int)($docsPayload['status'] ?? 0) === 1 && is_array($docsPayload['data'] ?? null)) ? $docsPayload['data'] : [];
    $docs = merge_docs(merge_docs($verificationDocs, $reportDocs), derive_docs($data, $applicationId));
    $primaryCount = count($docs);
    $needsFallbackNames = [];
    $unresolvedSeedByBase = [];
    if ($primaryCount > 0) {
        foreach ($docs as $d) {
            if (!is_array($d)) continue;
            if (resolve_local_path($d) !== null) continue;
            $b = doc_basename($d);
            if ($b !== '') {
                $needsFallbackNames[$b] = true;
                if (!isset($unresolvedSeedByBase[$b])) {
                    $unresolvedSeedByBase[$b] = $d;
                }
            }
        }
    }

    if ($primaryCount === 0 || count($needsFallbackNames) > 0) {
        $fsDocs = discover_docs_from_filesystem($applicationId);
        if ($primaryCount > 0 && count($needsFallbackNames) > 0) {
            $fsDocs = array_values(array_filter($fsDocs, static function ($d) use ($needsFallbackNames): bool {
                if (!is_array($d)) return false;
                $b = doc_basename($d);
                return $b !== '' && isset($needsFallbackNames[$b]);
            }));
            foreach ($fsDocs as &$fd) {
                if (!is_array($fd)) continue;
                $b = doc_basename($fd);
                $seed = is_array($unresolvedSeedByBase[$b] ?? null) ? $unresolvedSeedByBase[$b] : null;
                if ($seed === null) continue;
                // Preserve authoritative metadata from unresolved primary row.
                if (trim((string)($seed['doc_type'] ?? '')) !== '') $fd['doc_type'] = (string)$seed['doc_type'];
                if (trim((string)($seed['original_name'] ?? '')) !== '') $fd['original_name'] = (string)$seed['original_name'];
                if (trim((string)($seed['mime_type'] ?? '')) !== '') $fd['mime_type'] = (string)$seed['mime_type'];
                if (trim((string)($seed['uploaded_by_role'] ?? '')) !== '') $fd['uploaded_by_role'] = (string)$seed['uploaded_by_role'];
                if (trim((string)($seed['uploaded_by_name'] ?? '')) !== '') $fd['uploaded_by_name'] = (string)$seed['uploaded_by_name'];
                if (trim((string)($seed['uploaded_by_username'] ?? '')) !== '') $fd['uploaded_by_username'] = (string)$seed['uploaded_by_username'];
                if (trim((string)($seed['created_at'] ?? '')) !== '') $fd['created_at'] = (string)$seed['created_at'];
                if (trim((string)($seed['source_field'] ?? '')) !== '') $fd['source_field'] = (string)$seed['source_field'];
            }
            unset($fd);
            log_pdf('using selective filesystem fallback for unresolved doc basenames=' . count($needsFallbackNames) . ' matched_fs=' . count($fsDocs));
        } else {
            log_pdf('using filesystem doc fallback because primary doc sources returned 0 rows');
        }
        $docs = merge_docs($docs, $fsDocs);
    } else {
        log_pdf('skipping filesystem doc fallback because all primary docs resolved');
    }
    $docs = prune_unknown_shadow_docs($docs);
    foreach ($docs as &$d) if (is_array($d)) $d['__component'] = infer_component($d);
    unset($d);
    // Remove obvious non-evidence URLs
    $docs = array_values(array_filter($docs, static function ($d): bool {
        if (!is_array($d)) return false;
        $fp = (string)($d['file_path'] ?? '');
        if ($fp === '') return false;
        if (is_probably_remote_non_upload_url($fp)) return false;
        return true;
    }));
    $docs = normalize_documents($docs);
    log_pdf('docs after remote-url filter=' . count($docs));
    $timelineRows = ((int)($timelinePayload['status'] ?? 0) === 1 && is_array($timelinePayload['data'] ?? null)) ? $timelinePayload['data'] : [];

    $pdf = new GSSReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('VATI GSS');
    $pdf->SetAuthor('VATI GSS');
    $pdf->SetTitle('Candidate Verification Report');
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    $pdf->SetMargins(12, 30, 12);
    $pdf->SetHeaderMargin(2);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->setFontSubsetting(false);
    $pdf->SetFont('helvetica', '', 9);
    $headerLogoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/gss-logo.svg');
    if (!is_file($headerLogoPath)) {
        $headerLogoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/vatih.png');
    }
    if (!is_file($headerLogoPath)) {
        $headerLogoPath = str_replace('\\', '/', __DIR__ . '/../../assets/img/vati.png');
    }
    if (!is_file($headerLogoPath)) {
        $headerLogoPath = str_replace('\\', '/', __DIR__ . '/../../uploads/logo.png');
    }
    $pdf->setHeaderBrand($headerLogoPath, 'Candidate Verification Report');

    $case = is_array($data['case'] ?? null) ? $data['case'] : [];
    $app = is_array($data['application'] ?? null) ? $data['application'] : [];
    $workflow = is_array($data['component_workflow'] ?? null) ? $data['component_workflow'] : [];

    $candidateName = trim((string)($case['candidate_first_name'] ?? '') . ' ' . (string)($case['candidate_last_name'] ?? ''));
    if ($candidateName === '') {
        $basic = is_array($data['basic'] ?? null) ? $data['basic'] : [];
        $candidateName = trim((string)($basic['first_name'] ?? '') . ' ' . (string)($basic['last_name'] ?? ''));
    }

    render_cover_page($pdf, $applicationId, $candidateName);
    write_section($pdf, 'Verification Summary', verification_summary_table_html($workflow), true, true);

    $summaryHtml = report_header_html($applicationId, (string)($case['case_id'] ?? '-'))
        . render_summary_block_html([
        'Candidate Name' => $candidateName !== '' ? $candidateName : '-',
        'Application ID' => $applicationId,
        'Case ID' => (string)($case['case_id'] ?? '-'),
        'Client ID' => (string)($case['client_id'] ?? $clientId ?: '-'),
        'Generated At' => date('Y-m-d H:i:s'),
    ]);
    $overviewHtml = ''
        . $summaryHtml
        . panel_html('Case Details', table_from_assoc($case))
        . panel_html('Application Details', table_from_assoc($app))
        . panel_html('Component Workflow', workflow_summary_table_html($workflow));
    write_section($pdf, '', $overviewHtml, true);

    $components = [
        ['basic', (array)($data['basic'] ?? [])],
        ['contact', (array)($data['contact'] ?? [])],
        ['id', (array)($data['identification'] ?? [])],
        ['employment', (array)($data['employment'] ?? [])],
        ['education', (array)($data['education'] ?? [])],
        ['reference', (array)($data['reference'] ?? [])],
        ['social', (array)($data['social'] ?? $data['socialmedia'] ?? [])],
        ['ecourt', (array)($data['ecourt'] ?? []) + (array)($data['database'] ?? [])],
        ['reports', (array)($data['authorization'] ?? [])],
    ];
    foreach ($components as [$key, $entered]) {
        $wf = is_array($workflow[$key] ?? null) ? $workflow[$key] : [];
        $remarks = remarks_for_component($timelineRows, (string)$key);
        $compDocs = component_docs($docs, (string)$key);
        $html = component_section_html(component_title((string)$key), $entered, $wf, $remarks, $compDocs);
        write_section($pdf, component_title((string)$key), $html, true, true);
    }

    // Sort documents for a clean appendix
    $sortedDocs = sort_docs_for_appendix($docs);
    $pages = 0;
    $appendixCounter = 1;
    foreach ($sortedDocs as $doc) {
        try {
            if (!is_array($doc)) continue;
            log_pdf('doc start: file_path="' . (string)($doc['file_path'] ?? '') . '" original="' . (string)($doc['original_name'] ?? '') . '" mime="' . (string)($doc['mime_type'] ?? '') . '"');
            $path = trim((string)($doc['absolute_path'] ?? ''));
            if ($path === '' || !is_file($path)) {
                $path = resolve_local_path($doc);
            }
            if ($path === null || !is_file($path)) {
                log_pdf('doc skipped: unresolved path file_path="' . (string)($doc['file_path'] ?? '') . '" original="' . (string)($doc['original_name'] ?? '') . '"');
                continue;
            }
            log_pdf('doc resolved: ' . $path . ' type=' . (is_pdf_file($path, $doc) ? 'pdf' : (is_image_file($path, $doc) ? 'image' : 'other')));
            if ($pages >= GSS_MAX_TOTAL_EVIDENCE_PAGES) break;
            $title = build_document_title($doc, $path);
            $size = @filesize($path);
            if (is_int($size) && $size > GSS_MAX_FILE_BYTES) {
                log_pdf('doc skipped: too large file=' . basename($path) . ' size=' . $size);
                continue;
            }

            $remaining = GSS_MAX_TOTAL_EVIDENCE_PAGES - $pages;
            if ($remaining <= 0) break;

            $appendixTitle = 'Appendix A' . $appendixCounter . ' - ' . $title;
            $componentKey = (string)($doc['component'] ?? $doc['__component'] ?? $doc['doc_type'] ?? 'general');
            $doc['component'] = $componentKey;
            $added = render_appendix_attachment($pdf, $appendixTitle, $path, $doc, $appendixCounter, $componentKey);
            if ($added <= 0) {
                log_pdf('doc skipped: render_appendix_attachment added=0 file=' . basename($path));
                continue;
            }
            $appendixCounter++;
            $pages += $added;
            log_pdf('doc rendered: ' . basename($path) . ' pages_added=' . $added . ' total_pages=' . $pages);
            if ($pages >= GSS_MAX_TOTAL_EVIDENCE_PAGES) break;
        } catch (Throwable $docEx) {
            log_pdf('doc render exception: file_path="' . (string)($doc['file_path'] ?? '') . '" err=' . $docEx->getMessage());
            continue;
        }
    }

    if ($pages === 0) {
        write_section($pdf, 'Final Documents Appendix', '<table border="1" cellpadding="6" cellspacing="0" width="100%"><tr><td>No evidence documents could be embedded.</td></tr></table>');
    }
    render_signature_page($pdf);

    $safeAppId = preg_replace('/[^A-Za-z0-9\-_]/', '_', $applicationId) ?: 'NA';
    $file = 'GSS-Candidate-Report-' . $safeAppId . '.pdf';
    while (ob_get_level() > 0) @ob_end_clean();
    $pdf->Output($file, $inline ? 'I' : 'D');
    exit;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 0, 'message' => $e->getMessage()]);
}
