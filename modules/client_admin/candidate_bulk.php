<?php
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../includes/layout.php';

require_once __DIR__ . '/../../includes/menus.php';
require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('client_admin');

$menu = client_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Bulk Candidate Registration</h3>
    <p class="card-subtitle">Download the sample file, fill candidate basic details and upload for bulk creation.</p>
</div>

<div class="card">
    <h3>Candidate File Upload</h3>
    <div id="candidateBulkMessage" class="alert" style="display:none; margin-top:14px;"></div>

    <form id="candidateBulkForm" class="form-grid" style="margin-top:14px;" enctype="multipart/form-data">
        <input type="hidden" name="client_id" id="bulk_client_id" value="0">
        <input type="hidden" name="created_by_user_id" id="bulk_created_by_user_id" value="0">
        <div class="form-control">
            <label>Location Name *</label>
            <select name="joining_location" id="bulk_joining_location" required>
                <option value="">-- Select --</option>
            </select>
        </div>
        <div class="form-control">
            <label>Job Role *</label>
            <select name="job_role" id="bulk_job_role" required>
                <option value="">-- Select --</option>
            </select>
        </div>
        <div class="form-control">
            <label>Component *</label>
            <select name="component" id="bulk_component" required>
                <option>Basic Details</option>
            </select>
        </div>
        <div class="form-control">
            <label>Recruiter Name *</label>
            <input type="text" name="recruiter_name" id="bulk_recruiter_name" required>
        </div>
        <div class="form-control">
            <label>Recruiter Email *</label>
            <input type="email" name="recruiter_email" id="bulk_recruiter_email" required>
        </div>
        <div class="form-control">
            <label>Upload File *</label>
            <input type="file" name="file" id="bulk_file" accept=".csv,text/csv" required>
            <small>Upload CSV file only (sample format below). If the file includes Location/Job Role/Recruiter columns, those values will be used row-wise.</small>
        </div>
    </form>
    <div class="form-actions">
        <button type="button" class="btn" id="btnBulkUpload">Upload & Send Invites</button>
    </div>
</div>

<div class="card" id="bulkResultsCard" style="display:none;">
    <h3>Bulk Upload Results</h3>
    <div class="table-scroll" style="margin-top:10px;">
        <table class="table" id="bulkResultsTable">
            <thead>
            <tr>
                <th>#</th>
                <th>Candidate</th>
                <th>Email</th>
                <th>Status</th>
                <th>Invite Link</th>
                <th>Message</th>
            </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>Candidate Sample File Download</h3>
    <form class="form-grid" style="margin-top:14px; max-width:420px;">
        <div class="form-control">
            <label>Component</label>
            <select>
                <option>Basic Details</option>
            </select>
        </div>
    </form>
    <div class="form-actions">
        <a class="btn-secondary btn" href="<?php echo htmlspecialchars(app_url('/assets/samples/candidate_bulk_sample.csv')); ?>" download>Download Sample CSV</a>
    </div>
    <p class="card-subtitle" style="margin-top:10px;">Note: Please upload all DATE fields in dd/mm/yyyy format. Components having mandatory fields (*) should not be left blank.</p>
</div>
<?php
$content = ob_get_clean();
render_layout('Bulk Candidate Registration', 'Client Admin', $menu, $content);
