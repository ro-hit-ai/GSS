<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

$menu = gss_admin_menu();

ob_start();
?>
<style>
    .dashboard-table{overflow:visible;}
    .dashboard-table .table{min-width:0;}
</style>
<!-- <div class="subnav">
    <a href="#" class="subnav-link">Profile Settings</a>
    <a href="#" class="subnav-link">Masters Data</a>
    <a href="clients_create.php" class="subnav-link active">Customer Settings</a>
    <a href="#" class="subnav-link">Verification</a>
    <a href="#" class="subnav-link">Dashboard</a>
    <a href="../reports/dashboard.php" class="subnav-link">Reports</a>
    <a href="#" class="subnav-link">License</a>
</div> -->

<div class="card card-hero dashboard-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center dashboard-hero-row">
        <div>
            <h3 class="dashboard-hero-title">GSS Admin Command Center</h3>
            <p class="card-subtitle dashboard-hero-subtitle">High level snapshot of clients and case load (live data).</p>
            <div class="dashboard-hero-meta">
                <span id="gss-admin-clock">--:--:--</span>
                <span id="gss-admin-status">Loading live metrics &hellip;</span>
            </div>
        </div>
        <div class="dashboard-hero-actions">
            <div class="dashboard-hero-actions-row">
                <a class="btn btn-sm dashboard-hero-btn" href="clients_create.php">Create Client</a>
                <a class="btn btn-sm dashboard-hero-btn" href="candidates_list.php">View Cases</a>
            </div>
        </div>
    </div>
</div>

<div class="card dashboard-panel">
    <div id="gssDashMessage" style="display:none; margin-bottom:12px;"></div>
    <div class="row dashboard-flex-row">
        <div class="gss-kpi-card" data-href="clients_list.php">
            <div class="dashboard-kpi-inner">
                <div>
                    <div class="dashboard-kpi-label">Clients</div>
                    <div id="kpiClients" class="dashboard-kpi-value">--</div>
                    <div class="dashboard-kpi-note">View clients</div>
                </div>
                <div class="kpiIcon" data-icon="clients"></div>
            </div>
        </div>
        <div class="gss-kpi-card" data-href="clients_list.php">
            <div class="dashboard-kpi-inner">
                <div>
                    <div class="dashboard-kpi-label">Job Roles</div>
                    <div id="kpiJobRoles" class="dashboard-kpi-value">--</div>
                    <div class="dashboard-kpi-note">Manage in client edit</div>
                </div>
                <div class="kpiIcon" data-icon="roles"></div>
            </div>
        </div>
        <div class="gss-kpi-card" data-href="candidates_list.php">
            <div class="dashboard-kpi-inner">
                <div>
                    <div class="dashboard-kpi-label">Cases In Progress</div>
                    <div id="kpiInProgress" class="dashboard-kpi-value">--</div>
                    <div class="dashboard-kpi-note">Open cases list</div>
                </div>
                <div class="kpiIcon" data-icon="progress"></div>
            </div>
        </div>
        <div class="gss-kpi-card" data-href="candidates_list.php">
            <div class="dashboard-kpi-inner">
                <div>
                    <div class="dashboard-kpi-label">Created Today</div>
                    <div id="kpiToday" class="dashboard-kpi-value">--</div>
                    <div class="dashboard-kpi-note">See today’s cases</div>
                </div>
                <div class="kpiIcon" data-icon="today"></div>
            </div>
        </div>
    </div>
</div>

<div class="row dashboard-flex-row-lg">
    <div class="card dashboard-card-lg">
        <div class="dashboard-card-head">
            <div>
                <h3 class="dashboard-card-title">Cases Trend</h3>
                <p class="card-subtitle dashboard-card-subtitle">New cases created in last 14 days.</p>
            </div>
        </div>
        <div class="dashboard-chart">
            <canvas id="chartCasesTrend" height="260"></canvas>
        </div>
    </div>

    <div class="card dashboard-card-sm">
        <h3 class="dashboard-card-title">Case Status Mix</h3>
        <p class="card-subtitle dashboard-card-subtitle">Distribution of cases by status.</p>
        <div class="dashboard-chart">
            <canvas id="chartCaseStatus" height="260"></canvas>
        </div>
    </div>
</div>

<div class="row dashboard-flex-row-lg">
    <div class="card dashboard-card-xl">
        <div class="dashboard-card-head">
            <div>
                <h3 class="dashboard-card-title">Recent Cases</h3>
                <p class="card-subtitle dashboard-card-subtitle">Latest cases created in the system.</p>
            </div>
        </div>
        <div class="dashboard-table">
            <table class="table">
                <thead>
                <tr>
                    <th>Application ID</th>
                    <th>Client</th>
                    <th>Candidate</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody id="recentCasesBody">
                <tr><td colspan="5" style="color:#64748b;">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card dashboard-card-sm">
        <h3 class="dashboard-card-title">Upcoming Holidays</h3>
        <p class="card-subtitle dashboard-card-subtitle">Next 30 days.</p>
        <div id="upcomingHolidays" class="dashboard-holidays">
            <div style="color:#64748b;">Loading...</div>
        </div>
        <div class="dashboard-holidays-actions">
            <a class="btn btn-sm" href="holiday_calendar.php" style="border-radius:10px;">Manage Holidays</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<?php
$content = ob_get_clean();
render_layout('GSS Admin Dashboard', 'GSS Admin', $menu, $content);
