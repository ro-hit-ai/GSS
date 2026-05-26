<?php
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('client_admin');

$menu = client_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Candidate Cases</h3>
    <p class="card-subtitle">Candidates created for your client. Search and track their case status.</p>
</div>

<div class="card">
    <div id="casesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <input id="casesListSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:4px 6px;">
                <button class="btn" id="casesListRefreshBtn" type="button">Refresh</button>
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div id="casesListExportButtons"></div>
                <a class="btn" href="candidate_create.php">Create Applicant</a>
                <a class="btn btn-secondary" href="candidate_bulk.php">Bulk Upload</a>
            </div>
        </div>
    </div>

    <div class="table-scroll">
        <table class="table" id="casesListTable">
            <thead>
            <tr>
                <th>Case ID</th>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Stage</th>
                <th>Flow</th>
                <th>Status</th>
                <th>SLA</th>
                <th>PDF</th>
                <th>Invited</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Candidates List', 'Client Admin', $menu, $content);
