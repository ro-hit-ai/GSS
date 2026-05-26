<?php
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

$menu = gss_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Candidate Cases</h3>
    <p class="card-subtitle">All candidate cases across clients. Filter by client and search.</p>
</div>

<div class="card">
    <div id="casesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:13px; margin-right:6px;">Client</label>
                <select id="casesClientSelect" style="font-size:13px; padding:4px 6px; min-width:260px;"></select>
                <input id="casesListSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:4px 6px;">
                <button class="btn" id="casesListRefreshBtn" type="button">Refresh</button>
                <label style="font-size:13px; display:flex; align-items:center; gap:6px; margin-left:6px;">
                    <input type="checkbox" id="casesAutoRefresh" checked>
                    Auto refresh
                </label>
                <span id="casesLastUpdated" style="font-size:12px; color:#64748b;"></span>
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div id="casesListExportButtons"></div>
                <a class="btn" href="candidate_create.php">Create Case</a>
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
                <th>Validator / Compat</th>
                <th>Verifier Assigned</th>
                <th>Status</th>
                <th>PDF</th>
                <th>Invited</th>
                <th>Invite Link</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Candidates List', 'GSS Admin', $menu, $content);
