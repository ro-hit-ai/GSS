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

$applicationId = isset($_GET['application_id']) ? trim((string)$_GET['application_id']) : '';
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

ob_start();
?>
<div class="card">
    <div class="review-header">
        <div>
            <h3>QA Case Review</h3>
            <p class="card-subtitle">Review case status, add comments, and view full history timeline.</p>
        </div>
        <div class="header-actions">
            <button type="button" class="btn btn-sm btn-primary" id="qaCompleteNextBtn">Complete and Next</button>
            
            <a class="btn btn-sm" href="review_list.php">Back to List</a>
        </div>
    </div>
</div>

<div class="card qa-case-review"
     id="qaCaseReviewShell"
     data-application-id="<?php echo htmlspecialchars($applicationId); ?>"
     data-client-id="<?php echo (int)$clientId; ?>"
     data-role="<?php echo htmlspecialchars($access); ?>">

    <div class="qa-split">

        <!-- LEFT : REPORT -->
        <div class="qa-report-section">
            <div id="qaReportEmpty" class="qa-report-empty">
                <div class="empty-state">
                    <div class="empty-title">No case selected</div>
                    <div class="empty-description">
                        Open a case from <b>Review List</b> to start QA review.
                    </div>
                    <div class="empty-action">
                        <a class="btn btn-sm" href="review_list.php">Go to Review List</a>
                    </div>
                </div>
            </div>

            <iframe id="qaReportFrame"
                    title="Candidate Report"
                    class="qa-report-frame">
            </iframe>
        </div>

        <!-- RIGHT : REMARKS + TIMELINE -->
        <div class="qa-sidebar">

            <!-- HEADER -->
            <div class="sidebar-header">
                <div id="qaCaseMessage" class="case-message"></div>
            </div>

            <!-- REMARKS -->
            <aside class="remarks-panel"
                   aria-label="Remarks">

                <div class="remarks-panel-header">
                    <span>Remarks</span>
                    <span class="badge bg-secondary" id="cvRemarksPanelCount">0</span>
                </div>

                <!-- SCROLL AREA -->
                <div id="cvRemarksPanel"
                     class="remarks-list">
                </div>
            </aside>

            <!-- TIMELINE -->
            <div class="timeline-section">
                <div class="timeline-header">
                    <div>
                        <div class="timeline-title">Timeline</div>
                        <!-- <div class="timeline-subtitle">Chat-style remarks, newest first.</div> -->
                    </div>
                    <button class="btn btn-sm btn-light" id="qaTimelineRefresh">Refresh</button>
                </div>

                <div id="qaTimeline"
                     class="timeline-content">
                </div>

                <!-- CHAT-STYLE COMMENT BOX AT BOTTOM OF TIMELINE -->
                <div class="chat-input-container">
                    <div class="chat-input-wrapper">
                        <div class="chat-input-row">
                            <div class="chat-input-group">
                                <input id="qaCommentText" 
                                       class="chat-input" 
                                       type="text" 
                                       placeholder="Type your remark here..." 
                                       aria-label="Add remark" />
                                <!-- <div class="chat-section-dropdown">
                                    <select id="qaCommentSection" class="chat-section-select">
                                        <option value="general">General</option>
                                        <option value="basic">Basic</option>
                                        <option value="id">Identification</option>
                                        <option value="contact">Contact</option>
                                        <option value="employment">Employment</option>
                                        <option value="education">Education</option>
                                        <option value="reference">Reference</option>
                                        <option value="documents">Documents</option>
                                    </select>
                                </div> -->
                            </div>
                            <button class="chat-send-btn" id="qaCommentAddBtn" type="button" aria-label="Send remark">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="22" y1="2" x2="11" y2="13"></line>
                                    <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>


<style>
    .qa-case-review {
        --border-color: rgba(148, 163, 184, 0.25);
        --border-radius: 0.75rem;
        --spacing-sm: 0.5rem;
        --spacing-md: 0.75rem;
        --spacing-lg: 1rem;
        --primary-color: #3b82f6;
        --primary-light: rgba(59, 130, 246, 0.1);
        --bg-light: #f8fafc;
        --text-dark: #0f172a;
        --text-muted: #64748b;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
        --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    /* Layout improvements */
    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        gap: 0.625rem;
        flex-wrap: wrap;
        margin-bottom: var(--spacing-md);
    }

    .review-header h3 {
        margin-bottom: 0.125rem;
        font-size: 1.25rem;
    }

    .card-subtitle {
        margin-bottom: 0;
        font-size: 0.875rem;
        color: var(--text-muted);
    }

    .header-actions {
        display: flex;
        gap: 0.625rem;
        flex-wrap: wrap;
    }

    /* Main layout */
    .qa-case-review {
        padding: 0;
        overflow: hidden;
        border-radius: var(--border-radius);
        height: calc(100vh - 11rem);
        min-height: 35rem;
    }

    .qa-split {
        display: flex;
        gap: 0;
        height: 100%;
        min-height: inherit;
    }

    /* Report section */
    .qa-report-section {
        flex: 1 1 auto;
        border-right: 1px solid var(--border-color);
        background: var(--bg-light);
        position: relative;
        min-height: 0;
    }

    .qa-report-empty {
        display: none;
        padding: 1.125rem;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: var(--bg-light);
        z-index: 10;
    }

    .empty-state {
        background: #ffffff;
        border: 1px dashed #cbd5e1;
        border-radius: var(--border-radius);
        padding: 1.125rem;
        text-align: center;
    }

    .empty-title {
        font-weight: 900;
        color: var(--text-dark);
        font-size: 0.875rem;
        margin-bottom: 0.375rem;
    }

    .empty-description {
        color: var(--text-muted);
        font-size: 0.8125rem;
    }

    .empty-action {
        margin-top: 0.75rem;
    }

    .qa-report-frame {
        width: 100%;
        height: 100%;
        border: 0;
        display: block;
        background: #fff;
    }

    /* Sidebar */
    .qa-sidebar {
        flex: 0 0 22.5rem;
        max-width: 24rem;
        background: #ffffff;
        display: flex;
        flex-direction: column;
        min-height: 0;
    }

    .sidebar-header {
        padding: 0.75rem;
        border-bottom: 1px solid var(--border-color);
        flex-shrink: 0;
    }

    .case-message {
        display: none;
        margin-bottom: 0.625rem;
        padding: 0.5rem;
        border-radius: 0.5rem;
        font-size: 0.8125rem;
    }

    .remarks-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.625rem;
        flex-wrap: wrap;
    }

    .remarks-title-section {
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }

    .remarks-title {
        font-weight: 900;
        color: var(--text-dark);
        font-size: 0.8125rem;
    }

    /* Filter */
    .qa-filter-wrap {
        position: relative;
    }

    .qa-filter-btn {
        padding: 0.3125rem 0.5rem;
        font-size: 0.6875rem;
        font-weight: 900;
        border-radius: 999px;
        border: 1px solid var(--border-color);
        background: var(--bg-light);
        color: var(--text-dark);
    }

    .qa-filter-btn:hover,
    .qa-filter-btn:focus {
        background: rgba(59, 130, 246, 0.08);
        border-color: rgba(59, 130, 246, 0.3);
        outline: none;
    }

    .qa-filter-menu {
        position: absolute;
        top: calc(100% + 0.375rem);
        left: 0;
        min-width: 10rem;
        max-height: 16.25rem;
        overflow: auto;
        background: #fff;
        border: 1px solid rgba(148, 163, 184, 0.3);
        border-radius: 0.625rem;
        box-shadow: 0 0.625rem 1.5rem rgba(15, 23, 42, 0.14);
        padding: 0.375rem;
        display: none;
        z-index: 30;
    }

    .qa-filter-menu.open {
        display: block;
    }

    .qa-filter-item {
        display: block;
        width: 100%;
        text-align: left;
        border: 0;
        background: transparent;
        color: var(--text-dark);
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.4375rem 0.5rem;
        border-radius: 0.5rem;
        cursor: pointer;
    }

    .qa-filter-item:hover,
    .qa-filter-item:focus {
        background: rgba(59, 130, 246, 0.08);
        outline: none;
    }

    .qa-filter-item.active {
        background: rgba(59, 130, 246, 0.14);
        color: #1d4ed8;
    }

    /* Remarks panel */
    .remarks-panel {
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
       max-height: 35%;
    min-height: 4rem;
        padding: 0.75rem;
        border: 1px solid rgba(148, 163, 184, 0.2);
        border-radius: 0.75rem;
        background: #fff;
        margin: 0;
        box-shadow: 0 0.375rem 1rem rgba(15, 23, 42, 0.05);
        position: relative;
    }

    .remarks-panel-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.625rem;
        padding: 0.625rem;
        border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        background: var(--bg-light);
        font-size: 0.75rem;
        font-weight: 900;
        color: var(--text-dark);
        flex-shrink: 0;
    }

.remarks-list {
    max-height: 100%;
    overflow-y: auto;
}

    /* Scrollbar styling for all browsers */
    .remarks-list {
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.55) transparent;
    }

    .remarks-list::-webkit-scrollbar {
        width: 0.5rem;
    }

    .remarks-list::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.55);
        border-radius: 999px;
        border: 2px solid transparent;
        background-clip: padding-box;
    }

    .remarks-list::-webkit-scrollbar-track {
        background: transparent;
    }

.timeline-section {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
}
    /* Timeline header */
    .timeline-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
        flex-shrink: 0;
    }

    .timeline-title {
        font-weight: 800;
        color: var(--text-dark);
        font-size: 0.875rem;
    }

    .timeline-subtitle {
        font-size: 0.6875rem;
        color: var(--text-muted);
    }

    /* Timeline content */
.timeline-content {
    flex: 1;
    overflow-y: auto;
}
    .timeline-content {
        scrollbar-width: thin;
        scrollbar-color: rgba(148, 163, 184, 0.55) transparent;
    }

    .timeline-content::-webkit-scrollbar {
        width: 0.5rem;
    }

    .timeline-content::-webkit-scrollbar-thumb {
        background: rgba(148, 163, 184, 0.55);
        border-radius: 999px;
        border: 2px solid transparent;
        background-clip: padding-box;
    }

    .timeline-content::-webkit-scrollbar-track {
        background: transparent;
    }

    /* Chat-style input container at bottom of timeline */
    .chat-input-container {
        background: #fff;
        border-top: 1px solid rgba(148, 163, 184, 0.2);
        margin-top: auto;
        flex-shrink: 0;
        padding-top: 0.75rem;
    }

    .chat-input-wrapper {
        background: var(--bg-light);
        border-radius: 1rem;
        padding: 0.5rem;
        box-shadow: var(--shadow-sm);
    }

    .chat-input-row {
        display: flex;
        align-items: flex-start;
        gap: 0.5rem;
    }

    .chat-input-group {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 0.375rem;
    }

    .chat-input {
        width: 100%;
        border: none;
        background: transparent;
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
        color: var(--text-dark);
        outline: none;
        resize: none;
        min-height: 2.5rem;
        line-height: 1.4;
    }

    .chat-input::placeholder {
        color: #94a3b8;
    }

    .chat-input:focus {
        box-shadow: none;
    }

    .chat-section-dropdown {
        margin-top: 0.25rem;
    }

    .chat-section-select {
        width: 100%;
        border: 1px solid rgba(148, 163, 184, 0.3);
        border-radius: 0.5rem;
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-dark);
        background: white;
        cursor: pointer;
        outline: none;
        transition: all 0.2s ease;
    }

    .chat-section-select:hover {
        border-color: rgba(59, 130, 246, 0.5);
    }

    .chat-section-select:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }

    .chat-send-btn {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        border: none;
        border-radius: 50%;
        background: var(--primary-color);
        color: white;
        cursor: pointer;
        transition: all 0.2s ease;
        flex-shrink: 0;
    }

    .chat-send-btn:hover {
        background: #2563eb;
        transform: translateY(-1px);
        box-shadow: var(--shadow-md);
    }

    .chat-send-btn:active {
        transform: translateY(0);
    }

    .chat-send-btn:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3);
    }

    .chat-send-btn svg {
        stroke-width: 2.5;
    }

    /* Timeline items */
    .qa-tl-group {
        margin: 0.75rem 0 0.375rem;
        display: flex;
        align-items: center;
        gap: 0.625rem;
    }

    .qa-tl-group .qa-tl-date {
        font-size: 0.6875rem;
        font-weight: 900;
        color: #334155;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .qa-tl-group .qa-tl-line {
        flex: 1 1 auto;
        height: 1px;
        background: rgba(148, 163, 184, 0.45);
    }

    .qa-tl-item {
        border: 1px solid rgba(148, 163, 184, 0.28);
        border-left-width: 0.375rem;
        border-radius: 0.875rem;
        padding: 0.625rem 0.625rem 0.625rem 0.75rem;
        background: #fff;
        box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03);
    }

    .qa-tl-item[data-kind="comment"] {
        border-left-color: var(--primary-color);
    }

    .qa-tl-item[data-kind="action"] {
        border-left-color: #16a34a;
    }

    .qa-tl-item[data-kind="update"] {
        border-left-color: #f59e0b;
    }

    .qa-tl-item[data-kind="system"] {
        border-left-color: var(--text-muted);
    }

    .qa-tl-top {
        display: flex;
        justify-content: space-between;
        gap: 0.625rem;
        align-items: flex-start;
    }

    .qa-tl-who {
        font-size: 0.8125rem;
        font-weight: 900;
        color: var(--text-dark);
        line-height: 1.1;
    }

    .qa-tl-when {
        font-size: 0.6875rem;
        color: var(--text-muted);
        white-space: nowrap;
    }

    .qa-tl-badges {
        display: flex;
        gap: 0.375rem;
        flex-wrap: wrap;
        margin-top: 0.375rem;
    }

    .qa-tl-badge {
        font-size: 0.6875rem;
        font-weight: 800;
        padding: 0.25rem 0.5rem;
        border-radius: 999px;
        background: #f1f5f9;
        color: var(--text-dark);
        border: 1px solid rgba(148, 163, 184, 0.28);
    }

    .qa-tl-badge.primary {
        background: rgba(59, 130, 246, 0.1);
        border-color: rgba(59, 130, 246, 0.25);
        color: #1d4ed8;
    }

    .qa-tl-badge.success {
        background: rgba(22, 163, 74, 0.1);
        border-color: rgba(22, 163, 74, 0.25);
        color: #166534;
    }

    .qa-tl-badge.warn {
        background: rgba(245, 158, 11, 0.14);
        border-color: rgba(245, 158, 11, 0.22);
        color: #92400e;
    }

    .qa-tl-msg {
        margin-top: 0.375rem;
        font-size: 0.8125rem;
        color: var(--text-dark);
        line-height: 1.35;
        white-space: pre-wrap;
    }

    /* Chat styles */
    .qa-chat-empty {
        color: var(--text-muted);
        font-size: 0.8125rem;
        text-align: center;
        padding: 1rem;
    }

    .qa-chat-day {
        margin: 0.625rem 0 0.25rem;
        text-align: center;
    }

    .qa-chat-day span {
        display: inline-block;
        font-size: 0.625rem;
        font-weight: 900;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #475569;
        background: #e2e8f0;
        border-radius: 999px;
        padding: 0.25rem 0.5rem;
    }

    .qa-chat-item {
        display: flex;
        margin-top: 0.5rem;
    }

    .qa-chat-item.mine {
        justify-content: flex-end;
    }

    .qa-chat-bubble {
        max-width: 88%;
        border: 1px solid rgba(148, 163, 184, 0.24);
        border-radius: 0.75rem;
        padding: 0.5rem 0.625rem;
        background: #fff;
    }

    .qa-chat-item.mine .qa-chat-bubble {
        background: rgba(59, 130, 246, 0.1);
        border-color: rgba(59, 130, 246, 0.28);
    }

    .qa-chat-meta {
        font-size: 0.625rem;
        color: var(--text-muted);
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
    }

    .qa-chat-msg {
        font-size: 0.75rem;
        color: var(--text-dark);
        margin-top: 0.25rem;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .qa-chat-sec {
        font-size: 0.625rem;
        font-weight: 900;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #475569;
        margin-top: 0.25rem;
    }

    /* Responsive design */
    @media (max-width: 68.75rem) {
        .qa-split {
            flex-direction: column;
            height: auto;
        }

        .qa-report-section {
            height: 45vh;
            min-height: 25rem;
            border-right: none;
            border-bottom: 1px solid var(--border-color);
        }

        .qa-sidebar {
            flex: 1 1 auto;
            max-width: none;
            height: auto;
        }

        .remarks-panel {
            min-height: 20rem;
        }

        .timeline-section {
            min-height: 25rem;
        }
        
        .chat-input-row {
            flex-direction: column;
        }
        
        .chat-send-btn {
            width: 100%;
            height: 2.5rem;
            border-radius: 0.5rem;
        }
    }

    /* Button improvements */
    .btn {
        font-weight: 800;
        border-radius: 0.625rem;
        padding: 0.4375rem 0.625rem;
        border: 1px solid rgba(148, 163, 184, 0.35);
        background: #fff;
        color: var(--text-dark);
        font-size: 0.8125rem;
        cursor: pointer;
    }

    .btn:hover,
    .btn:focus {
        background: rgba(59, 130, 246, 0.08);
        border-color: rgba(59, 130, 246, 0.3);
        outline: none;
    }

    .btn-primary {
        background: var(--primary-color);
        color: white;
        border-color: var(--primary-color);
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: #2563eb;
        border-color: #2563eb;
    }

    .btn-light {
        background: var(--bg-light);
    }

    .btn-sm {
        padding: 0.375rem 0.5rem;
        font-size: 0.75rem;
    }

</style>



<?php
$content = ob_get_clean();
render_layout('QA Case Review', $roleLabel, $menu, $content);
