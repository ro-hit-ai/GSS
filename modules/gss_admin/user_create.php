<?php
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';

$menu = gss_admin_menu();

ob_start();
?>
<!-- <div class="subnav">
    <a href="clients_create.php" class="subnav-link active">Customer Settings</a>
    <a href="verification_profiles_list.php" class="subnav-link">Verification Profiles</a>
</div> -->

<div class="card">
    <h3>Create User</h3>
    <p class="card-subtitle">UI shell for creating client users (Personal + User Type tabs). No save logic yet.</p>

    <div id="userCreateMessage" style="display:none; margin-top: 10px;"></div>

    <div class="tabs">
        <button class="tab active" data-tab="personal">Personal</button>
        <button class="tab" data-tab="usertype">User Type</button>
    </div>

    <form id="userCreateForm" style="margin-top: 6px;">
        <input type="hidden" name="user_id" id="userId" value="">
        <input type="hidden" name="form_action" id="userFormAction" value="save">
        <div class="form-grid" style="margin-bottom:10px;">
            <div class="form-control">
                <label>Client *</label>
                <div id="userClientDropdown" style="position:relative;">
                    <button
                        type="button"
                        id="userClientDropdownToggle"
                        style="width:100%; min-height:44px; padding:10px 14px; border:1px solid #cfd8e3; border-radius:12px; background:#fff; text-align:left; cursor:pointer;"
                    >Select Client</button>
                    <div
                        id="userClientDropdownMenu"
                        style="display:none; position:absolute; top:calc(100% + 6px); left:0; right:0; z-index:30; background:#fff; border:1px solid #d6dfeb; border-radius:12px; box-shadow:0 12px 30px rgba(15, 23, 42, 0.12); padding:10px;"
                    >
                        <input
                            type="text"
                            id="userClientSearch"
                            placeholder="Search client"
                            autocomplete="off"
                            style="margin-bottom:8px;"
                        >
                        <div
                            id="userClientDropdownList"
                            style="max-height:260px; overflow-y:auto; display:flex; flex-direction:column; gap:4px;"
                        ></div>
                    </div>
                </div>
                <select name="client_id" id="userClientSelect" required style="display:none;"></select>
            </div>
        </div>
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
                    <label>Employee Type *</label>
                    <select name="role" required>
                        <option value="customer_admin">Customer Admin</option>
                        <option value="customer_location_admin">Customer Location Admin</option>
                        <option value="customer_candidate_data_entry">Customer Candidate Data Entry</option>
                        <option value="company_recruiter">HR Recruiter</option>
                        <option value="aadhar_validator">Aadhar Validator</option>
                        <option value="api_user">API User</option>
                        <option value="company_manager">Company Manager</option>
                    </select>
                </div>
                <div class="form-control">
                    <label>Location *</label>
                    <select name="locations[]" id="userLocationSelect" multiple required style="min-height: 90px;">
                        <option value="">Select Location</option>
                    </select>
                </div>
                <div class="form-control">
                    <label>Send Login Email</label>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:6px;">
                        <input type="checkbox" id="userSendEmail" name="send_email" value="1">
                        <label for="userSendEmail" style="margin:0;">Send CRM login link + temporary password</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-actions" style="justify-content:flex-end;">
            <button type="button" class="btn" id="userSaveNextBtn">Save &amp; Next</button>
            <button type="submit" class="btn" id="userFinalSubmitBtn" style="margin-left:8px; display:none;">Final Submit</button>
        </div>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var tabs = document.querySelectorAll('.tab');
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    var target = this.getAttribute('data-tab');
                    document.querySelectorAll('.tab').forEach(function (t) {
                        t.classList.remove('active');
                    });
                    document.querySelectorAll('.tab-panel').forEach(function (panel) {
                        panel.classList.remove('active');
                    });
                    this.classList.add('active');
                    var activePanel = document.getElementById('tab-' + target);
                    if (activePanel) {
                        activePanel.classList.add('active');
                    }
                });
            });
        });
    </script>
</div>
<?php
$content = ob_get_clean();
render_layout('Create User', 'GSS Admin', $menu, $content);
