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
<style>
    .qa-dash-page {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }
    .qa-dash-shell {
        border-radius: 16px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: linear-gradient(180deg, #f8fbff 0%, #eef4fb 100%);
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.05);
        padding: 15px;
    }
    .qa-dash-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        flex-wrap: wrap;
    }
    .qa-dash-title {
        margin: 0;
        font-size: 26px;
        font-weight: 900;
        letter-spacing: -0.03em;
        color: #0f172a;
    }
    .qa-dash-subtitle {
        margin-top: 5px;
        max-width: 720px;
        font-size: 12px;
        line-height: 1.5;
        color: #64748b;
    }
    .qa-dash-top-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        border-radius: 999px;
        border: 1px solid rgba(59, 130, 246, 0.16);
        background: rgba(255, 255, 255, 0.92);
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 800;
        color: #1d4ed8;
        box-shadow: 0 6px 16px rgba(15, 23, 42, 0.04);
    }
    .qa-dash-top-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #22c55e;
        box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.12);
    }
    .qa-dash-controls {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .qa-dash-refresh {
        border: 1px solid rgba(148, 163, 184, 0.2);
        background: #ffffff;
        color: #0f172a;
        box-shadow: 0 6px 14px rgba(15, 23, 42, 0.04);
    }
    .qa-dash-auto {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        padding: 7px 11px;
        border-radius: 999px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: rgba(255, 255, 255, 0.9);
        font-size: 12px;
        font-weight: 600;
        color: #334155;
    }
    .qa-zone-title {
        margin: 0 0 3px;
        font-size: 14px;
        font-weight: 700;
        color: #24364b;
        letter-spacing: -0.02em;
    }
    .qa-zone-subtitle {
        margin: 0;
        font-size: 12px;
        color: #64748b;
    }
    .qa-kpi-block,
    .qa-ops-block,
    .qa-watch-block,
    .qa-mini-block {
        margin-top: 12px;
    }
    .qa-kpi-head,
    .qa-ops-head,
    .qa-watch-head,
    .qa-mini-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 8px;
    }
    .qa-kpi-grid {
        display: grid;
        gap: 9px;
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    .qa-kpi-card {
        position: relative;
        overflow: hidden;
        border-radius: 15px;
        border: 1px solid rgba(148, 163, 184, 0.15);
        background: rgba(255, 255, 255, 0.95);
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.05);
        padding: 11px 13px;
        min-height: 92px;
    }
    .qa-kpi-card::after {
        content: '';
        position: absolute;
        inset: 0 auto auto 0;
        width: 100%;
        height: 4px;
        opacity: 0.95;
    }
    .qa-tone-info::after {
        background: linear-gradient(90deg, #2563eb 0%, #60a5fa 100%);
    }
    .qa-tone-attention::after {
        background: linear-gradient(90deg, #d97706 0%, #f59e0b 100%);
    }
    .qa-tone-danger::after {
        background: linear-gradient(90deg, #ea580c 0%, #f97316 100%);
    }
    .qa-kpi-label {
        font-size: 9px;
        line-height: 1.25;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6f8197;
    }
    .qa-kpi-value {
        margin-top: 8px;
        font-size: 26px;
        line-height: 1;
        font-weight: 900;
        color: #0f172a;
        letter-spacing: -0.04em;
    }
    .qa-watch-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 9px;
    }
    .qa-watch-card {
        display: flex;
        flex-direction: column;
        gap: 4px;
        border-radius: 13px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: rgba(255, 255, 255, 0.94);
        box-shadow: 0 10px 20px rgba(15, 23, 42, 0.04);
        padding: 10px 12px;
    }
    .qa-watch-card--info {
        border-color: rgba(59, 130, 246, 0.18);
        background: linear-gradient(180deg, #f8fbff 0%, #eff6ff 100%);
    }
    .qa-watch-card--warning {
        border-color: rgba(245, 158, 11, 0.2);
        background: linear-gradient(180deg, #fffaf2 0%, #fff7ed 100%);
    }
    .qa-watch-card--danger {
        border-color: rgba(251, 113, 133, 0.2);
        background: linear-gradient(180deg, #fff7f8 0%, #fff1f2 100%);
    }
    .qa-watch-label {
        font-size: 9px;
        line-height: 1.3;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6f8197;
    }
    .qa-watch-value {
        font-size: 22px;
        line-height: 1;
        font-weight: 900;
        color: #0f172a;
        letter-spacing: -0.03em;
    }
    .qa-watch-note {
        font-size: 10px;
        line-height: 1.35;
        color: #64748b;
    }
    .qa-exception-strip {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
        margin-top: 9px;
    }
    .qa-exception-chip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        border-radius: 12px;
        border: 1px solid rgba(251, 113, 133, 0.2);
        background: linear-gradient(180deg, #fff7f8 0%, #fff1f2 100%);
        box-shadow: 0 8px 18px rgba(244, 63, 94, 0.04);
        padding: 9px 11px;
    }
    .qa-exception-chip__label {
        font-size: 9px;
        line-height: 1.25;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #be123c;
    }
    .qa-exception-chip__value {
        font-size: 20px;
        line-height: 1;
        font-weight: 900;
        color: #9f1239;
    }
    .qa-mini-strip {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .qa-mini-stat {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-radius: 13px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.04);
        padding: 9px 12px;
    }
    .qa-mini-stat__label {
        font-size: 9px;
        line-height: 1.25;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6f8197;
    }
    .qa-mini-stat__value {
        font-size: 22px;
        line-height: 1;
        font-weight: 900;
        color: #0f172a;
        letter-spacing: -0.03em;
        white-space: nowrap;
    }
    .qa-ops-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
        align-items: start;
    }
    .qa-card-panel {
        border-radius: 15px;
        border: 1px solid rgba(148, 163, 184, 0.16);
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 14px 26px rgba(15, 23, 42, 0.05);
        overflow: hidden;
    }
    .qa-card-panel__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 14px 10px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.12);
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.92) 0%, rgba(255, 255, 255, 0.96) 100%);
    }
    .qa-card-panel__title {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: #24364b;
    }
    .qa-card-panel__subtitle {
        margin-top: 3px;
        font-size: 10px;
        line-height: 1.35;
        color: #64748b;
    }
    .qa-card-panel__tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        border: 1px solid rgba(59, 130, 246, 0.14);
        background: #eff6ff;
        padding: 4px 8px;
        font-size: 10px;
        font-weight: 600;
        color: #1d4ed8;
        white-space: nowrap;
    }
    .qa-card-panel__body {
        padding: 0 14px 13px;
    }
    .qa-assignments-card {
        border-color: rgba(59, 130, 246, 0.17);
        box-shadow: 0 18px 30px rgba(59, 130, 246, 0.07);
    }
    .qa-assignments-card .qa-card-panel__head {
        background: linear-gradient(180deg, #eef6ff 0%, #ffffff 100%);
    }
    .qa-support-stack {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(148, 163, 184, 0.12);
    }
    .qa-support-card {
        border-radius: 12px;
        border: 1px solid rgba(148, 163, 184, 0.1);
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.72) 0%, rgba(255, 255, 255, 0.78) 100%);
        box-shadow: none;
        overflow: hidden;
    }
    .qa-support-card__head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding: 9px 11px 8px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        background: rgba(255, 255, 255, 0.5);
    }
    .qa-support-card__title {
        margin: 0;
        font-size: 13px;
        font-weight: 650;
        letter-spacing: -0.01em;
        color: #31445a;
    }
    .qa-support-card__subtitle {
        margin-top: 2px;
        font-size: 9px;
        line-height: 1.3;
        color: #64748b;
    }
    .qa-support-card__body {
        padding: 0 11px 9px;
    }
    .qa-table-wrap {
        overflow-x: auto;
    }
    .qa-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 620px;
    }
    .qa-table thead th {
        padding: 9px 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.14);
        text-align: left;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #75879b;
        white-space: nowrap;
    }
    .qa-table tbody td {
        padding: 10px 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        vertical-align: top;
    }
    .qa-table tbody tr:last-child td {
        border-bottom: none;
    }
    .qa-name {
        font-weight: 700;
        color: #0f172a;
    }
    .qa-meta {
        margin-top: 3px;
        font-size: 11px;
        line-height: 1.4;
        color: #64748b;
    }
    .qa-app-id {
        font-weight: 700;
        color: #2b5fb8;
    }
    .qa-count-badge,
    .qa-status-badge,
    .qa-queue-badge,
    .qa-group-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        border-radius: 999px;
        padding: 4px 8px;
        font-size: 9px;
        font-weight: 600;
        line-height: 1;
        border: 1px solid transparent;
        white-space: nowrap;
    }
    .qa-support-card .qa-table {
        min-width: 100%;
    }
    .qa-support-card .qa-table thead th {
        padding: 8px 0;
        font-size: 9px;
    }
    .qa-support-card .qa-table tbody td {
        padding: 8px 0;
    }
    .qa-support-card .qa-name {
        font-size: 12px;
    }
    .qa-support-card .qa-meta {
        font-size: 10px;
    }
    .qa-support-card .qa-empty {
        padding: 12px 0 2px;
        font-size: 12px;
    }
    .qa-count-badge {
        min-width: 30px;
        background: #dbeafe;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .qa-queue-badge--vr {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .qa-queue-badge--dbv {
        background: #ecfeff;
        border-color: #a5f3fc;
        color: #0f766e;
    }
    .qa-group-badge {
        background: #f8fafc;
        border-color: #dbe5f0;
        color: #475569;
    }
    .qa-status-badge--info {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1d4ed8;
    }
    .qa-status-badge--attention {
        background: #fff7ed;
        border-color: #fed7aa;
        color: #c2410c;
    }
    .qa-status-badge--danger {
        background: #fff1f2;
        border-color: #fecdd3;
        color: #be123c;
    }
    .qa-status-badge--success {
        background: #ecfdf3;
        border-color: #bbf7d0;
        color: #166534;
    }
    .qa-status-badge--muted {
        background: #f8fafc;
        border-color: #dbe5f0;
        color: #475569;
    }
    .qa-empty {
        padding: 14px 0 2px;
        text-align: center;
        font-size: 13px;
        color: #64748b;
    }
    .qa-empty strong {
        display: block;
        margin-bottom: 4px;
        color: #334155;
    }
    @media (max-width: 1180px) {
        .qa-kpi-grid,
        .qa-watch-grid,
        .qa-exception-strip,
        .qa-mini-strip {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .qa-support-stack {
            grid-template-columns: 1fr;
        }
    }
    @media (max-width: 760px) {
        .qa-dash-shell {
            padding: 13px;
        }
        .qa-dash-title {
            font-size: 22px;
        }
        .qa-kpi-grid,
        .qa-watch-grid,
        .qa-exception-strip,
        .qa-mini-strip {
            grid-template-columns: 1fr;
        }
        .qa-card-panel__head {
            padding: 11px 12px 9px;
        }
        .qa-card-panel__body {
            padding: 0 12px 12px;
        }
        .qa-support-card__head {
            padding: 8px 9px 7px;
        }
        .qa-support-card__body {
            padding: 0 9px 8px;
        }
        .qa-table {
            min-width: 560px;
        }
    }
</style>

<div class="qa-dash-page" id="dashboardContent">
    <div class="card">
        <h3 style="margin-bottom: 4px;"><?php echo $isTeamLead ? 'Team Lead Dashboard' : 'QA Dashboard'; ?></h3>
        <p class="card-subtitle" style="margin: 0;">Sharper operational visibility with the same live data, refresh behavior, and assignment flow.</p>
    </div>

    <div id="qaDashMessage" class="alert" style="display:none;"></div>
    <div class="qa-dash-shell">
        <div class="qa-dash-head">
            <!-- <div>
                <h2 class="qa-dash-title">Live Dashboard</h2>
                <div class="qa-dash-subtitle">A cleaner governance view for live QA operations, assignment visibility, verifier workload, and exception signals without changing how the dashboard behaves.</div>
                <div class="qa-dash-top-chip"><span class="qa-dash-top-dot"></span>Governance View</div>
            </div> -->
            <div class="qa-dash-controls">
                <label class="qa-dash-auto">
                    <input type="checkbox" id="qaDashAutoRefresh" checked>
                    Auto refresh (15s)
                </label>
                <button type="button" class="btn qa-dash-refresh" id="qaDashRefreshBtn">Refresh</button>
            </div>
        </div>

        <section class="qa-kpi-block">
            <div class="qa-kpi-head">
                <div>
                    <h3 class="qa-zone-title">Primary Operational Overview</h3>
                    <p class="qa-zone-subtitle">The live workload and exception signals that matter most when scanning the queue.</p>
                </div>
            </div>
            <div class="qa-kpi-grid">
                <div class="qa-kpi-card qa-tone-info">
                    <div class="qa-kpi-label">VR Under Review</div>
                    <div class="qa-kpi-value" id="qaKpiVrOpen">0</div>
                    <div class="qa-kpi-note">Open verifier items still visible.</div>
                </div>
                <div class="qa-kpi-card qa-tone-info">
                    <div class="qa-kpi-label">DBV Under Review</div>
                    <div class="qa-kpi-value" id="qaKpiDbvOpen">0</div>
                    <div class="qa-kpi-note">Database verification load in review.</div>
                </div>
                <div class="qa-kpi-card qa-tone-attention">
                    <div class="qa-kpi-label">Supervisory Reopens Today</div>
                    <div class="qa-kpi-value" id="qaKpiSupervisoryReopens">0</div>
                    <div class="qa-kpi-note">Same-day supervisory reopen count.</div>
                </div>
                <div class="qa-kpi-card qa-tone-danger">
                    <div class="qa-kpi-label">Reopened Workflows</div>
                    <div class="qa-kpi-value" id="qaKpiReopenedWorkflows">0</div>
                    <div class="qa-kpi-note">Workflows pushed back into review.</div>
                </div>
            </div>
        </section>

        <section class="qa-watch-block">
            <div class="qa-watch-head">
                <div>
                    <h3 class="qa-zone-title">Queue Aging / TAT Watch</h3>
                    <p class="qa-zone-subtitle">Fast scan for older unresolved work and SLA-risk signals that should surface before the queue drifts.</p>
                </div>
            </div>
            <div class="qa-watch-grid">
                <div class="qa-watch-card qa-watch-card--info">
                    <div class="qa-watch-label">Oldest VR Pending</div>
                    <div class="qa-watch-value" id="qaAgingOldestVr">-</div>
                    <div class="qa-watch-note">Oldest unresolved verifier assignment.</div>
                </div>
                <div class="qa-watch-card qa-watch-card--info">
                    <div class="qa-watch-label">Oldest DBV Pending</div>
                    <div class="qa-watch-value" id="qaAgingOldestDbv">-</div>
                    <div class="qa-watch-note">Oldest unresolved DBV assignment.</div>
                </div>
                <div class="qa-watch-card qa-watch-card--warning">
                    <div class="qa-watch-label">Reopened Over SLA</div>
                    <div class="qa-watch-value" id="qaAgingReopenedOverSla">0</div>
                    <div class="qa-watch-note">Reopened workflow items older than 24 hours.</div>
                </div>
                <div class="qa-watch-card qa-watch-card--danger">
                    <div class="qa-watch-label">QA Attention Over SLA</div>
                    <div class="qa-watch-value" id="qaAgingAttentionOverSla">0</div>
                    <div class="qa-watch-note">Assigned QA-visible work older than 24 hours.</div>
                </div>
            </div>
            <div class="qa-exception-strip">
                <div class="qa-exception-chip">
                    <div class="qa-exception-chip__label">Verifier Invalidated</div>
                    <div class="qa-exception-chip__value" id="qaSignalInvalidatedVerifier">0</div>
                </div>
                <div class="qa-exception-chip">
                    <div class="qa-exception-chip__label">QA Invalidated</div>
                    <div class="qa-exception-chip__value" id="qaSignalInvalidatedQa">0</div>
                </div>
            </div>
        </section>

        <section class="qa-mini-block">
            <div class="qa-mini-head">
                <div>
                    <h3 class="qa-zone-title">Supporting Health Signals</h3>
                    <p class="qa-zone-subtitle">Quiet counters kept visible as compact stats.</p>
                </div>
            </div>
            <div class="qa-mini-strip">
                <div class="qa-mini-stat">
                    <div class="qa-mini-stat__label">Active Users</div>
                    <div class="qa-mini-stat__value" id="qaKpiUsersTotal">0</div>
                </div>
                <div class="qa-mini-stat">
                    <div class="qa-mini-stat__label">QA Users</div>
                    <div class="qa-mini-stat__value" id="qaKpiQaUsers">0</div>
                </div>
            </div>
        </section>

        <section class="qa-ops-block">
            <div class="qa-ops-head">
                <div>
                    <h3 class="qa-zone-title">Operational Detail</h3>
                    <p class="qa-zone-subtitle">Assignments stay front and center, with workload tables supporting the live oversight view.</p>
                </div>
            </div>
            <div class="qa-ops-grid">
                <div class="qa-card-panel qa-assignments-card">
                    <div class="qa-card-panel__head">
                        <div>
                            <h4 class="qa-card-panel__title">Who Is Handling Which Case</h4>
                            <div class="qa-card-panel__subtitle">The live assignment surface stays dominant so ownership and queue state are easy to scan first.</div>
                        </div>
                        <div class="qa-card-panel__tag">Primary Work Surface</div>
                    </div>
                    <div class="qa-card-panel__body">
                        <div class="qa-table-wrap">
                            <table class="qa-table">
                                <thead>
                                    <tr>
                                        <th>Queue</th>
                                        <th>Application</th>
                                        <th>Group</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Case Stage</th>
                                    </tr>
                                </thead>
                                <tbody id="qaAssignmentsBody"></tbody>
                            </table>
                        </div>
                        <div class="qa-support-stack">
                            <div class="qa-support-card">
                                <div class="qa-support-card__head">
                                    <div>
                                        <h5 class="qa-support-card__title">Verifier Workload</h5>
                                        <div class="qa-support-card__subtitle">Current VR queue ownership and open item load.</div>
                                    </div>
                                    <div class="qa-card-panel__tag">VR Group Queue</div>
                                </div>
                                <div class="qa-support-card__body">
                                    <div class="qa-table-wrap">
                                        <table class="qa-table">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Open</th>
                                                    <th>Stage</th>
                                                </tr>
                                            </thead>
                                            <tbody id="qaWorkloadVrBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="qa-support-card">
                                <div class="qa-support-card__head">
                                    <div>
                                        <h5 class="qa-support-card__title">DB Verifier Workload</h5>
                                        <div class="qa-support-card__subtitle">DBV queue coverage and active review load.</div>
                                    </div>
                                    <div class="qa-card-panel__tag">DBV</div>
                                </div>
                                <div class="qa-support-card__body">
                                    <div class="qa-table-wrap">
                                        <table class="qa-table">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Open</th>
                                                    <th>Stage</th>
                                                </tr>
                                            </thead>
                                            <tbody id="qaWorkloadDbvBody"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- <div class="qa-support-stack">
                    <div class="qa-card-panel">
                        <div class="qa-card-panel__head">
                            <div>
                                <h4 class="qa-card-panel__title">Verifier Workload</h4>
                                <div class="qa-card-panel__subtitle">Current VR queue ownership and open item load.</div>
                            </div>
                            <div class="qa-card-panel__tag">VR Group Queue</div>
                        </div>
                        <div class="qa-card-panel__body">
                            <div class="qa-table-wrap">
                                <table class="qa-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Open</th>
                                            <th>Stage</th>
                                        </tr>
                                    </thead>
                                    <tbody id="qaWorkloadVrBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="qa-card-panel">
                        <div class="qa-card-panel__head">
                            <div>
                                <h4 class="qa-card-panel__title">DB Verifier Workload</h4>
                                <div class="qa-card-panel__subtitle">DBV queue coverage and active review load.</div>
                            </div>
                            <div class="qa-card-panel__tag">DBV</div>
                        </div>
                        <div class="qa-card-panel__body">
                            <div class="qa-table-wrap">
                                <table class="qa-table">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Open</th>
                                            <th>Stage</th>
                                        </tr>
                                    </thead>
                                    <tbody id="qaWorkloadDbvBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div> -->
            </div>
        </section>
    </div>
</div>

<script src="<?php echo htmlspecialchars(app_url('/js/modules/qa/dashboard.js')); ?>"></script>
<?php
$content = ob_get_clean();
render_layout($isTeamLead ? 'Team Lead Dashboard' : 'QA Dashboard', $roleLabel, $menu, $content);
?>
