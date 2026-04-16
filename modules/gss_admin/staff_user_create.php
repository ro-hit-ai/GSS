<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('gss_admin');

$menu = gss_admin_menu();

ob_start();
?>
<div class="card">
    <h3><?php echo !empty($_GET['user_id']) ? 'Edit Staff User' : 'Create Staff User'; ?></h3>
    <p class="card-subtitle">Create staff users (GSS Admin / Validator / Verifier / DB Verifier / QA). Location is required for user mapping.</p>

    <div id="staffUserCreateMessage" style="display:none; margin-top: 10px;"></div>

    <div class="tabs">
        <button class="tab active" data-tab="personal">Personal</button>
        <button class="tab" data-tab="usertype">User Type</button>
    </div>

    <form id="staffUserCreateForm" style="margin-top: 6px;">
        <input type="hidden" name="user_id" id="staffUserId" value="">
        <input type="hidden" name="form_action" id="staffUserFormAction" value="save">
        <input type="hidden" name="client_id" id="staffUserClientId" value="">
        <div id="tab-personal" class="tab-panel active">
            <div class="form-grid">
                <div class="form-control">
                    <label>Username *</label>
                    <input type="text" name="username" value="" required>
                </div>
                <div class="form-control">
                    <label>First Name *</label>
                    <input type="text" name="first_name" value="" required>
                </div>
                <div class="form-control">
                    <label>Middle Name</label>
                    <input type="text" name="middle_name" value="">
                </div>
                <div class="form-control">
                    <label>Last Name *</label>
                    <input type="text" name="last_name" value="" required>
                </div>
                <div class="form-control">
                    <label>Phone *</label>
                    <input type="text" name="phone" value="" required>
                </div>
                <div class="form-control">
                    <label>Email *</label>
                    <input type="email" name="email" value="" required>
                </div>
            </div>
        </div>

        <div id="tab-usertype" class="tab-panel">
            <div class="form-grid">
                <div class="form-control">
                    <label>Staff Role *</label>
                    <select name="role" required>
                        <option value="gss_admin">GSS Admin</option>
                        <option value="validator">Validator</option>
                        <option value="verifier">Component Verifier</option>
                        <option value="db_verifier">DB Verifier</option>
                        <option value="qa">QA </option>
                        <option value="team_lead">Team Lead </option>
                    </select>
                </div>
                <div class="form-control">
                    <label>Location *</label>
                    <select name="locations[]" id="staffUserLocationSelect" multiple required style="min-height: 90px;">
                        <option value="">Select Location</option>
                    </select>
                </div>
                <div class="form-control">
                    <label>Allowed Authorization Sections</label>
                    <div id="staffAllowedSectionsHost" style="display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:8px; margin-top:6px;"></div>
                    <div style="font-size:11px; color:#6b7280; margin-top:6px;">Applies to Validator / Verifier / DB Verifier. QA/TL always has full access.</div>
                </div>
                <div class="form-control">
                    <label>Send Login Email</label>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                        <input type="checkbox" id="staffUserSendEmail" name="send_email" value="1">
                        <label for="staffUserSendEmail" style="margin:0;">Send CRM login link + temporary password</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions" style="justify-content:flex-end;">
            <button type="button" class="btn" id="staffUserSaveNextBtn">Save &amp; Next</button>
            <button type="submit" class="btn" id="staffUserFinalSubmitBtn" style="margin-left:8px; display:none;">Final Submit</button>
        </div>
    </form>
</div>
<?php
$content = ob_get_clean();
render_layout('Create Staff User', 'GSS Admin', $menu, $content);
