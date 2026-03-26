<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

$menu = verifier_menu();

ob_start();
?>
<style>
    .vr-hero{border:1px solid rgba(59,130,246,0.18); background:radial-gradient(900px 420px at 10% 0%, rgba(59,130,246,0.14), transparent 55%), radial-gradient(720px 380px at 90% 0%, rgba(34,197,94,0.10), transparent 55%), linear-gradient(180deg,#ffffff,#f8fafc); border-radius:16px; padding:16px; box-shadow:0 14px 30px rgba(15,23,42,0.08);}
    .vr-hero-top{display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap;}
    .vr-title{margin:0; font-size:18px; font-weight:800; letter-spacing:-.02em; color:#0f172a;}
    .vr-sub{margin-top:4px; font-size:12px; color:#475569; max-width:760px;}
    .vr-chip{display:inline-flex; align-items:center; gap:8px; border:1px solid rgba(2,6,23,0.10); background:rgba(255,255,255,0.70); border-radius:999px; padding:7px 10px; font-size:12px; color:#0f172a;}
    .vr-dot{width:8px; height:8px; border-radius:999px; background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,0.12);}
    .vr-kpis{display:grid; grid-template-columns:repeat(12, 1fr); gap:12px; margin-top:12px;}
    .vr-kpi{grid-column:span 3; border:1px solid rgba(148,163,184,0.25); background:rgba(255,255,255,0.75); border-radius:14px; padding:12px; box-shadow:0 10px 22px rgba(15,23,42,0.06);}
    .vr-kpi .n{font-size:22px; font-weight:900; color:#0f172a; letter-spacing:-.02em;}
    .vr-kpi .l{font-size:12px; color:#64748b; margin-top:2px;}
    .vr-kpi .t{font-size:11px; color:#94a3b8; margin-top:6px;}
    .vr-actions{display:flex; gap:10px; flex-wrap:wrap; margin-top:12px;}
    .vr-btn{display:inline-flex; align-items:center; gap:8px; border-radius:12px; padding:10px 12px; font-size:13px; font-weight:600; text-decoration:none; border:1px solid transparent;}
    .vr-btn-primary{background:#2563eb; border-color:#2563eb; color:#fff;}
    .vr-btn-primary:hover{filter:brightness(0.96); color:#fff;}
    .vr-btn-soft{background:rgba(37,99,235,0.08); border-color:rgba(37,99,235,0.18); color:#1d4ed8;}
    .vr-btn-soft:hover{background:rgba(37,99,235,0.12); color:#1d4ed8;}
    .vr-grid{display:grid; grid-template-columns:1.2fr .8fr; gap:12px; margin-top:12px;}
    .vr-panel{border:1px solid #e5e7eb; border-radius:16px; background:linear-gradient(180deg,#ffffff,#f8fafc); box-shadow:0 10px 26px rgba(15,23,42,0.06); overflow:hidden;}
    .vr-panel-h{padding:12px 14px; border-bottom:1px solid rgba(148,163,184,0.22); display:flex; align-items:center; justify-content:space-between; gap:10px;}
    .vr-panel-h h3{margin:0; font-size:14px; font-weight:800; color:#0f172a;}
    .vr-panel-h .m{font-size:12px; color:#64748b;}
    .vr-panel-b{padding:12px 14px;}
    .vr-table{width:100%; border-collapse:separate; border-spacing:0;}
    .vr-table th{font-size:11px; letter-spacing:.08em; text-transform:uppercase; color:#64748b; border-bottom:1px solid rgba(148,163,184,0.25); padding:10px 8px;}
    .vr-table td{padding:11px 8px; border-bottom:1px solid rgba(148,163,184,0.18); font-size:13px; color:#0f172a;}
    .vr-badge{display:inline-flex; align-items:center; gap:6px; border-radius:999px; padding:4px 10px; font-size:12px; font-weight:700;}
    .vr-badge-p{background:rgba(251,191,36,0.18); color:#92400e; border:1px solid rgba(251,191,36,0.35);}
    .vr-badge-i{background:rgba(59,130,246,0.12); color:#1d4ed8; border:1px solid rgba(59,130,246,0.22);}
    .vr-badge-d{background:rgba(34,197,94,0.12); color:#166534; border:1px solid rgba(34,197,94,0.22);}
    .vr-mini{display:grid; gap:10px;}
    .vr-mini-card{border:1px solid rgba(148,163,184,0.22); border-radius:14px; padding:12px; background:rgba(255,255,255,0.75);}
    .vr-mini-card .h{font-size:12px; font-weight:800; color:#0f172a;}
    .vr-mini-card .d{font-size:12px; color:#64748b; margin-top:4px;}
    @media (max-width: 1100px){
        .vr-kpi{grid-column:span 6;}
        .vr-grid{grid-template-columns:1fr;}
    }
</style>

<!-- <button id="refreshDashboard" class="btn btn-primary">Refresh</button> -->

<div id="dashboardContent">
<div class="vr-hero">
    <div class="vr-hero-top">
        <div>
            <div class="vr-title">Verifier Command Center</div>
            <div class="vr-sub">A premium queue view for component verification. Use the quick actions to jump into your candidate list and start verifying faster.</div>
        </div>
        <div class="vr-chip"><span class="vr-dot"></span><span>Live Queue</span></div>
    </div>

    <div id="vrDashMessage" style="display:none; margin-top:10px;"></div>

    <div id="vrAssignedModules" style="margin-top:10px;"></div>

    <div class="vr-kpis">
        <div class="vr-kpi">
            <div class="n" id="vrKpiPending">0</div>
            <div class="l">Pending Components</div>
            <div class="t">Awaiting start</div>
        </div>
        <div class="vr-kpi">
            <div class="n" id="vrKpiInProgress">0</div>
            <div class="l">In Progress</div>
            <div class="t">Work-in-hand</div>
        </div>
        <div class="vr-kpi">
            <div class="n" id="vrKpiCompletedToday">0</div>
            <div class="l">Completed Today</div>
            <div class="t">Ready for QA</div>
        </div>
        <div class="vr-kpi">
            <div class="n">2.1h</div>
            <div class="l">Avg. Handling Time</div>
            <div class="t">(sample metric)</div>
        </div>
    </div>

    <div class="vr-actions">
        <div id="vrStartActions" style="display:flex; gap:10px; flex-wrap:wrap;"></div>
        <a class="vr-btn vr-btn-primary" href="candidates_list.php">Open Candidate List</a>
        <button type="button" class="vr-btn vr-btn-soft" id="refreshDashboard">Refresh Dashboard</button>
    </div>
</div>

<div class="vr-grid">
    <div class="vr-panel">
        <div class="vr-panel-h">
            <div>
                <h3>My Tasks</h3>
                <div class="m">Top items assigned to you</div>
            </div>
            <div class="m" id="vrMyTasksUpdated">Updated: -</div>
        </div>
        <div class="vr-panel-b">
            <table class="vr-table">
                <thead>
                <tr>
                    <th>Candidate</th>
                    <th>Component</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                </thead>
                <tbody id="vrMyTasksBody"></tbody>
            </table>
        </div>
    </div>

    <div class="vr-panel">
        <div class="vr-panel-h">
            <div>
                <h3>Quick Insights</h3>
                <div class="m">Fast shortcuts & reminders</div>
            </div>
        </div>
        <div class="vr-panel-b">
            <div class="vr-mini">
                <div class="vr-mini-card">
                    <div class="h">Suggested next step</div>
                    <div class="d">Go to <strong>Candidate List</strong> and open the next pending case.</div>
                </div>
                <div class="vr-mini-card">
                    <div class="h">Evidence uploads</div>
                    <div class="d">Use the <strong>Upload Documents</strong> widget on the candidate report for proofs.</div>
                </div>
                <div class="vr-mini-card">
                    <div class="h">QA readiness</div>
                    <div class="d">Once completed, QA will review and can send back for rework (next phase).</div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?php
$content = ob_get_clean();
render_layout('Verifier Queue', 'Component Verifier', $menu, $content);
