<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_any_access(['qa', 'team_lead']);

auth_session_start();
$access = strtolower(trim((string)($_SESSION['auth_moduleAccess'] ?? '')));
$isTeamLead = ($access === 'team_lead');

$menu = $isTeamLead ? team_lead_menu() : qa_menu();
$roleLabel = $isTeamLead ? 'Team Lead' : 'QA';

ob_start();
?>
<div class="card">
    <h3 style="margin-bottom:4px;">QA Dashboard</h3>
    <p class="card-subtitle" style="margin-bottom:0; color:#64748b;">Live workload, users, and active case handling.</p>
</div>

<div class="card" id="qaDashMessage" style="display:none; margin-bottom:10px;"></div>

<div class="card qa-dash-main">
    <div class="qa-dash-head">
        <div>
            <div class="qa-dash-title">Live Dashboard</div>
            <div class="qa-dash-subtitle">Auto-refresh shows current open workload and assignments.</div>
        </div>
        <div class="qa-dash-controls">
            <label class="qa-dash-autorefresh">
                <input type="checkbox" id="qaDashAutoRefresh" checked>
                <span>Auto refresh (15s)</span>
            </label>
            <button class="btn btn-sm qa-dash-refresh-btn" id="qaDashRefreshBtn" type="button">Refresh</button>
        </div>
    </div>

    <div class="qa-kpi-grid">
        <div class="qa-kpi-card">
            <div class="qa-kpi-label">ACTIVE USERS</div>
            <div id="qaKpiUsersTotal" class="qa-kpi-value">-</div>
        </div>
        <div class="qa-kpi-card">
            <div class="qa-kpi-label">QA USERS</div>
            <div id="qaKpiQaUsers" class="qa-kpi-value">-</div>
        </div>
        <div class="qa-kpi-card">
            <div class="qa-kpi-label">VR OPEN ITEMS</div>
            <div id="qaKpiVrOpen" class="qa-kpi-value">-</div>
        </div>
        <div class="qa-kpi-card">
            <div class="qa-kpi-label">DBV OPEN CASES</div>
            <div id="qaKpiDbvOpen" class="qa-kpi-value">-</div>
        </div>
    </div>

    <div class="qa-workload-grid">
        <div class="qa-panel-card">
            <div class="qa-panel-title">Verifier workload (VR Group Queue)</div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>User</th><th>Open</th><th>State</th></tr></thead>
                    <tbody id="qaWorkloadVrBody"></tbody>
                </table>
            </div>
        </div>
        <div class="qa-panel-card">
            <div class="qa-panel-title">DB Verifier workload (DBV)</div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>User</th><th>Open</th><th>State</th></tr></thead>
                    <tbody id="qaWorkloadDbvBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="qa-assignments-card">
        <div class="qa-assignments-head">
            <div>
                <div class="qa-panel-title">Who is handling which case (live)</div>
                <div class="qa-panel-subtitle">Shows active claims (VR queue + DBV).</div>
            </div>
        </div>
        <div class="table-scroll qa-assignments-table">
            <table class="table">
                <thead>
                <tr>
                    <th>Queue</th>
                    <th>Application</th>
                    <th>Group</th>
                    <th>Queue Status</th>
                    <th>Assigned To</th>
                    <th>Case Status</th>
                </tr>
                </thead>
                <tbody id="qaAssignmentsBody"></tbody>
            </table>
        </div>
    </div>
</div>

<style>
    .qa-dash-main{
        padding:14px;
    }
    .qa-dash-head{
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
    }
    .qa-dash-title{
        font-weight:900;
        color:#0f172a;
    }
    .qa-dash-subtitle{
        font-size:12px;
        color:#64748b;
    }
    .qa-dash-controls{
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
    }
    .qa-dash-autorefresh{
        display:flex;
        align-items:center;
        gap:8px;
        font-size:13px;
        color:#334155;
        margin:0;
    }
    .qa-dash-autorefresh input[type="checkbox"]{
        margin:0;
    }
    .qa-dash-refresh-btn{
        border-radius:10px;
        font-weight:800;
    }

    /* KPI grid */
    .qa-kpi-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
        gap:10px;
        margin-top:14px;
    }
    .qa-kpi-card{
        border:1px solid rgba(148,163,184,0.25);
        border-radius:14px;
        padding:12px;
        background:#fff;
    }
    .qa-kpi-label{
        font-size:11px;
        color:#64748b;
        font-weight:800;
        letter-spacing:.08em;
        text-transform:uppercase;
    }
    .qa-kpi-value{
        font-size:22px;
        font-weight:900;
        color:#0f172a;
        margin-top:4px;
    }

    /* Workload + assignments cards */
    .qa-workload-grid{
        display:grid;
        grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));
        gap:12px;
        margin-top:14px;
    }
    .qa-panel-card,
    .qa-assignments-card{
        border:1px solid rgba(148,163,184,0.25);
        border-radius:14px;
        padding:12px;
        background:#fff;
        overflow:hidden;
    }
    .qa-panel-title{
        font-weight:900;
        color:#0f172a;
        margin-bottom:8px;
        font-size:13px;
    }
    .qa-panel-subtitle{
        font-size:12px;
        color:#64748b;
    }
    .qa-assignments-table{
        margin-top:8px;
    }

    /* Keep workload tables inside card without horizontal scrollbar */
    .qa-workload-grid .table-scroll{
        overflow-x: hidden;
    }
    .qa-workload-grid .table{
        width:100%;
        min-width:0 !important;
        table-layout: fixed;
    }
    .qa-workload-grid .table th,
    .qa-workload-grid .table td{
        white-space: normal;
        word-break: break-word;
    }

    /* Responsive behaviour */
    @media (max-width: 1100px){
        .qa-kpi-value{
            font-size:18px;
        }
    }
    @media (max-width: 768px){
        .qa-kpi-grid{
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 520px){
        .qa-kpi-grid{
            grid-template-columns:1fr;
        }
        .qa-dash-head{
            align-items:flex-start;
        }
        .qa-dash-controls{
            width:100%;
            justify-content:flex-start;
        }
    }
</style>
<?php
$content = ob_get_clean();
render_layout('QA Dashboard', $roleLabel, $menu, $content);
