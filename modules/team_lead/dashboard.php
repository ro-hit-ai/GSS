<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../api/shared/workflow/workflow_semantics.php';

auth_require_login('team_lead');

$menu = team_lead_menu();

ob_start();
?>
<div class="card">
    <h3>Team Lead Dashboard</h3>
    <p class="card-subtitle">Team queue, performance and assignment.</p>
</div>

<div class="card">
    <div id="tlMessage" style="display:none; margin-bottom:10px;"></div>

    <div class="form-grid tl-filters" style="margin-bottom:10px; align-items:end;">
        <div class="form-control">
            <label>Client</label>
            <select id="tlClientSelect">
                <option value="0">All</option>
            </select>
        </div>
        <div class="form-control">
            <label>Verifier / DBV</label>
            <select id="tlVerifierSelect">
                <option value="0">All</option>
            </select>
        </div>
        <div class="form-control">
            <label>VR Group</label>
            <select id="tlVrGroupSelect">
                <option value="">All</option>
                <?php foreach (wf_verifier_group_keys() as $vrGroupKey): ?>
                    <option value="<?= htmlspecialchars($vrGroupKey) ?>"><?= htmlspecialchars($vrGroupKey) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="btn" id="tlRefreshBtn">Refresh</button>
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#334155; margin:0; white-space:nowrap;">
                <input type="checkbox" id="tlAutoRefresh" checked>
                Auto refresh (20s)
            </label>
        </div>
    </div>

    <style>
        @media (min-width: 1100px) {
            .tl-filters {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 12px;
            }
        }
    </style>

    <div class="client-dashboard-kpis">
        <div style="display:grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap:10px;">
            <div class="card client-kpi-tile client-kpi-tile-utv" style="border-radius:14px; padding:12px;">
                <div class="client-kpi-label">UNASSIGNED VR</div>
                <div id="tlKpiVrUnassigned" class="client-kpi-value" style="margin-top:4px;">-</div>
            </div>
            <div class="card client-kpi-tile client-kpi-tile-utv" style="border-radius:14px; padding:12px;">
                <div class="client-kpi-label">UNASSIGNED DBV</div>
                <div id="tlKpiDbvUnassigned" class="client-kpi-value" style="margin-top:4px;">-</div>
            </div>
            <div class="card client-kpi-tile client-kpi-tile-total" style="border-radius:14px; padding:12px;">
                <div class="client-kpi-label">ACTIVE ASSIGNMENTS</div>
                <div id="tlKpiActiveAssignments" class="client-kpi-value" style="margin-top:4px;">-</div>
            </div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns: 1fr; gap:12px; margin-top:14px;">
        <div style="border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; background:#fff;">
            <div style="font-weight:900; color:#0f172a; margin-bottom:8px;">Unassigned VR Group Queue</div>
            <div class="table-scroll">
                <table class="table">
                    <thead><tr><th>Application</th><th>Group</th><th>Client</th><th>Assign</th></tr></thead>
                    <tbody id="tlVrUnassignedBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div style="margin-top:14px; border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; background:#fff;">
        <div style="font-weight:900; color:#0f172a; margin-bottom:8px;">Unassigned DBV Cases</div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Application</th><th>Client</th><th>Case Status</th><th>Assign</th></tr></thead>
                <tbody id="tlDbvUnassignedBody"></tbody>
            </table>
        </div>
    </div>

    <div style="margin-top:14px; border:1px solid rgba(148,163,184,0.25); border-radius:14px; padding:12px; background:#fff;">
        <div style="font-weight:900; color:#0f172a; margin-bottom:8px;">Recent Active Assignments</div>
        <div class="table-scroll">
            <table class="table">
                <thead><tr><th>Queue</th><th>Application</th><th>Group</th><th>Assigned To</th><th>Status</th></tr></thead>
                <tbody id="tlAssignmentsBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(app_url('/js/modules/team_lead/dashboard.js')); ?>"></script>
<?php
$content = ob_get_clean();
render_layout('Team Lead Dashboard', 'Team Lead', $menu, $content);
