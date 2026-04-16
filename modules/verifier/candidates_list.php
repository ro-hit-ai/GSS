<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

$menu = verifier_menu();

ob_start();
?>
<style>
    .vr-page{display:flex; flex-direction:column; gap:12px;}
    .vr-card{border-radius:14px;}
    .vr-toolbar{display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:10px;}
    .vr-toolbar-left{display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;}
    .vr-toolbar-group{display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
    .vr-input{font-size:13px; padding:6px 8px; border-radius:10px; border:1px solid #cbd5e1; background:#fff;}
    .vr-input-search{min-width:260px;}
    .vr-toolbar-right{margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;}
</style>

<div class="vr-page">
<div class="card vr-card">
    <h3>Candidate List</h3>
    <p class="card-subtitle">Verifier queue in the same portal style as validator, with group-based task filters.</p>
</div>

<div class="card vr-card">
    <div id="vrCasesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div id="vrCasesAssignedModules" style="margin-bottom:10px;"></div>

    <div class="vr-toolbar">
        <div class="vr-toolbar-left">
            <div class="vr-toolbar-group">
                <label style="font-size:13px; margin-right:6px;">Group</label>
                <select id="vrCasesGroupSelect" class="vr-input" style="min-width:180px;"></select>

                <label style="font-size:13px; margin-right:6px;">View</label>
                <select id="vrCasesViewSelect" class="vr-input" style="min-width:180px;">
                    <option value="available">Available</option>
                    <option value="mine" selected>My Tasks</option>
                    <option value="followup">Follow-up</option>
                    <option value="completed">Completed</option>
                </select>
                <input id="vrCasesListSearch" class="vr-input vr-input-search" type="text" placeholder="Search name / email / app id / status">
                <button class="btn btn-sm" id="vrCasesListRefreshBtn" type="button" style="border-radius:10px;">Refresh</button>
            </div>

            <div class="vr-toolbar-right">
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
</div>
<?php
$content = ob_get_clean();
render_layout('Candidate List', 'Component Verifier', $menu, $content);
