<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('validator');

$menu = validator_menu();

ob_start();
?>
<style>
    .vr-page{display:flex; flex-direction:column; gap:12px;}
    .vr-card{border-radius:14px;}
    .qa-like-main{padding:14px;}
    .qa-like-head{display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;}
    .qa-like-title{font-weight:900; color:#0f172a;}
    .qa-like-subtitle{font-size:12px; color:#64748b;}
    .qa-like-controls{display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
    .qa-like-chip{display:inline-flex; align-items:center; gap:8px; border:1px solid rgba(15,23,42,0.12); border-radius:999px; padding:6px 10px; font-size:12px; color:#334155; background:#fff;}
    .qa-like-dot{width:8px; height:8px; border-radius:999px; background:#22c55e;}

    .vr-kpis{display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-top:14px;}
    .vr-kpi{border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; background:#fff;}
    .vr-kpi .n{font-size:22px; font-weight:900; color:#0f172a;}
    .vr-kpi .l{font-size:11px; color:#64748b; font-weight:800; letter-spacing:.08em; text-transform:uppercase; margin-top:2px;}
    .vr-kpi .t{font-size:11px; color:#94a3b8; margin-top:4px;}

    .vr-actions{display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;}
    .vr-btn{display:inline-flex; align-items:center; gap:8px; border-radius:10px; padding:8px 12px; font-size:13px; font-weight:700; text-decoration:none; border:1px solid transparent;}
    .vr-btn-primary{background:#2563eb; border-color:#2563eb; color:#fff;}
    .vr-btn-primary:hover{color:#fff; filter:brightness(0.96);}
    .vr-btn-soft{background:#fff; border-color:rgba(148,163,184,0.35); color:#0f172a;}

    .vr-operational-grid{display:grid; grid-template-columns:minmax(0, 2fr) minmax(280px, 1fr); gap:12px; margin-top:14px;}
    .vr-secondary-grid{display:grid; grid-template-columns:1fr; gap:12px; margin-top:12px;}
    .vr-panel{border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; background:#fff;}
    .vr-panel-h h3{margin:0; font-size:13px; font-weight:900; color:#0f172a;}
    .vr-panel-h .m{font-size:12px; color:#64748b;}
    .vr-panel-b{margin-top:8px;}
    .vr-panel-b{overflow-x:auto;}
    .vr-table{width:100%; border-collapse:separate; border-spacing:0;}
    .vr-table th{font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; border-bottom:1px solid rgba(148,163,184,0.25); padding:10px 8px;}
    .vr-table td{padding:11px 8px; border-bottom:1px solid rgba(148,163,184,0.18); font-size:13px; color:#0f172a;}
    .vr-mini{display:grid; gap:10px;}
    .vr-mini-card{border:1px solid rgba(148,163,184,0.22); border-radius:14px; padding:12px; background:rgba(255,255,255,0.75);}
    .vr-mini-card .h{font-size:12px; font-weight:800; color:#0f172a;}
    .vr-mini-card .d{font-size:12px; color:#64748b; margin-top:4px;}
    .vr-focus-strip{display:grid; grid-template-columns:1fr; gap:10px;}
    .vr-focus-card{border:1px solid rgba(148,163,184,0.18); border-radius:12px; padding:10px 12px; background:rgba(248,250,252,0.9);}
    .vr-focus-card .h{font-size:12px; font-weight:800; color:#0f172a;}
    .vr-focus-card .d{font-size:12px; color:#64748b; margin-top:3px;}
    .vr-governance details{border:1px solid rgba(148,163,184,0.22); border-radius:14px; background:rgba(255,255,255,0.86);}
    .vr-governance summary{list-style:none; cursor:pointer; padding:12px 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; font-weight:800; color:#0f172a;}
    .vr-governance summary::-webkit-details-marker{display:none;}
    .vr-governance-summary-copy{font-size:12px; font-weight:600; color:#64748b;}
    .vr-governance-body{padding:0 12px 12px;}
    @media (max-width: 1100px){
        .vr-operational-grid{grid-template-columns:1fr;}
    }
    @media (max-width: 820px){
        .qa-like-main{padding:12px;}
        .vr-kpis{grid-template-columns:repeat(2, minmax(0, 1fr));}
        .vr-actions{flex-direction:column; align-items:stretch;}
        .vr-btn{justify-content:center; width:100%;}
        .vr-table{min-width:620px;}
    }
    @media (max-width: 560px){
        .vr-kpis{grid-template-columns:1fr;}
        .qa-like-head{align-items:flex-start;}
        .qa-like-controls{width:100%; justify-content:flex-start;}
    }
</style>

<div class="vr-page">
<div class="card vr-card">
    <h3 style="margin-bottom:4px;">Validator Dashboard</h3>
    <!-- <p class="card-subtitle" style="margin-bottom:0; color:#64748b;">QA-style layout with validator workflow actions.</p> -->
</div>

<div class="card vr-card qa-like-main">
    <div class="qa-like-head">
        <div>
            <div class="qa-like-title">Live Dashboard</div>
            <div class="qa-like-subtitle">Current validator evaluation workload, queue continuity, and next-step actions.</div>
        </div>
        <div class="qa-like-controls">
            <span class="qa-like-chip"><span class="qa-like-dot"></span>Live Queue</span>
        </div>
    </div>

    <div id="valDashMessage" style="display:none; margin-top:10px;"></div>

    <div class="vr-kpis">
        <div class="vr-kpi">
            <div class="n" id="valKpiPending">0</div>
            <div class="l">Awaiting Evaluation</div>
            <div class="t">Awaiting assignment/start</div>
        </div>
        <div class="vr-kpi">
            <div class="n" id="valKpiInProgress">0</div>
            <div class="l">Under Evaluation</div>
            <div class="t">Active evaluation workload</div>
        </div>
        <div class="vr-kpi">
            <div class="n" id="valKpiCompletedToday">0</div>
            <div class="l">Evaluated Today</div>
            <div class="t">Evaluated outcomes today</div>
        </div>
        <div class="vr-kpi">
            <div class="n">1.8h</div>
            <div class="l">Avg. Validation Time</div>
            <div class="t">(sample metric)</div>
        </div>
    </div>

    <div class="vr-actions">
        <button type="button" class="vr-btn vr-btn-primary" id="valDashStartNextBtn">Start Next</button>
        <a class="vr-btn vr-btn-soft" href="candidates_list.php">Candidate List</a>
        <button type="button" class="vr-btn vr-btn-soft" id="valDashRefreshBtn">Refresh</button>
    </div>

    <div class="vr-operational-grid">
        <div class="vr-panel">
            <div class="vr-panel-h">
                <h3>My Open Cases</h3>
                <div class="m">Top items assigned to you</div>
            </div>
            <div class="vr-panel-b">
                <table class="vr-table">
                    <thead>
                    <tr>
                        <th>Application</th>
                        <th>Candidate</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody id="valMyTasksBody"></tbody>
                </table>
            </div>
        </div>

        <div class="vr-panel">
            <div class="vr-panel-h">
                <h3>Operational Focus</h3>
                <div class="m">Linear queue-first workflow</div>
            </div>
            <div class="vr-panel-b">
                <div class="vr-focus-strip">
                    <div class="vr-focus-card">
                        <div class="h">Queue pickup</div>
                        <div class="d">FIFO queue pickup enabled.</div>
                    </div>
                    <div class="vr-focus-card">
                        <div class="h">Workflow path</div>
                        <div class="d">Validator review flows forward into verifier ownership.</div>
                    </div>
                    <div class="vr-focus-card">
                        <div class="h">Quality guardrail</div>
                        <div class="d">Close remarks and evidence checks before final action.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="vr-secondary-grid">
        <div class="vr-panel vr-governance">
            <details>
                <summary>
                    <span>Workflow Governance</span>
                    <span class="vr-governance-summary-copy" id="valGovernanceSummary">Decision updates, invalidation, correction, and workflow health</span>
                </summary>
                <div class="vr-governance-body">
                    <div class="vr-mini" id="valGovernanceSignals">
                        <div class="vr-mini-card">
                            <div class="h">Loading...</div>
                            <div class="d">Governance metrics are loading.</div>
                        </div>
                    </div>
                </div>
            </details>
        </div>
    </div>
</div>
</div>
<?php
$content = ob_get_clean();
render_layout('Validator Dashboard', 'Validator', $menu, $content);

echo '<script>window.APP_BASE_URL = ' . json_encode(app_base_url()) . ';</script>';
echo '<script src="' . htmlspecialchars(app_url('/js/includes/date_utils.js')) . '"></script>';
echo '<script src="' . htmlspecialchars(app_url('/js/modules/validator/dashboard.js')) . '"></script>';
