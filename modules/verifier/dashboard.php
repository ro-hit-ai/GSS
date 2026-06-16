<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('verifier');

$menu = verifier_menu();

ob_start();
?>
<style>
*,*::before,*::after{box-sizing:border-box;}

/* ── Page shell ──────────────────────────────────────────────────── */
.vr-page{
    display:flex;flex-direction:column;gap:0;
    margin:-20px -28px -32px;
    min-height:calc(100vh - 60px);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
}

/* ── Single content rail ─────────────────────────────────────────── */
.vr-inner{
    width:100%;max-width:1440px;
    margin-left:auto;margin-right:auto;
    padding-left:24px;padding-right:24px;
}

/* ═══════════════════════════════════════════════════════════════════
   HEADER
═══════════════════════════════════════════════════════════════════ */
.vr-header{background:#fff;border-bottom:1px solid #e5e7eb;padding-top:18px;}
.vr-header .vr-inner{padding-bottom:16px;}
.vr-header-title{
    font-size:18px;font-weight:700;color:#111827;
    margin:0 0 2px;letter-spacing:-.01em;
}
.vr-header-sub{font-size:12px;color:#6b7280;margin:0;}

/* ═══════════════════════════════════════════════════════════════════
   KPI STRIP — compact metric cards
═══════════════════════════════════════════════════════════════════ */
.vr-kpi-strip{background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:14px 0;}
.vr-kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.vr-kpi{
    background:#fff;border:1px solid #e5e7eb;
    border-radius:4px;              /* flat corners — not bubbly */
    padding:14px 16px 12px;
    display:flex;flex-direction:column;
    height:90px;
}
.vr-kpi-n{
    font-size:28px;font-weight:700;color:#111827;
    line-height:1;margin-bottom:6px;
    font-variant-numeric:tabular-nums;
}
.vr-kpi-state{
    font-size:11px;font-weight:600;
    letter-spacing:.04em;text-transform:uppercase;
    line-height:1;margin-bottom:4px;
}
.vr-kpi-state.claimable{color:#1d4ed8;}
.vr-kpi-state.active   {color:#0f766e;}
.vr-kpi-state.all      {color:#6d28d9;}
.vr-kpi-state.completed{color:#15803d;}
.vr-kpi-desc{font-size:11px;color:#9ca3af;line-height:1.3;margin-top:auto;}

/* ═══════════════════════════════════════════════════════════════════
   TAB BAR — text-only underline navigation
═══════════════════════════════════════════════════════════════════ */
.vr-tab-bar{background:#fff;border-bottom:1px solid #e5e7eb;}
.vr-tab-bar .vr-inner{
    display:flex;align-items:stretch;gap:0;
    padding-top:0;padding-bottom:0;
}
.vr-tab{
    display:inline-flex;align-items:center;
    padding:11px 14px;
    font-size:12px;font-weight:600;
    color:#6b7280;               /* inactive: dark gray */
    white-space:nowrap;line-height:1;
    border-bottom:2px solid transparent;
    margin-bottom:-1px;
    /* strip all Bootstrap button chrome */
    background:none !important;
    border-top:none !important;border-left:none !important;border-right:none !important;
    border-radius:0 !important;box-shadow:none !important;
    cursor:pointer;
    transition:color .12s,border-color .12s;
    appearance:none;-webkit-appearance:none;outline:none;
    text-transform:uppercase;letter-spacing:.04em;
}
.vr-tab:hover{color:#111827;}
.vr-tab.active{
    color:#2563eb !important;          /* active: blue */
    border-bottom-color:#2563eb !important;
    font-weight:700;
}
.vr-tab-spacer{flex:1;}
.vr-refresh-btn{
    align-self:center;margin-left:6px;
    border:1px solid #d1d5db;background:#fff;
    border-radius:4px;padding:5px 11px;
    font-size:12px;font-weight:500;color:#374151;
    cursor:pointer;line-height:1.4;
    transition:background .12s;white-space:nowrap;
}
.vr-refresh-btn:hover{background:#f9fafb;}

/* ═══════════════════════════════════════════════════════════════════
   BOARD BODY
═══════════════════════════════════════════════════════════════════ */
.vr-board-body{flex:1;background:#f3f4f6;padding:16px 0 36px;}
.vr-board-body .vr-inner{display:flex;flex-direction:column;gap:8px;}

/* ── Alert / compat note ─── */
.vr-msg{
    border-radius:4px;padding:8px 12px;
    font-size:12px;font-weight:500;line-height:1.4;display:none;
}
.vr-msg.info   {background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;}
.vr-msg.danger {background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.vr-msg.warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a;}
.vr-msg.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.vr-compat-note{
    background:#f0f9ff;border:1px solid #bae6fd;border-radius:4px;
    padding:7px 12px;font-size:12px;color:#0369a1;display:none;
}

/* ═══════════════════════════════════════════════════════════════════
   TOOLBAR
═══════════════════════════════════════════════════════════════════ */
.vr-toolbar{
    display:flex;align-items:center;gap:8px;flex-wrap:wrap;
    background:#fff;
    border:1px solid #e5e7eb;border-bottom:none;
    border-radius:4px 4px 0 0;
    padding:8px 12px;
}
.vr-search-wrap{position:relative;flex:1;min-width:160px;max-width:300px;}
.vr-search-wrap svg{
    position:absolute;left:8px;top:50%;transform:translateY(-50%);
    width:13px;height:13px;stroke:#9ca3af;fill:none;pointer-events:none;
}
.vr-search{
    width:100%;
    border:1px solid #d1d5db;border-radius:3px;
    padding:5px 8px 5px 27px;
    font-size:12px;color:#111827;
    background:#fff;outline:none;
    transition:border-color .12s;
}
.vr-search:focus{border-color:#6366f1;}
.vr-search::placeholder{color:#9ca3af;}
.vr-toolbar-right{display:flex;align-items:center;gap:8px;margin-left:auto;}
.vr-page-size{
    border:1px solid #d1d5db;border-radius:3px;
    padding:4px 6px;font-size:12px;color:#374151;
    background:#fff;cursor:pointer;outline:none;
}
.vr-tbl-info{font-size:12px;color:#6b7280;white-space:nowrap;}

/* ═══════════════════════════════════════════════════════════════════
   DATA GRID
═══════════════════════════════════════════════════════════════════ */
.vr-table-pane{
    background:#fff;
    border:1px solid #e5e7eb;
    border-radius:0 0 4px 4px;
    overflow:hidden;
}
.vr-table-wrap{overflow-x:auto;width:100%;}

.vr-tbl{
    width:100%;border-collapse:collapse;
    font-size:13px;color:#111827;
    table-layout:fixed;
}

/* Column widths */
.vr-tbl col.c-expand {width:32px;}
.vr-tbl col.c-appid  {width:170px;}
.vr-tbl col.c-name   {width:150px;}
.vr-tbl col.c-prio   {width:56px;}
.vr-tbl col.c-comp   {width:155px;}
.vr-tbl col.c-status {width:145px;}
.vr-tbl col.c-claimed{width:120px;}
.vr-tbl col.c-queue  {width:110px;}
.vr-tbl col.c-updated{width:120px;}
.vr-tbl col.c-action {width:100px;}

/* ── Sticky header ── */
.vr-tbl thead{
    position:sticky;top:0;z-index:10;
}
.vr-tbl thead tr{
    background:#fff;
    border-bottom:2px solid #e5e7eb;
    box-shadow:0 1px 0 #e5e7eb;   /* reinforces sticky shadow */
}
.vr-tbl th{
    padding:8px 12px;
    text-align:left;
    font-size:11px;font-weight:600;color:#667085;
    letter-spacing:.05em;text-transform:uppercase;
    white-space:nowrap;user-select:none;
    border-right:1px solid #f3f4f6;
}
.vr-tbl th:last-child{border-right:none;}
.vr-th-sort{cursor:pointer;}
.vr-th-sort:hover{background:#f9fafb;color:#374151;}
.vr-sort-icon{
    display:inline-flex;flex-direction:column;gap:1px;
    margin-left:3px;opacity:.4;vertical-align:middle;
}
.vr-sort-icon span{display:block;width:0;height:0;}
.vr-sort-icon .asc {border-left:3px solid transparent;border-right:3px solid transparent;border-bottom:3px solid #667085;}
.vr-sort-icon .desc{border-left:3px solid transparent;border-right:3px solid transparent;border-top:3px solid #667085;}
th[data-sort-dir="asc"]  .vr-sort-icon .asc {opacity:1;border-bottom-color:#2563eb;}
th[data-sort-dir="desc"] .vr-sort-icon .desc{opacity:1;border-top-color:#2563eb;}

/* ── Body rows — flat, compact, zebra ── */
.vr-tbl tbody tr.vr-data-row{
    height:42px;
    border-bottom:1px solid #e5e7eb;
    cursor:pointer;
    transition:background .08s;
}
/* Zebra striping — applied first, states override */
.vr-tbl tbody tr.vr-data-row:nth-child(4n+3),
.vr-tbl tbody tr.vr-data-row:nth-child(4n+4){
    background:#fafafa;
}
.vr-tbl tbody tr.vr-data-row:hover{background:#f0f4ff !important;}
/* Expanded state only — no color by ownership/lock/completion */
.vr-tbl tbody tr.vr-data-row.is-expanded{background:#eff6ff !important;}

.vr-tbl td{
    padding:8px 12px;
    vertical-align:middle;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
    border-right:1px solid #f3f4f6;
    font-size:13px;
}
.vr-tbl td:last-child{border-right:none;}

/* ── Expand toggle ── */
.vr-expand-btn{
    display:inline-flex;align-items:center;justify-content:center;
    width:18px;height:18px;
    background:none;border:1px solid #d1d5db;
    border-radius:3px;cursor:pointer;
    color:#6b7280;font-size:11px;line-height:1;padding:0;
    transition:background .1s,color .1s,border-color .1s;
}
.vr-expand-btn:hover{background:#eff6ff;color:#2563eb;border-color:#93c5fd;}
.vr-data-row.is-expanded .vr-expand-btn{
    background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;
}

/* ── Accordion row ── */
tr.vr-accordion-row{display:none;}
tr.vr-accordion-row.open{display:table-row;}
tr.vr-accordion-row td{
    padding:0;
    background:#f9fafb;
    border-bottom:2px solid #e5e7eb;
    overflow:visible;white-space:normal;
}
.vr-accordion-inner{padding:12px 14px 12px 32px;}
.vr-accordion-label{
    font-size:10px;font-weight:700;color:#6b7280;
    letter-spacing:.08em;text-transform:uppercase;
    margin-bottom:7px;
}

/* ── Child table ── */
.vr-child-tbl{
    width:100%;border-collapse:collapse;
    font-size:12px;
    border:1px solid #e5e7eb;
}
.vr-child-tbl th{
    background:#f9fafb;padding:6px 10px;
    text-align:left;font-size:11px;font-weight:600;color:#667085;
    letter-spacing:.05em;text-transform:uppercase;
    border-bottom:1px solid #e5e7eb;white-space:nowrap;
}
.vr-child-tbl td{
    padding:6px 10px;
    border-bottom:1px solid #f3f4f6;
    vertical-align:middle;color:#374151;
}
.vr-child-tbl tr:last-child td{border-bottom:none;}
.vr-child-tbl tr:hover td{background:#f9fafb;}

/* ═══════════════════════════════════════════════════════════════════
   PRIORITY — plain text, no pill
   .vr-p1 / .vr-p2 / .vr-p3 / .vr-p-other
═══════════════════════════════════════════════════════════════════ */
.vr-p1    {color:#b42318;font-weight:600;font-size:12px;}   /* red    */
.vr-p2    {color:#175cd3;font-weight:600;font-size:12px;}   /* blue   */
.vr-p3    {color:#667085;font-weight:600;font-size:12px;}   /* gray   */
.vr-p-other{color:#667085;font-weight:500;font-size:12px;}  /* muted  */

/* ═══════════════════════════════════════════════════════════════════
   STATUS — dot + text, no badge background
═══════════════════════════════════════════════════════════════════ */
.vr-status{
    display:inline-flex;align-items:center;gap:5px;
    font-size:12px;font-weight:500;
    white-space:nowrap;
}
.vr-status-dot{
    width:7px;height:7px;
    border-radius:50%;
    flex-shrink:0;
    display:inline-block;
}
/* Dot colours */
.vr-s-ready     .vr-status-dot{background:#16a34a;}
.vr-s-active    .vr-status-dot{background:#2563eb;}
.vr-s-locked    .vr-status-dot{background:#d1d5db;}
.vr-s-correction.vr-status-dot,
.vr-s-correction .vr-status-dot{background:#d97706;}
.vr-s-waiting   .vr-status-dot{background:#ea580c;}
.vr-s-hold      .vr-status-dot{background:#7c3aed;}
.vr-s-completed .vr-status-dot{background:#16a34a;}
.vr-s-pending   .vr-status-dot{background:#9ca3af;}
/* Text colours */
.vr-s-ready    {color:#15803d;}
.vr-s-active   {color:#1d4ed8;}
.vr-s-locked   {color:#9ca3af;}
.vr-s-correction{color:#b45309;}
.vr-s-waiting  {color:#c2410c;}
.vr-s-hold     {color:#6d28d9;}
.vr-s-completed{color:#15803d;}
.vr-s-pending  {color:#6b7280;}

/* Keep legacy .st classes for accordion child table (unchanged) */
.st{font-weight:500;font-size:12px;}
.st-ready    {color:#16a34a;}
.st-active   {color:#1d4ed8;}
.st-locked   {color:#9ca3af;}
.st-correction{color:#b45309;}
.st-waiting  {color:#c2410c;}
.st-hold     {color:#6d28d9;}
.st-completed{color:#15803d;}
.st-pending  {color:#6b7280;}

/* ═══════════════════════════════════════════════════════════════════
   QUEUE STATE
═══════════════════════════════════════════════════════════════════ */
.qs{font-size:12px;font-weight:500;}
.qs-claimable{color:#15803d;}
.qs-active   {color:#1d4ed8;}
.qs-locked   {color:#9ca3af;}
.qs-completed{color:#15803d;}
.qs-other    {color:#6b7280;}

/* ═══════════════════════════════════════════════════════════════════
   APP ID
═══════════════════════════════════════════════════════════════════ */
.vr-appid{
    font-weight:600;color:#1d4ed8;font-size:12px;
    font-family:"SF Mono","Fira Code",monospace;
}

/* ═══════════════════════════════════════════════════════════════════
   ACTION BUTTONS — compact, flat
═══════════════════════════════════════════════════════════════════ */
.vr-action-btn{
    border:1px solid transparent;
    border-radius:3px;
    padding:4px 12px;
    height:28px;
    font-size:12px;font-weight:600;
    cursor:pointer;
    display:inline-flex;align-items:center;justify-content:center;
    line-height:1;white-space:nowrap;
    transition:opacity .12s,filter .12s;
}
.vr-action-btn:hover:not([disabled]){filter:brightness(.92);}
.vr-action-btn.claim{background:#2563eb;color:#fff;border-color:#2563eb;}
.vr-action-btn.open {background:#0f766e;color:#fff;border-color:#0f766e;}
.vr-action-btn.view {background:#f3f4f6;color:#374151;border-color:#d1d5db;}
.vr-action-btn[disabled]{background:#f3f4f6;color:#9ca3af;border-color:#e5e7eb;cursor:not-allowed;}
.vr-locked-label{font-size:11px;font-weight:500;color:#9ca3af;}

/* ═══════════════════════════════════════════════════════════════════
   PAGINATION
═══════════════════════════════════════════════════════════════════ */
.vr-pagination{
    display:flex;align-items:center;justify-content:space-between;
    padding:8px 12px;
    background:#fff;border-top:1px solid #e5e7eb;
    flex-wrap:wrap;gap:6px;
}
.vr-pager{display:flex;align-items:center;gap:3px;}
.vr-pager-btn{
    border:1px solid #e5e7eb;background:#fff;border-radius:3px;
    padding:4px 8px;font-size:12px;font-weight:500;color:#374151;
    cursor:pointer;line-height:1.4;min-width:28px;text-align:center;
    transition:background .1s;
}
.vr-pager-btn:hover:not([disabled]):not(.active){background:#f9fafb;}
.vr-pager-btn.active{background:#2563eb;color:#fff;border-color:#2563eb;}
.vr-pager-btn[disabled]{color:#d1d5db;cursor:not-allowed;}

/* ─── Empty row ── */
.vr-empty-row td{
    padding:32px 20px;text-align:center;
    color:#9ca3af;font-size:13px;
}

/* ═══════════════════════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════════════════════ */
@media(max-width:1000px){
    .vr-kpi-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:700px){
    .vr-page{margin:-20px -14px -32px;}
    .vr-inner{padding-left:14px;padding-right:14px;}
    .vr-kpi-grid{grid-template-columns:1fr 1fr;gap:8px;}
    .vr-tbl col.c-updated,.vr-tbl col.c-queue{width:0;}
    .vr-tbl .c-updated,.vr-tbl .c-queue{display:none;}
}
</style>

<div class="vr-page" id="dashboardContent">

    <!-- Header -->
    <div class="vr-header">
        <div class="vr-inner">
            <div class="vr-header-title">Live Dashboard</div>
            <div class="vr-header-sub">Verifier workload · component claims · priority-gated routing</div>
        </div>
    </div>

    <!-- KPI strip -->
    <div class="vr-kpi-strip">
        <div class="vr-inner">
            <div class="vr-kpi-grid">
                <div class="vr-kpi">
                    <div class="vr-kpi-n" id="vrKpiPending">0</div>
                    <div class="vr-kpi-state claimable">To Be Claimed</div>
                    <div class="vr-kpi-desc">Components ready to claim</div>
                </div>
                <div class="vr-kpi">
                    <div class="vr-kpi-n" id="vrKpiInProgress">0</div>
                    <div class="vr-kpi-state active">Active</div>
                    <div class="vr-kpi-desc">Components owned by you</div>
                </div>
                <div class="vr-kpi">
                    <div class="vr-kpi-n" id="vrKpiFollowUp">0</div>
                    <div class="vr-kpi-state all">All</div>
                    <div class="vr-kpi-desc">Visible work and context</div>
                </div>
                <div class="vr-kpi">
                    <div class="vr-kpi-n" id="vrKpiCompleted">0</div>
                    <div class="vr-kpi-state completed">Completed</div>
                    <div class="vr-kpi-desc">Readonly history</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab bar -->
    <div class="vr-tab-bar">
        <div class="vr-inner" id="vrBucketChips">
            <button type="button" class="vr-tab active" data-bucket="claimable">TO BE CLAIMED</button>
            <button type="button" class="vr-tab" data-bucket="active">ACTIVE</button>
            <button type="button" class="vr-tab" data-bucket="followup">FOLLOW UP</button>
            <button type="button" class="vr-tab" data-bucket="completed">COMPLETED</button>
            <button type="button" class="vr-tab" data-bucket="all">ALL</button>
            <div class="vr-tab-spacer"></div>
            <button type="button" class="vr-refresh-btn" id="vrBoardRefreshBtn">&#8635; Refresh</button>
        </div>
    </div>

    <!-- Board body -->
    <div class="vr-board-body">
        <div class="vr-inner">
            <div id="vrBoardMessage" class="vr-msg"></div>
            <div id="vrBoardCompatNote" class="vr-compat-note">Completed rows are readonly. Active rows claimed by another verifier cannot be opened from this board.</div>

            <!-- Toolbar -->
            <div class="vr-toolbar" id="vrToolbar">
                <div class="vr-search-wrap">
                    <svg viewBox="0 0 24 24" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="vr-search" id="vrSearch" placeholder="Search app ID, candidate…" autocomplete="off">
                </div>
                <div class="vr-toolbar-right">
                    <span class="vr-tbl-info" id="vrTblInfo">— rows</span>
                    <select class="vr-page-size" id="vrPageSize">
                        <option value="25">25 / page</option>
                        <option value="50">50 / page</option>
                        <option value="100">100 / page</option>
                    </select>
                </div>
            </div>

            <!-- Table pane -->
            <div class="vr-table-pane">
                <div class="vr-table-wrap">
                    <table class="vr-tbl" id="vrTable">
                        <colgroup>
                            <col class="c-expand">
                            <col class="c-appid">
                            <col class="c-name">
                            <col class="c-prio">
                            <col class="c-comp">
                            <col class="c-status">
                            <col class="c-claimed">
                            <col class="c-queue">
                            <col class="c-updated">
                            <col class="c-action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th></th>
                                <th class="vr-th-sort" data-col="application_id">Application ID <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th class="vr-th-sort" data-col="candidate_name">Candidate <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th>Priority</th>
                                <th>Component</th>
                                <th class="vr-th-sort" data-col="status">Status <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th class="vr-th-sort" data-col="claimed_by">Claimed By <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th class="vr-th-sort" data-col="queue_state">Queue State <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th class="vr-th-sort" data-col="last_updated">Last Updated <span class="vr-sort-icon"><span class="asc"></span><span class="desc"></span></span></th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="vrBoardRows">
                            <tr class="vr-empty-row"><td colspan="10">Loading…</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="vr-pagination" id="vrPagination" style="display:none;">
                    <span class="vr-tbl-info" id="vrPagerInfo"></span>
                    <div class="vr-pager" id="vrPager"></div>
                </div>
            </div>

        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Verifier Dashboard', 'Component Verifier', $menu, $content);
echo '<script>window.APP_BASE_URL = ' . json_encode(app_base_url()) . ';</script>';
echo '<script src="' . htmlspecialchars(app_url('/js/modules/verifier/dashboard.js')) . '"></script>';
