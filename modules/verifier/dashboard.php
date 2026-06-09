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
    .vr-board-panel{margin-top:16px;border:1px solid rgba(148,163,184,0.2);border-radius:18px;overflow:hidden;background:rgba(255,255,255,0.94);box-shadow:0 18px 34px rgba(15,23,42,0.07);}
    .vr-board-grid{width:100%;display:flex;flex-direction:column;gap:12px;padding:12px;}
    #vrBoardRows{display:flex;flex-direction:column;gap:12px;}
    .vr-board-row{display:grid;grid-template-columns:minmax(130px,1fr) minmax(170px,1.15fr) minmax(360px,2.45fr) minmax(105px,.75fr) minmax(125px,.85fr) minmax(150px,.95fr) 120px;align-items:center;min-height:66px;border-top:1px solid rgba(148,163,184,0.16);}
    .vr-board-row:first-child{border-top:none;}
    .vr-board-head-row{background:linear-gradient(180deg,#f8fbff 0%,#eef5fc 100%);font-size:11px;font-weight:900;color:#64748b;letter-spacing:.08em;text-transform:uppercase;min-height:54px;}
    .vr-board-cell{padding:12px 14px;word-break:break-word;}
    .vr-board-data-row{transition:.16s ease;background:rgba(255,255,255,0.92);}
    .vr-board-data-row.is-clickable{cursor:pointer;}
    .vr-board-data-row.is-clickable:hover{background:#f8fbff;}
    .vr-board-data-row.is-mine{background:#eef6ff;}
    .vr-board-data-row.is-locked{background:#f8fafc;color:#64748b;}
    .vr-board-data-row.is-completed{background:#f5fbf7;}
    .vr-case-card{border:1px solid rgba(148,163,184,0.2);border-radius:16px;background:rgba(255,255,255,0.96);box-shadow:0 10px 24px rgba(15,23,42,0.05);overflow:hidden;}
    .vr-case-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:14px 16px;background:linear-gradient(180deg,#f8fbff 0%,#eef5fc 100%);border-bottom:1px solid rgba(148,163,184,0.16);}
    .vr-case-card-badges{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;text-align:right;}
    .vr-priority-section-list{display:flex;flex-direction:column;}
    .vr-priority-section{display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:74px;padding:13px 16px;border-top:1px solid rgba(148,163,184,0.14);}
    .vr-priority-section:first-child{border-top:none;}
    .vr-priority-section.is-clickable{cursor:pointer;}
    .vr-priority-section.is-clickable:hover{background:#f8fbff;}
    .vr-priority-section-main{display:flex;flex-direction:column;gap:8px;min-width:0;}
    .vr-priority-section-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:#64748b;}
    .vr-priority-section-meta strong{color:#0f172a;}
    .vr-priority-section-action{display:flex;justify-content:flex-end;min-width:110px;}
    .vr-board-child-row{min-height:48px;background:#fff;border-top:1px solid rgba(148,163,184,0.12);}
    .vr-board-child-row .vr-board-cell{padding-top:9px;padding-bottom:9px;}
    .vr-board-child-state{font-weight:950;color:#64748b;text-transform:capitalize;}
    .vr-board-case{font-weight:900;color:#1d4ed8;}
    .vr-board-name{font-weight:800;color:#0f172a;}
    .vr-board-muted{font-size:12px;color:#64748b;}
    .vr-component-strip{display:flex;flex-direction:column;gap:6px;margin-top:7px;max-width:100%;}
    .vr-component-strip.vr-work-components{flex-direction:row;align-items:center;gap:6px;margin-top:0;}
    .vr-priority-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:0;}
    .vr-board-child-row .vr-component-strip{margin-top:0;}
    .vr-component-context{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
    .vr-component-group{display:flex;align-items:flex-start;gap:8px;min-height:24px;max-width:100%;}
    .vr-board-child-row .vr-component-group{min-height:24px;}
    .vr-component-group-head{display:flex;align-items:center;gap:6px;line-height:1;}
    .vr-component-tier{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:4px 7px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;font-weight:950;white-space:nowrap;}
    .vr-component-group-items{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0;}
    .vr-component-pill{display:inline-flex;align-items:center;gap:5px;max-width:210px;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:850;border:1px solid #e2e8f0;background:#fff;color:#475569;line-height:1.1;white-space:nowrap;}
    .vr-component-pill.vr-work-component{max-width:260px;padding:6px 10px;box-shadow:0 4px 10px rgba(15,23,42,0.03);}
    .vr-component-name{min-width:0;overflow:hidden;text-overflow:ellipsis;}
    .vr-component-state{font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.03em;opacity:.86;}
    .vr-component-status{font-size:10px;font-weight:950;text-transform:uppercase;letter-spacing:.03em;color:#0f172a;}
    .vr-component-pill .vr-component-priority{font-weight:950;color:#1d4ed8;}
    .vr-component-pill .vr-component-reason{display:none;}
    .vr-component-pill.is-context{background:#f8fafc;color:#64748b;}
    .vr-component-pill.is-owned_active{background:#dbeafe;border-color:#93c5fd;color:#1d4ed8;}
    .vr-component-pill.is-claimable_next{background:#ecfdf5;border-color:#86efac;color:#166534;}
    .vr-component-pill.is-locked_future{background:#f8fafc;border-color:#cbd5e1;color:#64748b;}
    .vr-component-pill.is-completed{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;}
    .vr-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:900;white-space:nowrap;border:1px solid transparent;}
    .vr-badge.pending{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
    .vr-badge.owned_active{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;}
    .vr-badge.claimable_next{background:#ecfdf5;color:#166534;border-color:#86efac;}
    .vr-badge.followup{background:#fff7ed;color:#c2410c;border-color:#fed7aa;}
    .vr-badge.insuff_docs{background:#fffbeb;color:#b45309;border-color:#fde68a;}
    .vr-badge.completed{background:#ecfdf3;color:#166534;border-color:#bbf7d0;}
    .vr-badge.available{background:#f8fafc;color:#475569;border-color:#cbd5e1;}
    .vr-badge.locked_future{background:#f8fafc;color:#64748b;border-color:#cbd5e1;}
    .vr-badge.mine_active{background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;}
    .vr-badge.claimed_by_other{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    .vr-badge.followup_state{background:#fff7ed;color:#c2410c;border-color:#fed7aa;}
    .vr-badge.completed_state{background:#ecfdf3;color:#166534;border-color:#bbf7d0;}
    .vr-action-btn{border:none;border-radius:999px;padding:7px 13px;font-size:12px;font-weight:900;cursor:pointer;box-shadow:0 8px 18px rgba(15,23,42,0.06);transition:.16s ease;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;width:auto;min-width:0;}
    .vr-action-btn:hover:not([disabled]){transform:translateY(-1px);}
    .vr-action-btn.claim{background:linear-gradient(135deg,#2563eb 0%,#1d4ed8 100%);color:#fff;}
    .vr-action-btn.open{background:linear-gradient(135deg,#0f766e 0%,#0d9488 100%);color:#fff;}
    .vr-action-btn.view{background:#e2e8f0;color:#0f172a;}
    .vr-action-btn[disabled]{background:#e5e7eb;color:#94a3b8;cursor:not-allowed;box-shadow:none;}
    .vr-empty{padding:28px 18px;color:#64748b;font-size:13px;text-align:center;border-top:1px solid rgba(148,163,184,0.16);}
    .vr-summary-strip{margin-top:14px;padding:10px 12px;border:1px solid rgba(148,163,184,0.18);border-radius:14px;background:rgba(255,255,255,0.84);display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .vr-summary-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:7px 12px;background:#fff;border:1px solid rgba(148,163,184,0.18);font-size:12px;color:#475569;font-weight:800;}
    .vr-summary-pill strong{color:#0f172a;}
    @media (max-width: 820px){
        .qa-like-main{padding:14px;}
        .vr-kpis{grid-template-columns:repeat(2,minmax(0,1fr));}
        .vr-case-card-head,.vr-priority-section{align-items:flex-start;flex-direction:column;}
        .vr-case-card-badges,.vr-priority-section-action{justify-content:flex-start;text-align:left;}
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
                <div class="l">To Be Claimed</div>
                <div class="t">Components ready to claim</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiInProgress">0</div>
                <div class="l">Active</div>
                <div class="t">Components owned by you</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiFollowUp">0</div>
                <div class="l">All</div>
                <div class="t">Visible work and context</div>
            </div>
            <div class="vr-kpi">
                <div class="n" id="vrKpiCompleted">0</div>
                <div class="l">Completed</div>
                <div class="t">Readonly history available to you</div>
            </div>
        </div>

        <div class="vr-summary-strip">
            <span class="vr-summary-pill"><strong>Component Worklist</strong> One application per card</span>
            <span class="vr-summary-pill"><strong>Claim Model</strong> Component-level claims</span>
            <span class="vr-summary-pill"><strong>Work Surface</strong> Priority-gated components</span>
        </div>

        <div id="vrBoardMessage" class="vr-board-message"></div>
        <div id="vrBoardCompatNote" class="alert vr-board-note">Completed rows are readonly. Active rows claimed by another verifier cannot be opened from this board.</div>

        <div class="vr-chip-row" id="vrBucketChips">
            <button type="button" class="vr-chip active" data-bucket="claimable">TO BE CLAIMED</button>
            <button type="button" class="vr-chip" data-bucket="active">ACTIVE</button>
            <button type="button" class="vr-chip" data-bucket="completed">COMPLETED</button>
            <button type="button" class="vr-chip" data-bucket="all">ALL</button>
        </div>

        <div class="vr-board-panel">
            <div class="vr-board-grid">
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
