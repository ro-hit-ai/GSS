<?php
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

$menu = gss_admin_menu();

$view = strtolower(trim((string)($_GET['view'] ?? '')));
$isStaffView = ($view === 'staff');
$pageTitle = $isStaffView ? 'GSS Users' : 'Client Users';
$pageSubtitle = $isStaffView
    ? 'Staff users (GSS Admin / Verifier / DB Verifier / QA) across customers.'
    : 'Client users for selected customer.';

ob_start();
?>
<!-- <div class="subnav">
    <a href="clients_create.php" class="subnav-link">Customer Settings</a>
    <a href="locations_list.php" class="subnav-link">Locations</a>
    <a href="users_list.php" class="subnav-link active">Users</a>
    <a href="verification_profiles_list.php" class="subnav-link">Verification Profiles</a>
</div> -->

<div class="card">
    <h3><?php echo htmlspecialchars($pageTitle); ?></h3>
    <p class="card-subtitle"><?php echo htmlspecialchars($pageSubtitle); ?></p>
</div>

<div class="card">
    <div id="usersListMessage" style="display:none; margin-bottom: 10px;"></div>

    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px; gap:10px; flex-wrap:wrap;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; width:100%;">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <label style="font-size:13px; margin-right:6px;">Customer</label>
                <select id="usersClientSelect" style="font-size:13px; padding:4px 6px; min-width:260px;"></select>
                <input id="usersListSearch" type="text" placeholder="Search username / name / email" style="font-size:13px; padding:4px 6px;">
                <button class="btn" id="usersListRefreshBtn" type="button">Refresh</button>
            </div>

            <div style="margin-left:auto; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span id="usersListExportButtons" style="display:inline-flex; gap:6px; vertical-align:middle;"></span>
                <?php if ($isStaffView): ?>
                    <a href="staff_user_create.php" id="staffUsersCreateBtn" class="btn btn-secondary" style="text-decoration:none;">Create Staff User</a>
                <?php else: ?>
                    <a href="user_create.php" id="usersCreateBtn" class="btn" style="text-decoration:none;">Create New User</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="overflow:auto;">
        <table class="table" id="usersListTable">
            <thead>
            <tr>
                <th>Customer</th>
                <th>Username</th>
                <th>First Name</th>
                <th>Last Name</th>
                <th>Role</th>
                <th>Status</th>
                <th>Location</th>
            </tr>
            </thead>
            <tbody id="usersListTbody">
            <tr>
                <td colspan="7" style="color:#6b7280;">Loading...</td>
            </tr>
            </tbody>
        </table>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout($pageTitle, 'GSS Admin', $menu, $content);
