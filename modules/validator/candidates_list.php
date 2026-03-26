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
</style>

<div class="vr-page">
<div class="card vr-card">
    <h3>Candidate List</h3>
    <p class="card-subtitle">Validator FIFO queue. Open a case to validate it.</p>
</div>

<div class="card vr-card">
    <div id="valCasesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:13px; margin-right:6px;">View</label>
                <select id="valCasesViewSelect" style="font-size:13px; padding:6px 8px; min-width:180px; border-radius:10px; border:1px solid #cbd5e1;">
                    <option value="available">Available</option>
                    <option value="mine">My Tasks</option>
                    <option value="completed">Completed</option>
                </select>
                <input id="valCasesListSearch" type="text" placeholder="Search name / email / app id / status" style="font-size:13px; padding:6px 8px; border-radius:10px; border:1px solid #cbd5e1;">
                <button class="btn btn-sm" id="valCasesListRefreshBtn" type="button" style="border-radius:10px;">Refresh</button>
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <div id="valCasesListExportButtons"></div>
            </div>
        </div>
    </div>

    <div class="table-scroll">
        <table class="table" id="valCasesListTable">
            <thead>
            <tr>
                <th>Case ID</th>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>
</div>
<?php
$content = ob_get_clean();
render_layout('Candidate List', 'Validator', $menu, $content);
