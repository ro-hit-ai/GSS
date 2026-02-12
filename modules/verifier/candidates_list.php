<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

$menu = verifier_menu();

ob_start();
?>
<div class="card">
    <h3>Candidate List</h3>
    <p class="card-subtitle">Component verification queue (UI first). Click candidate to open verification view.</p>
</div>

<div class="card">
    <div id="vrCasesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div id="vrCasesAssignedModules" style="margin-bottom:10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:13px; margin-right:6px;">Group</label>
                <select id="vrCasesGroupSelect" style="font-size:13px; padding:6px 8px; min-width:180px; border-radius:10px; border:1px solid #cbd5e1;"></select>

                <label style="font-size:13px; margin-right:6px;">View</label>
                <select id="vrCasesViewSelect" style="font-size:13px; padding:6px 8px; min-width:180px; border-radius:10px; border:1px solid #cbd5e1;">
                    <option value="mine">My Tasks</option>
                    <option value="available">Available</option>
                    <option value="followup">Follow-up</option>
                    <option value="completed">Completed</option>
                </select>
                <input id="vrCasesListSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:6px 8px; border-radius:10px; border:1px solid #cbd5e1;">
                <!-- <button class="btn btn-sm" id="vrCasesListRefreshBtn" type="button" style="border-radius:10px;">Refresh</button> -->
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div id="vrCasesListExportButtons"></div>
            </div>
        </div>
    </div>

    <div class="table-scroll">
        <table class="table" id="vrCasesListTable">
            <thead>
            <tr>
                <th>Case ID</th>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>TAT Remaining</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Candidate List', 'Component Verifier', $menu, $content);
