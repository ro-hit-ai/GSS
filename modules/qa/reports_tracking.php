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
    <h3 style="margin-bottom:4px;">Reports Tracking</h3>
    <p class="card-subtitle" style="margin-bottom:0; color:#64748b;">Filter and open candidate reports across all clients. All opens/prints are recorded in the audit timeline.</p>
</div>

<div class="card qa-rpt-shell">
    <div id="qaReportsTrackMessage" style="display:none; margin-bottom: 10px;"></div>

    <div class="qa-rpt-filters">
        <div class="qa-rpt-filters-main">
            <div class="qa-rpt-filter-row">
                <div class="qa-rpt-filter-field">
                    <label for="qaRptClient">Client</label>
                    <select id="qaRptClient" class="qa-rpt-select"></select>
                </div>

                <div class="qa-rpt-filter-field">
                    <label for="qaRptStatus">Status</label>
                    <select id="qaRptStatus" class="qa-rpt-select">
                        <option value="">All</option>
                        <option value="APPROVED">APPROVED</option>
                        <option value="HOLD">HOLD</option>
                        <option value="REJECTED">REJECTED</option>
                        <option value="STOPPED">STOPPED</option>
                        <option value="WIP">WIP</option>
                        <option value="PENDING">PENDING</option>
                    </select>
                </div>

                <div class="qa-rpt-filter-field qa-rpt-filter-search">
                    <label for="qaRptSearch">Search</label>
                    <input
                        id="qaRptSearch"
                        type="text"
                        class="qa-rpt-input"
                        placeholder="Name / email / application ID / mobile"
                    >
                </div>

                <div class="qa-rpt-filter-actions">
                    <button class="btn btn-sm qa-rpt-apply-btn" id="qaRptRefresh" type="button">Apply</button>
                </div>
            </div>

            <div class="qa-rpt-filter-row qa-rpt-filter-row-secondary">
                <div class="qa-rpt-filter-field">
                    <label for="qaRptFrom">From</label>
                    <input id="qaRptFrom" type="date" class="qa-rpt-input">
                </div>
                <div class="qa-rpt-filter-field">
                    <label for="qaRptTo">To</label>
                    <input id="qaRptTo" type="date" class="qa-rpt-input">
                </div>
            </div>
        </div>

        <div class="qa-rpt-export">
            <div id="qaRptExportButtons"></div>
        </div>
    </div>

    <div class="qa-rpt-table-wrap table-scroll">
        <table class="table" id="qaRptTable">
            <thead>
            <tr>
                <th>Client</th>
                <th>Application</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<style>
    .qa-rpt-shell{
        padding:14px;
    }
    .qa-rpt-filters{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:12px;
    }
    .qa-rpt-filters-main{
        flex:1 1 0;
        min-width:0;
    }
    .qa-rpt-filter-row{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:flex-end;
    }
    .qa-rpt-filter-row + .qa-rpt-filter-row{
        margin-top:8px;
    }
    .qa-rpt-filter-field{
        display:flex;
        flex-direction:column;
        gap:4px;
        min-width:150px;
    }
    .qa-rpt-filter-field label{
        font-size:11px;
        font-weight:800;
        color:#64748b;
        text-transform:uppercase;
        letter-spacing:.08em;
        margin:0;
    }
    .qa-rpt-select,
    .qa-rpt-input{
        font-size:13px;
        padding:7px 8px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        min-width:0;
    }
    .qa-rpt-filter-search{
        flex:1 1 220px;
    }
    .qa-rpt-filter-search .qa-rpt-input{
        width:100%;
    }
    .qa-rpt-filter-actions{
        display:flex;
        align-items:center;
        gap:8px;
        margin-left:auto;
        flex-wrap:wrap;
    }
    .qa-rpt-apply-btn{
        border-radius:10px;
        font-weight:800;
    }
    .qa-rpt-export{
        display:flex;
        align-items:flex-start;
        justify-content:flex-end;
        min-width:140px;
    }
    .qa-rpt-table-wrap{
        margin-top:4px;
    }

    @media (max-width: 960px){
        .qa-rpt-filter-actions{
            margin-left:0;
        }
        .qa-rpt-export{
            width:100%;
            justify-content:flex-start;
        }
    }
    @media (max-width: 640px){
        .qa-rpt-shell{
            padding:10px;
        }
        .qa-rpt-filter-row{
            flex-direction:column;
            align-items:stretch;
        }
        .qa-rpt-filter-field,
        .qa-rpt-filter-search{
            width:100%;
        }
        .qa-rpt-select,
        .qa-rpt-input{
            width:100%;
        }
        .qa-rpt-filter-actions{
            justify-content:flex-start;
        }
        .qa-rpt-export{
            margin-top:6px;
        }
    }
</style>
<?php
$content = ob_get_clean();
render_layout('Reports Tracking', $roleLabel, $menu, $content);
