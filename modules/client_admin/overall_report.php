<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('client_admin');

$menu = client_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Overall Candidate Report</h3>
    <p class="card-subtitle">Live candidate case report with status and SLA visibility.</p>
</div>

<div class="card">
    <div id="overallReportMessage" style="display:none; margin-bottom: 10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <input id="overallReportSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:6px 8px; border-radius:10px; border:1px solid #cbd5e1;">
            <button type="button" class="btn" id="overallReportRefreshBtn">Refresh</button>
        </div>
        <div style="font-size:12px; color:#64748b;">SLA rule: <b>20 days</b> from case creation.</div>
    </div>

    <div class="table-scroll">
        <table class="table" id="overallReportTable">
            <thead>
            <tr>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Current Stage</th>
                <th>Case Status</th>
                <th>SLA</th>
                <th>Invited</th>
                <th>Created</th>
                <th>Open</th>
            </tr>
            </thead>
            <tbody id="overallReportBody"></tbody>
        </table>
    </div>
</div>
<script src="<?php echo htmlspecialchars(app_url('/js/modules/client_admin/overall_report.js')); ?>"></script>
<?php
$content = ob_get_clean();
render_layout('Overall Report', 'Client Admin', $menu, $content);
