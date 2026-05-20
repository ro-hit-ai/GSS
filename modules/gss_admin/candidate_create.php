<?php
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';

$menu = gss_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Create Candidate Case (GSS Admin)</h3>
    <p class="card-subtitle">Select a client, create a case and send an invite link to the candidate.</p>

    <div id="candidateCreateMessage" class="alert" style="display:none; margin-top:14px;"></div>

    <form id="candidateCreateForm" class="form-grid" style="margin-top:14px;">
        <div class="form-control">
            <label>Select Client *</label>
            <select name="client_id" id="client_id" required>
                <option value="">Loading...</option>
            </select>
        </div>
        <input type="hidden" name="created_by_user_id" id="created_by_user_id" value="0">

        <div class="form-control">
            <label>Candidate First Name *</label>
            <input type="text" name="candidate_first_name" id="candidate_first_name" required>
        </div>
        <div class="form-control">
            <label>Candidate Middle Name</label>
            <input type="text" name="candidate_middle_name" id="candidate_middle_name">
        </div>
        <div class="form-control">
            <label>Candidate Last Name *</label>
            <input type="text" name="candidate_last_name" id="candidate_last_name" required>
        </div>
        <div class="form-control">
            <label>Candidate DOB *</label>
            <input type="date" name="candidate_dob" id="candidate_dob" required>
        </div>
        <div class="form-control">
            <label>Candidate Father Name *</label>
            <input type="text" name="candidate_father_name" id="candidate_father_name" required>
        </div>
        <div class="form-control">
            <label>Candidate Mobile Number *</label>
            <input type="text" name="candidate_mobile" id="candidate_mobile" required>
        </div>
        <div class="form-control">
            <label>Candidate Email *</label>
            <input type="email" name="candidate_email" id="candidate_email" required>
        </div>
        <div class="form-control">
            <label>Candidate State Of Permanent Residence *</label>
            <input type="text" name="candidate_state" id="candidate_state" required>
        </div>
        <div class="form-control">
            <label>Candidate City Of Permanent Residence *</label>
            <input type="text" name="candidate_city" id="candidate_city" required>
        </div>

        <div class="form-control">
            <label>Joining Location *</label>
            <select name="joining_location" id="joining_location" required>
                <option value="">-- Select Client First --</option>
            </select>
        </div>
        <div class="form-control">
            <label>Job Role *</label>
            <select name="job_role" id="job_role" required>
                <option value="">-- Select Client First --</option>
            </select>
        </div>
        <div class="form-control">
            <label>Job Level *</label>
            <select name="job_level" id="selected_level" required>
                <option value="">-- Select Job Level --</option>
            </select>
        </div>
        <div class="form-control">
            <label>Stage *</label>
            <select name="stage_key" id="stage_key" required>
                <option value="">-- Select Stage --</option>
            </select>
        </div>

        <div class="form-control" style="grid-column: 1 / -1;">
            <label>Mapped Checks (Selected Job Role + Job Level + Stage)</label>
            <div id="jobRoleMappingPreview" class="card" style="margin:0; padding:12px; background:#f8fafc;">
                <div class="text-muted" style="font-size:12px;">Select a job role, job level and stage to view mapped verification checks.</div>
            </div>
        </div>
        <div class="form-control">
            <label>Recruiter Name *</label>
            <input type="text" name="recruiter_name" id="recruiter_name" required>
        </div>
        <div class="form-control">
            <label>Recruiter Email *</label>
            <input type="email" name="recruiter_email" id="recruiter_email" required>
        </div>
        <div class="form-control">
            <label>Candidate Reference ID</label>
            <input type="text" name="candidate_reference_id" id="candidate_reference_id">
        </div>
        <div class="form-control">
            <label>Requisition ID</label>
            <input type="text" name="requisition_id" id="requisition_id">
        </div>
        <div class="form-control">
            <label>Customer Cost Center</label>
            <input type="text" name="customer_cost_center" id="customer_cost_center">
        </div>
        <div class="form-control">
            <label>Rehire Candidate</label>
            <select name="rehire_candidate" id="rehire_candidate">
                <option value="0">No</option>
                <option value="1">Yes</option>
            </select>
        </div>
    </form>

    <div class="form-actions">
        <button type="button" class="btn-secondary btn" id="btnCandidateCancel">Cancel</button>
        <button type="button" class="btn" id="btnCandidateSave" style="margin-left:8px;">Create Case & Send Invite</button>
    </div>
</div>
<?php
$content = ob_get_clean();
render_layout('Create Candidate Case', 'GSS Admin', $menu, $content);
