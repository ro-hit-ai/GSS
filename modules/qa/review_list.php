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
    <h3 style="margin-bottom:4px;">QA Review List</h3>
    <p class="card-subtitle" style="margin-bottom:0; color:#64748b;">Queue of cases ready for QA review, with filters for client, validator, verifier, and VR group.</p>
</div>

<div class="card qa-review-shell">
    <div id="qaCasesListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div class="qa-review-filters">
        <div class="qa-review-filters-main">
            <div class="qa-review-filter-row">
                <div class="qa-review-filter-field">
                    <label for="qaCasesViewSelect">View</label>
                    <select id="qaCasesViewSelect" class="qa-review-select">
                        <option value="ready">Ready for QA</option>
                        <option value="all">All Cases</option>
                        <option value="pending">Pending</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>

                <div class="qa-review-filter-field qa-review-filter-search">
                    <label for="qaCasesListSearch">Search</label>
                    <input
                        id="qaCasesListSearch"
                        type="text"
                        class="qa-review-input"
                        placeholder="Name / email / application ID / status"
                    >
                </div>

                <div class="qa-review-filter-actions">
                    <button class="btn btn-sm qa-review-refresh-btn" id="qaCasesListRefreshBtn" type="button">Refresh</button>
                    <label class="qa-review-autorefresh">
                        <input type="checkbox" id="qaCasesAutoRefresh" checked>
                        <span>Auto refresh</span>
                    </label>
                </div>
            </div>

            <div class="qa-review-filter-row qa-review-filter-row-secondary">
                <div class="qa-review-filter-field">
                    <label for="qaCasesClientSelect">Client</label>
                    <select id="qaCasesClientSelect" class="qa-review-select"></select>
                </div>
                <div class="qa-review-filter-field">
                    <label for="qaCasesValidatorSelect">Validator</label>
                    <select id="qaCasesValidatorSelect" class="qa-review-select"></select>
                </div>
                <div class="qa-review-filter-field">
                    <label for="qaCasesVerifierSelect">Verifier</label>
                    <select id="qaCasesVerifierSelect" class="qa-review-select"></select>
                </div>
                <div class="qa-review-filter-field">
                    <label for="qaCasesVerifierGroupSelect">VR Group</label>
                    <select id="qaCasesVerifierGroupSelect" class="qa-review-select">
                        <option value="">All</option>
                        <option value="BASIC">BASIC</option>
                        <option value="EDUCATION">EDUCATION</option>
                    </select>
                </div>
                <div class="qa-review-filter-meta">
                    <span id="qaCasesLastUpdated" class="qa-review-last-updated"></span>
                </div>
            </div>
        </div>

        <div class="qa-review-export">
            <div id="qaCasesListExportButtons"></div>
        </div>
    </div>

    <div class="qa-review-table-wrap table-scroll">
        <table class="table" id="qaCasesListTable">
            <thead>
            <tr>
                <th>Case ID</th>
                <th>Application ID</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Stage</th>
                <th>Validator Assigned</th>
                <th>Verifier Assigned</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<style>
    .qa-review-shell{
        padding:14px;
    }
    .qa-review-filters{
        display:flex;
        justify-content:space-between;
        align-items:flex-start;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:12px;
    }
    .qa-review-filters-main{
        flex:1 1 0;
        min-width:0;
    }
    .qa-review-filter-row{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        align-items:flex-end;
    }
    .qa-review-filter-row + .qa-review-filter-row{
        margin-top:8px;
    }
    .qa-review-filter-field{
        display:flex;
        flex-direction:column;
        gap:4px;
        min-width:150px;
    }
    .qa-review-filter-field label{
        font-size:11px;
        font-weight:800;
        color:#64748b;
        text-transform:uppercase;
        letter-spacing:.08em;
        margin:0;
    }
    .qa-review-select,
    .qa-review-input{
        font-size:13px;
        padding:7px 8px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        min-width:0;
    }
    .qa-review-input{
        width:220px;
    }
    .qa-review-filter-search{
        flex:1 1 200px;
    }
    .qa-review-filter-search .qa-review-input{
        width:100%;
    }
    .qa-review-filter-actions{
        display:flex;
        align-items:center;
        gap:8px;
        margin-left:auto;
        flex-wrap:wrap;
    }
    .qa-review-refresh-btn{
        border-radius:10px;
        font-weight:800;
    }
    .qa-review-autorefresh{
        display:flex;
        align-items:center;
        gap:6px;
        font-size:12px;
        color:#334155;
        margin:0;
    }
    .qa-review-autorefresh input[type="checkbox"]{
        margin:0;
    }
    .qa-review-filter-meta{
        display:flex;
        align-items:flex-end;
        margin-left:auto;
        min-width:160px;
    }
    .qa-review-last-updated{
        font-size:12px;
        color:#64748b;
    }
    .qa-review-export{
        display:flex;
        align-items:flex-start;
        justify-content:flex-end;
        min-width:140px;
    }
    .qa-review-table-wrap{
        margin-top:4px;
    }

    @media (max-width: 960px){
        .qa-review-filter-actions{
            margin-left:0;
        }
        .qa-review-filter-meta{
            min-width:0;
        }
        .qa-review-export{
            width:100%;
            justify-content:flex-start;
        }
    }
    @media (max-width: 640px){
        .qa-review-shell{
            padding:10px;
        }
        .qa-review-filter-row{
            flex-direction:column;
            align-items:stretch;
        }
        .qa-review-filter-actions{
            justify-content:flex-start;
        }
        .qa-review-filter-field,
        .qa-review-filter-search{
            width:100%;
        }
        .qa-review-input,
        .qa-review-select{
            width:100%;
        }
        .qa-review-export{
            margin-top:6px;
        }
    }
</style>
<?php
$content = ob_get_clean();
render_layout('QA Review List', $roleLabel, $menu, $content);
