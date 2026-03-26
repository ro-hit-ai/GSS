<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/env.php';

auth_require_login('gss_admin');

$isPrint = isset($_GET['print']) && (string)$_GET['print'] === '1';

if ($isPrint) {
    $applicationId = trim((string)($_GET['application_id'] ?? ''));
    $clientId = (int)($_GET['client_id'] ?? 0);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo htmlspecialchars('Application Review - ' . ($applicationId ?: 'N/A')); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <style>
            :root{
                --ink:#0b2239;
                --muted:#4b5d73;
                --line:#cfd8e3;
                --soft:#f4f7fb;
                --brand:#0f3a66;
                --brand-soft:#e9f1fb;
                --ok:#15803d;
                --bad:#b91c1c;
                --wait:#64748b;
            }
            *{box-sizing:border-box;}
            body{margin:0; padding:20px; font:13px/1.45 "Segoe UI",Arial,sans-serif; color:var(--ink); background:#f1f5f9;}
            .report-wrap{max-width:1080px; margin:0 auto;}
            .sheet{background:#fff; border:1px solid var(--line); border-radius:12px; padding:18px; margin-bottom:14px;}
            .head{display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;}
            .brand{display:flex; align-items:center; gap:10px;}
            .brand-badge{width:38px; height:38px; border-radius:10px; background:linear-gradient(135deg,#0f766e,#0ea5e9); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:900;}
            .title{font-size:21px; font-weight:900; margin:0;}
            .sub{font-size:12px; color:var(--muted);}
            .meta{display:grid; gap:4px; font-size:12px; color:#0f172a;}
            .top-actions{display:flex; gap:8px; align-items:center; justify-content:flex-end; margin:0 0 10px;}
            .btnx{border:1px solid #94a3b8; background:#fff; color:#0f172a; border-radius:8px; padding:7px 12px; font-weight:700; font-size:12px; text-decoration:none;}
            .btnx:hover{background:#f8fafc;}
            .grid2{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px;}
            .kv{border:1px solid var(--line); border-radius:10px; padding:12px; background:var(--soft);}
            .kv .k{font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; font-weight:700;}
            .kv .v{font-size:14px; font-weight:900; color:var(--ink);}
            .sec{border:1px solid var(--line); border-radius:10px; overflow:hidden; margin-top:12px;}
            .sec-h{padding:10px 12px; background:var(--brand-soft); color:var(--brand); font-weight:900; text-transform:uppercase; letter-spacing:.06em; font-size:12px;}
            .sec-h-evidence{background:#2f5fd0; color:#fff;}
            .sec-b{padding:12px;}
            table{width:100%; border-collapse:collapse;}
            th,td{border:1px solid #e2e8f0; padding:8px; text-align:left; vertical-align:top; word-break:break-word;}
            th{background:#f8fafc; font-size:11px; text-transform:uppercase; letter-spacing:.05em;}
            .doc-grid{
              display:grid;
              grid-template-columns:repeat(auto-fill,minmax(380px,1fr));
              gap:18px;
              align-items:stretch;
            }
            .doc-card{
              display:flex;
              flex-direction:column;
              justify-content:flex-start;
              height:100%;
              border:1px solid #dbe3ed;
              border-radius:10px;
              padding:14px;
              background:#fff;
              page-break-inside:avoid;
              break-inside:avoid;
            }
            .doc-grid:has(.doc-card:only-child){
              grid-template-columns:minmax(380px,420px);
            }
            .doc-preview{
              width:100%;
              padding:20px;
              text-align:center;
            }
            .doc-preview img,
            .doc-preview canvas{
              width:100% !important;
              height:auto !important;
              max-width:100% !important;
              display:block;
              margin:auto;
            }
            .doc-preview .doc-embed-pdf{width:100%; height:100%; display:block; border:0; background:#fff;}
            .doc-preview a{color:#2563eb; text-decoration:none; font-weight:700;}
            .doc-meta{padding:8px; margin-top:10px;}
            .doc-name{font-size:12px; font-weight:800; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;}
            .doc-sub{font-size:11px; color:#64748b;}
            .doc-meta{
              margin-top:10px;
              font-size:12px;
            }
            .doc-fullpage{
                width:100%;
                text-align:center;
                margin-top:12px;
            }
            .document-page{
                width:100%;
                display:flex;
                align-items:center;
                justify-content:center;
                min-height:240mm;
                overflow:hidden;
            }
            .document-page img{
                width:auto !important;
                height:auto !important;
                max-width:100%;
                max-height:235mm;
                object-fit:contain;
                display:block;
            }
            .doc-embed-page{
                break-after:page;
                page-break-after:always;
                page-break-inside:avoid;
                break-inside:avoid;
                min-height:270mm;
                padding:6mm 4mm;
                border:1px solid #dbe3ed;
                border-radius:8px;
                background:#fff;
            }
            .doc-embed-page:last-child{
                break-after:auto;
                page-break-after:auto;
            }
            .doc-title{
                font-weight:800;
                font-size:14px;
                letter-spacing:.02em;
                margin:0 0 4px;
                color:#0f172a;
                text-transform:uppercase;
            }
            .doc-sub{
                margin-bottom:6px;
                color:#475569;
            }
            .doc-halfrow{
                page-break-before:always;
                text-align:center;
            }
            .doc-half{
                width:48%;
                display:inline-block;
                vertical-align:top;
            }
            .doc-fullpage canvas,
            .doc-fullpage img{
                width:95% !important;
                height:auto !important;
                max-width:95% !important;
                max-height:235mm !important;
                object-fit:contain;
            }
            .doc-half img{
                width:95%;
                height:auto;
                display:block;
                margin:0 auto;
            }
            .doc-card-photo{border-radius:4px; border-color:#d1d5db; box-shadow:none;}
            .doc-preview-photo{height:380px; background:#fff;}
            .doc-preview-photo img{object-fit:contain; background:#fff;}
            .doc-preview-photo canvas{background:#fff;}
            .doc-meta-photo{background:#f8fafc; border-top:1px solid #d1d5db;}
            .doc-meta-row{display:flex; justify-content:space-between; gap:10px; font-size:11px; color:#334155; margin-top:2px;}
            .chip{display:inline-flex; align-items:center; gap:6px; border:1px solid #dbe3ed; border-radius:999px; padding:4px 10px; font-size:11px; font-weight:900;}
            .ok{color:var(--ok);} .bad{color:var(--bad);} .wait{color:var(--wait);}
            .hidden{display:none !important;}
            @media (max-width:768px){
                body{padding:10px;}
                .grid2{grid-template-columns:1fr;}
                .doc-grid{grid-template-columns:1fr;}
                .doc-preview-photo{height:260px;}
            }
            @media print{
                body{padding:0; background:#fff;}
                .report-wrap{max-width:none;}
                .sheet{
                    border:1px solid #cfd8e3;
                    border-radius:0;
                    margin:0 0 8px;
                    page-break-inside:avoid;
                    box-shadow:none;
                }
                .sec{
                    border-color:#cfd8e3;
                    margin-top:8px;
                }
                .sec-h{
                    color:#0f3a66 !important;
                    background:#e9f1fb !important;
                }
                .doc-embed-page{
                    border:none;
                    border-radius:0;
                    min-height:270mm;
                    padding:4mm 2mm;
                }
                .no-print{display:none !important;}
            }
        </style>
    </head>
    <body>
    <div class="report-wrap" id="gssAdminPrintRoot"
         data-application-id="<?php echo htmlspecialchars($applicationId); ?>"
         data-client-id="<?php echo htmlspecialchars((string)$clientId); ?>">
        <div class="top-actions no-print">
            <button type="button" class="btnx" id="gssAdminPrintBtn">Print</button>
            <button type="button" class="btnx" id="gssAdminDownloadPdfBtn">Download PDF</button>
        </div>

        <div class="sheet">
            <div class="head">
                <div>
                    <div class="brand">
                        <div class="brand-badge">GSS</div>
                        <div>
                            <h1 class="title">Application Review Report</h1>
                            <div class="sub">Component-wise final report with evidence previews</div>
                        </div>
                    </div>
                </div>
                <div class="meta" id="gssAdminPrintMeta"></div>
            </div>
            <div id="gssAdminPrintSummary" class="grid2" style="margin-top:12px;"></div>
        </div>

        <div class="sheet">
            <div id="gssAdminPrintMessage" class="alert alert-info">Loading report...</div>
            <div id="gssAdminPrintContent"></div>
        </div>
    </div>

    <script>
        window.APP_BASE_URL = <?php echo json_encode(app_base_url()); ?>;
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="<?php echo htmlspecialchars(app_url('/js/modules/gss_admin/candidate_report_print.js?v=' . (string)filemtime(__DIR__ . '/../../js/modules/gss_admin/candidate_report_print.js'))); ?>"></script>
    </body>
    </html>
    <?php
    exit;
}

$menu = gss_admin_menu();

ob_start();
?>
<style id="gssFinalReportPageStyles">
    html, body{
        overflow-x:hidden;
    }
    .main{
        overflow-x:hidden;
    }
    .gss-final-report-shell{
        width:100%;
        max-width:1400px;
        margin:0 auto;
        padding:0 12px;
    }
    @media (max-width: 992px){
        .main > .gss-final-report-shell{
            max-width:100%;
            padding-left:0;
            padding-right:0;
        }
    }
    #gssFinalReportTable{
        width:100%;
        table-layout:auto;
    }
    #gssFinalReportTable th,
    #gssFinalReportTable td{
        vertical-align:middle;
        white-space:nowrap;
    }
    #gssFinalReportTable thead th,
    #gssFinalReportTable tbody td{
        padding-top: 15.5px;
        padding-bottom: 15.5px;
    }
    #gssFinalReportTable thead th,
    #gssFinalReportTable tbody td{
        padding-top: 12.5px;
        padding-bottom: 12.5px;
    }
    .gss-final-table-wrap{
        width:100%;
        overflow-x:auto;
        scrollbar-width: thin;
    }
    .gss-status-pill{
        position:relative;
    }
    .gss-status-hoverbox{
        position:fixed;
        z-index:2147483000;
        width:220px;
        max-width:220px;
        min-height:140px;
        white-space:pre-line;
        word-break:break-word;
        padding:10px 12px;
        border-radius:10px;
        border:1px solid rgba(59,130,246,0.35);
        background:linear-gradient(180deg,#0f172a,#1e293b);
        color:#e2e8f0;
        font-size:11px;
        line-height:1.35;
        box-shadow:0 10px 24px rgba(15,23,42,0.35);
        pointer-events:none;
        opacity:0;
        visibility:hidden;
        transform:translateY(4px);
        transition:opacity .12s ease, transform .12s ease, visibility .12s ease;
    }
    .gss-status-hoverbox.show{
        opacity:1;
        visibility:visible;
        transform:translateY(0);
    }
</style>
<div class="gss-final-report-shell">
<div class="card">
    <h3>Final Component Report</h3>
    <p class="card-subtitle">Case-wise component matrix with Validator (VA), Verifier (VE), and QA stage symbols.</p>
</div>

<div class="card">
    <div id="gssFinalReportMessage" style="display:none; margin-bottom:10px;"></div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:13px; margin-right:6px;">Client</label>
                <select id="gssFinalReportClientSelect" style="font-size:13px; padding:4px 6px; min-width:260px;"></select>
                <input id="gssFinalReportSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:4px 6px;">
                <button class="btn" id="gssFinalReportRefreshBtn" type="button">Refresh</button>
                <label style="font-size:13px; display:flex; align-items:center; gap:6px; margin-left:6px;">
                    <input type="checkbox" id="gssFinalReportAutoRefresh" checked>
                    Auto refresh
                </label>
                <span id="gssFinalReportLastUpdated" style="font-size:12px; color:#64748b;"></span>
            </div>
            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div id="gssFinalReportExportButtons"></div>
            </div>
        </div>
    </div>

    <div class="table-scroll gss-final-table-wrap">
        <table class="table" id="gssFinalReportTable">
            <thead>
            <tr>
                <th>Case&nbsp;ID</th>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Basic</th>
                <th>ID</th>
                <th>Contact</th>
                <th>Education</th>
                <th>Employment</th>
                <th>Reference</th>
                <th>Social Media</th>
                <th>E-Court</th>
                <th>Reports</th>
                <th>Final Status</th>
                <th>PDF</th>
                <th>Stopped&nbsp;By</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
</div>
<?php
$content = ob_get_clean();
render_layout('Final Component Report', 'GSS Admin', $menu, $content);
 
