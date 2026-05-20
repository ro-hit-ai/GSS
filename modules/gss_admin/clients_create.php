<?php
require_once __DIR__ . '/../../includes/layout.php';
require_once __DIR__ . '/../../includes/auth.php';

require_once __DIR__ . '/../../includes/menus.php';
auth_require_login('gss_admin');

$menu = gss_admin_menu();

ob_start();
?>
<div class="card">
    <h3>Customer Details</h3>
    <p class="card-subtitle">Configure a new client with basic, TAT and SOW details. This is the foundation for all other flows.</p>

    <div class="tabs">
        <button class="tab active" data-tab="client">Client Details</button>
        <button class="tab is-disabled" data-tab="verification" id="clientVerificationTab" aria-disabled="true">Verification Profile</button>
    </div>

    <div id="clientCreateMessage" style="display:none; margin-top: 10px;"></div>

    <div id="tab-client" class="tab-panel active">
        <form method="post" id="clientCreateForm" enctype="multipart/form-data" style="margin-top: 6px;">
            <input type="hidden" name="client_id" id="clientIdField" value="">
            <input type="hidden" name="customer_logo_path" id="customerLogoPathField" value="">
            <input type="hidden" name="sow_pdf_path" id="sowPdfPathField" value="">
            <input type="hidden" name="show_customer_logo" value="1">
            <div class="basic-section">
                <div class="basic-row">
                    <div class="form-control">
                        <label>Customer Name *</label>
                        <input type="text" name="customer_name" required>
                    </div>
                    <div class="form-control">
                        <label>Location</label>
                        <textarea name="location" rows="2"></textarea>
                    </div>
                    <div class="form-control">
                        <label>Customer Logo</label>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <div style="width:54px; height:54px; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center; background:#fff;">
                                <span id="customerLogoPreviewPlaceholder" style="font-size:11px; color:#6b7280;">No logo</span>
                                <img id="customerLogoPreview" alt="Customer Logo" style="display:none; width:100%; height:100%; object-fit:contain;" >
                            </div>
                            <div style="flex:1;">
                                <input type="file" name="customer_logo" id="customerLogoInput" accept="image/*">
                                <small>Allowed: JPG/PNG. Max: 2 MB.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <div class="basic-section">
            <div class="basic-row">
                    <div class="form-control">
                        <label>Authorization Settings</label>
                        <div class="multi-select" data-label-default="Select options">
                            <div class="multi-select-trigger">
                                <span class="multi-select-label">Select options</span>
                                <span class="multi-select-arrow">â–¾</span>
                            </div>
                            <div class="multi-select-dropdown">
                                <label class="basic-toggle">
                                    <input type="checkbox" name="authorization_form_required" value="1">
                                    Authorization Form Required
                                </label>
                                <div class="form-control" style="margin: 6px 0;">
                                    <label style="font-size: 11px; margin-bottom: 4px;">Authorization Type</label>
                                    <label class="basic-toggle" style="margin: 0;">
                                        <input type="radio" name="authorization_type" value="none" checked>
                                        None
                                    </label>
                                    <label class="basic-toggle" style="margin: 0;">
                                        <input type="radio" name="authorization_type" value="consent_checkbox">
                                        Consent Checkbox
                                    </label>
                                    <label class="basic-toggle" style="margin: 0;">
                                        <input type="radio" name="authorization_type" value="digital_signature">
                                        Digital Signature
                                    </label>
                                     <label class="basic-toggle" style="margin: 0;">
                                        <input type="radio" name="authorization_type" value="manual_signature">
                                        Manual Signature
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- <div class="form-control">
                        <label>Delegation Mechanism</label>
                        <select name="delegation_mechanism">
                            <option value="case_level">Case Level</option>
                            <option value="candidate_level">Candidate Level</option>
                        </select>
                    </div> -->
            </div>
        </div>

        <div class="form-grid">
            <div class="form-control">
                <label>GSS TAT (days)</label>
                <input type="number" name="internal_tat" value="3">
            </div>
            <div class="form-control">
                <label>Client TAT (days)</label>
                <input type="number" name="external_tat" value="5">
            </div>
            <div class="form-control">
                <label>Escalation Threshold (days)</label>
                <input type="number" name="escalation_days" value="2">
            </div>
            <div class="form-control">
                <label>Weekend / Holiday Rules</label>
                <select name="weekend_rules">
                    <option value="exclude">Exclude from TAT</option>
                    <option value="include">Include in TAT</option>
                </select>
            </div>
            <div class="form-control">
                <label>Auto Allocation</label>
                <select name="auto_allocation">
                    <option value="on">On</option>
                    <option value="off">Off</option>
                </select>
            </div>
            <div class="form-control">
                <label>Candidate Auto Notification</label>
                <select name="candidate_notification">
                    <option value="on">On</option>
                    <option value="off">Off</option>
                </select>
            </div>
            <div class="form-control">
                <label>SOW Upload</label>
                <div id="sowPdfCurrent" style="font-size:12px; margin-bottom:6px; color:#6b7280; display:none;"></div>
                <input type="file" name="sow_pdf" id="sowPdfInput" accept="application/pdf">
                <small>Allowed: PDF only. Max: 10 MB.</small>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn" id="clientCreateFinalSubmitBtn" type="button">Save Client</button>
        </div>
        </form>
    </div>

    <div id="tab-verification" class="tab-panel">
        <div id="clientVerificationMessage" class="alert" style="display:none; margin-top:10px;"></div>
        <div class="card" style="margin-top:10px;">
            <h3 style="margin:0;">Verification Setup</h3>
            <p class="card-subtitle" style="margin-top:6px;">Create job roles and map verification types for each job role.</p>

            <div class="cv-vp-grid" style="margin-top:12px;">
                <div class="cv-vp-box">
                    <div class="cv-vp-head">
                        <div class="cv-vp-title">Levels</div>
                        <div class="cv-vp-sub">Add / select (multi)</div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                        <input type="text" id="cv_level_new" placeholder="Add level (e.g. L1)" style="flex:1;">
                        <button type="button" class="btn" id="cv_level_add_btn">Add</button>
                    </div>

                    <div class="cv-vp-body" id="cv_level_box" style="margin-top:10px;">
                        <div style="color:#6b7280; font-size:12px;">Loading...</div>
                    </div>
                </div>

                <div class="cv-vp-box">
                    <div class="cv-vp-head">
                        <div class="cv-vp-title">Job Roles</div>
                        <div class="cv-vp-sub">Add / select (multi).</div>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top:10px;">
                        <input type="text" id="cv_jobrole_new" placeholder="Add job role" style="flex:1;">
                        <button type="button" class="btn" id="cv_jobrole_add_btn">Add</button>
                    </div>

                    <div class="cv-vp-body" id="cv_jobrole_box" style="margin-top:10px;">
                        <div style="color:#6b7280; font-size:12px;">Loading...</div>
                    </div>
                </div>

                <div class="cv-vp-box">
                    <div class="cv-vp-head">
                        <div class="cv-vp-title">Stages</div>
                        <div class="cv-vp-sub">Select P1 / P2 / P3</div>
                    </div>

                    <div class="cv-vp-body" id="cv_stage_box" style="margin-top:10px;">
                        <div class="cv-stage-board">
                            <label class="cv-stage-pill cv-stage-col" style="margin:0;">
                                <div class="cv-stage-col-head">
                                    <input type="radio" class="cv_stage_cb" name="cv_stage" value="pre_interview">
                                    <span class="cv-stage-code">P1</span>
                                    <span class="cv-stage-name">Pre-Interview</span>
                                </div>
                                <!-- <div class="cv-stage-drop-hint">Drop types here</div> -->
                            </label>
                            <label class="cv-stage-pill cv-stage-col" style="margin:0;">
                                <div class="cv-stage-col-head">
                                    <input type="radio" class="cv_stage_cb" name="cv_stage" value="post_interview">
                                    <span class="cv-stage-code">P2</span>
                                    <span class="cv-stage-name">Post-Interview</span>
                                </div>
                                <!-- <div class="cv-stage-drop-hint">Drop types here</div> -->
                            </label>
                            <label class="cv-stage-pill cv-stage-col" style="margin:0;">
                                <div class="cv-stage-col-head">
                                    <input type="radio" class="cv_stage_cb" name="cv_stage" value="employee_pool">
                                    <span class="cv-stage-code">P3</span>
                                    <span class="cv-stage-name">Current Employee Pool</span>
                                </div>
                                <!-- <div class="cv-stage-drop-hint">Drop types here</div> -->
                            </label>
                        </div>
                    </div>
                </div>

                <div class="cv-vp-box">
                    <div class="cv-vp-head">
                        <div class="cv-vp-title">Types</div>
                        <div class="cv-vp-sub">All system types (multi).</div>
                    </div>

                    <div class="cv-vp-body" id="cv_types_box" style="margin-top:10px;">
                        <div style="color:#6b7280; font-size:12px;">Select Level and Job Role to load types.</div>
                    </div>
                </div>
            </div>

            <div class="form-actions" style="justify-content:flex-end; margin-top:12px;">
                <button type="button" class="btn" id="cv_verification_save_btn">Save Verification Mapping</button>
            </div>

            <div class="cv-vp-preview" style="margin-top:12px;">
                <div class="cv-vp-head" style="border-bottom:none; padding-bottom:0;">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:10px;">
                        <div>
                            <div class="cv-vp-title">Saved Mapping</div>
                            <div class="cv-vp-sub">Level â†’ Job Role â†’ Stage â†’ Verification Types</div>
                        </div>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <button type="button" class="btn" id="cv_summary_view_list" style="padding:6px 10px;">List</button>
                            <button type="button" class="btn" id="cv_summary_view_kanban" style="padding:6px 10px;">Kanban</button>
                        </div>
                    </div>
                </div>
                <div id="cv_vp_summary" style="margin-top:10px;">
                    <div style="color:#6b7280; font-size:12px;">Loading...</div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .basic-section,
        .basic-row {
            overflow: visible;
        }
        .multi-select {
            position: relative;
            font-size: 12px;
            z-index: 50;
        }
        .multi-select-trigger {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #ffffff;
            cursor: pointer;
            min-height: 30px;
        }
        .multi-select-label {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .multi-select-arrow {
            margin-left: 8px;
            font-size: 10px;
            color: #6b7280;
        }
        .multi-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 9999;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-top: 4px;
            padding: 8px 10px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -4px rgba(0,0,0,0.1);
            max-height: 220px;
            overflow-y: auto;
            display: none;
        }
        .multi-select.open .multi-select-dropdown {
            display: block;
        }
        .multi-select-dropdown .basic-toggle {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
            font-size: 12px;
        }
        .multi-select-dropdown .basic-toggle:last-child {
            margin-bottom: 0;
        }

        .cv-step {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f8fafc;
            padding: 12px;
        }
        .cv-step-head {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        .cv-step-badge {
            width: 28px;
            height: 28px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #111827;
            color: #fff;
            font-weight: 800;
            font-size: 12px;
            flex: 0 0 auto;
        }
        .cv-step-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            margin-top: 1px;
        }
        .cv-step-sub {
            font-size: 12px;
            color: #64748b;
            margin-top: 2px;
        }
        .cv-stage-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
        }
        .cv-stage-pill {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            padding: 8px 10px;
            border: 1px solid rgba(148,163,184,0.45);
            border-radius: 999px;
            background: #ffffff;
            cursor: pointer;
            user-select: none;
            font-size: 12px;
            transition: box-shadow .18s ease, transform .18s ease, border-color .18s ease, background .18s ease;
        }
        .cv-stage-board {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .cv-stage-col {
            border-radius: 14px;
            padding: 10px;
            align-items: stretch;
            flex-direction: column;
            gap: 10px;
            min-height: 86px;
            background: #ffffff;
        }
        .cv-stage-col-head {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .cv-stage-name {
            font-weight: 800;
            color: #0f172a;
        }
        .cv-stage-drop-hint {
            font-size: 12px;
            color: #94a3b8;
            border: 1px dashed rgba(148,163,184,0.55);
            border-radius: 12px;
            padding: 10px;
            background: rgba(2, 132, 199, 0.03);
        }
        .cv-stage-pill.is-on {
            border-color: rgba(37,99,235,0.55);
            background: rgba(37,99,235,0.07);
        }
        .cv-stage-pill:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 28px -18px rgba(2,6,23,0.35);
        }
        .cv-stage-pill.cv-drop-active {
            border-color: rgba(124,58,237,0.6);
            background: rgba(124,58,237,0.08);
            box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
        }
        .cv-stage-pill input {
            margin: 0;
        }
        .cv-stage-code {
            font-size: 11px;
            font-weight: 900;
            letter-spacing: .08em;
            color: #1f2937;
            background: #e5e7eb;
            border-radius: 999px;
            padding: 2px 8px;
        }

        .cv-vp-grid {
            display: grid;
            grid-template-columns: 0.75fr 0.95fr 0.95fr 2.35fr;
            gap: 12px;
        }
        @media (max-width: 1400px) {
            .cv-vp-grid {
                grid-template-columns: 0.9fr 1fr 1fr 1.6fr;
            }
        }
        .cv-vp-box {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 12px;
            min-height: 320px;
            display: flex;
            flex-direction: column;
        }
        .cv-vp-head {
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }
        .cv-vp-title {
            font-size: 13px;
            font-weight: 900;
            color: #0f172a;
        }
        .cv-vp-sub {
            margin-top: 2px;
            font-size: 12px;
            color: #64748b;
        }
        .cv-vp-body {
            flex: 1;
            overflow: auto;
            padding-right: 4px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .cv-tat-grid {
            display: grid;
            grid-template-columns: 1fr 100px 96px 100px 96px 110px;
            gap: 8px;
            align-items: center;
        }
        .cv-tat-grid-wrap {
            width: 100%;
            overflow-x: auto;
            padding-bottom: 2px;
        }
        .cv-tat-grid {
            min-width: 720px;
        }
        .cv-tat-head {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            white-space: nowrap;
        }
        .cv-tat-cell {
            min-width: 0;
        }
        .cv-tat-type {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .cv-tat-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cv-tat-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px;
        }
        .cv-tat-card-title {
            font-size: 12px;
            font-weight: 900;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .cv-tat-card-fields {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .cv-tat-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cv-tat-label {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
        }
        .cv-tat-input-row {
            display: grid;
            grid-template-columns: 1fr 110px;
            gap: 8px;
            align-items: center;
        }
        .cv-tat-card input,
        .cv-tat-card select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            font-size: 12px;
        }
        @media (max-width: 720px) {
            .cv-tat-card-fields {
                grid-template-columns: 1fr;
            }
            .cv-tat-input-row {
                grid-template-columns: 1fr 96px;
            }
        }

        .cv-type-inline {
            margin-left: auto;
            display: flex;
            gap: 6px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .cv-type-inline-row {
            display: grid;
            grid-template-columns: 56px 44px;
            gap: 4px;
            align-items: center;
        }
        .cv-type-inline input,
        .cv-type-inline select {
            width: 100%;
            padding: 4px 6px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
            font-size: 12px;
            height: 28px;
        }
        .cv-type-inline select {
            padding-right: 6px;
        }
        .cv-type-inline .cv_cost {
            width: 68px;
        }
        .cv-type-row {
            padding-top: 8px !important;
            padding-bottom: 8px !important;
        }
        .cv-type-inline input:disabled,
        .cv-type-inline select:disabled {
            background: #f8fafc;
            color: #64748b;
        }
        @media (max-width: 1100px) {
            .cv-type-inline {
                width: 100%;
                margin-left: 0;
                justify-content: flex-start;
                padding-left: 28px;
            }
        }

        @media (max-width: 1100px) {
            .cv-tat-grid {
                grid-template-columns: 1fr 90px 90px;
            }
            .cv-tat-head:nth-child(4),
            .cv-tat-head:nth-child(5),
            .cv-tat-head:nth-child(6) {
                display: none;
            }
        }

        @media (max-width: 720px) {
            .cv-tat-grid {
                grid-template-columns: 1fr;
                min-width: 0;
            }
            .cv-tat-head {
                display: none;
            }
            .cv-tat-cell {
                position: relative;
                padding-top: 16px;
            }
            .cv-tat-cell::before {
                content: attr(data-label);
                position: absolute;
                top: 0;
                left: 0;
                font-size: 10px;
                font-weight: 800;
                color: #64748b;
            }
        }

        .cv-vp-preview {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 12px;
        }
        .cv-flow {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .cv-flow-role {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f8fafc;
            padding: 12px;
        }
        .cv-flow-role-title {
            font-size: 13px;
            font-weight: 900;
            color: #0f172a;
            margin: 0;
        }
        .cv-flow-row {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 10px;
        }
        .cv-flow-line {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .cv-flow-actions .btn {
            border-radius: 999px;
            font-size: 12px;
        }

        .cv-kanban {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
        }
        @media (max-width: 1100px) {
            .cv-kanban {
                grid-template-columns: 1fr;
            }
        }
        .cv-kanban-col {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #f8fafc;
            padding: 10px;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cv-kanban-col-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e5e7eb;
        }
        .cv-kanban-col-title {
            font-size: 12px;
            font-weight: 900;
            color: #0f172a;
        }
        .cv-kanban-col-count {
            font-size: 11px;
            font-weight: 900;
            color: #334155;
            background: #e5e7eb;
            border-radius: 999px;
            padding: 2px 8px;
        }
        .cv-kanban-cards {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .cv-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px;
            box-shadow: 0 12px 28px -18px rgba(2,6,23,0.25);
        }
        .cv-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }
        .cv-card-title {
            font-size: 12px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.2;
        }
        .cv-card-sub {
            margin-top: 4px;
            font-size: 12px;
            color: #64748b;
        }
        .cv-card-types {
            margin-top: 10px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .cv-card-type {
            font-size: 12px;
            background: #ffffff;
            border: 1px solid rgba(148,163,184,0.45);
            padding: 4px 8px;
            border-radius: 999px;
            color: #334155;
        }
        .cv-flow-pill {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            border: 1px solid rgba(148,163,184,0.45);
            background: #ffffff;
            border-radius: 999px;
            padding: 6px 10px;
            font-size: 12px;
            color: #0f172a;
        }
        .cv-flow-arrow {
            color: #94a3b8;
            font-weight: 900;
            font-size: 14px;
        }
        .cv-flow-types {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .cv-flow-type {
            font-size: 12px;
            background: #ffffff;
            border: 1px solid rgba(148,163,184,0.45);
            padding: 4px 8px;
            border-radius: 999px;
            color: #334155;
        }

        .cv-type-row {
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease, background .18s ease;
            border-radius: 10px;
        }
        .cv-type-row:hover {
            background: rgba(2, 132, 199, 0.04);
            box-shadow: 0 12px 28px -18px rgba(2,6,23,0.35);
            transform: translateY(-1px);
        }
        .cv-type-row.cv-dragging {
            opacity: 0.65;
            transform: scale(0.99);
        }

        .cv-selected-types {
            display: none;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background: #ffffff;
            padding: 10px;
        }
        .cv-selected-types.is-on {
            display: block;
        }
        .cv-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .cv-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            border: 1px solid rgba(148,163,184,0.45);
            background: rgba(2, 132, 199, 0.04);
            color: #0f172a;
            padding: 6px 10px;
            border-radius: 999px;
        }
        .cv-chip button {
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 900;
            color: #64748b;
            line-height: 1;
            padding: 0;
        }
        .cv-acc-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            user-select: none;
        }
        .cv-acc-badge {
            font-size: 11px;
            font-weight: 800;
            color: #334155;
            background: #e5e7eb;
            border-radius: 999px;
            padding: 2px 8px;
        }
        .cv-acc-body {
            display: none;
        }
        .cv-acc-open .cv-acc-body {
            display: block;
        }

        .cv-flow-level {
            position: relative;
            padding-left: 14px;
        }
        .cv-flow-level::before {
            content: "";
            position: absolute;
            left: 6px;
            top: 36px;
            bottom: 10px;
            width: 2px;
            background: #e2e8f0;
            border-radius: 2px;
        }
        .cv-flow-level-head {
            position: relative;
        }
        .cv-flow-level-head::before {
            content: "";
            position: absolute;
            left: -10px;
            top: 50%;
            width: 10px;
            height: 2px;
            background: #e2e8f0;
            border-radius: 2px;
        }
        .cv-flow-stage-row {
            position: relative;
        }
        .cv-flow-stage-row::before {
            content: "";
            position: absolute;
            left: -8px;
            top: 50%;
            width: 8px;
            height: 2px;
            background: #e2e8f0;
            border-radius: 2px;
        }
    </style>

</div>
<?php
$content = ob_get_clean();
render_layout('Create Client', 'GSS Admin', $menu, $content);
?> <!-- EOF -->
