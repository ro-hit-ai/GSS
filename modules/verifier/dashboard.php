<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

$menu = verifier_menu();

ob_start();
?>
<style>
    .vr-page{display:flex;flex-direction:column;gap:12px;}
    .vr-card{border-radius:16px;}
    .qa-like-main{padding:16px;background:linear-gradient(180deg,#f8fbff 0%,#eef4fb 100%);}
    .qa-like-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;}
    .qa-like-title{font-size:26px;font-weight:900;color:#0f172a;letter-spacing:-.03em;margin:0;}
    .qa-like-subtitle{font-size:13px;color:#5f7087;max-width:760px;margin-top:4px;}
    .qa-like-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .qa-like-chip{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(59,130,246,0.16);border-radius:999px;padding:7px 12px;font-size:12px;color:#1e3a5f;background:#ffffff;box-shadow:0 4px 14px rgba(15,23,42,0.05);}
    .qa-like-dot{width:8px;height:8px;border-radius:999px;background:#22c55e;box-shadow:0 0 0 4px rgba(34,197,94,0.12);}
    .vr-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin-top:16px;}
    .vr-kpi{border:1px solid rgba(148,163,184,0.18);border-radius:16px;padding:14px;background:rgba(255,255,255,0.9);box-shadow:0 12px 24px rgba(15,23,42,0.05);}
    .vr-kpi .n{font-size:24px;font-weight:900;color:#0f172a;line-height:1;}
    .vr-kpi .l{font-size:11px;color:#6b7c93;font-weight:800;letter-spacing:.08em;text-transform:uppercase;margin-top:6px;}
    .vr-kpi .t{font-size:12px;color:#94a3b8;margin-top:4px;}
    .vr-board-message{display:none;margin-top:12px;}
    .vr-board-note{margin-top:12px;display:none;border:1px solid rgba(59,130,246,0.16);background:#eff6ff;color:#1e40af;border-radius:12px;}
    .vr-chip-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;}
    .vr-chip{border:1px solid rgba(148,163,184,0.32);background:#fff;border-radius:999px;padding:9px 16px;font-size:12px;font-weight:900;color:#334155;cursor:pointer;transition:.16s ease;box-shadow:0 6px 16px rgba(15,23,42,0.05);}
    .vr-chip:hover{transform:translateY(-1px);border-color:rgba(96,165,250,0.45);color:#1d4ed8;}
    .vr-chip.active{background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);color:#fff;border-color:#2563eb;box-shadow:0 10px 22px rgba(37,99,235,0.24);}
    .vr-state-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
    .vr-state-chip{border:1px solid rgba(148,163,184,0.24);background:rgba(255,255,255,0.86);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:800;color:#475569;cursor:pointer;transition:.16s ease;}
    .vr-state-chip:hover{border-color:rgba(96,165,250,0.36);color:#1d4ed8;background:#fff;}
    .vr-state-chip.active{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;box-shadow:0 6px 14px rgba(59,130,246,0.12);}
    .vr-board-panel{margin-top:16px;border:1px solid rgba(148,163,184,0.2);border-radius:18px;overflow:hidden;background:rgba(255,255,255,0.94);box-shadow:0 18px 34px rgba(15,23,42,0.07);}
    .vr-board-grid{width:100%;}
    .vr-board-row{display:grid;grid-template-columns:minmax(130px,1.05fr) minmax(180px,1.3fr) minmax(220px,1.6fr) minmax(120px,.9fr) minmax(145px,1fr) minmax(165px,1.05fr) 130px;align-items:center;min-height:66px;border-top:1px solid rgba(148,163,184,0.16);}
    .vr-board-row:first-child{border-top:none;}
    .vr-board-head-row{background:linear-gradient(180deg,#f8fbff 0%,#eef5fc 100%);font-size:11px;font-weight:900;color:#64748b;letter-spacing:.08em;text-transform:uppercase;min-height:54px;}
    .vr-board-cell{padding:12px 14px;word-break:break-word;}
    .vr-board-data-row{transition:.16s ease;background:rgba(255,255,255,0.92);}
    .vr-board-data-row.is-clickable{cursor:pointer;}
    .vr-board-data-row.is-clickable:hover{background:#f8fbff;}
    .vr-board-data-row.is-mine{background:#eef6ff;}
    .vr-board-data-row.is-locked{background:#f8fafc;color:#64748b;}
    .vr-board-data-row.is-completed{background:#f5fbf7;}
    .vr-board-case{font-weight:900;color:#1d4ed8;}
    .vr-board-name{font-weight:800;color:#0f172a;}
    .vr-board-muted{font-size:12px;color:#64748b;}
    .vr-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;white-space:nowrap;border:1px solid transparent;}
    .vr-badge.pending{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
    .vr-badge.followup{background:#fff7ed;color:#c2410c;border-color:#fed7aa;}
    .vr-badge.insuff_docs{background:#fffbeb;color:#b45309;border-color:#fde68a;}
    .vr-badge.completed{background:#ecfdf3;color:#166534;border-color:#bbf7d0;}
    .vr-badge.available{background:#f8fafc;color:#475569;border-color:#cbd5e1;}
    .vr-badge.mine_active{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;}
    .vr-badge.claimed_by_other{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    .vr-badge.followup_state{background:#fff7ed;color:#c2410c;border-color:#fed7aa;}
    .vr-badge.completed_state{background:#ecfdf3;color:#166534;border-color:#bbf7d0;}
    .vr-action-btn{border:none;border-radius:10px;padding:8px 12px;font-size:12px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,0.06);transition:.16s ease;}
    .vr-action-btn:hover:not([disabled]){transform:translateY(-1px);}
    .vr-action-btn.claim{background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);color:#fff;}
    .vr-action-btn.open{background:linear-gradient(135deg,#0f766e 0%,#0d9488 100%);color:#fff;}
    .vr-action-btn.view{background:#e2e8f0;color:#0f172a;}
    .vr-action-btn[disabled]{background:#e5e7eb;color:#94a3b8;cursor:not-allowed;box-shadow:none;}
    .vr-empty{padding:28px 18px;color:#64748b;font-size:13px;text-align:center;border-top:1px solid rgba(148,163,184,0.16);}
    .vr-summary-strip{margin-top:14px;padding:10px 12px;border:1px solid rgba(148,163,184,0.18);border-radius:14px;background:rgba(255,255,255,0.84);display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .vr-summary-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:7px 12px;background:#fff;border:1px solid rgba(148,163,184,0.18);font-size:12px;color:#475569;font-weight:800;}
    .vr-summary-pill strong{color:#0f172a;}
    @media (max-width: 1180px){
        .vr-board-panel{overflow-x:auto;}
        .vr-board-grid{min-width:1200px;}
    }
    @media (max-width: 820px){
        .qa-like-main{padding:14px;}
        .vr-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}
    }
    @media (max-width: 640px){
        .vr-kpis{grid-template-columns:1fr;}
        .vr-chip-row{gap:8px;}
        .vr-chip{padding:9px 14px;}
        .qa-like-head{align-items:flex-start;}
    }
</style>

<div class="vr-page" id="dashboardContent">
    <div class="card vr-card">
        <h3 style="margin-bottom:4px;">Verifier Dashboard</h3>
    </div>

    <div class="card vr-card qa-like-main">
        <div class="qa-like-head">
            <div>
                <div class="qa-like-title">Live Dashboard</div>
                <div class="qa-like-subtitle">Current verifier review workload, component claim ownership, and priority-gated operational routing.</div>
            </div>
            <div class="qa-like-controls">
                <span class="qa-like-chip"><span class="qa-like-dot"></span>Live Queue</span>
                <button type="button" class="btn vr-btn-soft" id="vrBoardRefreshBtn">Refresh</button>
            </div>
        </div>

        <div class="vr-kpis">
            <div class="vr-kpi">
                <div class="n" id="vrKpiPending">0</div>
                <div class="l">Awaiting Review</div>
                <div class="t">Cases ready to be claimed</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiInProgress">0</div>
                <div class="l">Under Review</div>
                <div class="t">Cases currently owned by you</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiFollowUp">0</div>
                <div class="l">Follow Up</div>
                <div class="t">Cases needing rework or candidate follow-up</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiCompleted">0</div>
                <div class="l">Completed</div>
                <div class="t">Readonly history available to you</div>
            </div>
        </div>

        <div class="vr-summary-strip">
            <span class="vr-summary-pill"><strong>Case Board</strong> One application per row</span>
            <span class="vr-summary-pill"><strong>Claim Model</strong> Component ownership</span>
            <span class="vr-summary-pill"><strong>Work Surface</strong> Priority-gated components</span>
        </div>

        <div id="vrBoardMessage" class="vr-board-message"></div>
        <div id="vrBoardCompatNote" class="alert vr-board-note">Completed rows are readonly. Active rows claimed by another verifier cannot be opened from this board.</div>

        <div class="vr-chip-row" id="vrBucketChips">
            <button type="button" class="vr-chip active" data-bucket="pending">PENDING</button>
            <button type="button" class="vr-chip" data-bucket="completed">COMPLETED</button>
            <button type="button" class="vr-chip" data-bucket="followup">FOLLOW UP</button>
            <button type="button" class="vr-chip" data-bucket="insuff_docs">INSUFF. DOCS</button>
        </div>

        <div class="vr-state-row" id="vrStateChips">
            <button type="button" class="vr-state-chip active" data-state="all">All</button>
            <button type="button" class="vr-state-chip" data-state="owned_active">Owned Active</button>
            <button type="button" class="vr-state-chip" data-state="claimable_next">Claimable</button>
            <button type="button" class="vr-state-chip" data-state="locked_future">Locked</button>
            <button type="button" class="vr-state-chip" data-state="completed">Completed</button>
        </div>

        <div class="vr-board-panel">
            <div class="vr-board-grid">
                <div class="vr-board-row vr-board-head-row">
                    <div class="vr-board-cell">Application</div>
                    <div class="vr-board-cell">Candidate</div>
                    <div class="vr-board-cell">Components</div>
                    <div class="vr-board-cell">Bucket</div>
                    <div class="vr-board-cell">Claim State</div>
                    <div class="vr-board-cell">Claimed By</div>
                    <div class="vr-board-cell">Action</div>
                </div>
                <div id="vrBoardRows"></div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Verifier Dashboard', 'Component Verifier', $menu, $content);
echo '<script>window.APP_BASE_URL = ' . json_encode(app_base_url()) . ';</script>';
echo '<script src="' . htmlspecialchars(app_url('/js/modules/verifier/dashboard.js')) . '"></script>';
