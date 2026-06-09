(function () {
    var __crToastHost = null;
    function ensureCrToastHost() {
        if (__crToastHost && document.body.contains(__crToastHost)) return __crToastHost;
        if (!document.getElementById('cr-toast-style')) {
            var st = document.createElement('style');
            st.id = 'cr-toast-style';
            st.textContent =
                '.cr-toast-host{position:fixed;top:16px;right:16px;z-index:1200;display:flex;flex-direction:column;gap:8px;max-width:min(420px,calc(100vw - 20px));}' +
                '.cr-toast{border-radius:10px;padding:11px 14px;font-size:13px;font-weight:700;border:1px solid transparent;box-shadow:0 10px 25px rgba(2,6,23,.15);opacity:0;transform:translateY(-8px);transition:all .18s ease;}' +
                '.cr-toast.show{opacity:1;transform:translateY(0);}' +
                '.cr-toast.success{background:#ecfdf3;color:#065f46;border-color:#a7f3d0;}' +
                '.cr-toast.danger,.cr-toast.error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}' +
                '.cr-toast.warning{background:#fffbeb;color:#92400e;border-color:#fde68a;}' +
                '.cr-toast.info{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe;}';
            document.head.appendChild(st);
        }
        __crToastHost = document.createElement('div');
        __crToastHost.className = 'cr-toast-host';
        document.body.appendChild(__crToastHost);
        return __crToastHost;
    }

    function showCrToast(text, type) {
        var msg = String(text || '').trim();
        if (!msg) return;
        var host = ensureCrToastHost();
        var t = String(type || 'info').toLowerCase().trim();
        if (t === 'warn') t = 'warning';
        var item = document.createElement('div');
        item.className = 'cr-toast ' + t;
        item.textContent = msg;
        host.appendChild(item);
        requestAnimationFrame(function () { item.classList.add('show'); });
        setTimeout(function () {
            item.classList.remove('show');
            setTimeout(function () {
                if (item.parentNode) item.parentNode.removeChild(item);
            }, 220);
        }, 3000);
    }
    var ACTIONABLE_COMPONENTS = ['basic', 'id', 'contact', 'education', 'education_reference', 'employment', 'employment_reference', 'reference', 'socialmedia', 'ecourt', 'reports'];
    var REPORT_PAYLOAD = null;
    var CURRENT_APP_ID = '';
    var CURRENT_SECTION_KEY = '';
    var LAST_COMPONENT_SECTION_KEY = '';
    var CURRENT_MODAL_REASON_TYPE = '';
    var TL_CACHE = [];
    var EMAIL_REPLIES_CACHE = [];
    var EMAIL_REPLIES_META = {};
    var EMAIL_REPLIES_LAST_SYNC_AT = 0;
    var EMAIL_REPLIES_LAST_SYNC_APP_ID = '';
    var EMAIL_REPLIES_LAST_SCOPE_KEY = '';
    var EMAIL_REPLIES_SCOPE_CACHE = {};
    var EMAIL_REPLIES_INFLIGHT = null;
    var EMAIL_REPLIES_INFLIGHT_KEY = '';
    var EMAIL_REPLIES_LAST_RENDER_KEY = '';
    var EMAIL_REPLIES_CACHE_READY = false;
    var EMAIL_REPLIES_AUTO_REFRESH_TIMER = null;
    var EMAIL_REPLIES_AUTO_REFRESH_MS = 15000;
    var EMAIL_REPLIES_STALE_SYNC_MS = 60000;
    var EMAIL_REPLIES_FORCE_SYNC_ONCE = false;
    var TL_ACTIVE_FILTER = 'all';
    var COMPONENT_TABLE_ROWS = { id: [], education: [], employment: [] };
    var COMPONENT_TABLE_RENDERED = { id: false, education: false, employment: false };

    var SELECTED_UPLOAD_FILES = [];
    var ACTIVE_ITEM_BY_SECTION = {};
    var ROLE_ACTIONABLE_COMPONENTS = {};
    var ROLE_READONLY_COMPONENTS = {};
    var ROLE_LOCKED_COMPONENTS = {};
    var ROLE_LOCK_REASONS = {};
    var LOAD_REPORT_IN_FLIGHT = false;
    var LOAD_REPORT_PENDING_OPTS = null;
    var REPORT_VERSION_POLL_TIMER = null;
    var REPORT_VERSION_POLL_IN_FLIGHT = false;
    var REPORT_VERSION_CURRENT = null;
    var REPORT_VERSION_DEFERRED = false;
    var REPORT_VERSION_DEFERRED_TIMER = null;
    var REPORT_VERSION_REFRESH_IN_FLIGHT = false;
    var REPORT_VERSION_POLL_MS = 60000;
    try {
        if (!window.__validatorActionDebug || !Array.isArray(window.__validatorActionDebug)) {
            window.__validatorActionDebug = [];
        }
    } catch (_e) {
    }

    var HOLIDAY_SET = {};
    var HOLIDAYS_LOADED = false;
    var SPLIT_PANE_STATE = {
        isOpen: false,
        context: null,
        sourceDocUrl: '',
        sourceMimeType: '',
        uploadFile: null,
        uploadObjectUrl: ''
    };
var PDF_VIEWER_STATE = {
    open: false,
    context: null,
    mode: 'view',
    uploadFile: null,
    uploadUrl: ''
};
    var DOC_VIEWER_STATE = {
        isOpen: false,
        isMaximized: false,
        uploadObjectUrl: '',
        context: null,
        restoreRect: null
    };

    function isLegacyPdfViewerEnabled() {
        return false;
    }

    function qs(name) {
        try {
            return new URLSearchParams(window.location.search || '').get(name);
        } catch (e) {
            return null;
        }
    }

    function sectionKeyForTableHost(hostId) {
        var id = String(hostId || '').toLowerCase().trim();
        if (id === 'cv_identification_table') return 'id';
        if (id === 'cv_education_table') return 'education';
        if (id === 'cv_employment_table') return 'employment';
        return '';
    }

    function tableHostIdForSection(section) {
        var s = String(section || '').toLowerCase().trim();
        if (s === 'id') return 'cv_identification_table';
        if (s === 'education') return 'cv_education_table';
        if (s === 'employment') return 'cv_employment_table';
        return '';
    }

    function deriveRecordItemKey(section, row, idx) {
        section = normSection(section);
        row = row && typeof row === 'object' ? row : {};
        if (row.item_key) return String(row.item_key).toLowerCase().trim();
        if (section === 'id' && row.document_index != null && String(row.document_index).trim() !== '') {
            return 'id:' + String(row.document_index).trim().toLowerCase();
        }
        if (section === 'education' && row.education_index != null && String(row.education_index).trim() !== '') {
            return 'education:' + String(row.education_index).trim().toLowerCase();
        }
        if (section === 'employment' && row.employment_index != null && String(row.employment_index).trim() !== '') {
            return 'employment:' + String(row.employment_index).trim().toLowerCase();
        }
        return section + ':' + String((parseInt(String(idx || '0'), 10) || 0) + 1);
    }

    function getActiveItemKeyForSection(section) {
        var s = normSection(section);
        if (!s) return '';
        if (ACTIVE_ITEM_BY_SECTION[s]) return String(ACTIVE_ITEM_BY_SECTION[s]);
        var hostId = tableHostIdForSection(s);
        if (!hostId) return '';
        var host = document.getElementById(hostId);
        if (!host) return '';
        var k = String(host.dataset.activeRecordItemKey || '').trim();
        if (k) {
            ACTIVE_ITEM_BY_SECTION[s] = k;
        }
        return k;
    }

    function openBsModal(id) {
        try {
            var el = document.getElementById(id);
            if (!el || !window.bootstrap || !window.bootstrap.Modal) return;
            var inst = window.bootstrap.Modal.getOrCreateInstance(el);
            inst.show();
        } catch (e) {
        }
    }

function closeBsModal(id) {
    try {
        var el = document.getElementById(id);
        if (!el) return;

        if (window.bootstrap && window.bootstrap.Modal) {
            var modal = window.bootstrap.Modal.getInstance(el);
            if (modal) {
                modal.hide();
            } else {
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
            }
        } else {
            el.classList.remove('show');
            el.style.display = 'none';
            el.setAttribute('aria-hidden', 'true');
        }

    } catch (e) {
        try { console.warn('Error closing modal:', e); } catch (_e2) {}
    }
}

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function canUseSplitPaneRole() {
        var role = String(getRole() || '').toLowerCase().trim();
        return role === 'validator' || role === 'verifier' || role === 'qa' || role === 'team_lead';
    }

    function isActiveWorkflowStatus(status) {
        var s = String(status || '').toLowerCase().trim();
        return s === 'hold' || s === 'insufficient_documents' || s === 'waiting_candidate' || s === 'reopened' || s === 'blocked';
    }

    function shouldShowResendCandidateAccess(payload) {
        var role = String(getRole() || '').toLowerCase().trim();
        if (['validator', 'verifier', 'qa', 'team_lead', 'gss_admin', 'client_admin'].indexOf(role) === -1) return false;
        var p = payload && typeof payload === 'object' ? payload : {};
        var caseRow = p.case && typeof p.case === 'object' ? p.case : {};
        var caseStatus = String(caseRow.case_status || '').toUpperCase().trim();
        if (caseStatus === 'APPROVED' || caseStatus === 'COMPLETED' || caseStatus === 'CLEAR' || caseStatus === 'ARCHIVED' || caseStatus === 'STOP_BGV' || caseStatus === 'TERMINATED') {
            return false;
        }
        var hasActive = false;
        var comp = p.component_workflow && typeof p.component_workflow === 'object' ? p.component_workflow : {};
        Object.keys(comp).forEach(function (k) {
            var st = comp[k] || {};
            ['candidate', 'validator', 'verifier', 'qa'].forEach(function (sg) {
                var s = st[sg] && st[sg].status ? st[sg].status : '';
                if (isActiveWorkflowStatus(s)) hasActive = true;
            });
        });
        if (hasActive) return true;
        var app = p.application && typeof p.application === 'object' ? p.application : {};
        var appStatus = String(app.status || '').toLowerCase().trim();
        return appStatus === 'waiting_candidate' || appStatus === 'reopened' || caseStatus === 'PENDING_CANDIDATE' || caseStatus === 'CANDIDATE_PENDING';
    }

    function initCandidateAccessResend(getPayload) {
        var btn = document.getElementById('cvResendCandidateAccessBtn');
        var badge = document.getElementById('cvResendMetaBadge');
        if (!btn) return;
        var role = String(getRole() || '').toLowerCase().trim();
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var inFlight = false;

        async function refreshResendMeta(payload) {
            if (!badge) return;
            try {
                var p = payload && typeof payload === 'object' ? payload : (getPayload ? getPayload() : REPORT_PAYLOAD);
                var caseRow = p && p.case ? p.case : {};
                var applicationId = String((caseRow && caseRow.application_id) || '').trim();
                var caseId = parseInt(String((caseRow && caseRow.case_id) || '0'), 10) || 0;
                if (!applicationId && !caseId) {
                    badge.style.display = 'none';
                    return;
                }
                var url = base + '/api/shared/candidate_access_resend_status.php?application_id=' + encodeURIComponent(applicationId || '') + '&case_id=' + encodeURIComponent(String(caseId || ''));
                var res = await fetch(url, { credentials: 'same-origin' });
                var data = await res.json().catch(function () { return null; });
                if (!res.ok || !data || data.status !== 1 || !data.data) {
                    badge.style.display = 'none';
                    return;
                }
                var count = parseInt(String(data.data.resend_count || '0'), 10) || 0;
                var last = String(data.data.last_resent_at || '').trim();
                if (count <= 0) {
                    badge.style.display = 'none';
                    return;
                }
                var ts = last;
                try {
                    ts = last ? window.GSS_DATE.formatDbDateTime(last) : '';
                } catch (_eFmt) {}
                badge.textContent = 'Resent ' + String(count) + ' time(s)' + (ts ? (' | Last: ' + ts) : '');
                badge.style.display = '';
            } catch (_e) {
                badge.style.display = 'none';
            }
        }

        function refreshVisibility() {
            try {
                var p = getPayload ? getPayload() : REPORT_PAYLOAD;
                var show = shouldShowResendCandidateAccess(p);
                btn.style.display = show ? '' : 'none';
                refreshResendMeta(p);
            } catch (_e) {
                btn.style.display = 'none';
                if (badge) badge.style.display = 'none';
            }
        }

        refreshVisibility();
        document.addEventListener('cv:section-changed', refreshVisibility);

        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', async function () {
            if (inFlight) return;
            var payload = getPayload ? getPayload() : REPORT_PAYLOAD;
            var caseRow = payload && payload.case ? payload.case : {};
            var applicationId = String((caseRow && caseRow.application_id) || (qs('application_id') || '')).trim();
            var caseId = parseInt(String((caseRow && caseRow.case_id) || '0'), 10) || 0;
            if (!applicationId && !caseId) {
                setBoxMessage('cvTopMessage', 'Unable to resend access: missing case context.', 'danger');
                return;
            }
            inFlight = true;
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Resending...';
            try {
                var reqId = 'car-' + String(applicationId || caseId) + '-' + String(role) + '-' + String(Date.now());
                var res = await fetch(base + '/api/shared/candidate_access_resend.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        case_id: caseId || null,
                        application_id: applicationId || null,
                        role: role,
                        request_id: reqId,
                        reason: 'Workflow candidate access recovery'
                    })
                });
                var data = await res.json().catch(function () { return null; });
                if (!res.ok || !data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Resend failed');
                }
                setBoxMessage('cvTopMessage', data.message || 'Candidate access resent.', 'success');
                loadTimeline(applicationId || '').catch(function () {});
                refreshResendMeta(payload);
            } catch (e) {
                setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Resend failed', 'danger');
            } finally {
                inFlight = false;
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || 'Resend Candidate Access';
            }
        });
    }

    async function createCandidateCorrectionSession(options) {
        options = options || {};
        var baseUrl = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var applicationId = String(options.applicationId || '').trim();
        var caseId = parseInt(String(options.caseId || '0'), 10) || 0;
        var requestId = String(options.requestId || '').trim();
        if (requestId === '') {
            requestId = 'corr-' + String(applicationId || caseId || 'case') + '-' + String(Date.now());
        }
        var res = await fetch(baseUrl + '/api/shared/correction_session_create.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                case_id: caseId || null,
                application_id: applicationId || null,
                components: Array.isArray(options.components) ? options.components : [],
                reason: String(options.reason || '').trim(),
                request_id: requestId
            })
        });
        var payload = await res.json().catch(function () { return null; });
        return { res: res, payload: payload };
    }

    function initCandidateCorrectionRequest(getPayload) {
        var openBtn = document.getElementById('cvOpenCorrectionModal');
        var sendBtn = document.getElementById('cvCorrectionSendBtn');
        var host = document.getElementById('cvCorrectionComponents');
        var reasonEl = document.getElementById('cvCorrectionReason');
        if (!openBtn || !sendBtn || !host) return;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var busy = false;

        function labelForSection(s) {
            var k = String(s || '').toLowerCase().trim();
            if (k === 'basic') return 'Basic Details';
            if (k === 'id') return 'Identification';
            if (k === 'contact') return 'Contact Information';
            if (k === 'socialmedia') return 'Social Media';
            if (k === 'ecourt') return 'E-Court';
            if (k === 'education') return 'Education';
            if (k === 'education_reference') return 'Education Reference';
            if (k === 'employment') return 'Employment';
            if (k === 'employment_reference') return 'Employment Reference';
            if (k === 'reference') return 'Reference';
            return k;
        }

        async function buildRows() {
            var p = getPayload ? getPayload() : REPORT_PAYLOAD;
            var caseRow = p && p.case ? p.case : {};
            var applicationId = String((caseRow.application_id || '')).trim();
            var caseId = parseInt(String(caseRow.case_id || '0'), 10) || 0;
            var html = [];
            var keys = [];
            try {
                var url = base + '/api/shared/correction_eligible_components.php?application_id=' + encodeURIComponent(applicationId || '') + '&case_id=' + encodeURIComponent(String(caseId || ''));
                var res = await fetch(url, { credentials: 'same-origin' });
                var data = await res.json().catch(function () { return null; });
                if (res.ok && data && data.status === 1 && data.data && Array.isArray(data.data.components)) {
                    keys = data.data.components.slice();
                }
            } catch (_e0) {}
            keys.forEach(function (k) {
                html.push('<label style="display:flex;align-items:center;gap:8px;border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:8px;">'
                    + '<input type="checkbox" class="cv-correction-comp" value="' + escHtml(k) + '" checked>'
                    + '<span>' + escHtml(labelForSection(k)) + '</span>'
                    + '</label>');
            });
            host.innerHTML = html.length ? html.join('') : '<div style="color:#64748b;font-size:12px;">No operationally active components available for correction request.</div>';
        }

        openBtn.addEventListener('click', async function () {
            var activeSection = normSection(CURRENT_SECTION_KEY || activeComponentSectionKey() || '');
            if (activeSection && !canValidatorActOnComponent(activeSection, 'open_correction_modal', 'correction')) {
                return;
            }
            await buildRows();
            setBoxMessage('cvCorrectionMessage', '', '');
            if (reasonEl) reasonEl.value = '';
            openBsModal('cvCorrectionModal');
        });

        sendBtn.addEventListener('click', async function () {
            if (busy) return;
            var checks = Array.prototype.slice.call(host.querySelectorAll('.cv-correction-comp:checked'));
            var components = checks.map(function (c) { return String(c.value || '').trim(); }).filter(Boolean);
            if (!components.length) {
                setBoxMessage('cvCorrectionMessage', 'Select at least one component.', 'danger');
                return;
            }
            var denied = components.find(function (c) { return !canValidatorActOnComponent(c, 'request_correction', 'correction'); });
            if (denied) return;
            var payload = getPayload ? getPayload() : REPORT_PAYLOAD;
            var caseRow = payload && payload.case ? payload.case : {};
            var applicationId = String((caseRow.application_id || '')).trim();
            var caseId = parseInt(String(caseRow.case_id || '0'), 10) || 0;
            if (!applicationId && !caseId) {
                setBoxMessage('cvCorrectionMessage', 'Missing case context.', 'danger');
                return;
            }
            busy = true;
            sendBtn.disabled = true;
            var old = sendBtn.textContent;
            sendBtn.textContent = 'Sending...';
            try {
                var reqId = 'corr-' + String(applicationId || caseId) + '-' + String(Date.now());
                var out = await createCandidateCorrectionSession({
                    caseId: caseId,
                    applicationId: applicationId,
                    components: components,
                    reason: reasonEl ? String(reasonEl.value || '').trim() : '',
                    requestId: reqId
                });
                var res = out.res;
                var data = out.payload;
                if (!res.ok || !data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to create correction session');
                }
                setBoxMessage('cvCorrectionMessage', data.message || 'Correction request sent', 'success');
                setBoxMessage('cvTopMessage', data.message || 'Correction request sent', 'success');
                loadTimeline(applicationId || '').catch(function () {});
                setTimeout(function () { closeBsModal('cvCorrectionModal'); }, 700);
            } catch (e) {
                setBoxMessage('cvCorrectionMessage', (e && e.message) ? e.message : 'Failed to send correction request', 'danger');
            } finally {
                busy = false;
                sendBtn.disabled = false;
                sendBtn.textContent = old || 'Send Correction Request';
            }
        });
    }

    function correctionHistoryHtml(rows) {
        if (!Array.isArray(rows) || rows.length === 0) return 'No correction history yet.';
        return rows.map(function (r) {
            var comp = sectionLabel(String(r.component_key || ''));
            var cycle = parseInt(String(r.cycle_number || '0'), 10) || 0;
            var by = String(r.requested_by_name || r.requested_role || '');
            var role = String(r.requested_role || '');
            var reason = String(r.correction_reason || '');
            var reqAt = String(r.requested_at || r.created_at || '');
            var subAt = String(r.candidate_submitted_at || '');
            var fin = String(r.final_status || r.status || '');
            function fmt(v) { try { return v ? window.GSS_DATE.formatDbDateTime(v) : '-'; } catch (_e) { return v || '-'; } }
            return '<div style="border:1px solid rgba(148,163,184,.35);border-radius:8px;padding:8px;margin-bottom:8px;background:#fff;">'
                + '<div style="display:flex;justify-content:space-between;gap:8px;"><b>' + escHtml(comp) + ' - Cycle ' + escHtml(String(cycle)) + '</b><span style="font-size:11px;color:#64748b;">' + escHtml(fmt(reqAt)) + '</span></div>'
                + '<div style="font-size:12px;color:#334155;">By: ' + escHtml(by) + (role ? (' (' + escHtml(role) + ')') : '') + '</div>'
                + (reason ? ('<div style="font-size:12px;color:#0f172a;">Reason: ' + escHtml(reason) + '</div>') : '')
                + '<div style="font-size:12px;color:#334155;">Candidate Submitted: ' + escHtml(fmt(subAt)) + '</div>'
                + '<div style="font-size:12px;color:#334155;">Final Status: ' + escHtml(fin || '-') + '</div>'
                + '</div>';
        }).join('');
    }

    async function loadCorrectionHistory(applicationId, caseId) {
        var host = document.getElementById('cvCorrectionHistory');
        if (!host) return;
        if (!applicationId && !caseId) { host.innerHTML = 'No correction history yet.'; return; }
        host.innerHTML = 'Loading correction history...';
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        try {
            var url = base + '/api/shared/correction_history.php?application_id=' + encodeURIComponent(applicationId || '') + '&case_id=' + encodeURIComponent(String(caseId || ''));
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1 || !Array.isArray(data.data)) {
                host.innerHTML = 'No correction history yet.';
                return;
            }
            host.innerHTML = correctionHistoryHtml(data.data);
        } catch (_e) {
            host.innerHTML = 'No correction history yet.';
        }
    }

    function isRecordComponent(section) {
        var s = normSection(section);
        return s === 'id' || s === 'education' || s === 'employment';
    }

    function isActionableComponent(section) {
        var s = normSection(section);
        if (ACTIONABLE_COMPONENTS.indexOf(s) === -1) return false;
        if (s === 'basic') return false;
        var role = getRole();
        if (role === 'validator' || role === 'verifier' || role === 'db_verifier') {
            if (ROLE_ACTIONABLE_COMPONENTS && Object.prototype.hasOwnProperty.call(ROLE_ACTIONABLE_COMPONENTS, s)) {
                return !!ROLE_ACTIONABLE_COMPONENTS[s];
            }
            if (ROLE_READONLY_COMPONENTS && ROLE_READONLY_COMPONENTS[s]) return false;
            if (ROLE_LOCKED_COMPONENTS && ROLE_LOCKED_COMPONENTS[s]) return false;
        }
        return true;
    }

    function pushValidatorActionDebug(evt) {
        try {
            if (!window.__validatorActionDebug || !Array.isArray(window.__validatorActionDebug)) {
                window.__validatorActionDebug = [];
            }
            window.__validatorActionDebug.push(evt);
            if (window.__validatorActionDebug.length > 200) {
                window.__validatorActionDebug.shift();
            }
        } catch (_e) {
        }
    }

    function getValidatorComponentAccess(componentKey) {
        var section = normSection(componentKey);
        var role = getRole();
        if (role !== 'validator' && role !== 'verifier' && role !== 'db_verifier') {
            return { actionable: true, readonly: false, denied_reason: '' };
        }
        if (!section || ACTIONABLE_COMPONENTS.indexOf(section) === -1) {
            return { actionable: false, readonly: true, denied_reason: 'invalid_component' };
        }
        if (section === 'basic') {
            return { actionable: false, readonly: true, denied_reason: 'context_only' };
        }
        if (ROLE_LOCKED_COMPONENTS && ROLE_LOCKED_COMPONENTS[section]) {
            return { actionable: false, readonly: true, denied_reason: 'component_locked' };
        }
        if (ROLE_READONLY_COMPONENTS && ROLE_READONLY_COMPONENTS[section]) {
            return { actionable: false, readonly: true, denied_reason: 'component_readonly' };
        }
        if (ROLE_ACTIONABLE_COMPONENTS && Object.keys(ROLE_ACTIONABLE_COMPONENTS).length > 0) {
            if (!ROLE_ACTIONABLE_COMPONENTS[section]) {
                return { actionable: false, readonly: true, denied_reason: 'component_not_actionable' };
            }
        }
        return { actionable: true, readonly: false, denied_reason: '' };
    }

    function canValidatorActOnComponent(componentKey, actionType, uiMessageTarget) {
        var role = getRole();
        var section = normSection(componentKey);
        var access = getValidatorComponentAccess(section);
        var blocked = ((role === 'validator' || role === 'verifier' || role === 'db_verifier' || section === 'basic') && !access.actionable);
        var evt = {
            ts: new Date().toISOString(),
            attempted_action: String(actionType || ''),
            component_key: section,
            actionable: !!access.actionable,
            readonly: !!access.readonly,
            blocked_reason: String(access.denied_reason || ''),
            prevented_backend_call: blocked ? 1 : 0
        };
        pushValidatorActionDebug(evt);
        if (blocked) {
            var msg = access.denied_reason === 'context_only'
                ? 'Basic Details is readonly context for verifier review.'
                : 'Action unavailable for this component in the current view.';
            if (uiMessageTarget === 'split') setSplitPaneMessage(msg, 'error');
            else if (uiMessageTarget === 'pdf') setPdfViewerMessage(msg, 'error');
            else if (uiMessageTarget === 'upload') setBoxMessage('cvUploadMessage', msg, 'warning');
            else if (uiMessageTarget === 'correction') setBoxMessage('cvCorrectionMessage', msg, 'warning');
            else setBoxMessage('cvTopMessage', msg, 'info');
            return false;
        }
        return true;
    }

    function applyRoleActionabilityFromPayload(payload) {
        ROLE_ACTIONABLE_COMPONENTS = {};
        ROLE_READONLY_COMPONENTS = {};
        ROLE_LOCKED_COMPONENTS = {};
        ROLE_LOCK_REASONS = {};
        var d = payload && (payload.data || payload) ? (payload.data || payload) : {};
        var a = d && d.actionability ? d.actionability : {};
        var permissions = d && d.permissions && typeof d.permissions === 'object' ? d.permissions : {};
        var actionable = Array.isArray(a.actionable_components) ? a.actionable_components : [];
        var readonly = Array.isArray(a.readonly_components) ? a.readonly_components : [];
        var locked = Array.isArray(a.locked_components) ? a.locked_components : [];
        var reasons = a && typeof a.lock_reasons === 'object' ? a.lock_reasons : {};
        actionable.forEach(function (k) {
            var nk = normSection(k);
            if (nk) ROLE_ACTIONABLE_COMPONENTS[nk] = true;
        });
        readonly.forEach(function (k) {
            var nk = normSection(k);
            if (nk) ROLE_READONLY_COMPONENTS[nk] = true;
        });
        locked.forEach(function (k) {
            var nk = normSection(k);
            if (!nk) return;
            ROLE_LOCKED_COMPONENTS[nk] = true;
            if (reasons && Object.prototype.hasOwnProperty.call(reasons, nk)) {
                ROLE_LOCK_REASONS[nk] = String(reasons[nk] || '');
            }
        });
        try {
            var reportMode = String((a && a.report_mode) || permissions.report_mode || qs('report_mode') || '').toLowerCase().trim();
            var canTakeAction = !Object.prototype.hasOwnProperty.call(permissions, 'can_take_action')
                || Number(permissions.can_take_action) === 1;
            document.body.dataset.crReportMode = reportMode;
            document.body.dataset.crCanTakeAction = canTakeAction ? '1' : '0';
            document.dispatchEvent(new CustomEvent('cr:actionability-updated', {
                detail: { report_mode: reportMode, can_take_action: canTakeAction ? 1 : 0 }
            }));
        } catch (_e) {
        }
    }

    function activeComponentSectionKey() {
        var sec = normSection(CURRENT_SECTION_KEY || '');
        if (sec && sec !== 'timeline') return sec;
        sec = normSection(LAST_COMPONENT_SECTION_KEY || '');
        if (sec && sec !== 'timeline') return sec;
        var active = document.querySelector('.list-group-item[data-section].active');
        var fromNav = active ? normSection(active.getAttribute('data-section') || '') : '';
        if (fromNav && fromNav !== 'timeline') return fromNav;
        var activePanel = document.querySelector('.candidate-section.cr-active');
        if (activePanel && activePanel.id) {
            var fromPanel = normSection(String(activePanel.id).replace(/^section-/, ''));
            if (fromPanel && fromPanel !== 'timeline') return fromPanel;
        }
        return '';
    }

    function currentRepliesScopeComponent() {
        var sec = normSection(activeComponentSectionKey() || CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || '');
        if (!sec || sec === 'timeline') return '';
        return sec;
    }

    function getReplyViewerRole() {
        var role = String(getRole() || '').toLowerCase().trim();
        if (role === 'db_verifier') return 'verifier';
        if (role === 'team_lead') return 'qa';
        return role;
    }

    function currentReportApplicationId() {
        var fromCurrent = String(CURRENT_APP_ID || '').trim();
        if (fromCurrent) return fromCurrent;
        var fromPayload = REPORT_PAYLOAD && REPORT_PAYLOAD.case ? String(REPORT_PAYLOAD.case.application_id || '').trim() : '';
        if (fromPayload) return fromPayload;
        return String(qs('application_id') || '').trim();
    }

    function getResponsiveReportMode() {
        var vw = Math.max(320, window.innerWidth || 0);
        if (vw <= 767) return 'mobile';
        if (vw <= 1279) return 'tablet';
        return 'desktop';
    }

    function isCompactReportMode() {
        return getResponsiveReportMode() !== 'desktop';
    }

    function updateReviewActionbarTitle(sectionKey) {
        var titleEl = document.querySelector('#crReviewActionbar .cr-review-actionbar-title');
        if (!titleEl) return;
        var section = normSection(sectionKey || activeComponentSectionKey() || CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || '');
        var label = section ? sectionLabel(section) : 'Section';
        titleEl.textContent = getResponsiveReportMode() === 'mobile'
            ? ('Actions - ' + label)
            : ('Section Actions - ' + label);
    }

    function isLikelyImageUrl(url) {
        var lower = String(url || '').toLowerCase();
        return lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.png') || lower.endsWith('.gif') || lower.endsWith('.webp') || lower.endsWith('.bmp') || lower.endsWith('.svg');
    }

    function isLikelyPdfUrl(url) {
        return String(url || '').toLowerCase().indexOf('.pdf') !== -1;
    }

    function buildPdfViewerUrl(url) {
        var raw = String(url || '');
        if (!raw) return '';
        var flags = 'toolbar=0&navpanes=0&scrollbar=0';
        return raw + (raw.indexOf('#') === -1 ? '#' : '&') + flags;
    }

    function splitPaneRenderDoc(hostId, url, mimeType) {
        return;
    }

    function setSplitPaneMessage(text, tone) {
        var el = document.getElementById('cvSplitPaneMessage');
        if (!el) return;
        var t = String(text || '').trim();
        el.classList.remove('error', 'success');
        if (!t) {
            el.textContent = '';
            return;
        }
        if (tone === 'error') el.classList.add('error');
        if (tone === 'success') el.classList.add('success');
        el.textContent = t;
    }

    function updateSplitPaneLayout() {
        var overlay = document.getElementById('cvSplitPaneOverlay');
        if (!overlay) return;
        var hasUpload = !!(SPLIT_PANE_STATE && SPLIT_PANE_STATE.uploadFile);
        overlay.classList.toggle('has-upload-preview', hasUpload);
    }

    function resetSplitPaneUploadState() {
        SPLIT_PANE_STATE.uploadFile = null;
        if (SPLIT_PANE_STATE.uploadObjectUrl) {
            try { URL.revokeObjectURL(SPLIT_PANE_STATE.uploadObjectUrl); } catch (_e) {}
            SPLIT_PANE_STATE.uploadObjectUrl = '';
        }
        var fileEl = document.getElementById('cvSplitPaneFile');
        if (fileEl) fileEl.value = '';
        var metaEl = document.getElementById('cvSplitPaneFileMeta');
        if (metaEl) metaEl.textContent = 'or drag and drop here';
        var reasonEl = document.getElementById('cvSplitPaneReason');
        if (reasonEl) reasonEl.value = '';
        splitPaneRenderDoc('cvSplitPaneUploadPreview', '', '');
        updateSplitPaneLayout();
        setSplitPaneMessage('', '');
    }

    function updateSplitPaneHeader(context) {
        var lineEl = document.getElementById('cvSplitPaneContext');
        if (!lineEl) return;
        context = context || {};
        var parts = [];
        if (context.applicationId) parts.push('Application: ' + context.applicationId);
        if (context.componentKey) parts.push('Component: ' + sectionLabel(context.componentKey));
        if (context.itemKey) parts.push('Item: ' + context.itemKey);
        lineEl.textContent = parts.length ? parts.join(' | ') : 'Selected document';
    }

    function closePane() {
        var overlay = document.getElementById('cvSplitPaneOverlay');
        if (!overlay) return;
        overlay.classList.remove('open');
        overlay.classList.remove('has-upload-preview');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.removeProperty('overflow');
        if (document.querySelector('.cr-report-root.cr-validator-workspace') && String(qs('print') || '') !== '1') {
            document.body.style.overflow = 'hidden';
        }

        SPLIT_PANE_STATE.isOpen = false;
        SPLIT_PANE_STATE.context = null;
        SPLIT_PANE_STATE.sourceDocUrl = '';
        SPLIT_PANE_STATE.sourceMimeType = '';
        resetSplitPaneUploadState();
        splitPaneRenderDoc('cvSplitPaneCandidatePreview', '', '');
    }

    function openPane(docUrl, context) {
        return false;
    }

    function setSplitPaneUploadFile(file) {
        if (!file) return;
        SPLIT_PANE_STATE.uploadFile = file;
        if (SPLIT_PANE_STATE.uploadObjectUrl) {
            try { URL.revokeObjectURL(SPLIT_PANE_STATE.uploadObjectUrl); } catch (_e) {}
            SPLIT_PANE_STATE.uploadObjectUrl = '';
        }
        SPLIT_PANE_STATE.uploadObjectUrl = URL.createObjectURL(file);
        var metaEl = document.getElementById('cvSplitPaneFileMeta');
        if (metaEl) metaEl.textContent = String(file.name || 'Selected file');
        splitPaneRenderDoc('cvSplitPaneUploadPreview', SPLIT_PANE_STATE.uploadObjectUrl, String(file.type || ''));
        updateSplitPaneLayout();
    }

    function detectContextFromDocLink(linkEl) {
        var context = {
            applicationId: CURRENT_APP_ID || '',
            componentKey: '',
            itemKey: ''
        };
        if (!linkEl || !linkEl.closest) return context;
        var host = linkEl.closest('#cv_identification_table, #cv_education_table, #cv_employment_table');
        if (host && host.id) {
            context.componentKey = sectionKeyForTableHost(host.id);
        } else {
            context.componentKey = activeComponentSectionKey();
        }
        context.componentKey = normSection(context.componentKey || '');
        if (context.componentKey) {
            context.itemKey = getActiveItemKeyForSection(context.componentKey);
        }
        return context;
    }

    async function runSplitPaneAction(action) {
        var mode = String(action || '').toLowerCase().trim();
        if (!mode) return;
        var ctx = SPLIT_PANE_STATE.context || {};
        var applicationId = String(ctx.applicationId || CURRENT_APP_ID || '');
        var componentKey = normSection(ctx.componentKey || '');
        var itemKey = String(ctx.itemKey || '');
        var reasonEl = document.getElementById('cvSplitPaneReason');
        var reason = reasonEl ? String(reasonEl.value || '').trim() : '';

        if (!applicationId) {
            setSplitPaneMessage('Application not found for this action.', 'error');
            return;
        }
        if (!componentKey) {
            setSplitPaneMessage('Please open a document from Identification, Education, or Employment.', 'error');
            return;
        }
        if (!isActionableComponent(componentKey)) {
            setSplitPaneMessage('Please select a valid section before taking action.', 'error');
            return;
        }
        if (!canValidatorActOnComponent(componentKey, mode, 'split')) return;
        if (mode === 'reject' || mode === 'hold') {
            if (!reason) {
                setSplitPaneMessage('Reason is required for ' + mode + '.', 'error');
                if (reasonEl) reasonEl.focus();
                return;
            }
        }

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var endpoint = base + '/api/shared/component_action.php';
        var caseId = REPORT_PAYLOAD && REPORT_PAYLOAD.case && REPORT_PAYLOAD.case.case_id ? parseInt(REPORT_PAYLOAD.case.case_id, 10) : 0;
        var role = getRole();
        var group = role === 'verifier' ? (getVerifierGroup() || null) : null;

        var approveBtn = document.getElementById('cvSplitPaneApprove');
        var rejectBtn = document.getElementById('cvSplitPaneReject');
        var holdBtn = document.getElementById('cvSplitPaneHold');
        [approveBtn, rejectBtn, holdBtn].forEach(function (btn) {
            if (btn) btn.disabled = true;
        });
        setSplitPaneMessage('Submitting ' + mode + '...', '');

        try {
            var out = await postJson(endpoint, {
                application_id: applicationId,
                case_id: caseId || null,
                component_key: componentKey,
                item_key: itemKey || null,
                action: mode,
                group: group,
                reason: reason || null,
                override_reason: reason || null
            });
            if (!out.res.ok || !out.payload || out.payload.status !== 1) {
                var message = (out.payload && out.payload.message) ? out.payload.message : 'Failed to submit action.';
                setSplitPaneMessage(message, 'error');
                return;
            }
            setSplitPaneMessage('Action updated successfully.', 'success');
            setBoxMessage('cvTopMessage', 'Updated successfully.', 'success');
            closePane();
            try {
                loadReport({ preserveUi: true, section: componentKey }).catch(function () {});
            } catch (_e) {
            }
        } catch (e) {
            setSplitPaneMessage((e && e.message) ? e.message : 'Network error. Please try again.', 'error');
        } finally {
            [approveBtn, rejectBtn, holdBtn].forEach(function (btn) {
                if (btn) btn.disabled = false;
            });
        }
    }

    function initSplitPaneWorkspace() {
        var overlay = document.getElementById('cvSplitPaneOverlay');
        if (!overlay || overlay.dataset.bound === '1') return;
        overlay.dataset.bound = '1';

        var closeBtn = document.getElementById('cvSplitPaneClose');
        var fileEl = document.getElementById('cvSplitPaneFile');
        var dropEl = document.getElementById('cvSplitPaneDrop');
        var approveBtn = document.getElementById('cvSplitPaneApprove');
        var rejectBtn = document.getElementById('cvSplitPaneReject');
        var holdBtn = document.getElementById('cvSplitPaneHold');

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closePane();
            });
        }
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closePane();
        });
        document.addEventListener('keydown', function (e) {
            if (!SPLIT_PANE_STATE.isOpen) return;
            if (e && e.key === 'Escape') closePane();
        });
        if (fileEl) {
            fileEl.addEventListener('change', function () {
                var file = fileEl.files && fileEl.files[0] ? fileEl.files[0] : null;
                if (file) setSplitPaneUploadFile(file);
            });
        }
        if (dropEl) {
            ['dragenter', 'dragover'].forEach(function (evt) {
                dropEl.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropEl.classList.add('dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (evt2) {
                dropEl.addEventListener(evt2, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropEl.classList.remove('dragover');
                });
            });
            dropEl.addEventListener('drop', function (e) {
                var dt = e.dataTransfer;
                var file = dt && dt.files && dt.files[0] ? dt.files[0] : null;
                if (!file) return;
                if (fileEl) {
                    try {
                        var transfer = new DataTransfer();
                        transfer.items.add(file);
                        fileEl.files = transfer.files;
                    } catch (_e) {
                    }
                }
                setSplitPaneUploadFile(file);
            });
        }
        if (approveBtn) approveBtn.addEventListener('click', function () { runSplitPaneAction('approve'); });
        if (rejectBtn) rejectBtn.addEventListener('click', function () { runSplitPaneAction('reject'); });
        if (holdBtn) holdBtn.addEventListener('click', function () { runSplitPaneAction('hold'); });

        window.openPane = openPane;
        window.closePane = closePane;
    }

    function renderDocViewerContent(hostId, url, mimeType) {
        var host = document.getElementById(hostId);
        if (!host) return;
        var safeUrl = escHtml(url || '');
        var mt = String(mimeType || '').toLowerCase();
        if (!safeUrl) {
            host.innerHTML = '<div class="cv-docviewer-empty">No document selected.</div>';
            return;
        }
        if (isImageMime(mt) || isLikelyImageUrl(safeUrl)) {
            host.innerHTML = '<img src="' + safeUrl + '" alt="document">';
            return;
        }
        if (isPdfMime(mt) || isLikelyPdfUrl(safeUrl)) {
            var pdfUrl = escHtml(buildPdfViewerUrl(safeUrl));
            host.innerHTML = '<iframe src="' + pdfUrl + '" width="100%" height="100%" style="border:none;"></iframe>';
            return;
        }
        host.innerHTML = '<div class="cv-docviewer-empty">Preview unavailable. <a href="' + safeUrl + '" target="_blank" rel="noopener">Open document</a></div>';
    }

    function resetDocViewerUpload() {
        if (DOC_VIEWER_STATE.uploadObjectUrl) {
            try { URL.revokeObjectURL(DOC_VIEWER_STATE.uploadObjectUrl); } catch (_e) {}
            DOC_VIEWER_STATE.uploadObjectUrl = '';
        }
        var uploadInput = document.getElementById('cvDocViewerUploadInput');
        if (uploadInput) uploadInput.value = '';
        var split = document.getElementById('cvDocViewerSplit');
        if (split) split.classList.remove('is-split');
        renderDocViewerContent('cvDocViewerUploadPane', '', '');
    }

    function clampDocViewerRect(left, top, width, height) {
        var vw = Math.max(320, window.innerWidth || 0);
        var vh = Math.max(220, window.innerHeight || 0);
        var minW = 600;
        var minH = 400;
        var edgePad = DOC_VIEWER_STATE.isMaximized ? 0 : 10;

        width = Math.max(minW, Math.min(vw, parseInt(String(width || 0), 10) || minW));
        height = Math.max(minH, Math.min(vh, parseInt(String(height || 0), 10) || minH));

        var maxLeft = Math.max(edgePad, vw - width - edgePad);
        var maxTop = Math.max(edgePad, vh - height - edgePad);
        left = Math.max(edgePad, Math.min(maxLeft, parseInt(String(left || 0), 10) || edgePad));
        top = Math.max(edgePad, Math.min(maxTop, parseInt(String(top || 0), 10) || edgePad));

        return { left: left, top: top, width: width, height: height };
    }

    function ensureHeaderVisible(modal, top) {
        if (!modal) return parseInt(String(top || 0), 10) || 0;
        var t = parseInt(String(top || 0), 10) || 0;
        return t < 0 ? 0 : t;
    }

    function fixModalBounds(modal, left, top, width, height) {
        if (!modal) return { left: 0, top: 0, width: 600, height: 400 };
        var r = clampDocViewerRect(left, top, width, height);
        r.top = ensureHeaderVisible(modal, r.top);
        return r;
    }

    function setDocViewerRect(modal, left, top, width, height) {
        if (!modal) return;
        if (isCompactReportMode()) {
            modal.style.left = '8px';
            modal.style.top = '8px';
            modal.style.width = 'calc(100vw - 16px)';
            modal.style.height = 'calc(100vh - 16px)';
            return;
        }
        var r = fixModalBounds(modal, left, top, width, height);
        modal.style.left = r.left + 'px';
        modal.style.top = r.top + 'px';
        modal.style.width = r.width + 'px';
        modal.style.height = r.height + 'px';
    }

    function ensureDocViewerInViewport(modal) {
        if (!modal || DOC_VIEWER_STATE.isMaximized) return;
        var rect = modal.getBoundingClientRect();
        setDocViewerRect(modal, rect.left, rect.top, rect.width, rect.height);
    }

    function closeDocViewer() {
        var overlay = document.getElementById('cvDocViewerOverlay');
        var modal = document.getElementById('cvDocViewerModal');
        if (overlay) {
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }
        if (modal) {
            modal.classList.remove('is-compact');
            modal.classList.remove('is-maximized');
            modal.classList.remove('is-minimized');
            setDocViewerRect(modal, 50, 50, Math.min((window.innerWidth || 1200) * 0.8, 1100), Math.min((window.innerHeight || 900) * 0.8, 700));
        }
        DOC_VIEWER_STATE.isOpen = false;
        DOC_VIEWER_STATE.isMaximized = false;
        DOC_VIEWER_STATE.context = null;
        DOC_VIEWER_STATE.restoreRect = null;
        resetDocViewerUpload();
        renderDocViewerContent('cvDocViewerCandidatePane', '', '');
    }

    function minimizeDocViewer() {
        var modal = document.getElementById('cvDocViewerModal');
        if (!modal) return;
        if (isCompactReportMode()) return;
        if (DOC_VIEWER_STATE.isMaximized) toggleDocViewerMaximize();
        modal.classList.toggle('is-minimized');
    }

    function toggleDocViewerMaximize() {
        var modal = document.getElementById('cvDocViewerModal');
        if (!modal) return;
        if (isCompactReportMode()) {
            DOC_VIEWER_STATE.isMaximized = true;
            modal.classList.add('is-compact');
            modal.classList.add('is-maximized');
            modal.classList.remove('is-minimized');
            setDocViewerRect(modal, 0, 0, window.innerWidth || 0, window.innerHeight || 0);
            return;
        }
        modal.classList.remove('is-minimized');
        if (!DOC_VIEWER_STATE.isMaximized) {
            var rect = modal.getBoundingClientRect();
            DOC_VIEWER_STATE.restoreRect = {
                left: rect.left,
                top: rect.top,
                width: rect.width,
                height: rect.height
            };
            DOC_VIEWER_STATE.isMaximized = true;
            modal.classList.add('is-maximized');
            setDocViewerRect(modal, 0, 0, window.innerWidth || 0, window.innerHeight || 0);
        } else {
            DOC_VIEWER_STATE.isMaximized = false;
            modal.classList.remove('is-maximized');
            if (DOC_VIEWER_STATE.restoreRect) {
                setDocViewerRect(
                    modal,
                    DOC_VIEWER_STATE.restoreRect.left,
                    DOC_VIEWER_STATE.restoreRect.top,
                    DOC_VIEWER_STATE.restoreRect.width,
                    DOC_VIEWER_STATE.restoreRect.height
                );
            } else {
                setDocViewerRect(modal, 50, 50, Math.min((window.innerWidth || 1200) * 0.8, 1100), Math.min((window.innerHeight || 900) * 0.8, 700));
            }
        }
    }

    function openDocViewer(docUrl, context) {
        var overlay = document.getElementById('cvDocViewerOverlay');
        var modal = document.getElementById('cvDocViewerModal');
        if (!overlay || !modal) return false;
        closePane();
        closePdfViewer();
        closeBsModal('cvViewDocModal');

        var ctx = context && typeof context === 'object' ? context : {};
        DOC_VIEWER_STATE.context = {
            applicationId: String(ctx.applicationId || CURRENT_APP_ID || ''),
            componentKey: normSection(ctx.componentKey || ''),
            itemKey: String(ctx.itemKey || ''),
            mimeType: String(ctx.mimeType || '')
        };
        DOC_VIEWER_STATE.isOpen = true;
        DOC_VIEWER_STATE.isMaximized = isCompactReportMode();
        DOC_VIEWER_STATE.restoreRect = null;

        resetDocViewerUpload();
        renderDocViewerContent('cvDocViewerCandidatePane', String(docUrl || ''), DOC_VIEWER_STATE.context.mimeType);
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        modal.classList.toggle('is-compact', isCompactReportMode());
        modal.classList.remove('is-maximized');
        modal.classList.remove('is-minimized');
        if (isCompactReportMode()) {
            modal.classList.add('is-maximized');
            setDocViewerRect(modal, 0, 0, window.innerWidth || 0, window.innerHeight || 0);
        } else {
            setDocViewerRect(
                modal,
                50,
                50,
                Math.min((window.innerWidth || 1200) * 0.8, 1100),
                Math.min((window.innerHeight || 900) * 0.8, 700)
            );
        }
        return true;
    }

    function initDocViewer() {
        var overlay = document.getElementById('cvDocViewerOverlay');
        if (overlay && overlay.parentNode !== document.body) {
            document.body.appendChild(overlay);
        }
        var modal = document.getElementById('cvDocViewerModal');
        var header = document.getElementById('cvDocViewerHeader');
        var closeBtn = document.getElementById('cvDocViewerClose');
        var minBtn = document.getElementById('cvDocViewerMinimize');
        var maxBtn = document.getElementById('cvDocViewerMaximize');
        var uploadBtn = document.getElementById('cvDocViewerUploadBtn');
        var uploadRemoveBtn = document.getElementById('cvDocViewerUploadRemove');
        var uploadInput = document.getElementById('cvDocViewerUploadInput');
        var split = document.getElementById('cvDocViewerSplit');
        var handleRight = document.getElementById('cvDocViewerResizeRight');
        var handleBottom = document.getElementById('cvDocViewerResizeBottom');
        var handleCorner = document.getElementById('cvDocViewerResizeCorner');
        if (!overlay || !modal || !header || overlay.dataset.bound === '1') return;
        overlay.dataset.bound = '1';

        var dragging = false;
        var startX = 0;
        var startY = 0;
        var startLeft = 0;
        var startTop = 0;

        header.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            if (e.target && e.target.closest && e.target.closest('.cv-docviewer-actions')) return;
            if (isCompactReportMode()) return;
            if (DOC_VIEWER_STATE.isMaximized) return;
            dragging = true;
            var rect = modal.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var nextLeft = startLeft + (e.clientX - startX);
            var nextTop = startTop + (e.clientY - startY);
            setDocViewerRect(modal, nextLeft, nextTop, modal.offsetWidth, modal.offsetHeight);
        });

        document.addEventListener('mouseup', function () {
            dragging = false;
        });

        if (closeBtn) closeBtn.addEventListener('click', closeDocViewer);
        if (minBtn) minBtn.addEventListener('click', minimizeDocViewer);
        if (maxBtn) maxBtn.addEventListener('click', toggleDocViewerMaximize);
        if (uploadBtn && uploadInput) {
            uploadBtn.addEventListener('click', function () { uploadInput.click(); });
        }
        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                var file = uploadInput.files && uploadInput.files[0] ? uploadInput.files[0] : null;
                if (!file) return;
                resetDocViewerUpload();
                DOC_VIEWER_STATE.uploadObjectUrl = URL.createObjectURL(file);
                renderDocViewerContent('cvDocViewerUploadPane', DOC_VIEWER_STATE.uploadObjectUrl, String(file.type || ''));
                if (split) split.classList.add('is-split');
            });
        }
        if (uploadRemoveBtn) {
            uploadRemoveBtn.addEventListener('click', function () {
                resetDocViewerUpload();
            });
        }

        var resizing = false;
        var resizeMode = '';
        var resizeStartX = 0;
        var resizeStartY = 0;
        var resizeStartRect = null;

        function startResize(mode, e) {
            if (isCompactReportMode()) return;
            if (DOC_VIEWER_STATE.isMaximized) return;
            resizing = true;
            resizeMode = mode;
            resizeStartX = e.clientX;
            resizeStartY = e.clientY;
            resizeStartRect = modal.getBoundingClientRect();
            e.preventDefault();
        }

        if (handleRight) handleRight.addEventListener('mousedown', function (e) { startResize('right', e); });
        if (handleBottom) handleBottom.addEventListener('mousedown', function (e) { startResize('bottom', e); });
        if (handleCorner) handleCorner.addEventListener('mousedown', function (e) { startResize('corner', e); });

        document.addEventListener('mousemove', function (e) {
            if (!resizing || !resizeStartRect) return;
            var dx = e.clientX - resizeStartX;
            var dy = e.clientY - resizeStartY;
            var nextWidth = resizeStartRect.width;
            var nextHeight = resizeStartRect.height;
            if (resizeMode === 'right' || resizeMode === 'corner') nextWidth = resizeStartRect.width + dx;
            if (resizeMode === 'bottom' || resizeMode === 'corner') nextHeight = resizeStartRect.height + dy;
            setDocViewerRect(modal, resizeStartRect.left, resizeStartRect.top, nextWidth, nextHeight);
        });

        document.addEventListener('mouseup', function () {
            resizing = false;
            resizeMode = '';
            resizeStartRect = null;
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeDocViewer();
        });
        document.addEventListener('keydown', function (e) {
            if (!DOC_VIEWER_STATE.isOpen) return;
            if (e && e.key === 'Escape') closeDocViewer();
        });
        window.addEventListener('resize', function () {
            if (isCompactReportMode()) {
                modal.classList.add('is-compact');
                modal.classList.add('is-maximized');
                modal.classList.remove('is-minimized');
            } else {
                modal.classList.remove('is-compact');
            }
            setDocViewerRect(modal, modal.offsetLeft, modal.offsetTop, modal.offsetWidth, modal.offsetHeight);
        });

        window.openDocViewer = openDocViewer;
        window.closeDocViewer = closeDocViewer;
        window.closeDocModal = closeDocViewer;
    }

    function setPdfViewerMessage(text, tone) {
        var el = document.getElementById('pdfViewerMessage');
        if (!el) return;
        el.classList.remove('error', 'success');
        var msg = String(text || '').trim();
        if (!msg) {
            el.textContent = '';
            return;
        }
        if (tone === 'error') el.classList.add('error');
        if (tone === 'success') el.classList.add('success');
        el.textContent = msg;
    }

    function closePdfViewer() {
        var root = document.getElementById('pdfViewer');
        var frame = document.getElementById('pdfViewerFrame');
        var reason = document.getElementById('pdfViewerReason');
        if (!root) return;
        root.style.display = 'none';
        root.setAttribute('aria-hidden', 'true');
        if (frame) frame.src = 'about:blank';
        if (reason) reason.value = '';
        setPdfViewerMessage('', '');
        PDF_VIEWER_STATE.open = false;
        PDF_VIEWER_STATE.context = null;
    }

function openPdfViewer(url, context) {
    if (!isLegacyPdfViewerEnabled()) return false;
    if (SPLIT_PANE_STATE && SPLIT_PANE_STATE.isOpen) return false;
    return false;
}
function triggerUpload() {
    if (!isLegacyPdfViewerEnabled()) return;
    document.getElementById('uploadInput').click();
}

var uploadInput = document.getElementById('uploadInput');
if (uploadInput) {
    uploadInput.addEventListener('change', function(e) {
        if (!isLegacyPdfViewerEnabled()) return;
        const file = e.target.files[0];
        if (!file) return;

        const url = URL.createObjectURL(file);

        PDF_VIEWER_STATE.mode = 'split';
        PDF_VIEWER_STATE.uploadFile = file;
        PDF_VIEWER_STATE.uploadUrl = url;

        document.getElementById('uploadPane').style.display = 'block';
        document.getElementById('pdfViewerContent').classList.add('split');

        document.getElementById('uploadPreview').innerHTML =
            `<iframe src="${buildPdfViewerUrl(url)}" style="width:100%;height:100%;border:none;"></iframe>`;
    });
}

    async function runPdfViewerAction(action) {
        var mode = String(action || '').toLowerCase().trim();
        var ctx = PDF_VIEWER_STATE.context || {};
        var applicationId = String(ctx.application_id || CURRENT_APP_ID || '');
        var componentKey = normSection(ctx.component_key || '');
        var itemKey = String(ctx.item_key || '');
        var reasonEl = document.getElementById('pdfViewerReason');
        var reason = reasonEl ? String(reasonEl.value || '').trim() : '';

        if (!applicationId || !componentKey) {
            setPdfViewerMessage('Missing context. Re-open the document from a tab item.', 'error');
            return;
        }
        if (!isActionableComponent(componentKey)) {
            setPdfViewerMessage('Please select a valid section before taking action.', 'error');
            return;
        }
        if (!canValidatorActOnComponent(componentKey, mode, 'pdf')) return;
        if ((mode === 'reject' || mode === 'hold') && !reason) {
            setPdfViewerMessage('Reason is required for ' + mode + '.', 'error');
            if (reasonEl) reasonEl.focus();
            return;
        }

        var approveBtn = document.getElementById('pdfViewerApprove');
        var rejectBtn = document.getElementById('pdfViewerReject');
        var holdBtn = document.getElementById('pdfViewerHold');
        [approveBtn, rejectBtn, holdBtn].forEach(function (btn) { if (btn) btn.disabled = true; });
        setPdfViewerMessage('Submitting ' + mode + '...', '');

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var caseId = REPORT_PAYLOAD && REPORT_PAYLOAD.case && REPORT_PAYLOAD.case.case_id ? parseInt(REPORT_PAYLOAD.case.case_id, 10) : 0;
            var role = getRole();
            var group = role === 'verifier' ? (getVerifierGroup() || null) : null;

            var out = await postJson(base + '/api/shared/component_action.php', {
                application_id: applicationId,
                case_id: caseId || null,
                component_key: componentKey,
                item_key: itemKey || null,
                action: mode,
                group: group,
                reason: reason || null,
                override_reason: reason || null
            });
            if (!out.res.ok || !out.payload || out.payload.status !== 1) {
                setPdfViewerMessage((out.payload && out.payload.message) ? out.payload.message : 'Failed to submit action.', 'error');
                return;
            }
            setPdfViewerMessage('Action updated successfully.', 'success');
            setBoxMessage('cvTopMessage', 'Updated successfully.', 'success');
            try { loadReport({ preserveUi: true, section: componentKey }).catch(function () {}); } catch (_e) {}
        } catch (e) {
            setPdfViewerMessage((e && e.message) ? e.message : 'Network error. Please try again.', 'error');
        } finally {
            [approveBtn, rejectBtn, holdBtn].forEach(function (btn) { if (btn) btn.disabled = false; });
        }
    }

    function initPdfViewer() {
        if (!isLegacyPdfViewerEnabled()) return;
        var root = document.getElementById('pdfViewer');
        var header = document.getElementById('pdfViewerHeader');
        var closeBtn = document.getElementById('pdfViewerClose');
        var approveBtn = document.getElementById('pdfViewerApprove');
        var rejectBtn = document.getElementById('pdfViewerReject');
        var holdBtn = document.getElementById('pdfViewerHold');
        if (!root || !header || root.dataset.bound === '1') return;
        root.dataset.bound = '1';

        var dragging = false;
        var startX = 0;
        var startY = 0;
        var startLeft = 0;
        var startTop = 0;

        header.addEventListener('mousedown', function (e) {
            if (e.button !== 0) return;
            var t = e.target;
            if (t && t.closest && t.closest('#pdfViewerClose')) return;
            dragging = true;
            var rect = root.getBoundingClientRect();
            startX = e.clientX;
            startY = e.clientY;
            startLeft = rect.left;
            startTop = rect.top;
            root.style.left = rect.left + 'px';
            root.style.top = rect.top + 'px';
            root.style.right = 'auto';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            var nextLeft = startLeft + dx;
            var nextTop = startTop + dy;
            var maxLeft = Math.max(0, window.innerWidth - root.offsetWidth);
            var maxTop = Math.max(0, window.innerHeight - root.offsetHeight);
            if (nextLeft < 0) nextLeft = 0;
            if (nextTop < 0) nextTop = 0;
            if (nextLeft > maxLeft) nextLeft = maxLeft;
            if (nextTop > maxTop) nextTop = maxTop;
            root.style.left = nextLeft + 'px';
            root.style.top = nextTop + 'px';
            PDF_VIEWER_STATE.dragged = true;
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            dragging = false;
            document.body.style.removeProperty('user-select');
        });

        if (closeBtn) closeBtn.addEventListener('click', function () { closePdfViewer(); });
        if (approveBtn) approveBtn.addEventListener('click', function () { runPdfViewerAction('approve'); });
        if (rejectBtn) rejectBtn.addEventListener('click', function () { runPdfViewerAction('reject'); });
        if (holdBtn) holdBtn.addEventListener('click', function () { runPdfViewerAction('hold'); });

        window.closePdfViewer = closePdfViewer;
    }

    async function initVerifierMailAndPrint(getPayload) {
        var role = getRole();
        if (!(role === 'verifier' || role === 'validator' || role === 'qa' || role === 'team_lead')) return;

        var openBtn = document.getElementById('cvOpenMailModal');
        var printBtn = document.getElementById('cvPrintLetterBtn');
        var tplSel = document.getElementById('cvMailTemplateSelect');
        var toEl = document.getElementById('cvMailToEmail');
        var subjEl = document.getElementById('cvMailSubject');
        var notesEl = document.getElementById('cvCommNotes');
        var previewEl = document.getElementById('cvMailPreview');
        var sendBtn = document.getElementById('cvMailSendBtn');
        var actionsEl = document.getElementById('cvCommActionCards');
        var checklistEl = document.getElementById('cvCommChecklist');
        var deadlineEl = document.getElementById('cvCommDeadline');
        var historyEl = document.getElementById('cvCommHistory');
        var draftBtn = document.getElementById('cvCommSaveDraftBtn');
        var reuseBtn = document.getElementById('cvCommReuseLastBtn');
        var resendBtn = document.getElementById('cvCommResendLastBtn');

        if (!openBtn || !previewEl || !sendBtn || !actionsEl || !checklistEl) return;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var activeAction = '';
        var activeTemplateId = null;
        var activeBody = '';
        var activeChecklist = [];
        var lastHistory = [];
        var previewTimer = null;
        var OPEN_PREFETCH = null;

        function currentComponent() {
            return String(activeComponentSectionKey() || CURRENT_SECTION_KEY || 'basic').toLowerCase().trim();
        }

        function getContext() {
            var payload = getPayload ? getPayload() : null;
            var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
            var appId = payload && payload.case && payload.case.application_id ? String(payload.case.application_id) : (qs('application_id') || '');
            return { case_id: caseId || null, application_id: appId || null, role: role, component: currentComponent() };
        }

        function selectedChecklist() {
            var out = [];
            checklistEl.querySelectorAll('input[type="checkbox"][data-check-key]').forEach(function (cb) {
                if (cb.checked) out.push(String(cb.getAttribute('data-check-key') || '').trim());
            });
            return out.filter(Boolean);
        }

        function renderHistory(items) {
            var rows = Array.isArray(items) ? items : [];
            if (!rows.length) {
                historyEl.innerHTML = '<div style="font-size:12px; color:#64748b;">No previous communications.</div>';
                return;
            }
            historyEl.innerHTML = rows.map(function (r, idx) {
                var badgeTone = String(r.delivery_status || '').toLowerCase() === 'draft' ? '#b45309' : '#166534';
                return '<div data-history-idx="' + esc(String(idx)) + '" style="padding:8px; border:1px solid rgba(148,163,184,.24); border-radius:8px; margin-bottom:6px; background:#fff; cursor:pointer;">' +
                    '<div style="display:flex; justify-content:space-between; gap:8px;"><b style="font-size:12px;">' + esc((r.action_key || '').replace(/_/g, ' ')) + '</b>' +
                    '<span style="font-size:11px; color:' + badgeTone + '; font-weight:800;">' + esc(r.delivery_status || 'sent') + '</span></div>' +
                    '<div style="font-size:11px; color:#64748b;">' + esc(r.role_key || '-') + ' • ' + esc(r.component_key || '-') + ' • ' + esc(r.sent_at || '') + '</div>' +
                    '</div>';
            }).join('');
        }

        function renderChecklist(items) {
            var list = Array.isArray(items) ? items : [];
            if (!list.length) {
                checklistEl.innerHTML = '<div style="font-size:12px; color:#64748b;">No checklist required for this action.</div>';
                return;
            }
            checklistEl.innerHTML = list.map(function (it, idx) {
                var id = 'cvCommChk_' + idx;
                return '<label for="' + esc(id) + '" style="display:flex; gap:8px; align-items:flex-start; margin-bottom:6px; font-size:12px;">' +
                    '<input id="' + esc(id) + '" type="checkbox" data-check-key="' + esc(String(it)) + '" checked>' +
                    '<span>' + esc(String(it)) + '</span>' +
                '</label>';
            }).join('');
        }

        function schedulePreview() {
            if (previewTimer) clearTimeout(previewTimer);
            previewTimer = setTimeout(function () {
                var checks = selectedChecklist();
                var notes = notesEl ? String(notesEl.value || '').trim() : '';
                var deadline = deadlineEl ? String(deadlineEl.value || '') : '';
                var composed = activeBody;
                if (checks.length) composed += '\n\nChecklist:\n- ' + checks.join('\n- ');
                if (deadline) composed += '\n\nDeadline: ' + deadline;
                if (notes) composed += '\n\nNotes:\n' + notes;
                previewEl.innerHTML = wcSafeHtml(composed);
            }, 180);
        }

        function wcSafeHtml(body) {
            var s = escHtml(String(body || ''));
            return '<div style="font-family:Arial,sans-serif; font-size:13px; line-height:1.45;">' + s.replace(/\n/g, '<br>') + '</div>';
        }

        async function fetchJsonWithTimeout(url, options, timeoutMs) {
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timer = null;
            var opts = options || {};
            if (ctrl) {
                opts.signal = ctrl.signal;
                timer = setTimeout(function () {
                    try { ctrl.abort(); } catch (_e) {}
                }, Math.max(1000, parseInt(timeoutMs || 0, 10) || 6000))
            }
            try {
                var res = await fetch(url, opts);
                var data = await res.json().catch(function () { return null; });
                return { ok: !!res.ok, data: data };
            } finally {
                if (timer) clearTimeout(timer);
            }
        }

        function resolverActionForMode(mode, action, componentKey) {
            var m = String(mode || '').toLowerCase().trim();
            var a = String(action || '').toLowerCase().trim();
            var c = normSection(componentKey || '');
            if (m === 'verification') {
                if (a === 'resend_mail') return 'resend_mail';
                return 'send_mail';
            }
            if (!a) return 'insufficient_documents';
            if (a === 'reject') return 'rejected';
            return a;
        }

        async function resolveTemplateContract(mode, action, componentKey, deadlineValue) {
            var ctx = getContext();
            var resolvedAction = resolverActionForMode(mode, action, componentKey);
            var out = await fetchJsonWithTimeout(base + '/api/shared/mail_templates_resolve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    role: role,
                    component: componentKey,
                    action: resolvedAction,
                    mode: String(mode || 'workflow').toLowerCase().trim(),
                    application_id: ctx.application_id,
                    case_id: ctx.case_id,
                    deadline: String(deadlineValue || '')
                })
            }, 5000);
            var data = out.data;
            if (!out.ok || !data || data.status !== 1 || !data.data) {
                throw new Error((data && data.message) ? data.message : 'Failed to resolve communication template');
            }
            return data.data;
        }

        async function loadHistory() {
            var ctx = getContext();
            var url = base + '/api/shared/workflow_communications_history.php?application_id=' + encodeURIComponent(ctx.application_id || '') + '&component=' + encodeURIComponent(ctx.component || '');
            var out = await fetchJsonWithTimeout(url, { credentials: 'same-origin' }, 4500);
            var data = out.data;
            if (!out.ok || !data || data.status !== 1) return [];
            return Array.isArray(data.data) ? data.data : [];
        }

        async function resolveAction(action) {
            activeAction = String(action || '').toLowerCase().trim();
            var ctx = getContext();
            var resolved = await resolveTemplateContract('workflow', activeAction, ctx.component, deadlineEl ? String(deadlineEl.value || '') : '');
            activeTemplateId = resolved.template_id || null;
            activeBody = String(resolved.body || '');
            activeChecklist = Array.isArray(resolved.checklist) ? resolved.checklist : [];
            if (subjEl) subjEl.value = String(resolved.subject || '');
            if (tplSel) tplSel.value = activeTemplateId ? String(activeTemplateId) : '';
            renderChecklist(activeChecklist);
            schedulePreview();
        }

        async function loadActionCards() {
            var ctx = getContext();
            var resolved = await resolveTemplateContract('workflow', 'insufficient_documents', ctx.component, '');
            var actions = Array.isArray(resolved.actions) ? resolved.actions : [];
            actionsEl.innerHTML = actions.map(function (a) {
                var act = String(a.action || '');
                return '<button type="button" class="btn btn-sm btn-outline-primary" data-comm-action="' + esc(act) + '" style="text-align:left;">' + esc(String(a.label || act)) + '</button>';
            }).join('');
            if (!actions.length) {
                activeAction = '';
                activeTemplateId = null;
                activeBody = '';
                renderChecklist([]);
                schedulePreview();
                return;
            }
            // Use the same resolver response for first paint to avoid a second blocking call.
            activeAction = 'insufficient_documents';
            activeTemplateId = resolved.template_id || null;
            activeBody = String(resolved.body || '');
            activeChecklist = Array.isArray(resolved.checklist) ? resolved.checklist : [];
            if (subjEl) subjEl.value = String(resolved.subject || '');
            if (tplSel) tplSel.value = activeTemplateId ? String(activeTemplateId) : '';
            renderChecklist(activeChecklist);
            schedulePreview();
            // If first button action is not insufficient_documents, resolve it in background.
            var firstAct = String(actions[0].action || '').toLowerCase().trim();
            if (firstAct && firstAct !== 'insufficient_documents') {
                resolveAction(firstAct).catch(function () {});
            }
        }

        async function sendComm(mode) {
            var ctx = getContext();
            var componentKey = normSection(ctx.component || currentRepliesScopeComponent() || '');
            var to = toEl ? String(toEl.value || '').trim() : '';
            var subject = subjEl ? String(subjEl.value || '').trim() : '';
            var notes = notesEl ? String(notesEl.value || '').trim() : '';
            var deadline = deadlineEl ? String(deadlineEl.value || '') : '';
            var checks = selectedChecklist();
            if (!activeAction) {
                setBoxMessage('cvMailMessage', 'Please select an action.', 'danger');
                return;
            }
            if (mode !== 'draft' && !to) {
                setBoxMessage('cvMailMessage', 'To Email is required.', 'danger');
                return;
            }
            setBoxMessage('cvMailMessage', '', '');
            sendBtn.disabled = true;
            sendBtn.dataset.originalText = sendBtn.dataset.originalText || sendBtn.textContent;
            sendBtn.textContent = mode === 'draft' ? 'Saving...' : 'Sending...';
            try {
                var reqId = 'wc-' + String(ctx.application_id || '') + '-' + String(ctx.component || '') + '-' + String(activeAction || '') + '-' + String(Date.now());
                var res = await fetch(base + '/api/shared/workflow_communications_send.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        mode: mode,
                        role: role,
                        component: ctx.component,
                        action: activeAction,
                        template_id: activeTemplateId,
                        to_email: to,
                        subject: subject,
                        body: activeBody,
                        notes: notes,
                        deadline: deadline,
                        checklist: checks,
                        application_id: ctx.application_id,
                        case_id: ctx.case_id,
                        workflow_version: REPORT_PAYLOAD && REPORT_PAYLOAD.case ? REPORT_PAYLOAD.case.workflow_version : null,
                        request_id: reqId
                    })
                });
                var data = await res.json().catch(function () { return null; });
                if (!res.ok || !data || data.status !== 1) throw new Error((data && data.message) ? data.message : 'Communication send failed');
                if (mode !== 'draft') {
                    var optimisticRow = {
                        sender: (window.CV_CURRENT_USER_NAME || role || 'Operations'),
                        message: notes ? (activeBody + '\n\nNotes:\n' + notes) : activeBody,
                        created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                        direction: 'outgoing',
                        actor_role: role,
                        component_key: componentKey
                    };
                    var optimisticScopeKey = repliesScopeKey(ctx.application_id || '', componentKey, role);
                    var optimisticCache = getRepliesScopeCache(optimisticScopeKey);
                    var optimisticRows = [optimisticRow].concat(optimisticCache && Array.isArray(optimisticCache.rows) ? optimisticCache.rows : []);
                    var optimisticMeta = Object.assign({}, optimisticCache && optimisticCache.meta ? optimisticCache.meta : EMAIL_REPLIES_META || {}, { pending_local_send: true });
                    setRepliesScopeCache(optimisticScopeKey, optimisticRows, optimisticMeta, emailRepliesRequestKey(ctx.application_id || '', componentKey, false, role));
                    if (optimisticScopeKey === repliesScopeKey(currentReportApplicationId(), currentRepliesScopeComponent(), getReplyViewerRole())) {
                        EMAIL_REPLIES_CACHE = optimisticRows;
                        EMAIL_REPLIES_META = optimisticMeta;
                        EMAIL_REPLIES_LAST_SCOPE_KEY = optimisticScopeKey;
                        EMAIL_REPLIES_CACHE_READY = true;
                        renderRepliesState(role, componentKey);
                    }
                }
                setBoxMessage('cvMailMessage', mode === 'draft' ? 'Draft saved.' : 'Sent successfully.', 'success');
                lastHistory = await loadHistory();
                renderHistory(lastHistory);
                EMAIL_REPLIES_FORCE_SYNC_ONCE = true;
                loadEmailReplies(ctx.application_id || '', { componentKey: componentKey, sync: true }).catch(function () {});
                loadTimeline(ctx.application_id || '', { sync: false }).catch(function () {});
            } catch (e) {
                setBoxMessage('cvMailMessage', (e && e.message) ? e.message : 'Communication failed', 'danger');
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = sendBtn.dataset.originalText || 'Send';
            }
        }

        if (!openBtn.dataset.bound) {
            openBtn.dataset.bound = '1';
            openBtn.addEventListener('click', async function () {
                try {
                    if (typeof window.__CR_OPEN_UNIFIED_COMM === 'function') {
                        window.__CR_OPEN_UNIFIED_COMM({
                            mode: 'workflow',
                            action: 'insufficient_documents',
                            component: activeComponentSectionKey() || CURRENT_SECTION_KEY || 'basic',
                            notes: '',
                            requiresMutation: false
                        });
                        return;
                    }
                    var prefill = (window.__CR_COMM_PREFILL && typeof window.__CR_COMM_PREFILL === 'object') ? window.__CR_COMM_PREFILL : null;
                    var payload = getPayload ? getPayload() : null;
                    var basic = payload && payload.basic ? payload.basic : null;
                    if (toEl && basic && (basic.email || basic.candidate_email)) toEl.value = String(basic.email || basic.candidate_email || '');
                    if (notesEl) notesEl.value = '';
                    setBoxMessage('cvMailMessage', '', '');
                    previewEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">Loading template...</div>';
                    actionsEl.innerHTML = '<div style="font-size:12px; color:#64748b;">Loading actions...</div>';
                    checklistEl.innerHTML = '<div style="font-size:12px; color:#64748b;">Loading checklist...</div>';
                    historyEl.innerHTML = '<div style="font-size:12px; color:#64748b;">Loading communications...</div>';
                    openBsModal('cvMailModal');
                    // Parallel load for fast modal readiness.
                    var p1 = loadActionCards();
                    var p2 = loadHistory().then(function (rows) {
                        lastHistory = rows;
                        renderHistory(lastHistory);
                    });
                    OPEN_PREFETCH = Promise.allSettled([p1, p2]);
                    await OPEN_PREFETCH;
                    if (prefill) {
                        try {
                            if (prefill.action) {
                                await resolveAction(String(prefill.action || '').toLowerCase().trim());
                            }
                            if (notesEl && prefill.notes) {
                                notesEl.value = String(prefill.notes || '');
                                schedulePreview();
                            }
                        } catch (_prefErr) {}
                        window.__CR_COMM_PREFILL = null;
                    }
                } catch (e) {
                    setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Failed to open communication center', 'danger');
                }
            });
        }

        if (!actionsEl.dataset.bound) {
            actionsEl.dataset.bound = '1';
            actionsEl.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('[data-comm-action]') : null;
                if (!btn) return;
                var action = String(btn.getAttribute('data-comm-action') || '').trim();
                if (!action) return;
                resolveAction(action).catch(function (err) {
                    setBoxMessage('cvMailMessage', (err && err.message) ? err.message : 'Failed to resolve action', 'danger');
                });
            });
        }

        if (notesEl && !notesEl.dataset.bound) {
            notesEl.dataset.bound = '1';
            notesEl.addEventListener('input', schedulePreview);
        }
        if (deadlineEl && !deadlineEl.dataset.bound) {
            deadlineEl.dataset.bound = '1';
            deadlineEl.addEventListener('change', function () {
                if (activeAction) resolveAction(activeAction).catch(function () { schedulePreview(); });
            });
        }
        if (checklistEl && !checklistEl.dataset.bound) {
            checklistEl.dataset.bound = '1';
            checklistEl.addEventListener('change', schedulePreview);
        }
        if (!sendBtn.dataset.bound) {
            sendBtn.dataset.bound = '1';
            sendBtn.addEventListener('click', function () { sendComm('send'); });
        }
        if (draftBtn && !draftBtn.dataset.bound) {
            draftBtn.dataset.bound = '1';
            draftBtn.addEventListener('click', function () { sendComm('draft'); });
        }
        if (reuseBtn && !reuseBtn.dataset.bound) {
            reuseBtn.dataset.bound = '1';
            reuseBtn.addEventListener('click', function () {
                if (!lastHistory.length) return;
                var h = lastHistory[0];
                if (subjEl) subjEl.value = String(h.subject || '');
                if (notesEl) notesEl.value = String(h.notes || '');
                activeAction = String(h.action_key || activeAction || '');
                schedulePreview();
            });
        }
        if (resendBtn && !resendBtn.dataset.bound) {
            resendBtn.dataset.bound = '1';
            resendBtn.addEventListener('click', function () {
                if (!lastHistory.length) return;
                sendComm('send');
            });
        }

        if (historyEl && !historyEl.dataset.bound) {
            historyEl.dataset.bound = '1';
            historyEl.addEventListener('click', function (e) {
                var row = e.target && e.target.closest ? e.target.closest('[data-history-idx]') : null;
                if (!row) return;
                var idx = parseInt(String(row.getAttribute('data-history-idx') || '-1'), 10);
                if (!isFinite(idx) || idx < 0 || idx >= lastHistory.length) return;
                var h = lastHistory[idx];
                if (subjEl) subjEl.value = String(h.subject || '');
                if (notesEl) notesEl.value = String(h.notes || '');
                activeAction = String(h.action_key || activeAction || '');
                schedulePreview();
            });
        }

        if (printBtn && !printBtn.dataset.bound) {
            printBtn.dataset.bound = '1';
            printBtn.addEventListener('click', function () {
                openBtn.click();
            });
        }
    }

    async function initSectionVerificationMail(getPayload) {
        var role = getRole();
        if (!(role === 'validator' || role === 'verifier' || role === 'qa')) return;
        var allowedMailComponents = { education: true, employment: true };
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var modalId = 'cvVerificationMailModal';
        var modalEl = document.getElementById(modalId);
        var titleEl = document.getElementById('cvVerificationMailTitle');
        var toEl = document.getElementById('cvVerificationMailTo');
        var subjectEl = document.getElementById('cvVerificationMailSubject');
        var bodyEl = document.getElementById('cvVerificationMailBody');
        var remarksEl = document.getElementById('cvVerificationMailRemarks');
        var sendBtn = document.getElementById('cvVerificationMailSendBtn');
        if (!toEl || !subjectEl || !bodyEl || !sendBtn) return;

        var state = { component: '', resend: false, mode: 'verification', workflowAction: '', requiresMutation: false, templateId: null };
        var modalOpenNonce = 0;

        // Keep modal at top-level to avoid nested stacking contexts under report wrappers.
        try {
            if (modalEl && modalEl.parentElement && modalEl.parentElement !== document.body) {
                document.body.appendChild(modalEl);
            }
        } catch (_eMove) {
        }

        function isValidEmail(v) {
            var s = String(v || '').trim();
            if (!s) return false;
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(s);
        }

        function refreshSendBtnState() {
            if (!sendBtn || !toEl) return;
            var valid = isValidEmail(toEl.value);
            sendBtn.disabled = false;
            if (!valid) {
                setBoxMessage('cvVerificationMailMessage', 'Enter a valid recipient email to send verification mail.', 'warning');
            } else {
                setBoxMessage('cvVerificationMailMessage', '', '');
            }
        }

        function pickFirstEmail(rows, keys) {
            var list = Array.isArray(rows) ? rows : [];
            for (var i = 0; i < list.length; i++) {
                var row = list[i] || {};
                for (var j = 0; j < keys.length; j++) {
                    var v = String(row[keys[j]] || '').trim();
                    if (v && v.indexOf('@') > 0) return v;
                }
            }
            return '';
        }

        function prefillRecipientAndMessage(componentKey) {
            var payload = getPayload ? getPayload() : null;
            var app = payload && payload.application ? payload.application : {};
            var basic = payload && payload.basic ? payload.basic : {};
            var candidateName = String((app && app.candidate_name) || (basic && basic.name) || '').trim();
            var appId = String((payload && payload.case && payload.case.application_id) || '').trim();
            var companyName = String((app && (app.company_name || app.client_name)) || '').trim();
            var actorName = String(window.CV_CURRENT_USER_NAME || role || '').trim();
            if (state.mode === 'workflow') {
                var wfRecipient = String((basic && (basic.email || basic.candidate_email)) || '').trim();
                if (toEl) toEl.value = wfRecipient;
                var wfSection = sectionLabel(componentKey) || 'Component';
                var wfActionLabel = String((state.workflowAction || 'insufficient_documents')).replace(/_/g, ' ');
                subjectEl.value = 'Workflow Communication - ' + wfSection + ' - ' + wfActionLabel;
                bodyEl.value =
                    'Hello ' + (candidateName || 'Candidate') + ',\n\n'
                    + 'Please review the latest workflow update for ' + wfSection + '.\n'
                    + (appId ? ('Application ID: ' + appId + '\n') : '')
                    + '\nRegards,\n' + (actorName || 'Verification Team');
                refreshSendBtnState();
                return;
            }
            var rows = componentKey === 'education'
                ? (Array.isArray(payload && payload.education) ? payload.education : [])
                : (Array.isArray(payload && payload.employment) ? payload.employment : []);
            var recipient = '';
            var recipientName = '';
            var institutionOrEmployer = '';
            if (componentKey === 'education') {
                recipient = pickFirstEmail(rows, ['institution_email', 'college_email', 'university_email', 'email', 'contact_email']);
                recipientName = String((rows[0] && (rows[0].college_name || rows[0].university_board)) || '').trim();
                institutionOrEmployer = recipientName || 'Institution';
            } else {
                recipient = pickFirstEmail(rows, ['hr_email', 'employer_email', 'official_email', 'email', 'contact_employer']);
                recipientName = String((rows[0] && rows[0].employer_name) || '').trim();
                institutionOrEmployer = recipientName || 'Employer';
            }
            if (toEl) toEl.value = recipient;
            var defaultSubject = componentKey === 'education'
                ? ('Education Verification Request - ' + (candidateName || appId || 'Candidate'))
                : ('Employment Verification Request - ' + (candidateName || appId || 'Candidate'));
            subjectEl.value = defaultSubject;
            bodyEl.value =
                'Hello ' + (recipientName || 'Team') + ',\n\n'
                + 'Please assist with ' + (componentKey === 'education' ? 'education' : 'employment') + ' verification for '
                + (candidateName || 'the candidate') + (appId ? (' (Application ID: ' + appId + ')') : '') + '.\n'
                + (institutionOrEmployer ? ('Organization: ' + institutionOrEmployer + '\n') : '')
                + (companyName ? ('Client: ' + companyName + '\n') : '')
                + '\nRegards,\n' + (actorName || 'Verification Team');
            if (!recipient) {
                setBoxMessage('cvVerificationMailMessage', 'Recipient email is missing for this section. Update section data before sending.', 'warning');
            }
            refreshSendBtnState();
        }

        function resetUnifiedCommunicationState() {
            state.templateId = null;
            if (titleEl) titleEl.textContent = '';
            if (toEl) toEl.value = '';
            if (subjectEl) subjectEl.value = '';
            if (bodyEl) bodyEl.value = '';
            if (remarksEl) remarksEl.value = '';
            setBoxMessage('cvVerificationMailMessage', '', '');
        }

        async function refreshButtonStates() {
            var payload = getPayload ? getPayload() : null;
            var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
            var appId = payload && payload.case && payload.case.application_id ? String(payload.case.application_id) : '';
            var buttons = document.querySelectorAll('[data-mail-component]');
            for (var i = 0; i < buttons.length; i++) {
                var btn = buttons[i];
                var rawComponentKey = String(btn.getAttribute('data-mail-component') || '');
                var component = normSection(rawComponentKey);
                var shouldRenderSendMail = !!allowedMailComponents[component];
                try {
                    console.debug('[CR verification-mail render]', {
                        rawComponentKey: rawComponentKey,
                        normalizedComponentKey: component,
                        shouldRenderSendMail: shouldRenderSendMail
                    });
                } catch (_dbgErr) {
                }
                if (!shouldRenderSendMail) {
                    btn.style.display = 'none';
                    continue;
                }
                if (!hasSnapshotComponent(payload, component)) {
                    btn.style.display = 'none';
                    continue;
                }
                btn.style.display = '';
                if (!component || !appId) continue;
                try {
                    var res = await fetch(base + '/api/shared/send_verification_mail.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ mode: 'status', application_id: appId, case_id: caseId || null, component_key: component })
                    });
                    var data = await res.json().catch(function () { return null; });
                    var hasPrev = !!(data && data.status === 1 && data.data && data.data.has_previous === true);
                    btn.textContent = hasPrev ? (btn.getAttribute('data-mail-label-resend') || 'Resend Mail') : (btn.getAttribute('data-mail-label-send') || 'Send Mail');
                } catch (_e) {
                }
            }
        }

        async function sendVerificationMail() {
            var payload = getPayload ? getPayload() : null;
            var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
            var appId = payload && payload.case && payload.case.application_id ? String(payload.case.application_id) : '';
            var lockedComponent = normSection((modalEl && modalEl.dataset && modalEl.dataset.mailComponent) ? modalEl.dataset.mailComponent : state.component);
            var recipient = String(toEl.value || '').trim();
            sendBtn.disabled = true;
            var original = sendBtn.textContent;
            sendBtn.textContent = state.mode === 'workflow' ? 'Sending...' : (state.resend ? 'Resending...' : 'Sending...');
            try {
                if (state.mode === 'workflow') {
                    if (!state.workflowAction || ['approve', 'reject', 'hold', 'insufficient_documents', 'clarification_required'].indexOf(String(state.workflowAction).toLowerCase()) === -1) {
                        throw new Error('Invalid workflow communication action.');
                    }
                    var notes = remarksEl ? String(remarksEl.value || '').trim() : '';
                    if (!notes) {
                        throw new Error('Notes are required.');
                    }
                    if (!recipient) {
                        throw new Error('Recipient email is required.');
                    }
                    if (state.requiresMutation && typeof window.__CR_RUN_ACTION === 'function') {
                        var actionLabelMap = {
                            insufficient_documents: 'Insufficient Documents',
                            hold: 'Hold',
                            reject: 'Reject'
                        };
                        var ok = await window.__CR_RUN_ACTION(
                            state.workflowAction || 'insufficient_documents',
                            actionLabelMap[state.workflowAction || 'insufficient_documents'] || String(state.workflowAction || 'insufficient_documents'),
                            { fromUnifiedModal: true, skipUnifiedModalAuto: true, reason: notes }
                        );
                        if (!ok) {
                            throw new Error('Workflow update failed.');
                        }
                    }
                    var wfRes = await fetch(base + '/api/shared/workflow_communications_send.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            mode: 'send',
                            role: role,
                            component: lockedComponent,
                            action: state.workflowAction || 'insufficient_documents',
                            to_email: recipient,
                            subject: String(subjectEl.value || '').trim(),
                            body: String(bodyEl.value || ''),
                            notes: notes,
                            application_id: appId,
                            case_id: caseId || null
                        })
                    });
                    var wfData = await wfRes.json().catch(function () { return null; });
                    if (!wfRes.ok || !wfData || wfData.status !== 1) throw new Error((wfData && wfData.message) ? wfData.message : 'Communication send failed');
                    setBoxMessage('cvVerificationMailMessage', 'Workflow communication sent successfully.', 'success');
                    setTimeout(function () { closeBsModal(modalId); }, 150);
                } else {
                    if (!recipient) {
                        throw new Error('Recipient email is required.');
                    }
                    var res = await fetch(base + '/api/shared/send_verification_mail.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            mode: 'send',
                            application_id: appId,
                            case_id: caseId || null,
                            component_key: lockedComponent,
                            recipient_email: recipient,
                            subject: String(subjectEl.value || '').trim(),
                            message_body: String(bodyEl.value || ''),
                            remarks: remarksEl ? String(remarksEl.value || '').trim() : '',
                            sender_role: role
                        })
                    });
                    var rawText = await res.text();
                    var data = null;
                    try { data = JSON.parse(rawText); } catch (_e) { data = null; }
                    if (!res.ok || !data || data.status !== 1) {
                        var msg = (data && data.message) ? data.message : ('Send failed (HTTP ' + res.status + ')');
                        if (data && data.debug) {
                            msg += ' | debug: ' + JSON.stringify(data.debug);
                        } else if (!data && rawText) {
                            msg += ' | raw: ' + rawText.slice(0, 300);
                        }
                        throw new Error(msg);
                    }
                    setBoxMessage('cvVerificationMailMessage', 'Verification mail sent successfully.', 'success');
                    state.resend = true;
                    var b = document.querySelector('[data-mail-component="' + lockedComponent + '"]');
                    if (b) b.textContent = b.getAttribute('data-mail-label-resend') || 'Resend Mail';
                    setTimeout(function () { closeBsModal(modalId); }, 150);
                }
                EMAIL_REPLIES_FORCE_SYNC_ONCE = true;
                loadEmailReplies(appId || '', { componentKey: lockedComponent, sync: true }).catch(function () {});
                loadTimeline(appId || '', { sync: false }).catch(function () {});
            } catch (e) {
                setBoxMessage('cvVerificationMailMessage', friendlyMailError(e && e.message), 'danger');
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = original || 'Send';
            }
        }

        async function openUnifiedCommunicationModal(config) {
            var cfg = config && typeof config === 'object' ? config : {};
            var requestNonce = ++modalOpenNonce;
            var mode = String(cfg.mode || 'verification').toLowerCase().trim();
            var component = normSection(cfg.component || activeComponentSectionKey() || 'basic');
            var actionDefault = (mode === 'workflow') ? 'insufficient_documents' : '';
            var action = String((typeof cfg.action === 'string' ? cfg.action : actionDefault) || '').toLowerCase().trim();
            if (mode === 'workflow' && ['approve', 'reject', 'hold', 'insufficient_documents', 'clarification_required'].indexOf(action) === -1) {
                setBoxMessage('cvTopMessage', 'Invalid workflow communication action.', 'danger');
                return;
            }
            if (mode !== 'workflow' && mode !== 'verification') {
                setBoxMessage('cvTopMessage', 'Invalid communication mode.', 'danger');
                return;
            }
            state.mode = (mode === 'workflow') ? 'workflow' : 'verification';
            state.component = component;
            if (modalEl && modalEl.dataset) modalEl.dataset.mailComponent = component;
            state.workflowAction = (state.mode === 'workflow') ? action : '';
            state.requiresMutation = !!cfg.requiresMutation;
            state.resend = false;
            resetUnifiedCommunicationState();
            if (titleEl) {
                if (state.mode === 'workflow') {
                    titleEl.textContent = 'Workflow Communication - ' + sectionLabel(component);
                } else {
                    titleEl.textContent = 'Send Verification Mail - ' + (component === 'education' ? 'Education' : 'Employment');
                }
            }
            if (remarksEl) remarksEl.value = String(cfg.notes || '');
            prefillRecipientAndMessage(component);
            if (state.mode === 'verification') {
                try {
                    var vrAction = state.resend ? 'resend_mail' : 'send_mail';
                    var vrResolved = await resolveTemplateContract('verification', vrAction, component, '');
                    if (requestNonce !== modalOpenNonce) return;
                    if (state.mode !== 'verification' || state.component !== component) return;
                    if (subjectEl && String(vrResolved.subject || '').trim()) {
                        subjectEl.value = String(vrResolved.subject || '');
                    }
                    if (bodyEl && String(vrResolved.body || '').trim()) {
                        bodyEl.value = String(vrResolved.body || '');
                    }
                    state.templateId = vrResolved.template_id || null;
                } catch (_vrResolveErr) {
                    // Keep prefilled defaults when resolver is unavailable.
                }
            } else {
                state.templateId = null;
            }
            openBsModal(modalId);
        }

        window.__CR_OPEN_UNIFIED_COMM = openUnifiedCommunicationModal;

        if (!sendBtn.dataset.boundVerificationMail) {
            sendBtn.dataset.boundVerificationMail = '1';
            sendBtn.addEventListener('click', sendVerificationMail);
        }
        if (toEl && !toEl.dataset.boundVerificationMail) {
            toEl.dataset.boundVerificationMail = '1';
            toEl.addEventListener('input', refreshSendBtnState);
            toEl.addEventListener('change', refreshSendBtnState);
        }

        document.querySelectorAll('[data-mail-component]').forEach(function (btn) {
            if (btn.dataset.boundVerificationMail) return;
            btn.dataset.boundVerificationMail = '1';
            btn.addEventListener('click', async function () {
                var component = normSection(btn.getAttribute('data-mail-component') || '');
                if (!allowedMailComponents[component]) return;
                var payload = getPayload ? getPayload() : null;
                if (!hasSnapshotComponent(payload, component)) {
                    setBoxMessage('cvTopMessage', 'Verification mail is available only for snapshot-backed sections in this case.', 'info');
                    return;
                }
                if (!canValidatorActOnComponent(component, 'send_mail', 'top')) return;
                state.resend = (String(btn.textContent || '').toLowerCase().indexOf('resend') !== -1);
                openUnifiedCommunicationModal({ mode: 'verification', component: component, notes: '' });
                if (titleEl && state.resend) {
                    titleEl.textContent = 'Resend Verification Mail - ' + (component === 'education' ? 'Education' : 'Employment');
                }
            });
        });

        refreshButtonStates().catch(function () {});
    }

    function getVerifierGroup() {
        var g = (window.VR_GROUP || '').toString().toUpperCase().trim();
        // Verifier queue groups
        if (g === 'BASIC' || g === 'EDUCATION') return g;
        return '';
    }

    function getRole() {
        function normRole(v) {
            var r = String(v || '').toLowerCase().trim();
            if (r === 'customer_admin') return 'client_admin';
            if (r === 'component verifier' || r === 'component_verifier') return 'verifier';
            if (r === 'component validator' || r === 'component_validator') return 'validator';
            if (r === 'db verifier' || r === 'db-verifier') return 'db_verifier';
            if (r === 'gss admin') return 'gss_admin';
            if (r === 'team lead' || r === 'team_lead') return 'team_lead';
            return r;
        }
        var s = normRole(window.CURRENT_ROLE || '');
        if (s) return s;
        return normRole(qs('role') || '');
    }

    function setBoxMessage(id, text, type) {
        var el = document.getElementById(id);
        if (!el) return;
        if (id === 'cvTopMessage') {
            var msgTop = String(text || '').trim();
            if (msgTop) showCrToast(msgTop, type || 'info');
            el.style.display = 'none';
            el.textContent = '';
            el.className = '';
            return;
        }
        if (!text) {
            el.style.display = 'none';
            el.textContent = '';
            el.className = '';
            return;
        }
        el.style.display = 'block';
        el.textContent = String(text);
        el.className = type ? ('alert alert-' + type) : 'alert';
    }

    function friendlyMailError(message) {
        var text = String(message || '').trim();
        var lower = text.toLowerCase();
        if (
            lower.indexOf('node communication failed') !== -1 ||
            lower.indexOf('smtp') !== -1 ||
            lower.indexOf('temporary lookup failure') !== -1 ||
            lower.indexOf('recipients were rejected') !== -1 ||
            lower.indexOf('mail provider') !== -1
        ) {
            return 'Mail provider temporarily rejected the send. Please retry. If it continues, contact admin to check SMTP configuration.';
        }
        return text || 'Failed to send verification mail.';
    }

    function allowedSectionsSet() {
        var role = getRole();
        var raw = (window.ALLOWED_SECTIONS || '').toString().toLowerCase().trim();
        if (raw === '*') return { '*': true };
        if (!raw) {
            if (role === 'verifier' || role === 'validator' || role === 'db_verifier') return {};
            return { '*': true };
        }
        var out = {};
        raw.split(/[\s,|]+/).forEach(function (p) {
            var k = normSection(String(p || '').trim());
            if (k) out[k] = true;
        });
        return out;
    }

    function canSeeSection(key, allowSet) {
        if (!allowSet) allowSet = allowedSectionsSet();
        if (allowSet['*']) return true;
        var k = String(key || '').toLowerCase().trim();
        return !!(k && allowSet[k]);
    }

    function getWorkflowModeValue(payload) {
        var p = payload && typeof payload === 'object' ? payload : REPORT_PAYLOAD;
        try {
            return String(
                (p && (p.workflow_mode || p.workflowMode || (p.case && p.case.workflow_mode) || (p.data && p.data.workflow_mode) || (p.data && p.data.case && p.data.case.workflow_mode))) || ''
            ).trim().toLowerCase();
        } catch (_e) {
            return '';
        }
    }

    function workflowModeLabel(mode) {
        var m = String(mode || '').trim().toLowerCase();
        if (m === 'verifier_first') return 'Verifier First';
        if (m === 'validator_first') return 'Validator First';
        return '-';
    }

    function displayCaseStatus(appStatus, caseStatus) {
        var a = String(appStatus || '').trim();
        var c = String(caseStatus || '').trim();
        var role = getRole();
        var workflowMode = getWorkflowModeValue();
        var cu = c.toUpperCase();
        if (cu === 'REJECTED' || cu === 'STOP_BGV' || cu === 'APPROVED' || cu === 'VERIFIED' || cu === 'COMPLETED' || cu === 'CLEAR') {
            return c;
        }
        var au = a.toUpperCase();
        if (workflowMode === 'verifier_first') {
            if (cu === 'PENDING_VERIFIER' || au === 'PENDING_VERIFIER' || au === 'SUBMITTED') return 'V Pending';
            if (cu === 'PENDING_QA' || au === 'PENDING_QA') return 'Pending QA';
        }
        if (role === 'validator' || role === 'db_verifier') {
            if (cu === 'PENDING_VERIFIER') return 'Pending Verifier';
            if (cu === 'PENDING_QA') return 'Pending QA';
            if (cu === 'PENDING_VALIDATOR' || cu === 'IN_PROGRESS') return 'VA Pending';
            if (cu === 'PENDING_CANDIDATE' || cu === 'CANDIDATE_PENDING' || cu === 'INVITED') {
                if (au === 'SUBMITTED' || au === 'PENDING_VALIDATOR') return 'VA Pending';
            }
            if (au === 'SUBMITTED') return 'VA Pending';
        }
        if (role === 'verifier') {
            if (cu === 'PENDING_VERIFIER' || au === 'PENDING_VERIFIER') return 'V Pending';
            if (cu === 'PENDING_QA' || au === 'PENDING_QA') return 'Pending QA';
        }
        if (role === 'qa' || role === 'team_lead') {
            if (cu === 'PENDING_QA' || au === 'PENDING_QA') return 'Pending QA';
        }
        return c || a;
    }

    function sectionLabel(section) {
        section = normSection(section);
        if (section === 'basic') return 'Basic';
        if (section === 'id') return 'Identification';
        if (section === 'education') return 'Education';
        if (section === 'education_reference') return 'Education Reference';
        if (section === 'employment') return 'Employment';
        if (section === 'employment_reference') return 'Employment Reference';
        if (section === 'reference') return 'Reference';
        if (section === 'socialmedia') return 'Social Media';
        if (section === 'ecourt') return 'E-court';
        if (section === 'database') return 'Database';
        if (section === 'driving_licence') return 'Driving Licence';
        if (section === 'contact') return 'Contact';
        if (section === 'reports') return 'Reports';
        return section ? (section.charAt(0).toUpperCase() + section.slice(1)) : '';
    }

    function canUseComponentWorkflowRole(role) {
        role = String(role || '').toLowerCase().trim();
        return (role === 'verifier' || role === 'validator' || role === 'db_verifier' || role === 'qa' || role === 'team_lead');
    }

    function canTakeActionRole(role) {
        role = String(role || '').toLowerCase().trim();
        return canUseComponentWorkflowRole(role) || role === 'gss_admin' || role === 'client_admin';
    }

    function setCompNavActive(section) {
        var host = document.getElementById('cvComponentNavItems');
        if (!host) return;
        section = String(section || '').toLowerCase().trim();
        Array.prototype.slice.call(host.querySelectorAll('[data-comp]')).forEach(function (b) {
            b.classList.toggle('active', String(b.getAttribute('data-comp') || '') === section);
        });
    }

    function panelSectionForComponent(section) {
        section = normSection(section);
        if (section === 'education_reference' || section === 'employment_reference') return 'reference';
        return section;
    }

    function referenceTypeForSection(section) {
        section = normSection(section);
        if (section === 'education_reference') return 'education';
        if (section === 'employment_reference') return 'employment';
        return '';
    }

    function normalizeReferenceRow(row) {
        row = row && typeof row === 'object' ? row : {};
        return {
            reference_name: row.reference_name || row.name || '',
            reference_designation: row.reference_designation || row.designation || '',
            reference_company: row.reference_company || row.company || '',
            reference_mobile: row.reference_mobile || row.mobile || '',
            reference_email: row.reference_email || row.email || '',
            relationship: row.relationship || row.reference_relationship || '',
            years_known: row.years_known || row.reference_years_known || ''
        };
    }

    function legacyReferenceRowForType(referencePayload, type) {
        var legacy = referencePayload && referencePayload.legacy && typeof referencePayload.legacy === 'object'
            ? referencePayload.legacy
            : referencePayload;
        legacy = legacy && typeof legacy === 'object' ? legacy : {};
        if (type === 'education') {
            return normalizeReferenceRow({
                name: legacy.education_reference_name || '',
                designation: legacy.education_reference_designation || '',
                company: legacy.education_reference_company || '',
                mobile: legacy.education_reference_mobile || '',
                email: legacy.education_reference_email || '',
                relationship: legacy.education_reference_relationship || '',
                years_known: legacy.education_reference_years_known || ''
            });
        }
        if (type === 'employment') {
            return normalizeReferenceRow({
                name: legacy.employment_reference_name || legacy.reference_name || '',
                designation: legacy.employment_reference_designation || legacy.reference_designation || '',
                company: legacy.employment_reference_company || legacy.reference_company || '',
                mobile: legacy.employment_reference_mobile || legacy.reference_mobile || '',
                email: legacy.employment_reference_email || legacy.reference_email || '',
                relationship: legacy.employment_reference_relationship || legacy.relationship || '',
                years_known: legacy.employment_reference_years_known || legacy.years_known || ''
            });
        }
        return normalizeReferenceRow(legacy);
    }

    function referenceRowForSection(referencePayload, section) {
        var type = referenceTypeForSection(section);
        if (!referencePayload) return {};
        if (Array.isArray(referencePayload)) {
            if (type) {
                for (var i = 0; i < referencePayload.length; i++) {
                    var rowType = String(referencePayload[i] && (referencePayload[i].reference_type || referencePayload[i].type || '') || '').toLowerCase();
                    if (rowType.indexOf(type) !== -1) return normalizeReferenceRow(referencePayload[i]);
                }
            }
            return normalizeReferenceRow(referencePayload[0] || {});
        }
        if (type && Array.isArray(referencePayload[type]) && referencePayload[type].length) {
            return normalizeReferenceRow(referencePayload[type][0]);
        }
        if (type && referencePayload[type] && typeof referencePayload[type] === 'object' && !Array.isArray(referencePayload[type])) {
            return normalizeReferenceRow(referencePayload[type]);
        }
        return legacyReferenceRowForType(referencePayload, type);
    }

    function renderReferencePanelForSection(payload, section) {
        updateReferencePanelIdentity(section);
        var ref = referenceRowForSection(payload && payload.reference, section);
        setVal('cv_reference_name', ref.reference_name || '');
        setVal('cv_reference_designation', ref.reference_designation || '');
        setVal('cv_reference_company', ref.reference_company || '');
        setVal('cv_reference_mobile', ref.reference_mobile || '');
        setVal('cv_reference_email', ref.reference_email || '');
        setVal('cv_reference_relationship', ref.relationship || '');
        setVal('cv_reference_years_known', ref.years_known || '');
    }

    function updateReferencePanelIdentity(section) {
        section = normSection(section);
        var panel = document.getElementById('section-reference');
        if (!panel) return;
        var effectiveSection = (section === 'education_reference' || section === 'employment_reference') ? section : 'reference';
        panel.setAttribute('data-component-key', effectiveSection);
        panel.setAttribute('data-current-section', effectiveSection);
        var title = panel.querySelector('.cr-secbar-title');
        if (title) {
            title.textContent = sectionLabel(effectiveSection);
        }
        var remarksLabel = panel.querySelector('label[for="cvRemarksReference"]');
        if (remarksLabel) {
            remarksLabel.textContent = 'Comments / Remarks - ' + sectionLabel(effectiveSection);
        }
    }

    function canShowComponentToolbar(section) {
        section = String(section || '').toLowerCase().trim();
        if (!section || section === 'timeline') return false;
        var role = getRole();
        if (!canTakeActionRole(role)) return false;
        return true;
    }

    function componentToolbarHtml(section) {
        return '' +
            '<div class="cr-comp-tools">' +
                '<div class="cr-comp-tools-top">' +
                    '<div class="cr-comp-tools-title">Evidence / Uploaded Documents</div>' +
                '</div>' +
                '<div class="cr-comp-evidence">' +
                    '<div data-comp-docs style="margin-top:8px;"></div>' +
                '</div>' +
                '<div class="cr-comp-upload-row">' +
                    '<div class="cr-comp-upload-label">Evidence Document</div>' +
                    '<input type="file" class="cr-comp-file" data-comp-file accept=".pdf,image/*">' +
                    '<button type="button" class="btn" data-comp-upload>Upload</button>' +
                '</div>' +
            '</div>' +
            '';
    }

    async function loadUploadedDocsForComponent(applicationId, docType, hostEl) {
        if (!hostEl) return;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/verification_docs_list.php?application_id=' + encodeURIComponent(applicationId);
        if (docType) url += '&doc_type=' + encodeURIComponent(docType);

        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                hostEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
                return;
            }
            var rows = data.data || [];
            if (!Array.isArray(rows) || rows.length === 0) {
                hostEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
                return;
            }

            hostEl.innerHTML = rows.map(function (r) {
                var href = docHref(r) || '#';
                var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : 'Document';
                var by = r && r.uploaded_by_role ? String(r.uploaded_by_role) : '';
                var created = r && r.created_at ? String(r.created_at) : '';
                return '<div style="display:flex; gap:10px; justify-content:space-between; align-items:flex-start; padding:8px 10px; border:1px solid rgba(148,163,184,0.18); border-radius:10px; margin-bottom:8px;">' +
                    '<div style="min-width:0;">' +
                        '<div style="font-size:12px; font-weight:900; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + esc(label) + '</div>' +
                        '<div style="font-size:11px; color:#64748b; margin-top:2px;">' + (by ? ('By: ' + esc(by) + ' · ') : '') + esc(created) + '</div>' +
                    '</div>' +
                    '<a href="' + esc(href) + '" class="js-cv-doc-view" data-doc-label="' + esc(label) + '" style="text-decoration:none; color:#2563eb; font-weight:900; white-space:nowrap;">View</a>' +
                '</div>';
            }).join('');
        } catch (_e) {
            hostEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
        }
    }

    async function uploadEvidenceForComponent(applicationId, docType, files) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/verification_docs_upload.php';

        var fd = new FormData();
        fd.append('application_id', applicationId);
        fd.append('doc_type', String(docType || 'general'));
        fd.append('role', String(qs('role') || ''));
        var clientId = qs('client_id');
        if (clientId) fd.append('client_id', String(clientId));

        for (var i = 0; i < files.length; i++) {
            fd.append('files[]', files[i]);
        }

        var res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
        var data = await res.json().catch(function () { return null; });
        if (!res.ok || !data || data.status !== 1) {
            throw new Error((data && data.message) ? data.message : 'Upload failed');
        }
        return data;
    }

    function ensureComponentToolbar(section) {
        if (!canShowComponentToolbar(section)) return;
        if (!CURRENT_APP_ID) return;

        var panel = document.getElementById('section-' + String(section));
        if (!panel) return;
        if (panel.dataset.compToolsBound === '1') {
            var docsHost = panel.querySelector('[data-comp-docs]');
            if (docsHost) {
                loadUploadedDocsForComponent(CURRENT_APP_ID, section, docsHost);
            }
            return;
        }

        panel.dataset.compToolsBound = '1';
        var wrap = document.createElement('div');
        wrap.innerHTML = componentToolbarHtml(section);
        var toolsEl = wrap.firstElementChild || wrap;

        var leftCol = panel.querySelector('.cr-comp-left');
        var hostForInsert = leftCol || panel;
        var inserted = false;

        var tabPanel = hostForInsert.querySelector('.cr-record-panel.active .cr-kv2-wrap') ||
            hostForInsert.querySelector('.cr-record-panel .cr-kv2-wrap');
        if (tabPanel) {
            tabPanel.appendChild(toolsEl);
            inserted = true;
        }

        if (!inserted) {
            var lastKvWrap = hostForInsert.querySelector('.cr-kv2-wrap:last-of-type');
            if (lastKvWrap) {
                lastKvWrap.appendChild(toolsEl);
                inserted = true;
            }
        }

        if (!inserted) {
            var formGrid = hostForInsert.querySelector('.form-grid');
            if (formGrid) {
                formGrid.appendChild(toolsEl);
                inserted = true;
            }
        }

        if (!inserted) {
            hostForInsert.appendChild(toolsEl);
        }

        var docsHost2 = panel.querySelector('[data-comp-docs]');
        if (docsHost2) {
            loadUploadedDocsForComponent(CURRENT_APP_ID, section, docsHost2);
        }

        toolsEl.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t) return;

            var upBtn = t.closest ? t.closest('[data-comp-upload]') : null;
            if (upBtn) {
                if (!canValidatorActOnComponent(section, 'upload_evidence', 'upload')) return;
                var fileEl = panel.querySelector('[data-comp-file]');
                var files = fileEl && fileEl.files ? fileEl.files : null;
                if (!files || files.length === 0) {
                    setText('cvTopMessage', 'Please choose file(s) first.');
                    return;
                }
                upBtn.disabled = true;
                var oldText = upBtn.textContent;
                upBtn.textContent = 'Uploading...';
                uploadEvidenceForComponent(CURRENT_APP_ID, section, files)
                    .then(function () {
                        setText('cvTopMessage', 'Uploaded successfully.');
                        if (fileEl) fileEl.value = '';
                        var dh = panel.querySelector('[data-comp-docs]');
                        if (dh) return loadUploadedDocsForComponent(CURRENT_APP_ID, section, dh);
                    })
                    .catch(function (err) {
                        setText('cvTopMessage', err && err.message ? err.message : 'Upload failed');
                    })
                    .finally(function () {
                        upBtn.disabled = false;
                        upBtn.textContent = oldText;
                    });
            }
        });

        if (hostForInsert.dataset.recordTabsBound !== '1') {
            hostForInsert.dataset.recordTabsBound = '1';
            hostForInsert.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var btn = t && t.closest ? t.closest('[data-record-tab]') : null;
                if (!btn) return;
                var idx = String(btn.getAttribute('data-record-tab') || '0');
                var activePanel = hostForInsert.querySelector('.cr-record-panel[data-record-panel="' + idx + '"] .cr-kv2-wrap');
                if (activePanel && toolsEl.parentElement !== activePanel) {
                    activePanel.appendChild(toolsEl);
                    loadUploadedDocsForComponent(CURRENT_APP_ID, section, toolsEl.querySelector('[data-comp-docs]'));
                }
            });
        }
    }

    function verifierRoutingNavEntries(payload) {
        payload = payload || {};
        var d = payload.data || payload;
        var routing = d && d.verifier_routing_state ? d.verifier_routing_state : null;
        var components = routing && routing.components && typeof routing.components === 'object' ? routing.components : {};
        var priorityMap = { owned_active: 1, claimable_next: 2, locked_future: 3, completed: 4, context: 5 };
        var bySection = {};

        Object.keys(components).forEach(function (key) {
            var item = components[key] || {};
            var state = String(item.state || '').toLowerCase().trim();
            if (!state || state === 'hidden_unrelated') return;
            var section = normSection(key);
            if (!section) return;
            var entry = {
                section: section,
                componentKey: section,
                state: state,
                label: sectionLabel(section),
                priority: item.priority ? parseInt(String(item.priority), 10) || 0 : 0,
                reason: String(item.reason || '').trim(),
                displayStatus: String(item.display_status || '').trim(),
                history: Array.isArray(item.history) ? item.history : []
            };
            var current = bySection[section];
            if (!current || (priorityMap[entry.state] || 99) < (priorityMap[current.state] || 99)) {
                bySection[section] = entry;
            }
        });

        return Object.keys(bySection).map(function (section) {
            return bySection[section];
        }).sort(function (a, b) {
            if (a.section === 'basic' && b.section !== 'basic') return -1;
            if (b.section === 'basic' && a.section !== 'basic') return 1;
            if (a.priority !== b.priority) return a.priority - b.priority;
            return a.label.localeCompare(b.label);
        });
    }

    function reportNavStateLabel(state) {
        state = String(state || '').toLowerCase().trim();
        if (state === 'context') return 'Context';
        if (state === 'owned_active') return 'Owned';
        if (state === 'claimable_next') return 'Claimable';
        if (state === 'locked_future') return 'Locked';
        if (state === 'completed') return 'Completed';
        return '';
    }

    function renderComponentNav(payload) {
        var role = getRole();
        if (!canTakeActionRole(role)) return;

        var wrap = document.getElementById('cvComponentNav');
        var host = document.getElementById('cvComponentNavItems');
        if (!wrap || !host) return;

        var routingEntries = role === 'verifier' ? verifierRoutingNavEntries(payload) : [];
        var keys = getAssignedComponentKeys(payload);
        keys = (keys || []).filter(function (k) { return !!k; });
        var entries = routingEntries.length ? routingEntries : keys.map(function (k) {
            return { section: k, state: '', label: sectionLabel(k), priority: 0, reason: '' };
        });

        host.innerHTML = entries.map(function (entry) {
            var stateText = reportNavStateLabel(entry.state);
            if (entry.displayStatus) stateText = entry.displayStatus;
            var disabled = entry.state === 'locked_future';
            var historyTitle = entry.history.length
                ? entry.history.map(function (it) {
                    return [String(it.at || '').trim(), String(it.status || it.event || '').trim(), String(it.message || '').trim()].filter(Boolean).join(' - ');
                }).join('\n')
                : '';
            return '<button type="button" class="cr-compnav-btn" data-comp="' + esc(entry.section) + '" data-state="' + esc(entry.state || '') + '" data-lock-reason="' + esc(entry.reason || '') + '" title="' + esc(historyTitle || entry.reason || '') + '"' + (disabled ? ' disabled' : '') + '>' +
                esc(entry.label) +
                (stateText ? '<span class="cr-compnav-state">' + esc(stateText) + '</span>' : '') +
            '</button>';
        }).join('');

        if (!host.dataset.bound) {
            host.dataset.bound = '1';
            host.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var btn = t && t.closest ? t.closest('[data-comp]') : null;
                if (!btn) return;
                var sec = btn.getAttribute('data-comp') || '';
                if (btn.disabled || String(btn.getAttribute('data-state') || '') === 'locked_future') {
                    setBoxMessage('cvTopMessage', btn.getAttribute('data-lock-reason') || 'This component is locked by routing priority.', 'info');
                    return;
                }

                var sidebarBtn = document.querySelector('.list-group-item[data-section="' + sec.replace(/"/g, '') + '"]');
                if (sidebarBtn) {
                    sidebarBtn.click();
                }
                setCompNavActive(sec);
            });
        }

        var activeSidebar = document.querySelector('.list-group-item[data-section].active');
        var firstEnabled = entries.find(function (entry) { return entry.state !== 'locked_future'; });
        var activeSection = activeSidebar ? (activeSidebar.getAttribute('data-section') || '') : ((firstEnabled && firstEnabled.section) || (keys[0] || 'basic'));
        setCompNavActive(activeSection);
    }

    function renderVerifierWorkflowSidebar(payload) {
        var d = payload && payload.data ? payload.data : (payload || {});
        var routing = d && d.verifier_routing_state ? d.verifier_routing_state : null;
        var components = routing && routing.components && typeof routing.components === 'object' ? routing.components : {};
        var nav = document.querySelector('.cr-nav');
        if (!nav || !components || !Object.keys(components).length) return false;

        var entries = verifierRoutingNavEntries(d).filter(function (entry) {
            return entry && entry.section && entry.state !== 'hidden_unrelated';
        });
        var contextEntries = [];
        var componentEntries = [];
        entries.forEach(function (entry) {
            if (entry.section === 'basic' || entry.state === 'context') {
                contextEntries.push(entry);
                return;
            }
            componentEntries.push(entry);
        });

        function navStatus(entry) {
            var label = String(entry.displayStatus || reportNavStateLabel(entry.state) || '').trim();
            if (!label) return '';
            return '<span class="badge status-badge bg-secondary cr-dynamic-nav-badge">' + escHtml(label) + '</span>';
        }

        function navButton(entry) {
            var section = normSection(entry.section);
            var label = entry.label || sectionLabel(section);
            var disabled = entry.state === 'locked_future';
            return '<button type="button" class="list-group-item section-row cr-dynamic-nav-item" data-section="' + escHtml(section) + '" data-component-key="' + escHtml(section) + '" style="text-align:left;"' + (disabled ? ' data-locked="1" disabled' : '') + '>' +
                '<span class="section-left">' +
                    '<span class="section-title">' + escHtml(label) + '</span>' +
                '</span>' +
                '<span class="section-right">' + navStatus(entry) + '</span>' +
            '</button>';
        }

        function orderedBucketEntries(items) {
            var bySection = {};
            items.forEach(function (entry) {
                bySection[entry.section] = entry;
            });
            var out = [];
            ['education', 'employment', 'id', 'contact', 'ecourt', 'socialmedia', 'reports'].forEach(function (section) {
                if (bySection[section]) {
                    out.push({ entry: bySection[section] });
                    if (section === 'education' && bySection.education_reference) {
                        out.push({ entry: bySection.education_reference });
                    }
                    if (section === 'employment' && bySection.employment_reference) {
                        out.push({ entry: bySection.employment_reference });
                    }
                }
            });
            ['education_reference', 'employment_reference'].forEach(function (section) {
                if (bySection[section]) {
                    var hasParent = section === 'education_reference' ? !!bySection.education : !!bySection.employment;
                    if (!hasParent) out.push({ entry: bySection[section] });
                }
            });
            items.forEach(function (entry) {
                var exists = out.some(function (row) { return row.entry.section === entry.section; });
                if (!exists) out.push({ entry: entry });
            });
            return out;
        }

        var html = [];
        var basicEntry = contextEntries.find(function (entry) { return entry.section === 'basic'; }) || {
            section: 'basic',
            label: 'Basic Details',
            state: 'context',
            displayStatus: 'Context'
        };
        html.push(navButton(basicEntry));
        orderedBucketEntries(componentEntries).forEach(function (row) {
            html.push(navButton(row.entry));
        });

        nav.innerHTML = html.join('');
        return true;
    }

    function getAssignedComponentKeys(payload) {
        payload = payload || {};
        var d = payload.data || payload;
        var visible = Array.isArray(d.visible_sections) ? d.visible_sections : (Array.isArray(d.visibleSections) ? d.visibleSections : []);
        if (visible.length) {
            var snapshotStrict = {};
            visible.forEach(function (k) {
                var nk = normSection(k);
                if (nk) snapshotStrict[nk] = true;
            });
            return Object.keys(snapshotStrict);
        }

        var list = Array.isArray(d.assigned_components) ? d.assigned_components : [];
        var out = {};
        list.forEach(function (r) {
            var k = (r && r.component_key) ? String(r.component_key).toLowerCase().trim() : '';
            k = normSection(k);
            if (k) out[k] = true;
        });

        return Object.keys(out);
    }

    function hasSnapshotComponent(payload, section) {
        var k = normSection(section);
        if (!k) return false;
        var keys = getAssignedComponentKeys(payload || {});
        for (var i = 0; i < keys.length; i++) {
            if (normSection(keys[i]) === k) return true;
        }
        return false;
    }

    function normSection(s) {
        s = String(s || '').toLowerCase().trim();
        if (!s) return '';
        s = s.replace(/[\s\-]+/g, '_');
        if (s === 'identification') return 'id';
        if (s === 'address') return 'contact';
        if (s === 'employment_details' || s === 'employement' || s === 'employement_details') return 'employment';
        if (s === 'education_details') return 'education';
        if (s === 'references') return 'reference';
        if (s === 'social_media') return 'socialmedia';
        if (s === 'e_court') return 'ecourt';
        if (s === 'report') return 'reports';
        if (s === 'general') return '';
        return s;
    }

    function filterTimeline(items, section) {
        section = normSection(section);
        if (!section || section === 'all') return items;
        return (items || []).filter(function (it) {
            var s = normSection(it && (it.section_key || it.section));
            return s === section;
        });
    }

    function isWholeCaseCompletionItem(it) {
        var sec = normSection(it && (it.section_key || it.section));
        var msg = String(it && it.message ? it.message : '').toLowerCase();
        if (sec === 'case_status') return true;
        if (msg.indexOf('case action:') !== -1) return true;
        if (msg.indexOf('completed case') !== -1) return true;
        if (msg.indexOf('completed the case') !== -1) return true;
        if (msg.indexOf('completed the group') !== -1) return true;
        return false;
    }

    function renderMiniTimeline() {
        var host = document.getElementById('cvMiniTimeline');
        if (!host) return;

        var filtered = filterTimeline(TL_CACHE, TL_ACTIVE_FILTER);
        host.innerHTML = timelineHtml(filtered);

        var countEl = document.getElementById('cvMiniTimelineCount');
        if (countEl) {
            countEl.textContent = String(Array.isArray(filtered) ? filtered.length : 0);
        }
    }

    function setMiniTimelineFilter(section) {
        TL_ACTIVE_FILTER = normSection(section) || 'all';

        var allowSet = allowedSectionsSet();
        var pills = Array.prototype.slice.call(document.querySelectorAll('#cvMiniTimelineFilters [data-tl-section]'));
        pills.forEach(function (p) {
            p.classList.toggle('active', normSection(p.getAttribute('data-tl-section')) === TL_ACTIVE_FILTER);
        });

        // Hide pills for disallowed sections
        pills.forEach(function (p) {
            var sec = normSection(p.getAttribute('data-tl-section') || '');
            if (sec && sec !== 'all' && !canSeeSection(sec, allowSet)) {
                p.style.display = 'none';
            }
        });

        renderMiniTimeline();
    }

    function initMiniTimelineFilters() {
        var wrap = document.getElementById('cvMiniTimelineFilters');
        if (!wrap) return;
        if (wrap.dataset.bound) return;
        wrap.dataset.bound = '1';

        wrap.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t) return;
            var btn = t.closest ? t.closest('[data-tl-section]') : null;
            if (!btn) return;
            var sec = btn.getAttribute('data-tl-section') || 'all';
            setMiniTimelineFilter(sec);
        });
    }

    function timelineHtml(items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div style="color:#6b7280; font-size:13px;">No timeline yet.</div>';
        }

        function toneBadgeClass(tone) {
            var t = String(tone || '').toLowerCase();
            if (t === 'red') return 'bg-danger';
            if (t === 'green') return 'bg-success';
            if (t === 'amber') return 'bg-warning text-dark';
            return 'bg-primary';
        }

        function prettyEventType(type) {
            var t = String(type || '').trim();
            if (!t) return 'Timeline Event';
            return t.replace(/[._]+/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); });
        }

        var groups = {};
        items.forEach(function (it) {
            var dt = null;
            try {
                dt = it && it.created_at ? new Date(it.created_at) : null;
                if (!dt || isNaN(dt.getTime())) dt = null;
            } catch (_e) {
                dt = null;
            }
            var key = dt ? dt.toISOString().slice(0, 10) : 'Unknown';
            if (!groups[key]) groups[key] = [];
            groups[key].push(it);
        });

        var keys = Object.keys(groups).sort().reverse();
        var html = keys.map(function (k) {
            var label = k === 'Unknown' ? 'Unknown date' : k;
            var rows = groups[k] || [];

            var itemsHtml = rows.map(function (it, idx) {
                var side = (idx % 2 === 0) ? 'left' : 'right';
                var actorName = ((it.first_name || '') + ' ' + (it.last_name || '')).trim();
                var actorUser = (it.username || '') ? String(it.username) : '';
                var role = it.actor_role || '';
                var actor = actorName || actorUser || (role ? String(role).toUpperCase() : '') || 'System';
                var type = it.event_type || '';
                var section = it.section_key || it.section || '';
                var msg = it.message || '';
                var governance = (it && typeof it.governance === 'object' && it.governance) ? it.governance : null;
                var displayTitle = governance && governance.title ? String(governance.title) : prettyEventType(type);
                var displaySummary = governance && governance.summary ? String(governance.summary) : String(msg || '');
                var displayReason = governance && governance.reason ? String(governance.reason) : '';
                var displayLineage = governance && governance.lineage ? String(governance.lineage) : '';
                var ts = '';
                try {
                    ts = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
                } catch (_e) {
                    ts = it.created_at ? String(it.created_at) : '';
                }

                var dotTone = 'blue';
                if (governance && governance.tone) {
                    dotTone = String(governance.tone).toLowerCase();
                }
                var tLower = String(type || '').toLowerCase();
                var mLower = String(msg || '').toLowerCase();
                if (!governance) {
                    if (tLower.indexOf('hold') !== -1) dotTone = 'amber';
                    if (tLower.indexOf('reject') !== -1) dotTone = 'red';
                    if (tLower.indexOf('approve') !== -1 || tLower.indexOf('complete') !== -1) dotTone = 'green';
                    if (mLower.indexOf('hold') !== -1) dotTone = 'amber';
                    if (mLower.indexOf('reject') !== -1) dotTone = 'red';
                    if (mLower.indexOf('approve') !== -1 || mLower.indexOf('status: approved') !== -1) dotTone = 'green';
                }

                var badges = [];
                if (governance && governance.kind) {
                    badges.push('<span class="badge ' + toneBadgeClass(governance.tone) + '" style="margin-right:6px;">' + esc(String(governance.kind).toUpperCase()) + '</span>');
                } else if (type) {
                    badges.push('<span class="badge bg-secondary" style="margin-right:6px;">' + esc(type) + '</span>');
                }
                if (section) badges.push('<span class="badge bg-light text-dark" style="margin-right:6px; border:1px solid rgba(148,163,184,0.35);">' + esc(section) + '</span>');
                if (role) badges.push('<span class="badge bg-light text-dark" style="border:1px solid rgba(148,163,184,0.35);">' + esc(role) + '</span>');

                return (
                    '<div class="cr-flow-item cr-flow-' + side + '">' +
                        '<div class="cr-flow-dot cr-flow-dot-' + dotTone + '" aria-hidden="true"></div>' +
                        '<div class="cr-flow-card">' +
                            '<div class="cr-flow-head">' +
                                '<div class="cr-flow-actor">' + esc(actor) + '</div>' +
                                '<div class="cr-flow-time">' + esc(ts) + '</div>' +
                            '</div>' +
                            '<div class="cr-flow-actor" style="font-size:13px; font-weight:800; margin-bottom:4px;">' + esc(displayTitle) + '</div>' +
                            (badges.length ? ('<div class="cr-flow-badges">' + badges.join('') + '</div>') : '') +
                            (displaySummary ? ('<div class="cr-flow-msg">' + esc(displaySummary) + '</div>') : '') +
                            (displayReason ? ('<div class="cr-flow-msg" style="color:#92400e;"><strong>Reason:</strong> ' + esc(displayReason) + '</div>') : '') +
                            (displayLineage ? ('<div class="cr-flow-msg" style="color:#64748b;">' + esc(displayLineage) + '</div>') : '') +
                            (!governance && msg && displaySummary !== msg ? ('<div class="cr-flow-msg">' + esc(msg) + '</div>') : '') +
                        '</div>' +
                    '</div>'
                );
            }).join('');

            return (
                '<div class="cr-flow-group">' +
                    '<div class="cr-flow-date">' + esc(label) + '</div>' +
                    '<div class="cr-flow-list">' + itemsHtml + '</div>' +
                '</div>'
            );

        }).join('');

        return '<div class="cr-flow">' + html + '</div>';
    }

    function emailRepliesEmptyHtml(opts) {
        opts = opts || {};
        var role = String(opts.role || getRole() || '').toLowerCase().trim();
        var componentKey = normSection(String(opts.componentKey || currentRepliesScopeComponent() || ''));
        var meta = opts.meta && typeof opts.meta === 'object' ? opts.meta : {};
        var hasOtherThread = !!opts.hasOtherThread || (!!meta.scope_has_thread && !meta.viewer_thread_exists);

        var roleLabel = 'this role';
        if (role === 'validator') roleLabel = 'validator';
        else if (role === 'verifier' || role === 'db_verifier') roleLabel = 'verifier';
        else if (role === 'qa' || role === 'team_lead') roleLabel = 'QA';

        var componentLabel = componentKey ? sectionLabel(componentKey) : 'this component';
        if (meta.sync_failed) {
            return '<div style="color:#6b7280; font-size:13px;">No replies available yet for ' + esc(componentLabel) + '.</div>';
        }
        if (meta.no_thread) {
            return '<div style="color:#6b7280; font-size:13px;">No mail thread has been sent for ' + esc(componentLabel) + ' yet.</div>';
        }
        if (hasOtherThread && role !== 'qa' && role !== 'team_lead') {
            return '<div style="color:#6b7280; font-size:13px;">Replies exist for ' + esc(componentLabel) + ', but no ' + esc(roleLabel) + ' mail thread is linked to this component yet.</div>';
        }
        if (meta.viewer_thread_exists) {
            return '<div style="color:#6b7280; font-size:13px;">No replies yet for the ' + esc(roleLabel) + ' thread on ' + esc(componentLabel) + '.</div>';
        }
        return '<div style="color:#6b7280; font-size:13px;">No ' + esc(roleLabel) + ' replies yet for ' + esc(componentLabel) + '.</div>';
    }

    function emailRepliesHtml(items, opts) {
        if (!Array.isArray(items) || items.length === 0) {
            return emailRepliesEmptyHtml(opts);
        }
        var viewerRole = normalizeNodeWorkflowRole(String((opts && opts.role) ? opts.role : ''));
        var reviewerView = (viewerRole === 'validator' || viewerRole === 'verifier' || viewerRole === 'qa');

        function communicationBadge(item) {
            var subject = String(item && item.subject ? item.subject : '').toLowerCase();
            var type = String(item && item.communication_type ? item.communication_type : '').toLowerCase();
            var direction = String(item && item.direction ? item.direction : '').toLowerCase();
            var componentKey = String(item && item.component_key ? item.component_key : '').toLowerCase();

            function badge(label, fg, bg, border) {
                return '<span style="font-size:10px; color:' + fg + '; background:' + bg + '; border:1px solid ' + border + '; border-radius:999px; padding:1px 6px; font-weight:700;">' + esc(label) + '</span>';
            }

            var isNeedDocs = subject.indexOf('insufficient documents') !== -1
                || subject.indexOf('need docs') !== -1
                || type === 'insufficient_documents';
            if (isNeedDocs) {
                return direction === 'incoming'
                    ? badge('Need Docs Reply', '#9a3412', '#fff7ed', '#fdba74')
                    : badge('Need Docs', '#9a3412', '#fff7ed', '#fdba74');
            }

            var isVerificationMail = subject.indexOf('verification request') !== -1
                || type === 'verification_request';
            if (isVerificationMail) {
                var label = 'Verification Mail';
                if (componentKey === 'education') label = 'Education Mail';
                if (componentKey === 'employment') label = 'Employment Mail';
                if (direction === 'incoming') label = label.replace('Mail', 'Reply');
                return badge(label, '#1d4ed8', '#eff6ff', '#93c5fd');
            }

            var isCorrection = subject.indexOf('candidate correction') !== -1
                || type === 'correction_request';
            if (isCorrection) {
                return direction === 'incoming'
                    ? badge('Correction Reply', '#7c3aed', '#f5f3ff', '#c4b5fd')
                    : badge('Correction Mail', '#7c3aed', '#f5f3ff', '#c4b5fd');
            }

            if (direction === 'incoming') {
                return badge('Reply', '#065f46', '#ecfdf3', '#a7f3d0');
            }
            return badge('Mail', '#334155', '#f8fafc', '#cbd5e1');
        }

        function isReplyNoiseLine(line) {
            var l = String(line || '').trim();
            if (!l) return false;
            var lower = l.toLowerCase();
            if (/^>+$/.test(l)) return true;
            if (l.indexOf('<http://') === 0 || l.indexOf('<https://') === 0) return true;
            if (lower.indexOf('avg.com/email-signature') !== -1) return true;
            if (lower.indexOf('virus-free.www.avg.com') !== -1) return true;
            if (lower.indexOf('utm_medium=email') !== -1) return true;
            if (lower.indexOf('utm_source=link') !== -1) return true;
            if (lower.indexOf('utm_campaign=') !== -1) return true;
            if (lower.indexOf('utm_content=') !== -1) return true;
            if (lower.indexOf('cid:') === 0) return true;
            if (lower.indexOf('mailto:') === 0) return true;
            if (/^https?:\/\/\S+$/i.test(l)) return true;
            return false;
        }

        function isReplyThreadBoundary(line) {
            var l = String(line || '').trim();
            if (!l) return false;
            var lower = l.toLowerCase();
            if (/^on\s.+wrote:$/i.test(l)) return true;
            if (/^from:\s/i.test(l)) return true;
            if (/^sent:\s/i.test(l)) return true;
            if (/^to:\s/i.test(l)) return true;
            if (/^subject:\s/i.test(l)) return true;
            if (/^-----original message-----$/i.test(l)) return true;
            if (/^_{5,}$/.test(l)) return true;
            if (lower.indexOf('begin forwarded message') !== -1) return true;
            return false;
        }

        function smartReplyText(raw) {
            var text = String(raw || '')
                .replace(/\r\n?/g, '\n')
                .replace(/\u00a0/g, ' ')
                .replace(/[ \t]+\n/g, '\n');

            var out = [];
            var lines = text.split('\n');
            for (var i = 0; i < lines.length; i++) {
                var line = String(lines[i] || '');
                var trimmed = line.trim();
                if (isReplyThreadBoundary(trimmed)) break;
                if (!trimmed) {
                    if (out.length && out[out.length - 1] !== '') out.push('');
                    continue;
                }
                if (trimmed.charAt(0) === '>' || isReplyNoiseLine(trimmed)) continue;
                out.push(trimmed);
                if (out.length >= 24) break;
            }

            var clean = out.join('\n').replace(/\n{3,}/g, '\n\n').trim();
            if (!clean) {
                clean = String(raw || '').replace(/\r\n?/g, '\n').replace(/\n{3,}/g, '\n\n').trim();
            }

            var isLong = clean.length > 900;
            if (isLong) {
                clean = clean.slice(0, 900).trim() + '\n\n[truncated]';
            }
            return clean;
        }

        return items.map(function (it) {
            var sender = it && it.sender ? String(it.sender) : 'Unknown';
            var msg = smartReplyText(it && it.message ? String(it.message) : '');
            var when = it && it.created_at ? String(it.created_at) : '';
            var direction = String(it && it.direction ? it.direction : '').toLowerCase();
            var actorRole = String(it && it.actor_role ? it.actor_role : '');
            var ts = '';
            try {
                ts = when ? window.GSS_DATE.formatDbDateTime(when) : '';
            } catch (_e) {
                ts = when;
            }
            var badge = direction === 'incoming'
                ? '<span style="font-size:10px; color:#065f46; background:#ecfdf3; border:1px solid #a7f3d0; border-radius:999px; padding:1px 6px;">' + (reviewerView ? 'Response' : 'Incoming') + '</span>'
                : '<span style="font-size:10px; color:#1e3a8a; background:#eff6ff; border:1px solid #bfdbfe; border-radius:999px; padding:1px 6px;">' + (reviewerView ? 'Request' : 'Outgoing') + '</span>';
            var commBadge = communicationBadge(it);

            var safeMsg = esc(msg).replace(/\n/g, '<br>');

            return ''
                + '<div style="border:1px solid rgba(148,163,184,0.3); border-radius:10px; padding:10px; background:#fff;">'
                + '<div style="display:flex; justify-content:space-between; gap:8px; margin-bottom:6px;">'
                + '<b style="font-size:12px; color:#0f172a;">' + esc(sender) + (actorRole ? ' (' + esc(actorRole) + ')' : '') + '</b>'
                + '<span style="font-size:11px; color:#64748b;">' + esc(ts) + '</span>'
                + '</div>'
                + '<div style="margin-bottom:6px; display:flex; gap:6px; flex-wrap:wrap;">' + badge + commBadge + '</div>'
                + '<div style="font-size:12px; color:#0f172a; line-height:1.5; white-space:normal;">' + safeMsg + '</div>'
                + '</div>';
        }).join('');
    }

    function isRequestActionReplyRow(item) {
        if (!item) return false;
        var direction = String(item.direction || '').toLowerCase().trim();
        if (direction === 'incoming') return false;
        var subject = String(item.subject || '').toLowerCase();
        var type = String(item.communication_type || '').toLowerCase().trim();
        var action = String(item.action_key || '').toLowerCase().trim();
        return type === 'insufficient_documents'
            || action === 'insufficient_documents'
            || type === 'correction_request'
            || action === 'correction_request'
            || type === 'candidate_access_request'
            || action === 'candidate_access_request'
            || action === 'resend_candidate_access'
            || subject.indexOf('insufficient documents') !== -1
            || subject.indexOf('need docs') !== -1
            || subject.indexOf('candidate correction') !== -1
            || subject.indexOf('candidate access') !== -1;
    }

    function requestActionLabel(item) {
        var subject = String(item && item.subject ? item.subject : '').toLowerCase();
        var type = String(item && item.communication_type ? item.communication_type : '').toLowerCase().trim();
        var action = String(item && item.action_key ? item.action_key : '').toLowerCase().trim();
        if (type === 'insufficient_documents' || action === 'insufficient_documents' || subject.indexOf('need docs') !== -1 || subject.indexOf('insufficient documents') !== -1) {
            return 'Need Docs sent';
        }
        if (type === 'correction_request' || action === 'correction_request' || subject.indexOf('candidate correction') !== -1) {
            return 'Correction request sent';
        }
        if (type === 'candidate_access_request' || action === 'candidate_access_request' || action === 'resend_candidate_access' || subject.indexOf('candidate access') !== -1) {
            return 'Candidate access sent';
        }
        return 'Request sent';
    }

    function emailRequestActionsHtml(items) {
        var rows = Array.isArray(items) ? items.slice() : [];
        if (!rows.length) return '';
        rows.sort(function (a, b) { return rowTimeMsLocal(b) - rowTimeMsLocal(a); });
        return ''
            + '<div style="margin-bottom:10px;">'
            + '<div style="font-size:11px; font-weight:800; color:#475569; text-transform:uppercase; letter-spacing:.04em; margin:0 0 6px;">Requests / Actions</div>'
            + rows.map(function (it) {
                var sender = it && it.sender ? String(it.sender) : 'Operations';
                var msg = String(it && it.message ? it.message : '').trim();
                var when = it && it.created_at ? String(it.created_at) : '';
                var ts = '';
                try {
                    ts = when ? window.GSS_DATE.formatDbDateTime(when) : '';
                } catch (_e) {
                    ts = when;
                }
                return ''
                    + '<div style="border:1px solid rgba(251,146,60,0.45); border-radius:10px; padding:9px 10px; background:#fff7ed; margin-bottom:8px;">'
                    + '<div style="display:flex; justify-content:space-between; gap:8px; align-items:center; margin-bottom:5px;">'
                    + '<b style="font-size:12px; color:#9a3412;">' + esc(requestActionLabel(it)) + '</b>'
                    + '<span style="font-size:11px; color:#9a3412;">' + esc(ts) + '</span>'
                    + '</div>'
                    + '<div style="font-size:11px; color:#64748b; margin-bottom:' + (msg ? '5px' : '0') + ';">By ' + esc(sender) + '</div>'
                    + (msg ? '<div style="font-size:12px; color:#7c2d12; line-height:1.45;">' + esc(msg).replace(/\n/g, '<br>') + '</div>' : '')
                    + '</div>';
            }).join('')
            + '</div>';
    }

    function emailRepliesPanelHtml(items, opts) {
        var all = Array.isArray(items) ? items : [];
        var requestRows = all.filter(isRequestActionReplyRow);
        var mailRows = all.filter(function (row) { return !isRequestActionReplyRow(row); });
        if (!requestRows.length && !mailRows.length) {
            return emailRepliesEmptyHtml(opts);
        }
        var out = emailRequestActionsHtml(requestRows);
        if (mailRows.length) {
            out += emailRepliesHtml(mailRows, opts);
        } else {
            out += '<div style="color:#6b7280; font-size:13px;">No mail replies yet.</div>';
        }
        return out;
    }

    function replyRowIdentity(row) {
        if (!row) return '';
        var communicationId = String(row.communication_id || '').trim();
        if (communicationId && communicationId !== '0') return 'comm:' + communicationId;
        var id = String(row.id || '').trim();
        if (id) return 'id:' + id;
        var messageId = String(row.message_id || '').trim().toLowerCase();
        if (messageId) return 'msg:' + messageId;
        return [
            normSection(row.component_key || ''),
            String(row.communication_type || '').toLowerCase().trim(),
            String(row.action_key || '').toLowerCase().trim(),
            String(row.direction || '').toLowerCase().trim(),
            String(row.created_at || '').trim(),
            String(row.message || '').trim().slice(0, 120)
        ].join('|');
    }

    function mergeReplyRows(primaryRows, extraRows) {
        var merged = [];
        var seen = {};
        function add(row) {
            if (!row) return;
            var key = replyRowIdentity(row);
            if (key && seen[key]) return;
            if (key) seen[key] = true;
            merged.push(row);
        }
        (Array.isArray(primaryRows) ? primaryRows : []).forEach(add);
        (Array.isArray(extraRows) ? extraRows : []).forEach(add);
        return merged;
    }

    function emailRepliesRequestKey(applicationId, componentKey, shouldSync, viewerRole) {
        return [
            String(applicationId || '').trim(),
            String(componentKey || '').trim(),
            shouldSync ? 'sync' : 'nosync',
            String(viewerRole || '').trim()
        ].join('|');
    }

    function repliesScopeKey(applicationId, componentKey, viewerRole) {
        return [
            String(applicationId || '').trim(),
            String(componentKey || '').trim(),
            String(viewerRole || '').trim()
        ].join('|');
    }

    function getRepliesScopeCache(scopeKey) {
        var key = String(scopeKey || '');
        return key && EMAIL_REPLIES_SCOPE_CACHE[key] ? EMAIL_REPLIES_SCOPE_CACHE[key] : null;
    }

    function setRepliesScopeCache(scopeKey, rows, meta, renderKey) {
        var key = String(scopeKey || '');
        if (!key) return;
        EMAIL_REPLIES_SCOPE_CACHE[key] = {
            rows: Array.isArray(rows) ? rows : [],
            meta: meta && typeof meta === 'object' ? meta : {},
            renderKey: String(renderKey || ''),
            syncedAt: Date.now()
        };
    }

    function applyRepliesScopeCache(scopeKey) {
        var cached = getRepliesScopeCache(scopeKey);
        if (!cached) return false;
        EMAIL_REPLIES_CACHE = Array.isArray(cached.rows) ? cached.rows : [];
        EMAIL_REPLIES_META = cached.meta && typeof cached.meta === 'object' ? cached.meta : {};
        EMAIL_REPLIES_LAST_RENDER_KEY = String(cached.renderKey || '');
        EMAIL_REPLIES_LAST_SCOPE_KEY = String(scopeKey || '');
        EMAIL_REPLIES_CACHE_READY = true;
        return true;
    }

    function formatRepliesSyncStamp(value) {
        if (!value) return '';
        try {
            if (typeof value === 'number') {
                return window.GSS_DATE.formatDbDateTime(new Date(value).toISOString().slice(0, 19).replace('T', ' '));
            }
            return window.GSS_DATE.formatDbDateTime(String(value));
        } catch (_e) {
            return String(value || '');
        }
    }

    function setRepliesSyncMeta(meta, isBusy) {
        var el = document.getElementById('cvRepliesSyncMeta');
        if (!el) return;
        var m = meta && typeof meta === 'object' ? meta : {};
        if (isBusy) {
            el.textContent = 'Syncing replies...';
            el.style.color = '#2563eb';
            return;
        }
        if (m.sync_failed) {
            el.textContent = 'Replies are up to date with current data.';
            el.style.color = '#92400e';
            return;
        }
        if (m.sync_warning) {
            el.textContent = 'Replies are up to date with current data.';
            el.style.color = '#64748b';
            return;
        }
        var parts = [];
        if (m.used_fallback) {
            parts.push('Fallback data shown');
        } else {
            parts.push('Canonical replies');
        }
        var stamp = formatRepliesSyncStamp(m.last_synced_at || EMAIL_REPLIES_LAST_SYNC_AT || '');
        if (stamp) {
            parts.push('Last synced ' + stamp);
        }
        el.textContent = parts.join(' | ') || 'Not synced yet.';
        el.style.color = m.used_fallback ? '#92400e' : '#64748b';
    }

    function renderRepliesState(roleNow, componentKey) {
        var hostModal = document.getElementById('cvEmailReplies');
        var hostSidebar = document.getElementById('emailReplies');
        var countEl = document.getElementById('cvEmailRepliesCount');
        var rows = Array.isArray(EMAIL_REPLIES_CACHE) ? EMAIL_REPLIES_CACHE : [];
        var meta = EMAIL_REPLIES_META && typeof EMAIL_REPLIES_META === 'object' ? EMAIL_REPLIES_META : {};
        try {
            if (hostModal) hostModal.innerHTML = emailRepliesPanelHtml(rows, { role: roleNow, componentKey: componentKey, meta: meta });
            if (hostSidebar) hostSidebar.innerHTML = emailRepliesPanelHtml(rows.slice(0, 8), { role: roleNow, componentKey: componentKey, meta: meta });
            if (countEl) countEl.textContent = String(rows.length);
            setRepliesSyncMeta(meta, false);
        } catch (_e) {
            if (hostModal) hostModal.innerHTML = emailRepliesEmptyHtml({ role: roleNow, componentKey: componentKey, meta: meta });
            if (hostSidebar) hostSidebar.innerHTML = emailRepliesEmptyHtml({ role: roleNow, componentKey: componentKey, meta: meta });
            if (countEl) countEl.textContent = '0';
            setRepliesSyncMeta(meta, false);
        }
    }

    function normalizeNodeWorkflowRole(value) {
        var role = String(value || '').toLowerCase().trim();
        if (role === 'team_lead') return 'qa';
        if (role === 'db_verifier') return 'verifier';
        return role;
    }

    function parseNodeRepliesPayload(data) {
        if (!data || typeof data !== 'object') return null;
        if (data.source !== 'node' && !Object.prototype.hasOwnProperty.call(data, 'hasThread')) return null;
        return data;
    }

    function shouldFallbackToPhpReplies(nodePayload) {
        if (!nodePayload || typeof nodePayload !== 'object') return true;
        if (nodePayload.success === false) return true;
        var meta = nodePayload.meta && typeof nodePayload.meta === 'object' ? nodePayload.meta : {};
        var fallbackReason = String(meta.fallbackReason || '').toUpperCase().trim();
        if (nodePayload.hasThread === false && nodePayload.hasMessages === false && fallbackReason === 'THREAD_NOT_FOUND') {
            return true;
        }
        if (meta.fallbackRecommended === true) return true;
        return false;
    }

    function nodeRowsAreSafeForScopedReplies(rows, roleNow, componentKey) {
        if (!Array.isArray(rows) || rows.length === 0) return false;
        var wantedComponent = normSection(componentKey || '');
        var allowedMsgIds = {};
        var referenceThreadId = '';
        var referenceComponent = '';
        var outgoingForRole = 0;
        for (var i = 0; i < rows.length; i += 1) {
            var row = rows[i] || {};
            var rowRole = normalizeNodeWorkflowRole(String(row.actor_role || ''));
            var direction = String(row.direction || '').toLowerCase().trim();
            var rowComponent = normSection(String(row.component_key || ''));
            if (wantedComponent && rowComponent !== wantedComponent) {
                return false;
            }
            if (direction === 'outgoing' && rowRole === roleNow) {
                outgoingForRole += 1;
                if (!referenceThreadId) {
                    referenceThreadId = String(row.thread_id || '').toLowerCase().trim();
                }
                if (!referenceComponent && rowComponent) {
                    referenceComponent = rowComponent;
                }
                var msgId = normMsgIdLocal(row.message_id || '');
                if (msgId) {
                    allowedMsgIds[msgId] = true;
                }
            }
        }
        if ((roleNow === 'validator' || roleNow === 'verifier' || roleNow === 'qa') && outgoingForRole <= 0) {
            return !!wantedComponent && nodeRowsHaveInboundCandidate(rows);
        }
        for (var j = 0; j < rows.length; j += 1) {
            var current = rows[j] || {};
            var currentDirection = String(current.direction || '').toLowerCase().trim();
            if (currentDirection !== 'incoming') continue;
            var inReplyTo = normMsgIdLocal(current.in_reply_to || '');
            var refs = extractMsgIdsLocal(current.references_header || '');
            var hasHeaders = !!inReplyTo || refs.length > 0;
            var currentThreadId = String(current.thread_id || '').toLowerCase().trim();
            var currentComponent = normSection(String(current.component_key || ''));
            var linked = (!!inReplyTo && !!allowedMsgIds[inReplyTo]);
            if (!linked && refs.length) {
                linked = refs.some(function (id) { return !!allowedMsgIds[id]; });
            }
            if (!linked && referenceThreadId && currentThreadId === referenceThreadId) {
                linked = true;
            }
            if (!linked && !hasHeaders && referenceComponent && currentComponent === referenceComponent) {
                linked = true;
            }
            if (!linked) {
                return false;
            }
        }
        return true;
    }

    function inferNodeReplyComponentKeyFromText(value) {
        var text = String(value || '').toLowerCase();
        if (!text) return '';
        if (text.indexOf('employment_reference') !== -1
            || text.indexOf('employment reference') !== -1
            || text.indexOf('employment referee') !== -1) {
            return 'employment_reference';
        }
        if (text.indexOf('education_reference') !== -1
            || text.indexOf('education reference') !== -1
            || text.indexOf('academic reference') !== -1) {
            return 'education_reference';
        }
        if (text.indexOf('employment verification') !== -1
            || text.indexOf('employer verification') !== -1
            || text.indexOf('employment proof') !== -1) {
            return 'employment';
        }
        if (text.indexOf('education verification') !== -1
            || text.indexOf('institution verification') !== -1
            || text.indexOf('college verification') !== -1) {
            return 'education';
        }
        return '';
    }

    function nodePayloadHasMixedOutboundRoles(nodePayload) {
        var rows = Array.isArray(nodePayload && nodePayload.messages) ? nodePayload.messages : [];
        var seen = {};
        var count = 0;
        for (var i = 0; i < rows.length; i += 1) {
            var msg = rows[i] || {};
            var direction = String(msg.direction || '').toLowerCase().trim();
            if (direction !== 'outbound') continue;
            var role = normalizeNodeWorkflowRole(msg.sentByRole || '');
            if (!role || role === 'candidate') continue;
            if (!Object.prototype.hasOwnProperty.call(seen, role)) {
                seen[role] = true;
                count += 1;
                if (count > 1) return true;
            }
        }
        return false;
    }

    function nodeRowsHaveInboundCandidate(rows) {
        if (!Array.isArray(rows)) return false;
        for (var i = 0; i < rows.length; i += 1) {
            var r = rows[i] || {};
            var direction = String(r.direction || '').toLowerCase().trim();
            var actor = normalizeNodeWorkflowRole(String(r.actor_role || '').trim());
            if ((direction === 'incoming' || direction === 'inbound') && (actor === 'candidate' || actor === '')) {
                return true;
            }
        }
        return false;
    }

    function nodeMessageComponentKey(msg) {
        var meta = msg && msg.metadata && typeof msg.metadata === 'object' ? msg.metadata : {};
        var workflow = meta.workflow && typeof meta.workflow === 'object' ? meta.workflow : {};
        var tagged = normSection(
            workflow.componentKey
            || workflow.component_key
            || meta.componentKey
            || meta.component_key
            || ''
        );
        if (tagged) return tagged;
        return inferNodeReplyComponentKeyFromText([
            msg && msg.subject ? msg.subject : '',
            msg && msg.body ? msg.body : '',
            msg && msg.bodyHtml ? msg.bodyHtml : ''
        ].join('\n'));
    }

    function nodePayloadHasAnyComponentTag(nodePayload) {
        var rows = Array.isArray(nodePayload && nodePayload.messages) ? nodePayload.messages : [];
        for (var i = 0; i < rows.length; i += 1) {
            if (nodeMessageComponentKey(rows[i])) return true;
        }
        return false;
    }

    function normalizeNodeMessageDirection(msg, senderType, actorRole) {
        var raw = String(msg && msg.direction ? msg.direction : '').toLowerCase().trim();
        if (raw === 'inbound' || raw === 'incoming' || raw === 'received') return 'incoming';
        if (raw === 'outbound' || raw === 'outgoing' || raw === 'sent') return 'outgoing';
        if (senderType === 'external') return 'incoming';
        if (actorRole === 'candidate') return 'incoming';
        return 'outgoing';
    }

    function normMsgIdLocal(v) {
        var s = String(v || '').trim();
        if (!s) return '';
        if (s.charAt(0) === '<' && s.charAt(s.length - 1) === '>') {
            s = s.substring(1, s.length - 1).trim();
        }
        return s.toLowerCase();
    }

    function extractMsgIdsLocal(v) {
        var raw = String(v || '');
        if (!raw) return [];
        var out = [];
        var seen = {};
        var re = /<([^>]+)>/g;
        var m;
        while ((m = re.exec(raw)) !== null) {
            var id = normMsgIdLocal(m[1]);
            if (id && !seen[id]) {
                seen[id] = true;
                out.push(id);
            }
        }
        if (out.length > 0) return out;
        var one = normMsgIdLocal(raw);
        return one ? [one] : [];
    }

    function rowTimeMsLocal(r) {
        var t = Date.parse(String(r && r.created_at ? r.created_at : ''));
        return Number.isFinite(t) ? t : 0;
    }

    function adaptNodeReplyMessage(msg) {
        var sender = msg && msg.sender && typeof msg.sender === 'object' ? msg.sender : {};
        var rawMeta = msg && msg.metadata && typeof msg.metadata === 'object' ? msg.metadata : {};
        var rawHeaders = rawMeta.headers && typeof rawMeta.headers === 'object' ? rawMeta.headers : {};
        var rawWorkflow = rawMeta.workflow && typeof rawMeta.workflow === 'object' ? rawMeta.workflow : {};
        var senderName = String(sender.name || sender.email || 'Unknown');
        var senderType = String(sender.type || '').toLowerCase().trim();
        var actorRole = normalizeNodeWorkflowRole(msg && msg.sentByRole ? msg.sentByRole : '');
        if (!actorRole && senderType === 'external') actorRole = 'candidate';
        var direction = normalizeNodeMessageDirection(msg, senderType, actorRole);
        var msgId = normMsgIdLocal(
            msg && (
                msg.messageId
                || msg.externalMessageId
                || rawHeaders['message-id']
                || rawHeaders.messageId
                || ''
            )
        );
        var inReplyTo = normMsgIdLocal(
            msg && (
                msg.inReplyTo
                || rawHeaders['in-reply-to']
                || rawHeaders.inReplyTo
                || ''
            )
        );
        var referencesHeader = String(
            msg && (
                msg.references
                || rawHeaders.references
                || ''
            )
            || ''
        );
        return {
            id: String(msg && msg.id ? msg.id : msg && msg._id ? msg._id : ''),
            sender: senderName,
            message: String(msg && (msg.body || msg.bodyHtml || '') || ''),
            created_at: String(msg && (msg.createdAt || msg.updatedAt || '') || ''),
            direction: direction,
            actor_role: actorRole,
            communication_type: 'node_reply',
            component_key: nodeMessageComponentKey(msg) || normSection(rawWorkflow.componentKey || rawWorkflow.component_key || ''),
            thread_id: String(msg && (msg.threadId || msg.thread_id || '') || ''),
            message_id: msgId,
            in_reply_to: inReplyTo,
            references_header: referencesHeader,
            raw: msg || null
        };
    }

    function pickConversationRowsForRole(rows, roleNow) {
        if (!Array.isArray(rows) || rows.length === 0) return rows || [];
        if (!(roleNow === 'validator' || roleNow === 'verifier' || roleNow === 'qa')) return rows;

        var requestRows = rows.filter(isRequestActionReplyRow);
        var conversationRows = rows.filter(function (row) { return !isRequestActionReplyRow(row); });

        var outgoing = conversationRows.filter(function (r) {
            var dir = String(r && r.direction ? r.direction : '').toLowerCase().trim();
            var role = normalizeNodeWorkflowRole(String(r && r.actor_role ? r.actor_role : ''));
            return dir === 'outgoing' && role === roleNow;
        });
        if (outgoing.length === 0) return rows;

        outgoing.sort(function (a, b) { return rowTimeMsLocal(b) - rowTimeMsLocal(a); });
        var anchor = outgoing[0];
        var anchorIds = {};
        var anchorMsgId = normMsgIdLocal(anchor && anchor.message_id ? anchor.message_id : '');
        var anchorThreadId = String(anchor && anchor.thread_id ? anchor.thread_id : '').toLowerCase().trim();
        var anchorComponent = normSection(anchor && anchor.component_key ? anchor.component_key : '');
        if (anchorMsgId) anchorIds[anchorMsgId] = true;
        extractMsgIdsLocal(anchor && anchor.references_header ? anchor.references_header : '').forEach(function (id) {
            anchorIds[id] = true;
        });

        var selected = [];
        selected.push(anchor);

        conversationRows.forEach(function (r) {
            if (!r || r.id === anchor.id) return;
            var dir = String(r.direction || '').toLowerCase().trim();
            if (dir !== 'incoming') return;
            var inReplyTo = normMsgIdLocal(r.in_reply_to || '');
            var refs = extractMsgIdsLocal(r.references_header || '');
            var hasHeaders = !!inReplyTo || refs.length > 0;
            var rowThreadId = String(r.thread_id || '').toLowerCase().trim();
            var rowComponent = normSection(r.component_key || '');
            var linked = (inReplyTo && anchorIds[inReplyTo]) || refs.some(function (id) { return !!anchorIds[id]; });
            if (!linked && anchorThreadId && rowThreadId === anchorThreadId) linked = true;
            if (!linked && !hasHeaders && anchorComponent && rowComponent === anchorComponent) linked = true;
            if (linked) selected.push(r);
        });

        selected.sort(function (a, b) { return rowTimeMsLocal(a) - rowTimeMsLocal(b); });
        return requestRows.concat(selected);
    }

    function adaptNodeReplies(nodePayload, componentKey) {
        var out = [];
        var rows = Array.isArray(nodePayload && nodePayload.messages) ? nodePayload.messages : [];
        var wantedComponent = normSection(componentKey || '');
        var hasTaggedRows = false;
        for (var i = 0; i < rows.length; i += 1) {
            var rowComponent = nodeMessageComponentKey(rows[i]);
            if (rowComponent) {
                hasTaggedRows = true;
            }
        }
        for (var j = 0; j < rows.length; j += 1) {
            var item = rows[j];
            var itemComponent = nodeMessageComponentKey(item);
            if (wantedComponent && hasTaggedRows && itemComponent && itemComponent !== wantedComponent) {
                continue;
            }
            out.push(adaptNodeReplyMessage(item));
        }
        return out;
    }

    function replyComponentAliases(componentKey) {
        var key = normSection(componentKey || '');
        var aliases = {};
        if (!key) return aliases;
        aliases[key] = true;
        if (key === 'reference') {
            aliases.education_reference = true;
            aliases.employment_reference = true;
        }
        return aliases;
    }

    function filterRepliesForComponent(rows, componentKey) {
        if (!Array.isArray(rows)) return [];
        var key = normSection(componentKey || '');
        if (!key) return rows;
        var aliases = replyComponentAliases(key);
        return rows.filter(function (row) {
            var rowKey = normSection(row && row.component_key ? row.component_key : '');
            if (rowKey && aliases[rowKey]) return true;
            var inferred = inferNodeReplyComponentKeyFromText([
                row && row.subject ? row.subject : '',
                row && row.message ? row.message : '',
                row && row.body ? row.body : '',
                row && row.bodyHtml ? row.bodyHtml : ''
            ].join('\n'));
            return !!inferred && !!aliases[inferred];
        });
    }

    function setRepliesSyncButtonState(isBusy) {
        var btn = document.getElementById('cvRepliesSyncBtn');
        if (!btn) return;
        if (!document.getElementById('cvRepliesSpinStyle')) {
            var style = document.createElement('style');
            style.id = 'cvRepliesSpinStyle';
            style.textContent = '@keyframes cvRepliesSpin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}#cvRepliesSyncBtn.cv-replies-syncing span{animation:cvRepliesSpin 0.85s linear infinite;transform-origin:center center;}';
            document.head.appendChild(style);
        }
        btn.disabled = !!isBusy;
        btn.style.opacity = isBusy ? '0.65' : '1';
        btn.style.cursor = isBusy ? 'wait' : 'pointer';
        btn.classList.toggle('cv-replies-syncing', !!isBusy);
        btn.innerHTML = isBusy
            ? '<span aria-hidden="true" style="font-size:15px; line-height:1; display:inline-block; animation:cvRepliesSpin 0.85s linear infinite; transform-origin:center center;">&#8635;</span>'
            : '<span aria-hidden="true" style="font-size:16px; line-height:1; font-weight:700;">&#8635;</span>';
        setRepliesSyncMeta(EMAIL_REPLIES_META, !!isBusy);
    }

    function bindRepliesSyncButton() {
        setRepliesSyncButtonState(false);
        function handleRefreshClick(event) {
            var btn = event && event.target && event.target.closest ? event.target.closest('#cvRepliesSyncBtn') : document.getElementById('cvRepliesSyncBtn');
            if (!btn) return;
            event.preventDefault();
            event.stopPropagation();
            if (btn.disabled) return;
            var applicationId = currentReportApplicationId();
            if (!applicationId) {
                EMAIL_REPLIES_META = { sync_warning: true };
                setRepliesSyncMeta(EMAIL_REPLIES_META, false);
                return;
            }
            EMAIL_REPLIES_INFLIGHT = null;
            EMAIL_REPLIES_INFLIGHT_KEY = '';
            var roleNow = getReplyViewerRole();
            var scopeKey = repliesScopeKey(applicationId, currentRepliesScopeComponent(), roleNow);
            delete EMAIL_REPLIES_SCOPE_CACHE[scopeKey];
            if (scopeKey === EMAIL_REPLIES_LAST_SCOPE_KEY) {
                EMAIL_REPLIES_CACHE_READY = false;
            }
            EMAIL_REPLIES_FORCE_SYNC_ONCE = true;
            setRepliesSyncButtonState(true);
            loadEmailReplies(applicationId, {
                componentKey: currentRepliesScopeComponent(),
                sync: true
            }).catch(function () {
            }).finally(function () {
                setRepliesSyncButtonState(false);
            });
        }
        var directBtn = document.getElementById('cvRepliesSyncBtn');
        if (directBtn && directBtn.dataset.repliesSyncBound !== '1') {
            directBtn.dataset.repliesSyncBound = '1';
            directBtn.addEventListener('click', handleRefreshClick);
        }
        if (document.body.dataset.repliesSyncDelegated === '1') return;
        document.body.dataset.repliesSyncDelegated = '1';
        document.addEventListener('click', handleRefreshClick, true);
    }

    function bindRepliesAutoRefresh() {
        if (EMAIL_REPLIES_AUTO_REFRESH_TIMER) return;
        EMAIL_REPLIES_AUTO_REFRESH_TIMER = setInterval(function () {
            try {
                if (document.hidden) return;
                var applicationId = currentReportApplicationId();
                if (!applicationId) return;
                if (EMAIL_REPLIES_INFLIGHT) return;
                var roleNow = getReplyViewerRole();
                var componentKey = currentRepliesScopeComponent();
                var now = Date.now();
                var scopeKey = repliesScopeKey(applicationId, componentKey, roleNow);
                var sameScope = scopeKey === EMAIL_REPLIES_LAST_SCOPE_KEY;
                var stale = !EMAIL_REPLIES_LAST_SYNC_AT || (now - EMAIL_REPLIES_LAST_SYNC_AT) >= EMAIL_REPLIES_STALE_SYNC_MS;
                var shouldHeavySync = EMAIL_REPLIES_FORCE_SYNC_ONCE
                    || !sameScope
                    || stale
                    || !!(EMAIL_REPLIES_META && (EMAIL_REPLIES_META.viewer_thread_exists || EMAIL_REPLIES_META.scope_has_thread) && !(Array.isArray(EMAIL_REPLIES_CACHE) && EMAIL_REPLIES_CACHE.length));
                loadEmailReplies(applicationId, {
                    componentKey: componentKey,
                    sync: shouldHeavySync
                }).catch(function () {});
            } catch (_e) {}
        }, Math.max(5000, parseInt(String(EMAIL_REPLIES_AUTO_REFRESH_MS || 15000), 10) || 15000));
    }

    async function loadEmailReplies(applicationId, opts) {
        opts = opts || {};
        var hostModal = document.getElementById('cvEmailReplies');
        var hostSidebar = document.getElementById('emailReplies');
        var countEl = document.getElementById('cvEmailRepliesCount');
        if (!hostModal && !hostSidebar) return;
        var componentKey = normSection(opts.componentKey || currentRepliesScopeComponent() || '');
        var roleNow = getReplyViewerRole();
        var shouldSync = !!opts.sync;
        var scopeKey = repliesScopeKey(applicationId, componentKey, roleNow);
        var sameScope = scopeKey === EMAIL_REPLIES_LAST_SCOPE_KEY;
        var requestKey = emailRepliesRequestKey(applicationId, componentKey, shouldSync, roleNow);
        if (!sameScope && applyRepliesScopeCache(scopeKey)) {
            sameScope = true;
        }

        function renderTarget(el, html) {
            if (el) el.innerHTML = html;
        }

        function parsePhpPayload(data) {
            if (!data || typeof data !== 'object' || data.status !== 1 || !Array.isArray(data.data)) {
                return null;
            }
            return {
                rows: data.data,
                meta: data.debug && typeof data.debug === 'object' ? data.debug : {}
            };
        }

        async function fetchJson(url, timeoutMs) {
            var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var tm = null;
            if (ctrl && timeoutMs > 0) {
                tm = setTimeout(function () {
                    try { ctrl.abort(); } catch (_e0) {}
                }, timeoutMs);
            }
            try {
                var response = await fetch(url, {
                    credentials: 'same-origin',
                    signal: ctrl ? ctrl.signal : undefined
                });
                var data = await response.json().catch(function () { return null; });
                return {
                    response: response,
                    data: data,
                    error: null,
                    timedOut: false
                };
            } catch (e) {
                return {
                    response: null,
                    data: null,
                    error: e,
                    timedOut: !!(e && e.name === 'AbortError')
                };
            } finally {
                if (tm) clearTimeout(tm);
            }
        }

        async function fetchNodeReplies(baseUrl, forceCaseScope) {
            var nodeUrl = baseUrl + '/api/shared/node_replies_proxy.php?application_id=' + encodeURIComponent(applicationId);
            if (componentKey && !forceCaseScope) {
                nodeUrl += '&component_key=' + encodeURIComponent(componentKey);
            }
            if (shouldSync) {
                nodeUrl += (nodeUrl.indexOf('?') >= 0 ? '&' : '?') + 'sync=1&_ts=' + String(Date.now());
            }
            var out = await fetchJson(nodeUrl, shouldSync ? 12000 : 8000);
            var response = out.response;
            var nodeData = out.data;
            if (out.error) {
                return {
                    ok: false,
                    payload: null,
                    rows: [],
                    useFallback: true,
                    error: out.error,
                    timedOut: out.timedOut
                };
            }
            if (!response.ok) {
                return {
                    ok: false,
                    payload: parseNodeRepliesPayload(nodeData),
                    rows: [],
                    useFallback: true
                };
            }
            var payload = parseNodeRepliesPayload(nodeData);
            if (!payload) {
                return {
                    ok: false,
                    payload: null,
                    rows: [],
                    useFallback: true
                };
            }
            var useFallback = shouldFallbackToPhpReplies(payload);
            var roleNow = getReplyViewerRole();
            var adaptedRows = adaptNodeReplies(payload, forceCaseScope ? '' : componentKey);
            adaptedRows = pickConversationRowsForRole(adaptedRows, roleNow);
            if (payload.hasMessages === true && Array.isArray(payload.messages) && payload.messages.length > 0 && (!Array.isArray(adaptedRows) || adaptedRows.length === 0)) {
                var rawRows = payload.messages.map(function (msg) { return adaptNodeReplyMessage(msg); });
                var scopedRows = (componentKey && !forceCaseScope) ? filterRepliesForComponent(rawRows, componentKey) : rawRows;
                adaptedRows = pickConversationRowsForRole(scopedRows, roleNow);
            }
            if (!useFallback && componentKey && (roleNow === 'validator' || roleNow === 'verifier' || roleNow === 'qa')) {
                if (nodePayloadHasMixedOutboundRoles(payload) || !nodePayloadHasAnyComponentTag(payload) || !nodeRowsAreSafeForScopedReplies(adaptedRows, roleNow, componentKey)) {
                    useFallback = true;
                }
            }
            if (payload.hasMessages === true && Array.isArray(adaptedRows) && adaptedRows.length > 0) {
                useFallback = false;
            }
            return {
                ok: payload.success !== false,
                payload: payload,
                rows: adaptedRows,
                useFallback: useFallback
            };
        }

        async function fetchPhpReplies(baseUrl, forceCaseScope) {
            var phpSync = shouldSync;
            var caseUrl = baseUrl + '/api/get_replies.php?application_id=' + encodeURIComponent(applicationId) + '&scope=case&sync=' + (phpSync ? '1' : '0');
            if (componentKey && !forceCaseScope) {
                caseUrl = baseUrl + '/api/get_replies.php?application_id=' + encodeURIComponent(applicationId)
                    + '&scope=component&component_key=' + encodeURIComponent(componentKey) + '&sync=' + (phpSync ? '1' : '0');
            }
            if (shouldSync) {
                caseUrl += '&_ts=' + String(Date.now());
            }
            var out = await fetchJson(caseUrl, shouldSync ? 60000 : 15000);
            if (out.error) return null;
            return parsePhpPayload(out.data);
        }

        async function fetchPhpRequestActionRows(baseUrl) {
            var phpPayload = await fetchPhpReplies(baseUrl);
            var rows = phpPayload && Array.isArray(phpPayload.rows) ? phpPayload.rows : [];
            if (componentKey && rows.filter(isRequestActionReplyRow).length === 0) {
                var casePayload = await fetchPhpReplies(baseUrl, true);
                var caseRows = casePayload && Array.isArray(casePayload.rows)
                    ? filterRepliesForComponent(casePayload.rows, componentKey)
                    : [];
                if (caseRows.length > 0) rows = caseRows;
            }
            return rows.filter(isRequestActionReplyRow);
        }

        if (!applicationId) {
            EMAIL_REPLIES_CACHE = [];
            EMAIL_REPLIES_META = { sync_failed: false };
            EMAIL_REPLIES_LAST_SYNC_AT = 0;
            EMAIL_REPLIES_LAST_SYNC_APP_ID = '';
            EMAIL_REPLIES_LAST_SCOPE_KEY = '';
            EMAIL_REPLIES_CACHE_READY = false;
            renderTarget(hostModal, '<div style="color:#6b7280; font-size:13px;">application_id not found.</div>');
            renderTarget(hostSidebar, '<div style="color:#6b7280; font-size:13px;">application_id not found.</div>');
            if (countEl) countEl.textContent = '0';
            return;
        }

        if (!shouldSync && sameScope && EMAIL_REPLIES_CACHE_READY && EMAIL_REPLIES_LAST_RENDER_KEY === requestKey) {
            renderRepliesState(roleNow, componentKey);
            return;
        }

        if (EMAIL_REPLIES_INFLIGHT && EMAIL_REPLIES_INFLIGHT_KEY === requestKey) {
            return EMAIL_REPLIES_INFLIGHT;
        }

        if (!shouldSync && !EMAIL_REPLIES_CACHE_READY) {
            renderTarget(hostModal, '<div style="color:#6b7280; font-size:13px;">Loading email replies...</div>');
            renderTarget(hostSidebar, '<div style="color:#6b7280; font-size:13px;">Loading email replies...</div>');
        } else {
            var steadyRows = sameScope && Array.isArray(EMAIL_REPLIES_CACHE) ? EMAIL_REPLIES_CACHE : [];
            var steadyMeta = sameScope && EMAIL_REPLIES_META && typeof EMAIL_REPLIES_META === 'object'
                ? EMAIL_REPLIES_META
                : { sync_failed: false };
            renderTarget(hostModal, emailRepliesPanelHtml(steadyRows, { role: roleNow, componentKey: componentKey, meta: steadyMeta }));
            renderTarget(hostSidebar, emailRepliesPanelHtml(steadyRows.slice(0, 8), { role: roleNow, componentKey: componentKey, meta: steadyMeta }));
            if (countEl) countEl.textContent = String(steadyRows.length);
        }
        setRepliesSyncButtonState(true);
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

        EMAIL_REPLIES_INFLIGHT_KEY = requestKey;
        EMAIL_REPLIES_INFLIGHT = (async function () {
            try {
                var nodeAttempt = await fetchNodeReplies(base);
                var finalRows = nodeAttempt.rows;
                var finalMeta = {
                    resolved_source: 'node_proxy',
                    resolved_component_key: componentKey,
                    resolved_thread_owner_role: roleNow,
                    used_fallback: false,
                    no_thread: !!(nodeAttempt.payload && nodeAttempt.payload.hasThread === false && nodeAttempt.payload.hasMessages === false),
                    sync_mode: shouldSync ? 'canonical_sync' : 'read_only_refresh',
                    last_synced_at: new Date().toISOString().slice(0, 19).replace('T', ' ')
                };
                var reviewerRole = (roleNow === 'validator' || roleNow === 'verifier' || roleNow === 'qa');
                if (!nodeAttempt.useFallback && reviewerRole && componentKey && (!Array.isArray(finalRows) || finalRows.length === 0)) {
                    var caseNodeAttempt = await fetchNodeReplies(base, true);
                    var caseNodeRows = Array.isArray(caseNodeAttempt.rows)
                        ? filterRepliesForComponent(caseNodeAttempt.rows, componentKey)
                        : [];
                    if (caseNodeRows.length > 0) {
                        nodeAttempt = Object.assign({}, caseNodeAttempt, {
                            rows: caseNodeRows,
                            useFallback: false
                        });
                        finalRows = caseNodeRows;
                        finalMeta = Object.assign({}, finalMeta, {
                            scope_relaxed: true,
                            resolved_component_key: componentKey
                        });
                    } else {
                        nodeAttempt.useFallback = true;
                    }
                }

                if (!nodeAttempt.useFallback) {
                    var phpRequestRows = await fetchPhpRequestActionRows(base);
                    finalRows = mergeReplyRows(finalRows, phpRequestRows);
                }

                if (nodeAttempt.useFallback) {
                    var phpPayload = await fetchPhpReplies(base);
                    if (phpPayload && componentKey && Array.isArray(phpPayload.rows) && phpPayload.rows.length === 0) {
                        var casePayload = await fetchPhpReplies(base, true);
                        var scopedCaseRows = casePayload && Array.isArray(casePayload.rows)
                            ? filterRepliesForComponent(casePayload.rows, componentKey)
                            : [];
                        if (scopedCaseRows.length > 0) {
                            phpPayload = {
                                rows: scopedCaseRows,
                                meta: Object.assign({}, casePayload.meta || {}, {
                                    scope_relaxed: true,
                                    resolved_component_key: componentKey
                                })
                            };
                        }
                    }
                    if (!phpPayload) {
                        finalRows = [];
                        finalMeta = {
                            sync_failed: false,
                            sync_warning: true,
                            resolved_source: 'unavailable',
                            used_fallback: true,
                            sync_mode: shouldSync ? 'canonical_sync' : 'read_only_refresh',
                            last_synced_at: EMAIL_REPLIES_LAST_SYNC_AT
                                ? new Date(EMAIL_REPLIES_LAST_SYNC_AT).toISOString().slice(0, 19).replace('T', ' ')
                                : ''
                        };
                        if (EMAIL_REPLIES_CACHE_READY && sameScope && Array.isArray(EMAIL_REPLIES_CACHE)) {
                            finalRows = EMAIL_REPLIES_CACHE;
                        }
                    } else {
                        finalRows = phpPayload.rows;
                        finalMeta = Object.assign({}, phpPayload.meta || {}, {
                            resolved_source: (phpPayload.meta && phpPayload.meta.resolved_source) || 'canonical',
                            used_fallback: !!(phpPayload.meta && phpPayload.meta.used_fallback),
                            sync_mode: (phpPayload.meta && phpPayload.meta.sync_mode) || (shouldSync ? 'canonical_sync' : 'read_only_refresh'),
                            last_synced_at: (phpPayload.meta && phpPayload.meta.last_synced_at) || new Date().toISOString().slice(0, 19).replace('T', ' ')
                        });
                    }
                    if (!phpPayload && !EMAIL_REPLIES_CACHE_READY) {
                        EMAIL_REPLIES_CACHE = finalRows;
                        EMAIL_REPLIES_META = finalMeta;
                        EMAIL_REPLIES_LAST_RENDER_KEY = requestKey;
                        EMAIL_REPLIES_LAST_SCOPE_KEY = scopeKey;
                        EMAIL_REPLIES_CACHE_READY = true;
                        setRepliesScopeCache(scopeKey, finalRows, finalMeta, requestKey);
                        renderRepliesState(roleNow, componentKey);
                        return;
                    }
                }

                EMAIL_REPLIES_CACHE = finalRows;
                EMAIL_REPLIES_META = finalMeta || {};
                EMAIL_REPLIES_LAST_RENDER_KEY = requestKey;
                EMAIL_REPLIES_LAST_SCOPE_KEY = scopeKey;
                EMAIL_REPLIES_CACHE_READY = true;
                setRepliesScopeCache(scopeKey, finalRows, finalMeta, requestKey);
                if (shouldSync) {
                    EMAIL_REPLIES_LAST_SYNC_AT = Date.now();
                    EMAIL_REPLIES_LAST_SYNC_APP_ID = String(applicationId || '');
                }
                EMAIL_REPLIES_FORCE_SYNC_ONCE = false;
                renderRepliesState(roleNow, componentKey);
            } catch (_e) {
                EMAIL_REPLIES_CACHE = [];
                EMAIL_REPLIES_META = {
                    sync_failed: false,
                    sync_warning: true,
                    sync_mode: shouldSync ? 'canonical_sync' : 'read_only_refresh'
                };
                renderTarget(hostModal, emailRepliesPanelHtml([], { role: roleNow, componentKey: componentKey, meta: EMAIL_REPLIES_META }));
                renderTarget(hostSidebar, emailRepliesPanelHtml([], { role: roleNow, componentKey: componentKey, meta: EMAIL_REPLIES_META }));
                if (countEl) countEl.textContent = '0';
                setRepliesSyncMeta(EMAIL_REPLIES_META, false);
            } finally {
                setRepliesSyncButtonState(false);
                try {
                    var fallbackRows = Array.isArray(EMAIL_REPLIES_CACHE) ? EMAIL_REPLIES_CACHE : [];
                    var fallbackMeta = EMAIL_REPLIES_META && typeof EMAIL_REPLIES_META === 'object' ? EMAIL_REPLIES_META : {};
                    if (hostModal) hostModal.innerHTML = emailRepliesPanelHtml(fallbackRows, { role: roleNow, componentKey: componentKey, meta: fallbackMeta });
                    if (hostSidebar) hostSidebar.innerHTML = emailRepliesPanelHtml(fallbackRows.slice(0, 8), { role: roleNow, componentKey: componentKey, meta: fallbackMeta });
                    if (countEl) countEl.textContent = String(fallbackRows.length);
                    setRepliesSyncMeta(fallbackMeta, false);
                } catch (_e2) {}
                if (EMAIL_REPLIES_INFLIGHT_KEY === requestKey) {
                    EMAIL_REPLIES_INFLIGHT = null;
                    EMAIL_REPLIES_INFLIGHT_KEY = '';
                }
            }
        })();
        return EMAIL_REPLIES_INFLIGHT;
    }

    async function loadTimeline(applicationId, opts) {
        opts = opts || {};
        var doSync = !!opts.sync;
        try {
            var c = REPORT_PAYLOAD && REPORT_PAYLOAD.case ? REPORT_PAYLOAD.case : {};
            loadCorrectionHistory(applicationId || String(c.application_id || ''), parseInt(String(c.case_id || '0'), 10) || 0);
        } catch (_e0) {}
        var inWorkspace = !!document.querySelector('.cr-report-root.cr-validator-workspace');
        var host = inWorkspace
            ? (document.getElementById('cvValidatorTimeline') || document.getElementById('cvTimeline'))
            : (document.getElementById('cvTimeline') || document.getElementById('cvValidatorTimeline'));
        if (!host) return;

        initMiniTimelineFilters();

        var remarksHost = document.getElementById('cvRemarksPanel');
        if (remarksHost) {
            remarksHost.innerHTML = '';
        }
        if (!applicationId) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">application_id not found.</div>';
            var mini = document.getElementById('cvMiniTimeline');
            if (mini) mini.innerHTML = '<div style="color:#6b7280; font-size:13px;">application_id not found.</div>';
            return;
        }

        host.innerHTML = '<div style="color:#6b7280; font-size:13px;">Loading timeline...</div>';

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/case_timeline_list.php?application_id=' + encodeURIComponent(applicationId);

        try {
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            if (!res.ok || !data || data.status !== 1) {
                host.innerHTML = '<div style="color:#b91c1c; font-size:13px;">Failed to load timeline.</div>';
                var mini2 = document.getElementById('cvMiniTimeline');
                if (mini2) mini2.innerHTML = '<div style="color:#b91c1c; font-size:13px;">Failed to load timeline.</div>';
                return;
            }

            var itemsRaw = Array.isArray(data.data) ? data.data : [];
            var items = itemsRaw.map(function (it) {
                var row = (it && typeof it === 'object') ? it : {};
                var actor = (row.actor && typeof row.actor === 'object') ? row.actor : {};
                return Object.assign({}, row, {
                    timeline_id: row.timeline_id || row.timelineId || null,
                    application_id: row.application_id || row.applicationId || '',
                    event_type: row.event_type || row.eventType || '',
                    section_key: row.section_key || row.sectionKey || row.componentKey || row.section || '',
                    created_at: row.created_at || row.eventTimestamp || '',
                    actor_user_id: row.actor_user_id || actor.userId || null,
                    actor_role: row.actor_role || actor.role || '',
                    username: row.username || actor.username || '',
                    first_name: row.first_name || '',
                    last_name: row.last_name || ''
                });
            });
            TL_CACHE = items.filter(function (it) { return !isWholeCaseCompletionItem(it); });
            host.innerHTML = timelineHtml(TL_CACHE);
            renderMiniTimeline();

            try {
                var activeBtn = document.querySelector('.list-group-item[data-section].active');
                var activeSec = activeBtn ? (activeBtn.getAttribute('data-section') || '') : '';
                renderRemarksPanel(activeSec);
                if (activeSec && !document.querySelector('.cr-report-root.cr-validator-workspace')) {
                    var activePanel = document.getElementById('section-' + String(activeSec).toLowerCase());
                    if (activePanel) ensureComponentChat(activePanel, activeSec);
                }
                updateValidatorWorkspace(activeSec);
            } catch (_e) {
            }

            var badge = document.getElementById('cvNavBadgeTimeline');
            if (badge) {
                badge.className = 'badge bg-secondary';
                badge.textContent = String(Array.isArray(TL_CACHE) ? TL_CACHE.length : 0);
            }
        } catch (_e) {
            host.innerHTML = '<div style="color:#b91c1c; font-size:13px;">Network error loading timeline.</div>';
            var mini3 = document.getElementById('cvMiniTimeline');
            if (mini3) mini3.innerHTML = '<div style="color:#b91c1c; font-size:13px;">Network error loading timeline.</div>';
        }
    }

    function isRemarkItem(it) {
        var type = it && it.event_type ? String(it.event_type).toLowerCase().trim() : '';
        var sec = remarkSectionKey(it);
        if (!sec) return false;
        if (type && type !== 'comment' && type !== 'update' && type !== 'action') return false;
        var msg = it && it.message ? String(it.message).trim() : '';
        if (!msg) return false;
        if (type === 'comment') return true;
        var low = msg.toLowerCase();
        if (low.indexOf('component status:') !== -1) return true;
        if (low.indexOf('component action:') !== -1) return true;
        return false;
    }

    function remarkSectionKey(it) {
        var sec = it && (it.section_key || it.section) ? String(it.section_key || it.section) : '';
        sec = normSection(sec);
        if (!sec || sec === 'remarks' || sec === 'case_status') return '';
        return sec;
    }

    function chatItemsForSection(section) {
        section = normSection(section);
        var list = Array.isArray(TL_CACHE) ? TL_CACHE.filter(isRemarkItem) : [];
        return list.filter(function (it) {
            return remarkSectionKey(it) === section;
        });
    }

    function remarksChatHtml(section) {
        var items = chatItemsForSection(section);
        if (!items.length) {
            return '<div style="color:#6b7280; font-size:12px; padding:10px;">No remarks yet.</div>';
        }
        return items.slice(-50).map(function (it) {
            var actorName = ((it.first_name || '') + ' ' + (it.last_name || '')).trim();
            var actorUser = (it.username || '') ? String(it.username) : '';
            var role2 = it.actor_role || '';
            var actor = actorName || actorUser || (role2 ? String(role2).toUpperCase() : '') || 'System';
            var ts = '';
            try {
                ts = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
            } catch (_e) {
                ts = it.created_at ? String(it.created_at) : '';
            }

            return '' +
                '<div style="margin-bottom:10px;">' +
                    '<div style="font-size:11px; color:#64748b; display:flex; justify-content:space-between; gap:10px;">' +
                        '<span style="font-weight:900; color:#0f172a;">' + esc(actor) + '</span>' +
                        '<span>' + esc(ts) + '</span>' +
                    '</div>' +
                    '<div style="margin-top:4px; background:rgba(59,130,246,0.06); border:1px solid rgba(148,163,184,0.18); border-radius:10px; padding:8px 10px; font-size:12px; color:#0f172a; white-space:pre-wrap;">' +
                        esc(it.message || '') +
                    '</div>' +
                '</div>';
        }).join('');
    }

    function renderValidatorRemarksPanel(section) {
        var host = document.getElementById('cvValidatorRemarksPanel');
        if (!host) return;

        var items = chatItemsForSection(section);
        if (!items.length) {
            host.innerHTML = 'No remarks added for this section yet.';
            return;
        }

        host.innerHTML = items.slice(-12).reverse().map(function (it) {
            var actorName = ((it.first_name || '') + ' ' + (it.last_name || '')).trim();
            var actorUser = (it.username || '') ? String(it.username) : '';
            var role2 = it.actor_role || '';
            var actor = actorName || actorUser || (role2 ? String(role2).toUpperCase() : '') || 'System';
            var ts = '';
            try {
                ts = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
            } catch (_e) {
                ts = it.created_at ? String(it.created_at) : '';
            }

            return '' +
                '<div class="cr-validator-remark">' +
                    '<div class="cr-validator-remark-meta"><span>' + esc(actor) + '</span><span>' + esc(ts) + '</span></div>' +
                    '<div class="cr-validator-remark-text">' + esc(it.message || '') + '</div>' +
                '</div>';
        }).join('');
    }

    function renderValidatorTimelinePanel(section) {
        var host = document.getElementById('cvValidatorTimeline');
        if (!host) return;
        host.innerHTML = timelineHtml((Array.isArray(TL_CACHE) ? TL_CACHE : []).slice(-12).reverse());
    }

    function isAuditLikeItem(it) {
        if (!it || typeof it !== 'object') return false;
        var type = it && it.event_type ? String(it.event_type).toLowerCase().trim() : '';
        if (!type) return false;
        if (type === 'comment') return false;
        if (type === 'audit') return true;
        if (String(it.isGovernanceEvent || '0') === '1') return true;
        return type === 'create' || type === 'update' || type === 'action'
            || type.indexOf('workflow.') === 0
            || type === 'candidate_correction'
            || type === 'reply';
    }

    function auditTrailHtml(items) {
        if (!Array.isArray(items) || items.length === 0) {
            return '<div style="color:#6b7280; font-size:13px;">No audit trail yet.</div>';
        }

        return items.slice(0, 40).map(function (it) {
            var actorName = ((it.first_name || '') + ' ' + (it.last_name || '')).trim();
            var actorUser = (it.username || '') ? String(it.username) : '';
            var role2 = it.actor_role || '';
            var actor = actorName || actorUser || (role2 ? String(role2).toUpperCase() : '') || 'System';
            var when = '';
            try {
                when = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
            } catch (_e) {
                when = it.created_at ? String(it.created_at) : '';
            }

            var title = String(it.displayTitle || '').trim();
            if (!title) {
                var rawType = String(it.event_type || '').trim();
                title = rawType ? rawType.replace(/[._]/g, ' ').replace(/\b\w/g, function (m) { return m.toUpperCase(); }) : 'Audit Event';
            }

            var summary = String(it.displaySummary || it.message || '').trim();
            var section = String(it.section_key || it.section || '').trim();
            var lineage = '';
            if (it.governance && typeof it.governance === 'object' && it.governance.lineage) {
                lineage = String(it.governance.lineage || '').trim();
            }

            return ''
                + '<div class="cr-validator-remark">'
                +     '<div class="cr-validator-remark-meta"><span>' + esc(actor) + ' - ' + esc(title) + '</span><span>' + esc(when) + '</span></div>'
                +     (summary ? ('<div class="cr-validator-remark-text">' + esc(summary) + '</div>') : '')
                +     ((section || lineage)
                    ? ('<div class="cr-validator-remark-text" style="font-size:11px; color:#64748b;">'
                        + (section ? ('Section: ' + esc(sectionLabel(section))) : '')
                        + ((section && lineage) ? ' | ' : '')
                        + (lineage ? esc(lineage) : '')
                        + '</div>')
                    : '')
                + '</div>';
        }).join('');
    }

    function renderValidatorAuditPanel() {
        var host = document.getElementById('cvValidatorAuditTrail');
        if (!host) return;
        var items = (Array.isArray(TL_CACHE) ? TL_CACHE : []).filter(isAuditLikeItem);
        host.innerHTML = auditTrailHtml(items);
    }

    function reviewedSectionCount(payload) {
        var assigned = getAssignedComponentKeys(payload || {});
        if (!assigned.length) return { reviewed: 0, total: 0 };

        var reviewed = 0;
        assigned.forEach(function (key) {
            var label = String(getWorkflowStageLabel(payload || {}, key) || '').toLowerCase().trim();
            if (label && label !== 'pending candidate') {
                reviewed++;
            }
        });

        return { reviewed: reviewed, total: assigned.length };
    }

    function setValidatorWorkspaceSummary(payload) {
        var d = payload || REPORT_PAYLOAD || {};
        var cs = d.case_summary || {};
        var app = d.application || {};
        var clientEl = document.getElementById('cvHeaderClient');
        var reviewedEl = document.getElementById('cvHeaderReviewed');
        if (clientEl) {
            clientEl.textContent = String(cs.client_name || cs.customer_name || app.client_name || app.customer_name || '-');
        }
        if (reviewedEl) {
            var counts = reviewedSectionCount(d);
            reviewedEl.textContent = counts.total ? (counts.reviewed + ' / ' + counts.total + ' sections') : '0 / 0 sections';
        }
    }

    function updateValidatorWorkspace(section) {
        var role = getRole();
        if (!(role === 'validator' || role === 'verifier' || role === 'qa' || role === 'team_lead')) return;

        section = normSection(section || CURRENT_SECTION_KEY);
        renderValidatorRemarksPanel(section);
        renderValidatorTimelinePanel(section);
        renderValidatorAuditPanel();
        setValidatorWorkspaceSummary(REPORT_PAYLOAD || {});
        try {
            var panel = document.getElementById('section-' + section);
            if (panel) {
                    var isReadonly = (section && !isActionableComponent(section));
                panel.setAttribute('data-readonly', isReadonly ? '1' : '0');
                var secbar = panel.querySelector('.cr-secbar');
                if (secbar) {
                    var badge = secbar.querySelector('.cr-readonly-badge');
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'cr-readonly-badge';
                        badge.style.cssText = 'margin-left:8px;padding:3px 8px;border-radius:999px;border:1px solid rgba(148,163,184,.35);font-size:11px;color:#64748b;background:#f8fafc;font-weight:700;display:none;';
                        badge.textContent = 'Readonly';
                        secbar.appendChild(badge);
                    }
                    badge.style.display = isReadonly ? '' : 'none';
                }
                panel.querySelectorAll('.cr-sec-action[data-proxy-action]').forEach(function (el) {
                    el.style.display = isReadonly ? 'none' : '';
                    el.disabled = !!isReadonly;
                });
                panel.querySelectorAll('[data-comp-upload], #cvUploadBtn, #cvOpenCorrectionModal').forEach(function (el) {
                    el.disabled = !!isReadonly;
                });
            }
        } catch (_e) {
        }
    }

    function initValidatorWorkspace() {
        var role = getRole();
        if (!(role === 'validator' || role === 'verifier' || role === 'qa' || role === 'team_lead')) return;

        var saveBtn = document.getElementById('cvValidatorRemarkSave');
        if (saveBtn && !saveBtn.dataset.bound) {
            saveBtn.dataset.bound = '1';
            saveBtn.addEventListener('click', function () {
                if (!CURRENT_APP_ID) return;

                var section = normSection(CURRENT_SECTION_KEY);
                var ta = document.getElementById('cvValidatorRemarkText');
                var msg = ta ? String(ta.value || '').trim() : '';
                if (!section) {
                    setBoxMessage('cvTopMessage', 'Select a section first.', 'danger');
                    return;
                }
                if (!msg) {
                    setBoxMessage('cvTopMessage', 'Remark is required.', 'danger');
                    return;
                }

                saveBtn.disabled = true;
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                fetch(base + '/api/shared/case_timeline_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        application_id: CURRENT_APP_ID,
                        event_type: 'comment',
                        section_key: section,
                        message: msg
                    })
                })
                    .then(function (res) { return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; }); })
                    .then(function (data) {
                        if (!data || data.status !== 1) {
                            throw new Error((data && data.message) ? data.message : 'Failed to save remark.');
                        }
                        if (ta) ta.value = '';
                        setBoxMessage('cvTopMessage', 'Remark saved.', 'success');
                        return loadTimeline(CURRENT_APP_ID);
                    })
                    .catch(function (e) {
                        setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Failed to save remark.', 'danger');
                    })
                    .finally(function () {
                        saveBtn.disabled = false;
                    });
            });
        }

        updateValidatorWorkspace(CURRENT_SECTION_KEY);
    }

    function ensureComponentChat(panel, section) {
        if (!panel) return;
        section = normSection(section);
        if (!section) return;

        var role = getRole();
        if (!canTakeActionRole(role)) return;
        if (String(qs('print') || '') === '1') return;

        if (panel.dataset.chatBound !== '1') {
            panel.dataset.chatBound = '1';

            var secbar = panel.querySelector('.cr-secbar');
            if (!secbar) return;

            var after = [];
            var n = secbar.nextSibling;
            while (n) {
                var next = n.nextSibling;
                if (!(n.nodeType === 3 && String(n.textContent || '').trim() === '')) {
                    after.push(n);
                }
                n = next;
            }

            var layout = document.createElement('div');
            layout.className = 'cr-comp-layout';
            layout.style.display = 'grid';
            layout.style.gridTemplateColumns = '1fr 340px';
            layout.style.gap = '12px';
            layout.style.marginTop = '0';

            var left = document.createElement('div');
            left.className = 'cr-comp-left';

            var rightCol = document.createElement('div');
            rightCol.className = 'cr-comp-right';
            rightCol.style.display = 'flex';
            rightCol.style.flexDirection = 'column';
            rightCol.style.gap = '8px';

            var actions = document.createElement('div');
            actions.className = 'cr-comp-actions-host';
            actions.innerHTML = '' +
                '<button type="button" class="cr-action-btn" data-comp-action="insufficient_documents">Insufficient Documents</button>' +
                '<button type="button" class="cr-action-btn cr-dark" data-comp-action="hold">Hold</button>' +
                '<button type="button" class="cr-action-btn cr-danger" data-comp-action="reject">Reject</button>' +
                '<button type="button" class="cr-action-btn cr-ok" data-comp-action="approve">Approve</button>';

            var right = document.createElement('div');
            right.className = 'cr-comp-chat';
            right.style.border = '1px solid rgba(148,163,184,0.22)';
            right.style.borderRadius = '10px';
            right.style.background = '#fff';
            right.style.padding = '10px';
            right.innerHTML = '' +
                '<div style="font-size:11px; font-weight:950; letter-spacing:.10em; text-transform:uppercase; color:#64748b;">Remarks</div>' +
                '<div data-chat-list style="margin-top:10px; max-height:320px; overflow:auto; padding-right:4px;"></div>' +
                '<div style="margin-top:10px;">' +
                    '<textarea data-chat-text rows="3" placeholder="Enter comments..." style="width:100%; resize:vertical; border:1px solid rgba(148,163,184,0.25); border-radius:10px; padding:8px 10px; font-size:12px;"></textarea>' +
                    '<div style="display:flex; justify-content:flex-end; margin-top:8px;">' +
                        '<button type="button" class="btn btn-sm" data-chat-save>Save</button>' +
                    '</div>' +
                '</div>';

            after.forEach(function (node) {
                left.appendChild(node);
            });

            rightCol.appendChild(actions);
            rightCol.appendChild(right);
            layout.appendChild(left);
            layout.appendChild(rightCol);
            secbar.insertAdjacentElement('afterend', layout);

            actions.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var actBtn = t && t.closest ? t.closest('[data-comp-action]') : null;
                if (actBtn) {
                    var action = String(actBtn.getAttribute('data-comp-action') || '').toLowerCase();
                    if (window.__CR_RUN_ACTION) {
                        window.__CR_RUN_ACTION(action, action.charAt(0).toUpperCase() + action.slice(1));
                    }
                    return;
                }
            });

            right.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var btn = t && t.closest ? t.closest('[data-chat-save]') : null;
                if (!btn) return;
                if (!CURRENT_APP_ID) return;

                var ta = right.querySelector('[data-chat-text]');
                var msg = ta ? String(ta.value || '').trim() : '';
                if (!msg) {
                    setText('cvTopMessage', 'Remark is required.');
                    return;
                }

                btn.disabled = true;
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                fetch(base + '/api/shared/case_timeline_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        application_id: CURRENT_APP_ID,
                        event_type: 'comment',
                        section_key: section,
                        message: msg
                    })
                })
                    .then(function (res) { return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; }); })
                    .then(function (data) {
                        if (!data || data.status !== 1) {
                            setText('cvTopMessage', (data && data.message) ? data.message : 'Failed to save remark.');
                            return;
                        }
                        if (ta) ta.value = '';
                        setText('cvTopMessage', 'Saved.');
                        if (CURRENT_APP_ID) {
                            return loadTimeline(CURRENT_APP_ID);
                        }
                    })
                    .catch(function () {
                        setText('cvTopMessage', 'Network error. Please try again.');
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        }

        var listHost = panel.querySelector('[data-chat-list]');
        if (listHost) {
            listHost.innerHTML = remarksChatHtml(section);
            try {
                listHost.scrollTop = listHost.scrollHeight;
            } catch (_e) {
            }
        }
    }

    function renderRemarksPanel(activeSection) {
        var host = document.getElementById('cvRemarksPanel');
        var countEl = document.getElementById('cvRemarksPanelCount');
        if (!host) return;

        var list = Array.isArray(TL_CACHE) ? TL_CACHE.filter(isRemarkItem) : [];
        if (countEl) {
            countEl.textContent = String(list.length);
        }

        if (!list.length) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No remarks yet.</div>';
            return;
        }

        var groups = {};
        list.forEach(function (it) {
            var k = remarkSectionKey(it) || 'general';
            if (!groups[k]) groups[k] = [];
            groups[k].push(it);
        });

        var order = Object.keys(groups);
        order.sort(function (a, b) {
            if (a === 'general') return 1;
            if (b === 'general') return -1;
            return a.localeCompare(b);
        });

        activeSection = String(activeSection || '').toLowerCase().trim();

        host.innerHTML = order.map(function (k) {
            var label = sectionLabel(k === 'general' ? 'General' : k);
            var items = groups[k] || [];
            var isActive = (k !== 'general' && k === activeSection);
            var open = isActive ? ' open' : '';
            return '' +
                '<div class="cr-remarksbar-group" data-rg="' + esc(k) + '">' +
                    '<div class="cr-remarksbar-head' + (isActive ? ' active' : '') + '" data-rh="1" data-sec="' + esc(k) + '">' +
                        '<b>' + esc(label) + '</b>' +
                        '<span class="badge bg-secondary">' + esc(String(items.length)) + '</span>' +
                    '</div>' +
                    '<div class="cr-remarksbar-body' + open + '">' +
                        items.slice(0, 30).map(function (it) {
                            var actorName = ((it.first_name || '') + ' ' + (it.last_name || '')).trim();
                            var actorUser = (it.username || '') ? String(it.username) : '';
                            var role2 = it.actor_role || '';
                            var actor = actorName || actorUser || (role2 ? String(role2).toUpperCase() : '') || 'System';
                            var ts = '';
                            try {
                                ts = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
                            } catch (_e) {
                                ts = it.created_at ? String(it.created_at) : '';
                            }
                            return '' +
                                '<div class="cr-remark-item">' +
                                    '<div class="cr-remark-meta"><span>' + esc(actor) + '</span><span>' + esc(ts) + '</span></div>' +
                                    '<div class="cr-remark-msg">' + esc(it.message || '') + '</div>' +
                                '</div>';
                        }).join('') +
                    '</div>' +
                '</div>';
        }).join('');

        if (!host.dataset.bound) {
            host.dataset.bound = '1';
            host.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                if (!t) return;
                var head = t.closest ? t.closest('[data-rh="1"]') : null;
                if (!head) return;

                var sec = String(head.getAttribute('data-sec') || '');
                var body = head.parentElement ? head.parentElement.querySelector('.cr-remarksbar-body') : null;
                if (body) {
                    body.classList.toggle('open');
                }

                if (sec && sec !== 'general') {
                    var sidebarBtn = document.querySelector('.list-group-item[data-section="' + sec.replace(/"/g, '') + '"]');
                    if (sidebarBtn) {
                        sidebarBtn.click();
                    }
                }
            });
        }
    }

    function bytesToHuman(n) {
        var v = Number(n || 0);
        if (!isFinite(v) || v <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (v >= 1024 && i < units.length - 1) {
            v = v / 1024;
            i++;
        }
        return (Math.round(v * 10) / 10) + ' ' + units[i];
    }

    function syncInputFilesFromSelected(inputEl) {
        if (!inputEl) return;
        try {
            var dt = new DataTransfer();
            (SELECTED_UPLOAD_FILES || []).forEach(function (f) {
                try { dt.items.add(f); } catch (_e) {}
            });
            inputEl.files = dt.files;
        } catch (_e) {
        }
    }

    function addFilesToSelected(filesLike, inputEl) {
        var list = [];
        try {
            list = filesLike ? Array.prototype.slice.call(filesLike) : [];
        } catch (_e) {
            list = [];
        }

        if (!Array.isArray(SELECTED_UPLOAD_FILES)) SELECTED_UPLOAD_FILES = [];
        list.forEach(function (f) {
            if (!f) return;
            var key = (f.name || '') + '|' + (f.size || 0) + '|' + (f.lastModified || 0);
            var exists = SELECTED_UPLOAD_FILES.some(function (x) {
                var k2 = (x.name || '') + '|' + (x.size || 0) + '|' + (x.lastModified || 0);
                return k2 === key;
            });
            if (!exists) SELECTED_UPLOAD_FILES.push(f);
        });

        syncInputFilesFromSelected(inputEl);
    }

    function removeSelectedFileAt(idx, inputEl) {
        idx = parseInt(String(idx), 10);
        if (!Array.isArray(SELECTED_UPLOAD_FILES)) SELECTED_UPLOAD_FILES = [];
        if (isNaN(idx) || idx < 0 || idx >= SELECTED_UPLOAD_FILES.length) return;
        SELECTED_UPLOAD_FILES.splice(idx, 1);
        syncInputFilesFromSelected(inputEl);
    }

    function setSelectedFilesUi(files) {
        var chipsEl = document.getElementById('cvFileChips');
        var metaEl = document.getElementById('cvFileMeta');
        if (chipsEl) chipsEl.innerHTML = '';

        var list = [];
        try {
            list = files ? Array.prototype.slice.call(files) : [];
        } catch (e) {
            list = [];
        }

        if (!list.length) {
            if (metaEl) metaEl.textContent = 'or drag & drop here';
            return;
        }

        var total = list.reduce(function (acc, f) { return acc + (f && f.size ? f.size : 0); }, 0);
        if (metaEl) metaEl.textContent = list.length + ' file(s) selected • ' + bytesToHuman(total);

        if (chipsEl) {
            chipsEl.innerHTML = list.map(function (f, idx) {
                var name = (f && f.name) ? String(f.name) : 'file';
                return '<span class="cr-chip" title="' + esc(name) + '">' +
                    '<span>' + esc(name) + '</span>' +
                    '<button type="button" class="cr-chip-x" data-chip-idx="' + String(idx) + '">X</button>' +
                '</span>';
            }).join('');
        }
    }

    function initUploadPicker() {
        var drop = document.getElementById('cvFileDrop');
        var input = document.getElementById('cvUploadFiles');
        if (!drop || !input) return;

        if (drop.dataset.bound) {
            if (Array.isArray(SELECTED_UPLOAD_FILES) && SELECTED_UPLOAD_FILES.length) {
                syncInputFilesFromSelected(input);
                setSelectedFilesUi(input.files);
            } else {
                setSelectedFilesUi(input.files);
            }
            return;
        }
        drop.dataset.bound = '1';

        if (!Array.isArray(SELECTED_UPLOAD_FILES)) SELECTED_UPLOAD_FILES = [];
        addFilesToSelected(input.files, input);
        setSelectedFilesUi(input.files);

        input.addEventListener('change', function () {
            SELECTED_UPLOAD_FILES = [];
            addFilesToSelected(input.files, input);
            setSelectedFilesUi(input.files);
        });

        var chipsEl = document.getElementById('cvFileChips');
        if (chipsEl && !chipsEl.dataset.bound) {
            chipsEl.dataset.bound = '1';
            chipsEl.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                if (!t) return;
                var btn = t.closest ? t.closest('[data-chip-idx]') : null;
                if (!btn) return;
                var idx = btn.getAttribute('data-chip-idx');
                removeSelectedFileAt(idx, input);
                setSelectedFilesUi(input.files);
            });
        }

        var dragCounter = 0;

        function setDragState(on) {
            drop.classList.toggle('cr-dragover', !!on);
        }

        drop.addEventListener('dragenter', function (e) {
            e.preventDefault();
            dragCounter++;
            setDragState(true);
        });

        drop.addEventListener('dragover', function (e) {
            e.preventDefault();
            setDragState(true);
        });

        drop.addEventListener('dragleave', function (e) {
            e.preventDefault();
            dragCounter = Math.max(0, dragCounter - 1);
            if (dragCounter === 0) setDragState(false);
        });

        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            dragCounter = 0;
            setDragState(false);

            var dt = e.dataTransfer;
            if (!dt || !dt.files) return;
            addFilesToSelected(dt.files, input);
            setSelectedFilesUi(input.files);
        });

        if (!drop.dataset.pasteBound) {
            drop.dataset.pasteBound = '1';
            drop.setAttribute('tabindex', '0');
            drop.addEventListener('paste', function (e) {
                var cd = e && e.clipboardData ? e.clipboardData : null;
                if (!cd || !cd.items) return;
                var files = [];
                for (var i = 0; i < cd.items.length; i++) {
                    var it = cd.items[i];
                    if (it && it.kind === 'file') {
                        var f = it.getAsFile ? it.getAsFile() : null;
                        if (f) files.push(f);
                    }
                }
                if (files.length) {
                    e.preventDefault();
                    addFilesToSelected(files, input);
                    setSelectedFilesUi(input.files);
                }
            });
        }
    }

    function initValidatorRemarks() {
        var role = getRole();
        if (!(role === 'validator' || role === 'verifier')) return;

        var map = [
            { section: 'basic', ta: 'cvRemarksBasic', btn: 'cvSaveRemarksBasic' },
            { section: 'id', ta: 'cvRemarksId', btn: 'cvSaveRemarksId' },
            { section: 'contact', ta: 'cvRemarksContact', btn: 'cvSaveRemarksContact' },
            { section: 'education', ta: 'cvRemarksEducation', btn: 'cvSaveRemarksEducation' },
            { section: 'employment', ta: 'cvRemarksEmployment', btn: 'cvSaveRemarksEmployment' },
            { section: 'reference', ta: 'cvRemarksReference', btn: 'cvSaveRemarksReference' },
            { section: 'reports', ta: 'cvRemarksReports', btn: 'cvSaveRemarksReports' }
        ];

        map.forEach(function (cfg) {
            var btn = document.getElementById(cfg.btn);
            var ta = document.getElementById(cfg.ta);
            if (!btn || !ta) return;
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';

            btn.addEventListener('click', function () {
                var applicationId = qs('application_id') || '';
                var msg = (ta.value || '').trim();
                if (!applicationId) return;
                if (!msg) return;

                btn.disabled = true;
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                fetch(base + '/api/shared/case_timeline_add.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        application_id: applicationId,
                        event_type: 'comment',
                        section_key: String(cfg.section === 'reference'
                            ? (currentSectionKey() || cfg.section || 'reference')
                            : (cfg.section || 'basic')),
                        message: msg
                    })
                })
                    .then(function (res) { return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; }); })
                    .then(function (data) {
                        var out = document.getElementById('cvTopMessage');
                        if (!data || data.status !== 1) {
                            if (out) out.textContent = (data && data.message) ? data.message : 'Failed to save remarks.';
                            return;
                        }
                        ta.value = '';
                        if (out) out.textContent = 'Saved.';
                        try {
                            var appId = qs('application_id') || '';
                            if (appId) loadTimeline(appId);
                        } catch (_e) {}
                    })
                    .catch(function () {
                        var out = document.getElementById('cvTopMessage');
                        if (out) out.textContent = 'Network error. Please try again.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
        });
    }

    function initHeaderModals(applicationId) {
        var openUpload = document.getElementById('cvOpenUploadModal');
        if (openUpload && !openUpload.dataset.bound) {
            openUpload.dataset.bound = '1';
            openUpload.addEventListener('click', function () {
                openBsModal('cvUploadModal');
                initUploadPicker();
                var uploadTypeEl = document.getElementById('cvUploadDocType');
                var currentType = uploadTypeEl ? String(uploadTypeEl.value || '') : '';
                if (applicationId) loadUploadedDocs(applicationId, currentType);
            });
        }

        var openTimeline = document.getElementById('cvOpenTimelineModal');
        if (openTimeline && !openTimeline.dataset.bound) {
            openTimeline.dataset.bound = '1';
            openTimeline.addEventListener('click', function () {
                openBsModal('cvTimelineModal');
            });
        }

        // Remarks modal flow removed; remarks are handled via right sidebar only.
    }

    function initClientAdminEscalation() {
        var role = getRole();
        if (role !== 'client_admin') return;
        var tlBtn = document.getElementById('cvEscalateTlBtn');
        var gssBtn = document.getElementById('cvEscalateGssBtn');
        if (!tlBtn && !gssBtn) return;

        function postEscalation(targetLabel) {
            var appId = String(CURRENT_APP_ID || qs('application_id') || '').trim();
            if (!appId) {
                setBoxMessage('cvTopMessage', 'application_id is required for escalation.', 'danger');
                return;
            }
            var section = String(activeComponentSectionKey() || CURRENT_SECTION_KEY || 'general').toLowerCase().trim();
            var msg = 'Escalation requested by Client Admin to ' + targetLabel + ' for section: ' + sectionLabel(section) + '.';
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            fetch(base + '/api/shared/case_timeline_add.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    application_id: appId,
                    event_type: 'action',
                    section_key: section || 'general',
                    message: msg
                })
            })
                .then(function (res) { return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; }); })
                .then(function (data) {
                    if (!data || data.status !== 1) {
                        throw new Error((data && data.message) ? data.message : 'Failed to raise escalation.');
                    }
                    setBoxMessage('cvTopMessage', 'Escalation logged to timeline (' + targetLabel + ').', 'success');
                    loadTimeline(appId).catch(function () {});
                })
                .catch(function (e) {
                    setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Failed to raise escalation.', 'danger');
                });
        }

        if (tlBtn && !tlBtn.dataset.bound) {
            tlBtn.dataset.bound = '1';
            tlBtn.addEventListener('click', function () { postEscalation('Team Lead'); });
        }
        if (gssBtn && !gssBtn.dataset.bound) {
            gssBtn.dataset.bound = '1';
            gssBtn.addEventListener('click', function () { postEscalation('GSS Admin'); });
        }
    }

    function esc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function isImageMime(m) {
        var v = String(m || '').toLowerCase();
        return v.indexOf('image/') === 0;
    }

    function isPdfMime(m) {
        var v = String(m || '').toLowerCase();
        return v.indexOf('pdf') !== -1;
    }

    function docHref(row) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var fp = row && row.file_path ? String(row.file_path) : '';
        if (!fp) return '';
        if (/^https?:\/\//i.test(fp)) return fp;

        // normalize to avoid double base path like /GSS1/GSS1/...
        try {
            if (base) {
                var bPath = base.replace(/^https?:\/\/[^/]+/i, '');
                if (bPath && bPath !== '/' && fp.indexOf(bPath + '/') === 0) {
                    fp = fp.substring(bPath.length);
                }
            }
        } catch (_e) {
        }

        if (fp.indexOf('/') === 0) return base + fp;
        return base + '/' + fp;
    }

    function renderDocPreviewPanel(rows) {
        var role = getRole();
        if (!(role === 'validator' || role === 'verifier')) return;

        var frameHost = document.getElementById('cvDocPreviewFrameHost');
        var listHost = document.getElementById('cvDocPreviewList');
        if (!frameHost || !listHost) return;

        var list = Array.isArray(rows) ? rows.slice() : [];
        if (!list.length) {
            frameHost.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px;">No document selected.</div>';
            listHost.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px;">No uploaded documents.</div>';
            return;
        }

        listHost.innerHTML = list.map(function (r, idx) {
            var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : ('Document ' + (idx + 1));
            var dt = r && r.doc_type ? String(r.doc_type) : '';
            var by = r && r.uploaded_by_role ? String(r.uploaded_by_role) : '';
            return '<div class="cr-docbar-item" data-doc-idx="' + String(idx) + '">' +
                '<div style="min-width:0; flex:1;">' +
                    '<div class="cr-docbar-meta">' + esc(dt || 'Document') + '</div>' +
                    '<div class="cr-docbar-sub">' + esc(label) + (by ? (' · ' + esc(by)) : '') + '</div>' +
                '</div>' +
                '<div class="cr-docbar-open">Open</div>' +
            '</div>';
        }).join('');

        function setActive(idx, opts) {
            opts = opts || {};
            var loadPreview = opts.loadPreview !== false;
            idx = parseInt(String(idx), 10);
            if (isNaN(idx) || idx < 0 || idx >= list.length) return;

            var r = list[idx];
            var href = docHref(r);
            if (!href) return;

            Array.prototype.slice.call(listHost.querySelectorAll('.cr-docbar-item')).forEach(function (el) {
                el.classList.toggle('active', String(el.getAttribute('data-doc-idx')) === String(idx));
            });

            var mt = r && r.mime_type ? String(r.mime_type) : '';
            if (isImageMime(mt)) {
                if (!loadPreview) {
                    frameHost.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px;">Select a document to load preview.</div>';
                    return;
                }
                frameHost.innerHTML = '<img src="' + esc(href) + '" alt="document" />';
                return;
            }

            if (isPdfMime(mt) || href.toLowerCase().indexOf('.pdf') !== -1) {
                if (!loadPreview) {
                    frameHost.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px;">Select a document to load preview.</div>';
                    return;
                }
                frameHost.innerHTML = '<iframe src="' + esc(buildPdfViewerUrl(href)) + '"></iframe>';
                return;
            }

            frameHost.innerHTML = '<div style="padding:10px; color:#0f172a; font-size:12px;">' +
                '<div style="font-weight:900; margin-bottom:6px;">Preview not available</div>' +
                '<a href="' + esc(href) + '" class="js-cv-doc-view" data-doc-label="' + esc(label || 'Document') + '" style="text-decoration:none; color:#2563eb; font-weight:800;">Open document</a>' +
                '</div>';
        }
        listHost.__cvDocSetActive = setActive;
        listHost.__cvDocList = list;

        if (!listHost.dataset.bound) {
            listHost.dataset.bound = '1';
            listHost.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var item = t && t.closest ? t.closest('.cr-docbar-item') : null;
                if (!item) return;
                var idx = item.getAttribute('data-doc-idx');
                if (typeof listHost.__cvDocSetActive === 'function') {
                    listHost.__cvDocSetActive(idx);
                }
                var n = parseInt(String(idx || '0'), 10);
                var rows = Array.isArray(listHost.__cvDocList) ? listHost.__cvDocList : [];
                var row = (!isNaN(n) && n >= 0 && n < rows.length) ? rows[n] : null;
                var href = row ? docHref(row) : '';
                var docType = row && row.doc_type ? normSection(String(row.doc_type)) : '';
                var componentKey = isRecordComponent(docType) ? docType : activeComponentSectionKey();
                var context = {
                    applicationId: CURRENT_APP_ID || '',
                    componentKey: componentKey,
                    itemKey: componentKey ? getActiveItemKeyForSection(componentKey) : '',
                    mimeType: row && row.mime_type ? String(row.mime_type) : ''
                };
                if (href) {
                    if (openDocViewer(href, {
                        applicationId: context.applicationId || '',
                        componentKey: context.componentKey || '',
                        itemKey: context.itemKey || '',
                        mimeType: row && row.mime_type ? String(row.mime_type) : ''
                    })) return;
                    showCrToast('Unable to open preview for this file.', 'warning');
                }
            });
        }

        frameHost.innerHTML = '<div style="padding:10px; color:#64748b; font-size:12px;">Select a document to load preview.</div>';
    }

    function fileUrlForField(fieldKey, value) {
        var v = (value === null || typeof value === 'undefined') ? '' : String(value);
        v = v.trim();
        if (!v || v === 'INSUFFICIENT_DOCUMENTS') return '';

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

        if (v.indexOf('/uploads/') === 0) return base + v;
        if (v.indexOf('uploads/') === 0) return base + '/' + v;
        if (v.indexOf('uploads\\') === 0) return base + '/' + v.replace(/\\/g, '/');
        if (/^https?:\/\//i.test(v)) return v;

        var k = String(fieldKey || '').toLowerCase();
        if (k === 'upload_document') return base + '/uploads/identification/' + encodeURIComponent(v);
        if (k === 'proof_file') return base + '/uploads/address/' + encodeURIComponent(v);
        if (k === 'marksheet_file' || k === 'degree_file') return base + '/uploads/education/' + encodeURIComponent(v);
        if (k === 'employment_doc') return base + '/uploads/employment/' + encodeURIComponent(v);
        if (k === 'photo_path') return base + '/uploads/photos/' + encodeURIComponent(v);
        return '';
    }

    function fileCellHtml(fieldKey, value) {
        var v = (value === null || typeof value === 'undefined') ? '' : String(value);
        v = v.trim();
        if (!v) return '';
        if (v === 'INSUFFICIENT_DOCUMENTS') return '<span class="badge bg-secondary">Insufficient</span>';

        var href = fileUrlForField(fieldKey, v);
        if (!href) return esc(v);

        var lower = v.toLowerCase();
        var icon = '<i>FILE</i>';
        if (lower.endsWith('.pdf')) icon = '<i>PDF</i>';
        if (lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.png') || lower.endsWith('.gif') || lower.endsWith('.webp')) icon = '<i>IMG</i>';

        return '' +
            '<div class="cr-doc-uploadbox">' +
                '<div class="cr-doc-uploadrow">' +
                    '<span class="cr-doc-uploadbtn">Uploaded</span>' +
                    '<a href="' + esc(href) + '" class="js-cv-doc-view cr-doc-uploadname" data-doc-label="' + esc(v) + '">' +
                        icon +
                        '<span>' + esc(v) + '</span>' +
                    '</a>' +
                '</div>' +
            '</div>';
    }

    function setBadge(id, kind, text) {
        var el = document.getElementById(id);
        if (!el) return;
        var k = String(kind || '').toLowerCase();
        el.classList.remove('bg-success', 'bg-warning', 'bg-secondary', 'bg-danger', 'bg-primary', 'bg-info', 'text-dark');
        el.style.removeProperty('background');
        el.style.removeProperty('color');
        el.style.removeProperty('border');
        if (k === 'done') {
            el.classList.add('bg-success');
            el.textContent = String(text || 'COMPLETED').toUpperCase();
            return;
        }
        if (k === 'wip') {
            el.classList.add('bg-primary');
            el.textContent = String(text || 'PENDING').toUpperCase();
            return;
        }
        if (k === 'rejected') {
            el.classList.add('bg-danger');
            el.textContent = String(text || 'REJECTED').toUpperCase();
            return;
        }
        if (k === 'hold') {
            el.classList.add('bg-warning', 'text-dark');
            el.textContent = String(text || 'HOLD').toUpperCase();
            return;
        }
        if (k === 'need_docs') {
            el.classList.add('bg-warning', 'text-dark');
            el.style.background = '#fef08a';
            el.style.color = '#854d0e';
            el.style.border = '1px solid #facc15';
            el.textContent = String(text || 'NEED DOCS').toUpperCase();
            return;
        }
        if (k === 'candidate_pending') {
            el.classList.add('bg-secondary');
            el.style.background = '#c7d2fe';
            el.style.color = '#3730a3';
            el.style.border = '1px solid #a5b4fc';
            el.textContent = String(text || 'CANDIDATE PENDING').toUpperCase();
            return;
        }
        if (k === 'correction_submitted') {
            el.classList.add('bg-info');
            el.textContent = String(text || 'CORRECTION SUBMITTED').toUpperCase();
            return;
        }
        if (k === 'mail_sent') {
            el.classList.add('bg-info');
            el.textContent = String(text || 'MAIL SENT').toUpperCase();
            return;
        }
        el.classList.add('bg-secondary');
        el.textContent = String(text || 'PENDING').toUpperCase();
    }
    var __cvLineageStylesInjected = false;
    function ensureSidebarLineageStyles() {
        if (__cvLineageStylesInjected) return;
        __cvLineageStylesInjected = true;
        var st = document.createElement('style');
        st.id = 'cv-lineage-style';
        st.textContent =
            '.cv-badge-wrap{display:flex;align-items:flex-start;gap:6px;flex:0 0 auto;}' +
            '.cv-badge-info{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:999px;border:1px solid rgba(148,163,184,.55);color:#475569;background:#f8fafc;font-size:10px;font-weight:700;cursor:default;}' +
            '.cv-lineage-card{position:fixed;z-index:1300;min-width:220px;max-width:300px;background:#fff;border:1px solid rgba(148,163,184,.35);border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.16);padding:10px 12px;opacity:0;transform:translateY(4px);transition:opacity .14s ease, transform .14s ease;pointer-events:none;}' +
            '.cv-lineage-card.show{opacity:1;transform:translateY(0);}' +
            '.cv-lineage-title{font-size:11px;font-weight:800;color:#334155;margin-bottom:6px;letter-spacing:.02em;text-transform:uppercase;}' +
            '.cv-lineage-row{display:flex;justify-content:space-between;gap:10px;font-size:12px;line-height:1.35;margin:3px 0;}' +
            '.cv-lineage-stage{color:#64748b;font-weight:700;}' +
            '.cv-lineage-state{color:#0f172a;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}';
        document.head.appendChild(st);
    }

    function primaryStatusText(stageLabel) {
        var raw = String(stageLabel || '').trim();
        if (!raw) return '';
        var p = raw.indexOf('(');
        return (p >= 0 ? raw.substring(0, p) : raw).trim();
    }

    function buildLineageData(stages) {
        var row = (stages && typeof stages === 'object') ? stages : {};
        var out = [];
        ['validator', 'verifier', 'qa'].forEach(function (stage) {
            var s = String(row[stage] && row[stage].status ? row[stage].status : '').trim();
            if (!s) return;
            if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                out.push({ stage: stage, label: String(window.WF_UI.labelByRole(s, stage)).toUpperCase() });
            } else {
                out.push({ stage: stage, label: String(s).toUpperCase() });
            }
        });
        return out;
    }

    function attachBadgeLineage(badgeId, lineageRows) {
        var badge = document.getElementById(badgeId);
        if (!badge) return;
        var rows = Array.isArray(lineageRows) ? lineageRows : [];
        var row = badge.closest ? badge.closest('.list-group-item') : null;
        if (!row) row = badge.parentElement;
        if (!row) return;
        ensureSidebarLineageStyles();
        var right = row.querySelector('.section-right') || row;
        var holder = right.querySelector('.cv-badge-wrap');
        if (!holder) {
            holder = document.createElement('span');
            holder.className = 'cv-badge-wrap';
            right.appendChild(holder);
        }
        if (badge.parentElement !== holder) {
            holder.insertBefore(badge, holder.firstChild || null);
        }

        var oldInfo = holder.querySelector('.cv-badge-info');
        if (oldInfo && oldInfo.parentNode) oldInfo.parentNode.removeChild(oldInfo);
        if (!rows.length) return;

        var info = document.createElement('span');
        info.className = 'cv-badge-info';
        info.textContent = 'i';
        info.setAttribute('aria-label', 'Workflow lineage');

        var card = null;
        function hideCard() {
            if (!card) return;
            card.classList.remove('show');
            setTimeout(function () {
                if (card && card.parentNode) card.parentNode.removeChild(card);
                card = null;
            }, 140);
        }
        function showCard() {
            hideCard();
            card = document.createElement('div');
            card.className = 'cv-lineage-card';
            var rowsHtml = rows.map(function (r) {
                return '<div class="cv-lineage-row"><span class="cv-lineage-stage">' + esc(String(r.stage || '').toUpperCase()) + '</span><span class="cv-lineage-state">' + esc(String(r.label || '').toUpperCase()) + '</span></div>';
            }).join('');
            card.innerHTML = '<div class="cv-lineage-title">Workflow History</div>' + rowsHtml;
            document.body.appendChild(card);
            var rect = info.getBoundingClientRect();
            var top = rect.bottom + 8;
            var left = rect.left - 120;
            if (left < 8) left = 8;
            if (left + 320 > window.innerWidth) left = Math.max(8, window.innerWidth - 320);
            card.style.top = top + 'px';
            card.style.left = left + 'px';
            requestAnimationFrame(function () { if (card) card.classList.add('show'); });
        }
        info.addEventListener('mouseenter', showCard);
        info.addEventListener('mouseleave', function () { setTimeout(hideCard, 60); });
        info.addEventListener('click', function (e) { e.preventDefault(); if (card) hideCard(); else showCard(); });
        holder.appendChild(info);
    }
    function isFilled(v) {
        return v != null && String(v).trim() !== '';
    }

    function computeComponentStageLabel(stages) {
        if (window.WF_UI && typeof window.WF_UI.resolveComponentWorkflowStatus === 'function') {
            var resolved = window.WF_UI.resolveComponentWorkflowStatus(stages || {});
            if (resolved && resolved.label) return String(resolved.label).toUpperCase();
        }
        var cand = String(stages && stages.candidate ? stages.candidate : '').toLowerCase().trim();
        if (cand === 'rejected') return 'CANDIDATE REJECTED';
        return '';
    }

    function normalizeStageLabelForRole(stageLabel) {
        var raw = String(stageLabel || '').trim();
        var low = raw.toLowerCase();
        var role = getRole();
        if (raw.indexOf('(') !== -1 && raw.indexOf(')') !== -1) {
            return raw.toUpperCase();
        }
        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
            return String(window.WF_UI.labelByRole(low || raw, role || 'validator')).toUpperCase();
        }
        return raw.toUpperCase();
    }
    function getWorkflowComponentRow(d, componentKey) {
        try {
            componentKey = normSection(componentKey);
            if (!componentKey) return null;
            var cw = d && d.component_workflow ? d.component_workflow : (d && d.componentWorkflow ? d.componentWorkflow : null);
            if (!cw || typeof cw !== 'object') return null;
            if (cw[componentKey] && typeof cw[componentKey] === 'object') return cw[componentKey];
            if ((componentKey === 'education_reference' || componentKey === 'employment_reference')
                && cw.reference && typeof cw.reference === 'object') {
                return cw.reference;
            }

            var keys = Object.keys(cw);
            for (var i = 0; i < keys.length; i++) {
                var rawKey = String(keys[i] || '');
                if (normSection(rawKey) === componentKey) {
                    var row = cw[rawKey];
                    if (row && typeof row === 'object') return row;
                }
            }
            return null;
        } catch (_e) {
            return null;
        }
    }

    function getWorkflowStageLabel(d, componentKey) {
        try {
            componentKey = normSection(componentKey);
            if (!componentKey) return '';
            function isVerifierReportContext() {
                try {
                    var roleNow = String(getRole() || '').toLowerCase().trim();
                    return roleNow === 'verifier' || roleNow === 'db_verifier';
                } catch (_eRole) {
                    return false;
                }
            }
            function isVerifierParticipatedContext() {
                try {
                    var roleNow = String(getRole() || '').toLowerCase().trim();
                    if (roleNow !== 'verifier' && roleNow !== 'db_verifier') return false;
                    var v = String(qs('list_view') || qs('view') || '').toLowerCase().trim();
                    return v === 'participated' || v === 'history' || v === 'completed';
                } catch (_eCtx) {
                    return false;
                }
            }
            var caseStatus = '';
            try {
                var c = d && d.case && typeof d.case === 'object' ? d.case : null;
                caseStatus = String((c && c.case_status) || '').toLowerCase().trim();
            } catch (_eCase) {
                caseStatus = '';
            }

            var byComp = getWorkflowComponentRow(d, componentKey);
            if (byComp && typeof byComp === 'object') {
                if (isVerifierReportContext()) {
                    var vStage = byComp.verifier && typeof byComp.verifier === 'object'
                        ? String(byComp.verifier.status || '').toLowerCase().trim()
                        : '';
                    var qaStage = byComp.qa && typeof byComp.qa === 'object'
                        ? String(byComp.qa.status || '').toLowerCase().trim()
                        : '';
                    var verifierPrimaryStatuses = {
                        approved: true,
                        rejected: true,
                        hold: true,
                        insufficient_documents: true,
                        waiting_candidate: true,
                        correction_submitted: true,
                        reopened: true,
                        blocked: true,
                        completed: true,
                        done: true,
                        verified: true,
                        clear: true,
                        invalidated_by_validator_reopen: true,
                        invalidated_by_verifier_reopen: true
                    };
                    if (vStage && verifierPrimaryStatuses[vStage]) {
                        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                            return String(window.WF_UI.labelByRole(vStage, 'verifier')).toUpperCase();
                        }
                        return String(vStage || '').toUpperCase();
                    }
                    if (isVerifierParticipatedContext() && vStage) {
                        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                            return String(window.WF_UI.labelByRole(vStage, 'verifier')).toUpperCase();
                        }
                        return String(vStage || '').toUpperCase();
                    }
                    if (!vStage && qaStage && (qaStage === 'pending' || qaStage === 'in_progress')) {
                        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                            return String(window.WF_UI.labelByRole('pending', 'verifier')).toUpperCase();
                        }
                        return 'VE PENDING';
                    }
                }
                if (window.WF_UI && typeof window.WF_UI.resolveComponentWorkflowStatus === 'function') {
                    var resolved = window.WF_UI.resolveComponentWorkflowStatus(byComp, { case_status: caseStatus });
                    if (resolved && resolved.label) return String(resolved.label).toUpperCase();
                }
                var role = String(getRole() || '').toLowerCase().trim();
                if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                    return String(window.WF_UI.labelByRole('pending', role || 'validator')).toUpperCase();
                }
                return 'PENDING';
            }
            return '';
        } catch (e) {
            return '';
        }
    }

    function getWorkflowLineageRows(d, componentKey) {
        var byComp = getWorkflowComponentRow(d, componentKey);
        return buildLineageData(byComp || {});
    }

    function setStageBadge(badgeId, stageLabel) {
        stageLabel = normalizeStageLabelForRole(stageLabel);
        stageLabel = String(stageLabel || '').trim();
        if (!stageLabel) return false;
        var compact = primaryStatusText(stageLabel) || stageLabel;

        var low = compact.toLowerCase();
        if (low.indexOf('rejected') !== -1) {
            setBadge(badgeId, 'rejected', compact);
            return true;
        }
        if (low.indexOf('approved') !== -1 || low.indexOf('completed') !== -1) {
            setBadge(badgeId, 'done', compact);
            return true;
        }
        if (low.indexOf('hold') !== -1) {
            setBadge(badgeId, 'hold', compact);
            return true;
        }
        if (low.indexOf('need docs') !== -1) {
            setBadge(badgeId, 'need_docs', compact);
            return true;
        }
        if (low.indexOf('correction submitted') !== -1) {
            setBadge(badgeId, 'correction_submitted', compact);
            return true;
        }
        if (low.indexOf('candidate pending') !== -1 || low.indexOf('waiting candidate') !== -1 || low.indexOf('reopened') !== -1) {
            setBadge(badgeId, 'candidate_pending', compact);
            return true;
        }
        if (low.indexOf('mail sent') !== -1) {
            setBadge(badgeId, 'mail_sent', compact);
            return true;
        }
        if (low.indexOf('blocked') !== -1 || low.indexOf('in progress') !== -1 || low.indexOf('on hold') !== -1) {
            setBadge(badgeId, 'wip', compact);
            return true;
        }
        if (low.indexOf('pending') === 0) {
            setBadge(badgeId, 'wip', compact);
            return true;
        }

        setBadge(badgeId, 'pending', compact);
        return true;
    }
    function isValidatorRejectedOpenState(d, componentKey) {
        try {
            componentKey = normSection(componentKey);
            if (!componentKey) return false;

            var byComp = getWorkflowComponentRow(d, componentKey);
            if (byComp && typeof byComp === 'object') {
                var vfs = byComp.verifier && typeof byComp.verifier === 'object'
                    ? String(byComp.verifier.status || '').toLowerCase().trim()
                    : '';
                var qas = byComp.qa && typeof byComp.qa === 'object'
                    ? String(byComp.qa.status || '').toLowerCase().trim()
                    : '';
                if (vfs === 'approved' || vfs === 'rejected' || qas === 'approved' || qas === 'rejected') {
                    return false;
                }
            }
            if (byComp && byComp.validator && typeof byComp.validator === 'object') {
                var s = String(byComp.validator.status || '').toLowerCase().trim();
                if (s === 'rejected') return true;
            }

            var list = Array.isArray(d && d.assigned_components) ? d.assigned_components : [];
            for (var i = 0; i < list.length; i++) {
                var r = list[i] || {};
                var k = r.component_key ? normSection(r.component_key) : '';
                if (k !== componentKey) continue;
                var wf = r.workflow && typeof r.workflow === 'object' ? r.workflow : null;
                if (wf) {
                    var ver = String(wf.verifier || '').toLowerCase().trim();
                    var qa = String(wf.qa || '').toLowerCase().trim();
                    if (ver === 'approved' || ver === 'rejected' || qa === 'approved' || qa === 'rejected') {
                        return false;
                    }
                }
                var s2 = wf ? String(wf.validator || '').toLowerCase().trim() : '';
                if (s2 === 'rejected') return true;
            }
        } catch (_e) {
        }
        return false;
    }

    function updateSectionBadges(d) {
        var role = getRole();
        d = d || {};
        var basic = d.basic || {};
        var contact = d.contact || {};
        var ref = d.reference || {};
        var social = d.social_media || {};
        var ecourt = d.ecourt || {};
        var app = d.application || {};
        var auth = d.authorization || {};

        var identification = Array.isArray(d.identification) ? d.identification : [];
        var education = Array.isArray(d.education) ? d.education : [];
        var employment = Array.isArray(d.employment) ? d.employment : [];

        var reportsDone = isFilled(app.submitted_at) || isFilled(auth.file_name) || isFilled(auth.uploaded_at);

        // Show Validator Rejected only while verifier/qa has not finalized that component.
        var forcedRejected = {
            basic: isValidatorRejectedOpenState(d, 'basic'),
            id: isValidatorRejectedOpenState(d, 'id'),
            contact: isValidatorRejectedOpenState(d, 'contact'),
            education: isValidatorRejectedOpenState(d, 'education'),
            employment: isValidatorRejectedOpenState(d, 'employment'),
            reference: isValidatorRejectedOpenState(d, 'reference'),
            socialmedia: isValidatorRejectedOpenState(d, 'socialmedia'),
            ecourt: isValidatorRejectedOpenState(d, 'ecourt'),
            reports: isValidatorRejectedOpenState(d, 'reports')
        };
        var forcedRejectedLabel = (window.WF_UI && typeof window.WF_UI.labelByRole === 'function')
            ? window.WF_UI.labelByRole('rejected', 'validator')
            : 'Rejected';
        if (forcedRejected.basic) setBadge('cvNavBadgeBasic', 'rejected', forcedRejectedLabel);
        if (forcedRejected.id) setBadge('cvNavBadgeId', 'rejected', forcedRejectedLabel);
        if (forcedRejected.contact) setBadge('cvNavBadgeContact', 'rejected', forcedRejectedLabel);
        if (forcedRejected.education) setBadge('cvNavBadgeEducation', 'rejected', forcedRejectedLabel);
        if (forcedRejected.employment) setBadge('cvNavBadgeEmployment', 'rejected', forcedRejectedLabel);
        if (forcedRejected.reference) setBadge('cvNavBadgeReference', 'rejected', forcedRejectedLabel);
        if (forcedRejected.socialmedia) setBadge('cvNavBadgeSocialmedia', 'rejected', forcedRejectedLabel);
        if (forcedRejected.ecourt) setBadge('cvNavBadgeEcourt', 'rejected', forcedRejectedLabel);
        if (forcedRejected.reports) setBadge('cvNavBadgeReports', 'rejected', forcedRejectedLabel);

        // Prefer workflow stage when available
        var usedWorkflow = false;
        if (!forcedRejected.basic) usedWorkflow = setStageBadge('cvNavBadgeBasic', getWorkflowStageLabel(d, 'basic')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeBasic', getWorkflowLineageRows(d, 'basic'));
        if (!forcedRejected.id) usedWorkflow = setStageBadge('cvNavBadgeId', getWorkflowStageLabel(d, 'id')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeId', getWorkflowLineageRows(d, 'id'));
        if (!forcedRejected.contact) usedWorkflow = setStageBadge('cvNavBadgeContact', getWorkflowStageLabel(d, 'contact')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeContact', getWorkflowLineageRows(d, 'contact'));
        if (!forcedRejected.education) usedWorkflow = setStageBadge('cvNavBadgeEducation', getWorkflowStageLabel(d, 'education')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeEducation', getWorkflowLineageRows(d, 'education'));
        if (!forcedRejected.employment) usedWorkflow = setStageBadge('cvNavBadgeEmployment', getWorkflowStageLabel(d, 'employment')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeEmployment', getWorkflowLineageRows(d, 'employment'));
        if (!forcedRejected.reference) usedWorkflow = setStageBadge('cvNavBadgeReference', getWorkflowStageLabel(d, 'reference')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeReference', getWorkflowLineageRows(d, 'reference'));
        if (!forcedRejected.socialmedia) usedWorkflow = setStageBadge('cvNavBadgeSocialmedia', getWorkflowStageLabel(d, 'socialmedia')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeSocialmedia', getWorkflowLineageRows(d, 'socialmedia'));
        if (!forcedRejected.ecourt) usedWorkflow = setStageBadge('cvNavBadgeEcourt', getWorkflowStageLabel(d, 'ecourt')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeEcourt', getWorkflowLineageRows(d, 'ecourt'));
        if (!forcedRejected.reports) usedWorkflow = setStageBadge('cvNavBadgeReports', getWorkflowStageLabel(d, 'reports')) || usedWorkflow;
        attachBadgeLineage('cvNavBadgeReports', getWorkflowLineageRows(d, 'reports'));

        if (!usedWorkflow) {
            var pendingLabel = (window.WF_UI && typeof window.WF_UI.labelByRole === 'function')
                ? window.WF_UI.labelByRole('pending', role || 'validator')
                : 'Pending';
            setBadge('cvNavBadgeBasic', 'wip', pendingLabel);
            setBadge('cvNavBadgeId', 'wip', pendingLabel);
            setBadge('cvNavBadgeContact', 'wip', pendingLabel);
            setBadge('cvNavBadgeEducation', 'wip', pendingLabel);
            setBadge('cvNavBadgeEmployment', 'wip', pendingLabel);
            setBadge('cvNavBadgeReference', 'wip', pendingLabel);
            setBadge('cvNavBadgeSocialmedia', 'wip', pendingLabel);
            setBadge('cvNavBadgeEcourt', 'wip', pendingLabel);
            setBadge('cvNavBadgeReports', 'wip', pendingLabel);
        }

        // Fallback only when workflow stage label is not available for reports.
        if (!getWorkflowStageLabel(d, 'reports')) {
            if (reportsDone) {
                var workflowMode = String((d && (d.workflow_mode || d.workflowMode || (d.case && d.case.workflow_mode))) || '').toLowerCase().trim();
                var reportsLabel = (role === 'validator' && workflowMode !== 'verifier_first')
                    ? ((window.WF_UI && typeof window.WF_UI.labelByRole === 'function') ? window.WF_UI.labelByRole('pending', 'validator') : 'Pending')
                    : ((window.WF_UI && typeof window.WF_UI.labelByRole === 'function') ? window.WF_UI.labelByRole('completed', role || 'verifier') : 'Completed');
                setBadge('cvNavBadgeReports', (role === 'validator' && workflowMode !== 'verifier_first') ? 'wip' : 'done', reportsLabel);
            } else {
                setBadge('cvNavBadgeReports', 'pending', 'Pending');
            }
        }

        // Keep status badges visible for validator readonly sections.
        // Readonly should affect actions, not visibility semantics.
    }

    function setVal(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        var text = (value === null || typeof value === 'undefined') ? '' : String(value);
        el.value = text;
        if (el.dataset && el.dataset.simpleDone) {
            var displayEl = el.nextElementSibling;
            if (displayEl) displayEl.textContent = text.trim() || '-';
        }
    }

    function setFileField(id, fieldKey, value) {
        var el = document.getElementById(id);
        if (!el) return;
        try {
            var parent = el.parentNode;
            if (parent) {
                var old = parent.querySelectorAll('[data-cr-filefield-for="' + id + '"]');
                old.forEach(function (n) { if (n && n.parentNode) n.parentNode.removeChild(n); });
            }
        } catch (_e) {
        }
        var v = (value === null || typeof value === 'undefined') ? '' : String(value);
        v = v.trim();

        var href = fileUrlForField(fieldKey, v);
        if (href) {
            var wrap = document.createElement('div');
            wrap.setAttribute('data-cr-filefield-for', id);
            wrap.style.display = 'flex';
            wrap.style.alignItems = 'center';
            wrap.style.gap = '10px';

            var a = document.createElement('a');
            a.href = href;
            a.className = 'js-cv-doc-view';
            a.setAttribute('data-doc-label', v);
            a.textContent = 'View';
            a.style.textDecoration = 'none';
            a.style.color = '#2563eb';
            a.style.fontWeight = '700';

            var small = document.createElement('div');
            small.textContent = v;
            small.style.color = '#64748b';
            small.style.fontSize = '12px';
            small.style.overflow = 'hidden';
            small.style.textOverflow = 'ellipsis';
            small.style.whiteSpace = 'nowrap';

            wrap.appendChild(a);
            wrap.appendChild(small);

            el.value = '';
            el.style.display = 'none';
            el.insertAdjacentElement('afterend', wrap);
            return;
        }

        el.value = v;
    }

    function simplifyReadonlyField(id) {
        var role = getRole();
        var isPrint = String(qs('print') || '') === '1';
        if (isPrint) return;
        if (!(role === 'verifier' || role === 'validator' || role === 'db_verifier')) return;

        var el = document.getElementById(id);
        if (!el) return;
        if (el.dataset.simpleDone) return;

        // If this field was already converted into a link (setFileField) we should not overwrite it.
        try {
            var cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
            if (cs && cs.display === 'none') return;
        } catch (_e) {
        }

        var tag = String(el.tagName || '').toLowerCase();
        if (!(tag === 'input' || tag === 'select' || tag === 'textarea')) return;

        var value = '';
        try {
            if (tag === 'select') {
                var opt = el.options && el.selectedIndex >= 0 ? el.options[el.selectedIndex] : null;
                value = opt ? String(opt.textContent || opt.value || '') : String(el.value || '');
            } else {
                value = String(el.value || '');
            }
        } catch (_e) {
            value = String(el.value || '');
        }
        value = value.trim();

        var textEl = document.createElement('div');
        textEl.textContent = value || '-';
        textEl.style.padding = '6px 0';
        textEl.style.fontWeight = '800';
        textEl.style.color = '#0f172a';
        textEl.style.display = 'block';
        textEl.style.width = '100%';
        textEl.style.whiteSpace = 'normal';
        textEl.style.overflowWrap = 'anywhere';
        textEl.style.wordBreak = 'break-word';

        el.style.display = 'none';
        el.insertAdjacentElement('afterend', textEl);
        el.dataset.simpleDone = '1';
    }

    function setCandidateAvatar(basic, cs) {
        var host = document.getElementById('cvHeaderAvatar');
        var fallback = document.getElementById('cvHeaderAvatarFallback');
        if (!host) return;

        var first = String((basic && basic.first_name) || (cs && cs.candidate_first_name) || '').trim();
        var last = String((basic && basic.last_name) || (cs && cs.candidate_last_name) || '').trim();
        var initials = ((first ? first.charAt(0) : '') + (last ? last.charAt(0) : '')).toUpperCase() || 'NA';

        if (fallback) fallback.textContent = initials;
        host.innerHTML = fallback ? fallback.outerHTML : ('<span class="cr-avatar-fallback">' + initials + '</span>');

        var photoRaw = String((basic && (basic.photo_path || basic.photo || basic.profile_photo || basic.candidate_photo)) || '').trim();
        if (!photoRaw) return;

        var src = fileUrlForField('photo_path', photoRaw) || photoRaw;
        if (!src) return;

        var img = document.createElement('img');
        img.alt = 'Candidate photo';
        img.src = src;
        img.onerror = function () {};
        host.innerHTML = '';
        host.appendChild(img);
    }

    function simplifyAllReadonlyFields() {
        var role = getRole();
        if (!(role === 'verifier' || role === 'validator' || role === 'db_verifier')) return;
        var isPrint = String(qs('print') || '') === '1';
        if (isPrint) return;

        [
            'cv_basic_first_name',
            'cv_basic_last_name',
            'cv_basic_dob',
            'cv_basic_mobile',
            'cv_basic_email',
            'cv_basic_gender',
            'cv_basic_father_name',
            'cv_basic_mother_name',
            'cv_basic_country',
            'cv_basic_state',
            'cv_basic_nationality',
            'cv_basic_marital_status',

            'cv_contact_current_address',
            'cv_contact_permanent_address',
            'cv_contact_proof_type',

            'cv_reference_name',
            'cv_reference_designation',
            'cv_reference_company',
            'cv_reference_mobile',
            'cv_reference_email',
            'cv_reference_relationship',
            'cv_reference_years_known',

            'cv_social_linkedin_url',
            'cv_social_facebook_url',
            'cv_social_instagram_url',
            'cv_social_twitter_url',
            'cv_social_other_url',
            'cv_social_consent_bgv',
            'cv_social_content',

            'cv_ecourt_current_address',
            'cv_ecourt_permanent_address',
            'cv_ecourt_evidence_document',
            'cv_ecourt_period_from_date',
            'cv_ecourt_period_to_date',
            'cv_ecourt_period_duration_years',
            'cv_ecourt_dob',
            'cv_ecourt_comments',

            'cv_app_submitted_at',
            'cv_auth_signature',
            'cv_auth_file_name',
            'cv_auth_uploaded_at'
        ].forEach(function (id) {
            simplifyReadonlyField(id);
        });
    }

    function setText(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        if (id === 'cvTopMessage') {
            var msg = (value === null || typeof value === 'undefined') ? '' : String(value);
            if (msg.trim()) showCrToast(msg, 'info');
            el.style.display = 'none';
            el.textContent = '';
            el.className = '';
            return;
        }
        el.textContent = (value === null || typeof value === 'undefined') ? '' : String(value);
    }

    function ymd(dt) {
        try {
            if (!(dt instanceof Date) || isNaN(dt.getTime())) return '';
            var y = dt.getFullYear();
            var m = String(dt.getMonth() + 1).padStart(2, '0');
            var d = String(dt.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + d;
        } catch (e) {
            return '';
        }
    }

    function isWeekend(dt) {
        try {
            var day = dt.getDay();
            return day === 0 || day === 6;
        } catch (e) {
            return false;
        }
    }

    function businessDaysPassed(startDt, endDt, weekendRules) {
        if (!startDt || !endDt) return 0;
        var include = String(weekendRules || '').toLowerCase().trim() === 'include';
        if (include) {
            var ms = endDt.getTime() - startDt.getTime();
            var daysPassed = Math.floor(ms / 86400000);
            return isFinite(daysPassed) ? Math.max(0, daysPassed) : 0;
        }

        var s = new Date(startDt.getFullYear(), startDt.getMonth(), startDt.getDate());
        var e = new Date(endDt.getFullYear(), endDt.getMonth(), endDt.getDate());
        if (e.getTime() < s.getTime()) return 0;

        var count = 0;
        var cur = new Date(s.getTime());
        cur.setDate(cur.getDate() + 1);
        while (cur.getTime() <= e.getTime()) {
            var key = ymd(cur);
            var isHol = !!(key && HOLIDAY_SET[key]);
            if (!isWeekend(cur) && !isHol) count++;
            cur.setDate(cur.getDate() + 1);
        }
        return count;
    }

    function loadHolidaysOnce() {
        if (HOLIDAYS_LOADED) return Promise.resolve();
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/holidays_list.php';
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                HOLIDAYS_LOADED = true;
                HOLIDAY_SET = {};
                if (!data || data.status !== 1 || !Array.isArray(data.data)) return;
                data.data.forEach(function (h) {
                    var d = h && h.holiday_date ? String(h.holiday_date).slice(0, 10) : '';
                    var on = h && typeof h.is_active !== 'undefined' ? (parseInt(h.is_active || '1', 10) || 1) : 1;
                    if (d && on === 1) HOLIDAY_SET[d] = true;
                });
            })
            .catch(function () {
                HOLIDAYS_LOADED = true;
                HOLIDAY_SET = {};
            });
    }

    function tatLabelFromCreated(createdAt, tatDays) {
        var weekendRules = 'exclude';
        if (tatDays && typeof tatDays === 'object') {
            weekendRules = tatDays.weekend_rules || 'exclude';
            tatDays = parseInt(tatDays.internal_tat || '20', 10) || 20;
        } else {
            tatDays = parseInt(tatDays, 10);
            if (!isFinite(tatDays) || tatDays <= 0) tatDays = 20;
        }

        var dt = null;
        try {
            dt = createdAt ? new Date(createdAt) : null;
            if (!dt || isNaN(dt.getTime())) dt = null;
        } catch (e) {
            dt = null;
        }

        if (!dt) return '-';
        var now = new Date();
        var daysPassed = businessDaysPassed(dt, now, weekendRules);
        var remaining = tatDays - daysPassed;
        if (remaining >= 0) return remaining + ' day(s) remaining';
        return 'Overdue ' + Math.abs(remaining) + ' day(s)';
    }

    function renderTable(hostId, rows, columns) {
        var host = document.getElementById(hostId);
        if (!host) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No data available.</div>';
            return;
        }

        rows = rows.map(window.GSS_DATE.formatRowDates);
        host.innerHTML = rows.map(function (r, idx) {
            var body = columns.map(function (c) {
                var key = c && c.key ? String(c.key) : '';
                var label = c && c.label ? String(c.label) : key;
                var v = r ? r[key] : '';
                var forceText = !!(c && c.forceText);
                var href = forceText ? '' : fileUrlForField(key, v);
                var safeVal = (v === null || typeof v === 'undefined' || String(v).trim() === '') ? '-' : String(v);
                var valHtml = href ? fileCellHtml(key, v) : ('<span style="font-weight:800; color:#0f172a; white-space:normal; overflow-wrap:break-word; word-break:normal;">' + esc(safeVal) + '</span>');
                return '<div class="cr-kv2-cell">' +
                    '<div class="cr-kv2-k">' + esc(label) + '</div>' +
                    '<div class="cr-kv2-v">' + valHtml + '</div>' +
                '</div>';
            }).join('');

            return '<div class="cr-kv2-wrap">' +
                '<div style="font-size:12px; font-weight:950; color:#0f172a; margin-bottom:4px;">Item ' + esc(String(idx + 1)) + '</div>' +
                '<div class="cr-kv2-grid">' + body + '</div>' +
            '</div>';
        }).join('');
    }

    function renderTabbedTable(hostId, rows, columns, tabPrefix) {
        var host = document.getElementById(hostId);
        if (!host) return;
        var sectionKey = sectionKeyForTableHost(hostId);

        if (!Array.isArray(rows) || rows.length === 0) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No data available.</div>';
            return;
        }

        rows = rows.map(window.GSS_DATE.formatRowDates);

        if (rows.length === 1) {
            renderTable(hostId, rows, columns);
            return;
        }

        function setActiveRecordTab(idx) {
            var max = rows.length - 1;
            var n = parseInt(String(idx || '0'), 10);
            if (!isFinite(n) || n < 0) n = 0;
            if (n > max) n = 0;
            var key = String(n);
            host.dataset.activeRecordTab = key;
            var currentItemKey = '';

            Array.prototype.slice.call(host.querySelectorAll('[data-record-tab]')).forEach(function (el) {
                var on = String(el.getAttribute('data-record-tab') || '') === key;
                el.classList.toggle('active', on);
                el.setAttribute('aria-selected', on ? 'true' : 'false');
                if (on) {
                    currentItemKey = String(el.getAttribute('data-record-item-key') || '');
                }
            });

            Array.prototype.slice.call(host.querySelectorAll('[data-record-panel]')).forEach(function (el) {
                var on = String(el.getAttribute('data-record-panel') || '') === key;
                el.classList.toggle('active', on);
                // Force deterministic visibility even if external CSS/classes interfere.
                el.style.display = on ? 'block' : 'none';
                el.setAttribute('aria-hidden', on ? 'false' : 'true');
            });

            if (currentItemKey) {
                host.dataset.activeRecordItemKey = currentItemKey;
                if (sectionKey) {
                    ACTIVE_ITEM_BY_SECTION[sectionKey] = currentItemKey;
                }
            } else {
                host.dataset.activeRecordItemKey = '';
                if (sectionKey) {
                    delete ACTIVE_ITEM_BY_SECTION[sectionKey];
                }
            }

            try {
                document.dispatchEvent(new CustomEvent('cv:record-tab-changed', {
                    detail: { section: sectionKey, hostId: hostId, index: n, item_key: host.dataset.activeRecordItemKey || '' }
                }));
            } catch (_e) {
            }
        }

        host.setAttribute('data-record-section', sectionKey || '');
        host.classList.add('cr-record-host');

        function buildRecordSummary(row) {
            var summaryFields = [];
            (Array.isArray(columns) ? columns : []).forEach(function (c) {
                var key = c && c.key ? String(c.key) : '';
                var label = c && c.label ? String(c.label) : key;
                if (!key || summaryFields.length >= 2) return;
                var raw = row ? row[key] : '';
                var text = String(raw == null ? '' : raw).trim();
                if (!text || text === '-' || fileUrlForField(key, raw)) return;
                summaryFields.push({
                    label: label,
                    value: text
                });
            });
            return summaryFields;
        }

        var tabsHtml = rows.map(function (_r, idx) {
            var itemKey = deriveRecordItemKey(sectionKey, _r || {}, idx);
            return '<button type="button" class="cr-record-tab' + (idx === 0 ? ' active' : '') + '" data-record-tab="' + esc(String(idx)) + '" data-record-item-key="' + esc(itemKey) + '">' +
                esc((tabPrefix || 'Item') + ' ' + String(idx + 1)) +
            '</button>';
        }).join('');

        var panelsHtml = rows.map(function (r, idx) {
            var body = columns.map(function (c) {
                var key = c && c.key ? String(c.key) : '';
                var label = c && c.label ? String(c.label) : key;
                var v = r ? r[key] : '';
                var href = fileUrlForField(key, v);
                var safeVal = (v === null || typeof v === 'undefined' || String(v).trim() === '') ? '-' : String(v);
                var valHtml = href ? fileCellHtml(key, v) : ('<span style="font-weight:800; color:#0f172a; white-space:normal; overflow-wrap:break-word; word-break:normal;">' + esc(safeVal) + '</span>');
                return '<div class="cr-kv2-cell">' +
                    '<div class="cr-kv2-k">' + esc(label) + '</div>' +
                    '<div class="cr-kv2-v">' + valHtml + '</div>' +
                '</div>';
            }).join('');
            var summary = buildRecordSummary(r).map(function (item) {
                return '<span class="cr-record-chip"><b>' + esc(item.label) + ':</b> ' + esc(item.value) + '</span>';
            }).join('');

            return '<div class="cr-record-panel' + (idx === 0 ? ' active' : '') + '" data-record-panel="' + esc(String(idx)) + '">' +
                '<div class="cr-kv2-wrap">' +
                    '<div class="cr-record-panel-head">' +
                        '<div class="cr-record-panel-title">' + esc((tabPrefix || 'Item') + ' ' + String(idx + 1)) + '</div>' +
                        (summary ? ('<div class="cr-record-panel-meta">' + summary + '</div>') : '') +
                    '</div>' +
                    '<div class="cr-kv2-grid">' + body + '</div>' +
                '</div>' +
            '</div>';
        }).join('');

        host.innerHTML = '<div class="cr-record-tabs">' + tabsHtml + '</div>' + panelsHtml;
        setActiveRecordTab(host.dataset.activeRecordTab || '0');

        if (!host.dataset.tabsBound) {
            host.dataset.tabsBound = '1';
            host.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var btn = t && t.closest ? t.closest('[data-record-tab]') : null;
                if (!btn) return;

                var idx = String(btn.getAttribute('data-record-tab') || '0');
                setActiveRecordTab(idx);
            });
        }
    }

    function resetComponentTableRenderState(d) {
        COMPONENT_TABLE_ROWS = {
            id: Array.isArray(d && d.identification) ? d.identification : [],
            education: Array.isArray(d && d.education) ? d.education : [],
            employment: Array.isArray(d && d.employment) ? d.employment : []
        };
        COMPONENT_TABLE_RENDERED = { id: false, education: false, employment: false };
    }

    function ensureComponentTableRendered(section) {
        var s = normSection(section);
        if (s !== 'id' && s !== 'education' && s !== 'employment') return;
        if (COMPONENT_TABLE_RENDERED[s]) return;

        if (s === 'id') {
            renderTabbedTable('cv_identification_table', COMPONENT_TABLE_ROWS.id || [], [
                { key: 'documentId_type', label: 'Document Type' },
                { key: 'id_number', label: 'ID Number' },
                { key: 'name', label: 'Name on ID' },
                { key: 'upload_document', label: 'Uploaded File' }
            ], 'ID');
        } else if (s === 'education') {
            renderTabbedTable('cv_education_table', COMPONENT_TABLE_ROWS.education || [], [
                { key: 'qualification', label: 'Qualification' },
                { key: 'college_name', label: 'College' },
                { key: 'university_board', label: 'University/Board' },
                { key: 'year_from', label: 'From' },
                { key: 'year_to', label: 'To' },
                { key: 'roll_number', label: 'Roll No' },
                { key: 'marksheet_file', label: 'Marksheet' },
                { key: 'degree_file', label: 'Degree' }
            ], 'Education');
        } else if (s === 'employment') {
            renderTabbedTable('cv_employment_table', COMPONENT_TABLE_ROWS.employment || [], [
                { key: 'employer_name', label: 'Employer' },
                { key: 'job_title', label: 'Job Title' },
                { key: 'employee_id', label: 'Employee ID' },
                { key: 'joining_date', label: 'Joining' },
                { key: 'relieving_date', label: 'Relieving' },
                { key: 'currently_employed', label: 'Currently Employed' },
                { key: 'contact_employer', label: 'Contact Employer' },
                { key: 'employment_doc', label: 'Document' }
            ], 'Employment');
        }

        COMPONENT_TABLE_RENDERED[s] = true;
    }

    function toTitle(key) {
        var s = String(key || '');
        if (!s) return '';
        return s
            .replace(/_/g, ' ')
            .replace(/\s+/g, ' ')
            .trim()
            .replace(/\b\w/g, function (m) { return m.toUpperCase(); });
    }

    function isPlainObject(v) {
        return v && typeof v === 'object' && !Array.isArray(v);
    }

    function renderKeyValue(hostId, title, obj) {
        var host = document.getElementById(hostId);
        if (!host) return;
        if (!isPlainObject(obj)) return;

        var keys = Object.keys(obj);
        if (!keys.length) return;

        var rows = keys.map(function (k) {
            var v = obj[k];
            if (v === null || typeof v === 'undefined') v = '';
            if (typeof v === 'object') {
                try { v = JSON.stringify(v); } catch (_e) { v = String(v); }
            }
            return '<tr><th style="width:240px;">' + esc(toTitle(k)) + '</th><td>' + esc(String(v)) + '</td></tr>';
        }).join('');

        host.insertAdjacentHTML('beforeend',
            '<div style="margin-bottom:12px;">' +
                '<div style="font-weight:700; margin:6px 0; font-size:13px;">' + esc(title) + '</div>' +
                '<div class="table-scroll"><table class="table"><tbody>' + rows + '</tbody></table></div>' +
            '</div>'
        );
    }

    function renderArray(hostId, title, list) {
        var host = document.getElementById(hostId);
        if (!host) return;
        if (!Array.isArray(list) || !list.length) return;

        var keys = [];
        list.forEach(function (row) {
            if (!isPlainObject(row)) return;
            Object.keys(row).forEach(function (k) {
                if (keys.indexOf(k) === -1) keys.push(k);
            });
        });

        if (!keys.length) return;

        var thead = '<tr>' + keys.map(function (k) { return '<th>' + esc(toTitle(k)) + '</th>'; }).join('') + '</tr>';
        var tbody = list.map(function (row) {
            return '<tr>' + keys.map(function (k) {
                var v = row && typeof row === 'object' ? row[k] : '';
                if (v === null || typeof v === 'undefined') v = '';
                if (typeof v === 'object') {
                    try { v = JSON.stringify(v); } catch (_e) { v = String(v); }
                }
                return '<td>' + esc(String(v)) + '</td>';
            }).join('') + '</tr>';
        }).join('');

        host.insertAdjacentHTML('beforeend',
            '<div style="margin-bottom:12px;">' +
                '<div style="font-weight:700; margin:6px 0; font-size:13px;">' + esc(title) + '</div>' +
                '<div class="table-scroll"><table class="table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>' +
            '</div>'
        );
    }

    function renderDocsForPrint(hostId, rows) {
        var host = document.getElementById(hostId);
        if (!host) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
            return;
        }

        rows = rows.map(formatRowDates);

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

        host.innerHTML = '<div class="table-scroll"><table class="table">' +
            '<thead><tr><th>Type</th><th>File</th><th>Uploaded By</th><th>Created</th></tr></thead>' +
            '<tbody>' + rows.map(function (r) {
                var href = r && r.file_path ? (base + String(r.file_path)) : '#';
                var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : '';
                return '<tr>' +
                    '<td>' + esc(r.doc_type || '') + '</td>' +
                    '<td><a href="' + esc(href) + '" target="_blank" style="text-decoration:none; color:#2563eb;">' + esc(label) + '</a></td>' +
                    '<td>' + esc(r.uploaded_by_role || '') + '</td>' +
                    '<td>' + esc(r.created_at || '') + '</td>' +
                '</tr>';
            }).join('') +
            '</tbody></table></div>';
    }

    function setHtml(id, html) {
        var el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = html || '';
    }

    function kvBox(label, value) {
        return '<div class="cr-pdf-kv"><div class="k">' + esc(label) + '</div><div class="v">' + esc(value) + '</div></div>';
    }

    function computeExecutiveSummary(d) {
        var sections = [
            { key: 'basic', label: 'Basic Details' },
            { key: 'identification', label: 'Identification' },
            { key: 'contact', label: 'Contact Information' },
            { key: 'education', label: 'Education Details' },
            { key: 'employment', label: 'Employment Details' },
            { key: 'reference', label: 'Reference' },
            { key: 'authorization', label: 'Authorization' },
            { key: 'docs', label: 'Uploaded Documents' }
        ];

        function statusFor(key) {
            if (key === 'docs') {
                var docs = d.uploaded_docs || [];
                return (Array.isArray(docs) && docs.length) ? 'Available' : 'Not Available';
            }
            var v = d[key];
            if (Array.isArray(v)) return v.length ? 'Available' : 'Not Available';
            if (v && typeof v === 'object') return Object.keys(v).length ? 'Available' : 'Not Available';
            return v ? 'Available' : 'Not Available';
        }

        return sections.map(function (s) {
            return { section: s.label, status: statusFor(s.key) };
        });
    }

    function renderExecutive(hostId, d) {
        var host = document.getElementById(hostId);
        if (!host) return;

        var rows = computeExecutiveSummary(d);
        var thead = '<tr><th>Section</th><th>Status</th></tr>';
        var tbody = rows.map(function (r) {
            return '<tr><td>' + esc(r.section) + '</td><td>' + esc(r.status) + '</td></tr>';
        }).join('');
        host.innerHTML = '<div class="table-scroll"><table class="table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';
    }

    function renderChecklist(hostId, docs) {
        var host = document.getElementById(hostId);
        if (!host) return;

        var rows = Array.isArray(docs) ? docs.slice() : [];
        if (!rows.length) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No documents uploaded.</div>';
            return;
        }

        var thead = '<tr><th>Document Type</th><th>File</th><th>Uploaded By</th><th>Created</th></tr>';
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var tbody = rows.map(function (r) {
            var href = r && r.file_path ? (base + String(r.file_path)) : '#';
            var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : '';
            return '<tr>' +
                '<td>' + esc(r.doc_type || '') + '</td>' +
                '<td><a href="' + esc(href) + '" target="_blank" style="text-decoration:none; color:#2563eb;">' + esc(label) + '</a></td>' +
                '<td>' + esc(r.uploaded_by_role || '') + '</td>' +
                '<td>' + esc(r.created_at || '') + '</td>' +
            '</tr>';
        }).join('');
        host.innerHTML = '<div class="table-scroll"><table class="table"><thead>' + thead + '</thead><tbody>' + tbody + '</tbody></table></div>';
    }

    function isImageMime(m) {
        var v = String(m || '').toLowerCase();
        return v.indexOf('image/') === 0;
    }

    function renderDocsGrouped(hostId, rows) {
        var host = document.getElementById(hostId);
        if (!host) return;

        var list = Array.isArray(rows) ? rows : [];
        if (!list.length) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
            return;
        }

        list = list.map(window.GSS_DATE.formatRowDates);

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        host.innerHTML = list.map(function (r) {
            var href = r && r.file_path ? (base + String(r.file_path)) : '#';
            var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : '';
            var by = (r && r.uploaded_by_role) ? String(r.uploaded_by_role) : '';
            var dt = (r && r.doc_type) ? String(r.doc_type) : '';
            var created = (r && r.created_at) ? String(r.created_at) : '';
            var thumb = '';
            if (href !== '#' && isImageMime(r && r.mime_type)) {
                thumb = '<div class="cr-pdf-thumb"><img src="' + esc(href) + '" alt="' + esc(label) + '"></div>';
            }
            return '<div class="cr-pdf-doc">' +
                '<h4>' + esc(dt || 'Document') + '</h4>' +
                '<small><b>File:</b> <a href="' + esc(href) + '" target="_blank" style="text-decoration:none; color:#2563eb;">' + esc(label) + '</a></small>' +
                '<small><b>Uploaded By:</b> ' + esc(by) + '</small>' +
                '<small><b>Created:</b> ' + esc(created) + '</small>' +
                thumb +
            '</div>';
        }).join('');
    }

    function setBoxMessage(id, text, type) {
        var el = document.getElementById(id);
        if (!el) return;
        if (id === 'cvTopMessage') {
            var msg = String(text || '').trim();
            if (msg) showCrToast(msg, type || 'info');
            el.style.display = 'none';
            el.textContent = '';
            el.className = '';
            return;
        }
        el.textContent = text || '';
        el.className = type ? ('alert alert-' + type) : '';
        el.style.display = text ? 'block' : 'none';
    }

    async function postJson(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        var payload = await res.json().catch(function () { return null; });
        return { res: res, payload: payload };
    }

    function setActionsDisabled(disabled) {
        ['cvActionInsufficient', 'cvActionHold', 'cvActionReject', 'cvActionStopBgv', 'cvActionApprove', 'cvValidatorActionInsufficient', 'cvValidatorActionHold', 'cvValidatorActionReject', 'cvValidatorActionApprove'].forEach(function (id) {
            var b = document.getElementById(id);
            if (!b) return;
            b.disabled = !!disabled;
        });
        try {
            var proxyBtns = document.querySelectorAll('.cr-sec-action[data-proxy-action]');
            proxyBtns.forEach(function (b) { b.disabled = !!disabled; });
        } catch (_e) {
        }
    }

    function canTakeCaseAction() {
        var perms = REPORT_PAYLOAD && REPORT_PAYLOAD.permissions ? REPORT_PAYLOAD.permissions : null;
        if (perms && Object.prototype.hasOwnProperty.call(perms, 'can_take_action')) {
            return !!perms.can_take_action;
        }
        return true;
    }

    function applyCaseActionCardVisibility() {
        var card = document.getElementById('cvCaseActionsCard');
        if (!card) return;
        card.style.display = canTakeCaseAction() ? '' : 'none';
    }

    function initCaseActions(applicationId) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/case_action.php';
        var compUrl = base + '/api/shared/component_action.php';
        var actionInFlight = false;
        var lastActionFingerprint = '';
        var lastActionTs = 0;

        function roleToStage(role) {
            role = String(role || '').toLowerCase().trim();
            if (role === 'validator') return 'validator';
            if (role === 'qa' || role === 'team_lead') return 'qa';
            if (role === 'verifier' || role === 'db_verifier') return 'verifier';
            return role;
        }

        function getAssignedRowForComponent(d, componentKey) {
            try {
                componentKey = normSection(componentKey);
                if (!componentKey) return null;
                var list = Array.isArray(d && d.assigned_components) ? d.assigned_components : [];
                for (var i = 0; i < list.length; i++) {
                    var r = list[i] || {};
                    var k = r.component_key ? normSection(r.component_key) : '';
                    if (k === componentKey) return r;
                }
                return null;
            } catch (e) {
                return null;
            }
        }

        function getItemStageStatusFor(componentKey, itemKey, stage) {
            try {
                componentKey = normSection(componentKey);
                itemKey = String(itemKey || '').toLowerCase().trim();
                stage = String(stage || '').toLowerCase().trim();
                if (!componentKey || !itemKey || !stage) return '';
                var d = REPORT_PAYLOAD || {};
                var byComp = d.component_item_workflow && typeof d.component_item_workflow === 'object'
                    ? d.component_item_workflow
                    : (d.componentItemWorkflow && typeof d.componentItemWorkflow === 'object' ? d.componentItemWorkflow : null);
                if (!byComp || typeof byComp !== 'object') return '';

                var compRow = byComp[componentKey] && typeof byComp[componentKey] === 'object' ? byComp[componentKey] : null;
                if (!compRow) {
                    var keys = Object.keys(byComp);
                    for (var i = 0; i < keys.length; i++) {
                        if (normSection(keys[i]) === componentKey) {
                            compRow = byComp[keys[i]];
                            break;
                        }
                    }
                }
                if (!compRow || typeof compRow !== 'object') return '';

                var itemRow = compRow[itemKey] && typeof compRow[itemKey] === 'object' ? compRow[itemKey] : null;
                if (!itemRow) {
                    var itemKeys = Object.keys(compRow);
                    for (var j = 0; j < itemKeys.length; j++) {
                        if (String(itemKeys[j]).toLowerCase().trim() === itemKey) {
                            itemRow = compRow[itemKeys[j]];
                            break;
                        }
                    }
                }
                if (!itemRow || typeof itemRow !== 'object') return '';
                if (!itemRow[stage] || typeof itemRow[stage] !== 'object') return '';
                return String(itemRow[stage].status || '').toLowerCase().trim();
            } catch (e) {
                return '';
            }
        }

        function getStageStatusFor(componentKey, stage, itemKey) {
            try {
                componentKey = normSection(componentKey);
                stage = String(stage || '').toLowerCase().trim();
                if (!componentKey || !stage) return '';

                var scopedItemKey = String(itemKey || '').toLowerCase().trim();
                if (scopedItemKey) {
                    var itemScoped = getItemStageStatusFor(componentKey, scopedItemKey, stage);
                    if (itemScoped) return itemScoped;
                }

                var d = REPORT_PAYLOAD || {};
                var byComp = getWorkflowComponentRow(d, componentKey);
                if (byComp && byComp[stage] && typeof byComp[stage] === 'object') {
                    var s1 = String(byComp[stage].status || '').toLowerCase().trim();
                    if (s1) return s1;
                }

                var r = getAssignedRowForComponent(d, componentKey);
                if (r && r.workflow && typeof r.workflow === 'object') {
                    return String(r.workflow[stage] || '').toLowerCase().trim();
                }
                return '';
            } catch (e) {
                return '';
            }
        }

        function setComponentActionButtonsEnabled(enabled) {
            ['cvActionInsufficient', 'cvActionHold', 'cvActionReject', 'cvActionApprove', 'cvValidatorActionInsufficient', 'cvValidatorActionHold', 'cvValidatorActionReject', 'cvValidatorActionApprove'].forEach(function (id) {
                var b = document.getElementById(id);
                if (!b) return;
                b.disabled = !enabled;
            });
        }

        function normalizeStageStatus(status) {
            var s = String(status || '').toLowerCase().trim();
            if (!s || s === 'in_progress' || s === 'in-progress' || s === 'submitted') return 'pending';
            return s;
        }

        var WORKFLOW_ACTION_RULES = {
            pending: ['hold', 'insufficient_documents', 'reject', 'approve'],
            correction_submitted: ['hold', 'insufficient_documents', 'reject', 'approve'],
            hold: ['approve', 'reject', 'insufficient_documents'],
            insufficient_documents: ['approve', 'hold', 'reject', 'insufficient_documents'],
            approved: ['hold', 'insufficient_documents', 'reject'],
            rejected: ['hold', 'insufficient_documents', 'approve'],
            waiting_candidate: ['hold', 'insufficient_documents', 'reject', 'approve'],
            blocked: ['hold', 'insufficient_documents', 'reject', 'approve'],
            reopened: ['hold', 'insufficient_documents', 'reject', 'approve'],
            invalidated_by_validator_reopen: ['hold', 'insufficient_documents', 'reject', 'approve'],
            invalidated_by_verifier_reopen: ['hold', 'insufficient_documents', 'reject', 'approve'],
            completed: ['hold', 'insufficient_documents', 'reject', 'approve'],
            clear: ['hold', 'insufficient_documents', 'reject', 'approve'],
            verified: ['hold', 'insufficient_documents', 'reject', 'approve'],
            stop_bgv: [],
            archived: [],
            terminated: []
        };

        function isEvaluatedWorkflowStatus(status) {
            var s = normalizeStageStatus(status);
            return s === 'approved' || s === 'rejected' || s === 'hold' || s === 'insufficient_documents' || s === 'completed' || s === 'clear' || s === 'verified';
        }

        function supervisoryReopenTargetFor(componentKey, actorStage) {
            return '';
        }

        function componentWorkflowStageMeta(componentKey, stageKey) {
            try {
                var d = REPORT_PAYLOAD || {};
                var row = getWorkflowComponentRow(d, componentKey);
                if (!row || typeof row !== 'object') return null;
                var meta = row[String(stageKey || '').toLowerCase().trim()] || null;
                return (meta && typeof meta === 'object') ? meta : null;
            } catch (_e) {
                return null;
            }
        }

        function lockMetaTextForComponent(componentKey, stageNow, stageStatus) {
            componentKey = normSection(componentKey);
            if (!componentKey) return '';
            var meta = componentWorkflowStageMeta(componentKey, stageNow);
            if (stageStatus === 'invalidated_by_validator_reopen' || stageStatus === 'invalidated_by_verifier_reopen') {
                var invalidatedBy = String((meta && meta.invalidated_by_role) || '').trim();
                var invalidatedAt = String((meta && meta.invalidated_at) || '').trim();
                var invalidatedReason = String((meta && meta.invalidation_reason) || '').trim();
                var invalidatedSource = String((meta && meta.invalidated_source_stage) || '').trim();
                var invalidTxt = 'Invalidated';
                if (invalidatedSource) invalidTxt += ' after ' + invalidatedSource.toUpperCase() + ' decision change';
                if (invalidatedBy) invalidTxt += ' via ' + invalidatedBy.toUpperCase();
                if (invalidatedAt) invalidTxt += ' at ' + invalidatedAt;
                if (invalidatedReason) invalidTxt += ' - ' + invalidatedReason;
                return invalidTxt;
            }
            if (normalizeStageStatus(stageStatus) === 'reopened') {
                var reopenedBy = String((meta && meta.reopened_by_role) || '').trim();
                var reopenedAt = String((meta && meta.reopened_at) || '').trim();
                var reason = String((meta && meta.reopen_reason) || '').trim();
                var txt = 'Decision update in progress';
                if (reopenedBy) txt += ' by ' + reopenedBy.toUpperCase();
                if (reopenedAt) txt += ' at ' + reopenedAt;
                if (reason) txt += ' - ' + reason;
                return txt;
            }
            if (meta) {
                var relockedAt = String(meta.relocked_at || '').trim();
                if (relockedAt && isEvaluatedWorkflowStatus(stageStatus)) {
                    return 'Decision finalized at ' + relockedAt;
                }
            }
            return '';
        }

        function applySectionLockHint(sectionEl, hintText, kind) {
            try {
                if (!sectionEl) return;
                var titleBlock = sectionEl.querySelector('.cr-secbar-titleblock');
                if (!titleBlock) return;
                var host = titleBlock.querySelector('.cr-lock-state-hint');
                if (!hintText) {
                    if (host && host.parentNode) host.parentNode.removeChild(host);
                    return;
                }
                if (!host) {
                    host = document.createElement('div');
                    host.className = 'cr-lock-state-hint';
                    titleBlock.appendChild(host);
                }
                host.classList.remove('is-locked', 'is-reopened', 'is-relocked');
                host.classList.add(kind === 'reopened' ? 'is-reopened' : (kind === 'relocked' ? 'is-relocked' : 'is-locked'));
                host.textContent = hintText;
            } catch (_e) {}
        }

        function allowedActionsForStageStatus(status) {
            var s = normalizeStageStatus(status);
            if (Object.prototype.hasOwnProperty.call(WORKFLOW_ACTION_RULES, s)) {
                return WORKFLOW_ACTION_RULES[s].slice();
            }
            return WORKFLOW_ACTION_RULES.pending.slice();
        }

        function setComponentActionAvailabilityForStatus(status) {
            var allowed = allowedActionsForStageStatus(status);
            var map = [
                { id: 'cvActionInsufficient', action: 'insufficient_documents' },
                { id: 'cvActionHold', action: 'hold' },
                { id: 'cvActionReject', action: 'reject' },
                { id: 'cvActionApprove', action: 'approve' },
                { id: 'cvValidatorActionInsufficient', action: 'insufficient_documents' },
                { id: 'cvValidatorActionHold', action: 'hold' },
                { id: 'cvValidatorActionReject', action: 'reject' },
                { id: 'cvValidatorActionApprove', action: 'approve' }
            ];
            map.forEach(function (it) {
                var b = document.getElementById(it.id);
                if (!b) return;
                b.disabled = allowed.indexOf(it.action) === -1;
            });
        }

        function syncSectionActionVisibility() {
            try {
                var navBadgeMap = {
                    basic: 'cvNavBadgeBasic',
                    id: 'cvNavBadgeId',
                    contact: 'cvNavBadgeContact',
                    education: 'cvNavBadgeEducation',
                    employment: 'cvNavBadgeEmployment',
                    reference: 'cvNavBadgeReference',
                    socialmedia: 'cvNavBadgeSocialmedia',
                    ecourt: 'cvNavBadgeEcourt',
                    reports: 'cvNavBadgeReports'
                };
                var sections = document.querySelectorAll('.candidate-section[id^="section-"]');
                if (!sections || !sections.length) return;
                sections.forEach(function (sectionEl) {
                    var sid = String(sectionEl.id || '').replace(/^section-/, '');
                    var key = normSection(sid);
                    var activeIdentity = currentSectionKey();
                    if (key === 'reference' && (activeIdentity === 'education_reference' || activeIdentity === 'employment_reference')) {
                        key = activeIdentity;
                    }
                    var showActions = isActionableComponent(key);
                    var isReadonly = (getRole() === 'validator' && !showActions);
                    var roleNow = getRole();
                    var stageNow = roleToStage(roleNow);
                    var stageStatus = getStageStatusFor(key, stageNow, '');
                    var allowed = allowedActionsForStageStatus(stageStatus);
                    var isInvalidatedStage = (stageStatus === 'invalidated_by_validator_reopen' || stageStatus === 'invalidated_by_verifier_reopen');
                    var lockHint = lockMetaTextForComponent(key, stageNow, stageStatus);
                    applySectionLockHint(
                        sectionEl,
                        lockHint,
                        isInvalidatedStage
                            ? 'relocked'
                            : ((normalizeStageStatus(stageStatus) === 'reopened')
                                ? 'reopened'
                                : (lockHint ? 'relocked' : ''))
                    );
                    sectionEl.querySelectorAll('.cr-secbar-actions').forEach(function (actionsEl) {
                        if (showActions) {
                            actionsEl.style.setProperty('display', 'flex', 'important');
                        } else {
                            actionsEl.style.setProperty('display', 'none', 'important');
                        }
                        actionsEl.querySelectorAll('[data-proxy-action]').forEach(function (btn) {
                            var act = String(btn.getAttribute('data-proxy-action') || '').toLowerCase().trim();
                            var can = showActions && (allowed.indexOf(act) !== -1);
                            btn.style.display = can ? '' : 'none';
                            btn.disabled = !can;
                        });
                    });
                    var badgeId = navBadgeMap[key] || '';
                    if (badgeId) {
                        var navBadge = document.getElementById(badgeId);
                        if (navBadge) {
                            // Keep badge visible even when section actions are readonly.
                            navBadge.style.display = '';
                        }
                    }
                });
            } catch (_e) {
            }
        }

        function applyComponentActionLock() {
            try {
                var role = getRole();
                var stage = roleToStage(role);
                if (!(stage === 'validator' || stage === 'verifier' || stage === 'qa')) {
                    return;
                }

                var componentKey = currentSectionKey();
                if (!componentKey || componentKey === 'timeline') {
                    setComponentActionButtonsEnabled(false);
                    return;
                }
                if (!isActionableComponent(componentKey)) {
                    setComponentActionButtonsEnabled(false);
                    return;
                }
                var itemKey = getActiveItemKeyForSection(componentKey);
                var st = getStageStatusFor(componentKey, stage, itemKey);
                setComponentActionAvailabilityForStatus(st);
            } catch (_e) {
            }
        }

        function askOverrideReason(componentKey, promptText, titleText, modalReasonType) {
            return new Promise(function (resolve) {
                var label = sectionLabel(componentKey) || String(componentKey || 'Component');
                var modalEl = document.getElementById('cvVerifierOverrideModal');
                var ta = document.getElementById('cvVerifierOverrideText');
                var err = document.getElementById('cvVerifierOverrideError');
                var okBtn = document.getElementById('cvVerifierOverrideSubmit');
                var cancelBtn = document.getElementById('cvVerifierOverrideCancel');
                var titleEl = document.getElementById('cvVerifierOverrideTitle');
                var effectiveReasonType = String(modalReasonType || 'reprocess_action');

                var hasBootstrapModal = !!(window.bootstrap && window.bootstrap.Modal);
                if (!modalEl || !ta || !okBtn || !hasBootstrapModal) {
                    var fallback = window.prompt((promptText || 'Enter reason:') + ' (' + label + ')');
                    if (fallback == null) return resolve(null);
                    var fallbackMsg = String(fallback || '').trim();
                    if (!fallbackMsg) return resolve(null);
                    CURRENT_MODAL_REASON_TYPE = '';
                    return resolve({ reason: fallbackMsg, reasonType: effectiveReasonType });
                }

                CURRENT_MODAL_REASON_TYPE = effectiveReasonType;
                var done = false;
                function finish(v) {
                    if (done) return;
                    done = true;
                    try { modalEl.removeEventListener('hidden.bs.modal', onHidden); } catch (_e) {}
                    try { okBtn.removeEventListener('click', onOk); } catch (_e2) {}
                    try { if (cancelBtn) cancelBtn.removeEventListener('click', onCancel); } catch (_e3) {}
                    CURRENT_MODAL_REASON_TYPE = '';
                    resolve(v);
                }
                function onCancel() {
                    finish(null);
                    closeBsModal('cvVerifierOverrideModal');
                }
                function onHidden() {
                    finish(null);
                    closeBsModal('cvVerifierOverrideModal');
                }
                function onOk() {
                    var msg = String(ta.value || '').trim();
                    if (!msg) {
                        if (err) {
                            err.textContent = 'Reason is required.';
                            err.style.display = 'block';
                        }
                        ta.focus();
                        return;
                    }
                    if (err) {
                        err.textContent = '';
                        err.style.display = 'none';
                    }
                    finish({ reason: msg, reasonType: (CURRENT_MODAL_REASON_TYPE || effectiveReasonType) });
                    closeBsModal('cvVerifierOverrideModal');
                }

                if (titleEl) {
                    titleEl.textContent = (titleText || 'Reason Required') + ' - ' + label;
                }
                if (ta) ta.value = '';
                if (err) {
                    err.textContent = '';
                    err.style.display = 'none';
                }

                modalEl.addEventListener('hidden.bs.modal', onHidden);
                okBtn.addEventListener('click', onOk);
                if (cancelBtn) cancelBtn.addEventListener('click', onCancel);
                openBsModal('cvVerifierOverrideModal');
                try { ta.focus(); } catch (_e4) {}
            });
        }

function askActionConfirm(label) {
    return new Promise(function (resolve) {
        var modalEl = document.getElementById('cvActionConfirmModal');
        var titleEl = document.getElementById('cvActionConfirmTitle');
        var textEl = document.getElementById('cvActionConfirmText');
        var yesBtn = document.getElementById('cvActionConfirmYes');
        var noBtn = document.getElementById('cvActionConfirmNo');

        if (!modalEl || !yesBtn || !window.bootstrap) {
            resolve(window.confirm('Confirm: ' + label + '?'));
            return;
        }

        var done = false;
        
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            if (backdrop && backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
        
        document.body.classList.remove('modal-open');
        document.documentElement.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('padding-right');

        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);

        function cleanupAndResolve(result) {
            if (done) return;
            done = true;

            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            yesBtn.removeEventListener('click', onYes);
            if (noBtn) noBtn.removeEventListener('click', onNo);

            modal.hide();

            setTimeout(function () {
                document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                    if (backdrop && backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                
                document.body.classList.remove('modal-open');
                document.documentElement.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
                document.body.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('padding-right');
                
                document.querySelectorAll('.modal.show').forEach(function (m) {
                    m.classList.remove('show');
                    m.style.display = 'none';
                    m.setAttribute('aria-hidden', 'true');
                });

                if (document.querySelector('.cr-report-root.cr-validator-workspace') &&
                    String(qs('print') || '') !== '1') {
                    document.body.style.overflow = 'hidden';
                }
            }, 50);

            resolve(result);
        }

        function onYes() {
            cleanupAndResolve(true);
        }

        function onNo() {
            cleanupAndResolve(false);
        }

        function onHidden() {
            cleanupAndResolve(false);
        }

        if (titleEl) titleEl.textContent = 'Confirm Action';
        if (textEl) {
            textEl.textContent = 'Are you sure you want to ' +
                String(label || 'continue').toLowerCase() + '?';
        }

        yesBtn.removeEventListener('click', onYes);
        if (noBtn) noBtn.removeEventListener('click', onNo);
        modalEl.removeEventListener('hidden.bs.modal', onHidden);

        modalEl.addEventListener('hidden.bs.modal', onHidden);
        yesBtn.addEventListener('click', onYes);
        if (noBtn) noBtn.addEventListener('click', onNo);

        modal.show();
    });
}

        function bindSectionChangeLock() {
            if (document.body && document.body.dataset.cvSectionLockBound === '1') return;
            if (document.body) document.body.dataset.cvSectionLockBound = '1';
            document.addEventListener('cv:section-changed', function () {
                try {
                    var active = currentSectionKey();
                    console.debug('[CR actions] section-changed:', active, 'actionable=', isActionableComponent(active));
                } catch (_e0) {}
                updateReviewActionbarTitle(currentSectionKey());
                syncSectionActionVisibility();
                applyComponentActionLock();
                try {
                    if (CURRENT_APP_ID) {
                        loadEmailReplies(CURRENT_APP_ID, { componentKey: currentRepliesScopeComponent(), sync: false }).catch(function () {});
                    }
                } catch (_e1) {}
            });
            document.addEventListener('cv:record-tab-changed', function () {
                syncSectionActionVisibility();
                applyComponentActionLock();
            });
            document.addEventListener('click', function (e) {
                var btn = e.target && e.target.closest ? e.target.closest('.list-group-item[data-section]') : null;
                if (!btn) return;
                setTimeout(function () {
                    try {
                        var active = currentSectionKey();
                        console.debug('[CR actions] sidebar-click:', active, 'actionable=', isActionableComponent(active));
                    } catch (_e1) {}
                    syncSectionActionVisibility();
                    applyComponentActionLock();
                }, 0);
            });
        }

        function currentSectionKey() {
            if (CURRENT_SECTION_KEY) {
                var current = normSection(CURRENT_SECTION_KEY);
                if (current && current !== 'timeline') return current;
            }
            if (LAST_COMPONENT_SECTION_KEY) {
                var last = normSection(LAST_COMPONENT_SECTION_KEY);
                if (last && last !== 'timeline') return last;
            }
            var active = document.querySelector('.list-group-item[data-section].active');
            var sec = active ? (active.getAttribute('data-section') || '') : '';
            sec = normSection(sec);
            if (sec === 'timeline' && LAST_COMPONENT_SECTION_KEY) {
                var last2 = normSection(LAST_COMPONENT_SECTION_KEY);
                if (last2 && last2 !== 'timeline') sec = last2;
            }
            if (!sec) {
                var activePanel = document.querySelector('.candidate-section.cr-active');
                if (activePanel && activePanel.id) {
                    sec = normSection(String(activePanel.id).replace(/^section-/, ''));
                }
            }
            return sec;
        }

        var ACTION_CONTRACT = {
            approve: { isWorkflowMutation: true, requiresReason: false, communicationMode: null },
            reject: { isWorkflowMutation: true, requiresReason: true, communicationMode: null },
            hold: { isWorkflowMutation: true, requiresReason: true, communicationMode: null },
            insufficient_documents: { isWorkflowMutation: true, requiresReason: true, communicationMode: 'workflow' },
            clarification_required: { isWorkflowMutation: false, requiresReason: false, communicationMode: 'workflow' },
            send_mail: { isWorkflowMutation: false, requiresReason: false, communicationMode: 'verification' }
        };

        function resolveActionPolicy(action) {
            var a = String(action || '').toLowerCase().trim();
            return ACTION_CONTRACT[a] || null;
        }

        async function run(action, label, options) {
            options = options && typeof options === 'object' ? options : {};
            action = String(action || '').toLowerCase().trim();
            var fromUnifiedModal = !!options.fromUnifiedModal;
            var skipUnifiedModalAuto = !!options.skipUnifiedModalAuto;
            var targetStage = String(options.targetStage || '').toLowerCase().trim();
            var policy = resolveActionPolicy(action);
            if (!policy) {
                setBoxMessage('cvTopMessage', 'Unsupported action.', 'danger');
                return false;
            }
            if (!applicationId) return;
            var dedupeKey = [String(action || ''), String(currentSectionKey() || ''), String(getActiveItemKeyForSection(currentSectionKey()) || '')].join('|');
            var nowTs = Date.now();
            if (lastActionFingerprint === dedupeKey && (nowTs - lastActionTs) < 1200) {
                return;
            }
            lastActionFingerprint = dedupeKey;
            lastActionTs = nowTs;
            if (actionInFlight) {
                showCrToast('Action already in progress. Please wait.', 'info');
                return;
            }

            try {
                if (!canTakeCaseAction()) {
                    setBoxMessage('cvTopMessage', 'You do not have permission to take action on this case.', 'warning');
                    return;
                }

                var caseId = REPORT_PAYLOAD && REPORT_PAYLOAD.case && REPORT_PAYLOAD.case.case_id ? parseInt(REPORT_PAYLOAD.case.case_id, 10) : 0;

                var role = getRole();
                var isComponentRole = canUseComponentWorkflowRole(role);
                var overrideReason = String(options.reason || '').trim();
                var componentKey = '';
                var itemKey = '';
                var reasonType = '';
                var reasonRequiredActions = { hold: true, reject: true, insufficient_documents: true };
                var isWorkflowMutation = !!policy.isWorkflowMutation;
                var communicationMode = String(policy.communicationMode || '').toLowerCase();

                if (isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve' || action === 'insufficient_documents')) {
                    componentKey = currentSectionKey();
                    if (!componentKey || componentKey === 'timeline') {
                        setBoxMessage('cvTopMessage', 'Please select a component first.', 'warning');
                        return;
                    }
                    if (!isActionableComponent(componentKey)) {
                        canValidatorActOnComponent(componentKey, action, 'top');
                        return;
                    }
                    if (!canValidatorActOnComponent(componentKey, action, 'top')) return;
                    itemKey = getActiveItemKeyForSection(componentKey);
                    if (communicationMode === 'workflow' && action !== 'insufficient_documents' && !fromUnifiedModal) {
                        if (typeof window.__CR_OPEN_UNIFIED_COMM === 'function') {
                            window.__CR_OPEN_UNIFIED_COMM({
                                mode: 'workflow',
                                action: action,
                                component: componentKey || '',
                                notes: '',
                                requiresMutation: isWorkflowMutation
                            });
                        }
                        return false;
                    }
                }

                var requiresReason = (action === 'reject' || action === 'hold' || action === 'insufficient_documents');
                var reasonTitle = 'Reason Required';
                var reasonPrompt = 'Enter reason to ' + String(label || action || 'continue').toLowerCase();
                if (action === 'reject') reasonType = 'reject';
                if (action === 'hold') reasonType = 'hold';
                if (action === 'insufficient_documents') reasonType = 'insufficient_documents';
                // Workflow mutations always enforce their own reason policy deterministically.
                requiresReason = !!policy.requiresReason;

                if (action === 'approve' && componentKey) {
                    var currentStageStatus = normalizeStageStatus(getStageStatusFor(componentKey, roleToStage(role), itemKey));
                    if (currentStageStatus === 'rejected') {
                        requiresReason = true;
                        reasonTitle = 'Decision Change Reason Required';
                        reasonPrompt = 'This item was rejected. Enter reason to approve';
                        reasonType = 'decision_change';
                    }
                    if (isEvaluatedWorkflowStatus(currentStageStatus)) {
                        requiresReason = true;
                        reasonTitle = 'Decision Change Reason Required';
                        reasonPrompt = 'Enter reason to replace the previous decision';
                        reasonType = 'decision_change';
                    }
                }

                if (action === 'approve' && componentKey) {
                    var validatorStatus = getStageStatusFor(componentKey, 'validator', itemKey);
                    var verifierStatus = getStageStatusFor(componentKey, 'verifier', itemKey);
                    if (validatorStatus === 'rejected') {
                        requiresReason = true;
                        reasonTitle = 'Verifier Reason Required';
                        reasonPrompt = 'Validator rejected this item. Enter reason to approve';
                        reasonType = 'reprocess_action';
                    } else if (verifierStatus === 'rejected') {
                        requiresReason = true;
                        reasonTitle = 'QA Reason Required';
                        reasonPrompt = 'Verifier rejected this item. Enter reason to proceed';
                        reasonType = 'reprocess_action';
                    }
                }

                if (requiresReason && !overrideReason) {
                    var reasonPayload = await askOverrideReason(
                        componentKey || 'case',
                        reasonPrompt,
                        reasonTitle,
                        reasonType || 'reprocess_action'
                    );
                    if (reasonPayload == null) return;
                    overrideReason = String(reasonPayload.reason || '').trim();
                    if (!overrideReason) {
                        setBoxMessage('cvTopMessage', 'Reason is required to continue.', 'warning');
                        return;
                    }
                }

                if (requiresReason && !overrideReason) {
                    setBoxMessage('cvTopMessage', 'Reason is required to continue.', 'warning');
                    return false;
                }

                if (action === 'stop_bgv') {
                    var ok = await askActionConfirm(label);
                    if (!ok) return;
                }

                actionInFlight = true;
                setActionsDisabled(true);
                setBoxMessage('cvTopMessage', '', '');
                try {
                    if (window.WF_STATUS_DEBUG_LOGS === 1 || window.WF_STATUS_DEBUG_LOGS === '1') {
                        console.debug('[WF action route]', {
                            action: action,
                            role: role,
                            component: componentKey || null,
                            endpoint: (isWorkflowMutation ? compUrl : url),
                            modal_mode: communicationMode || null,
                            transition_invoked: !!isWorkflowMutation
                        });
                    }
                } catch (_dbg1) {}

                var out;
                if (isComponentRole && action === 'insufficient_documents') {
                    var correctionRequestId = [
                        'need-docs',
                        String(applicationId || '').trim(),
                        String(caseId || '0').trim(),
                        String(componentKey || '').trim(),
                        String(role || '').trim(),
                        String(Date.now())
                    ].join('-');
                    out = await createCandidateCorrectionSession({
                        applicationId: applicationId,
                        caseId: caseId || null,
                        components: [componentKey],
                        reason: overrideReason,
                        requestId: correctionRequestId
                    });
                    if (!out.res.ok || !out.payload || out.payload.status !== 1) {
                        var correctionMsg = (out.payload && out.payload.message) ? out.payload.message : 'Failed to send correction request.';
                        if (out.res && out.res.status === 409) {
                            setBoxMessage('cvTopMessage', correctionMsg, 'warning');
                            try { loadTimeline(applicationId || '', { sync: false }).catch(function () {}); } catch (_eConflictTl) {}
                            try { loadCorrectionHistory(applicationId || '', caseId || 0).catch(function () {}); } catch (_eConflictHist) {}
                            try { loadReport({ preserveUi: true, section: componentKey }).catch(function () {}); } catch (_eConflictLoad) {}
                            return false;
                        }
                        setBoxMessage('cvTopMessage', correctionMsg, 'danger');
                        return false;
                    }

                    var correctionData = out.payload.data || {};
                    var correctionComponent = normSection(componentKey || currentSectionKey());
                    var correctionStage = roleToStage(role);
                    var correctionStatus = 'waiting_candidate';
                    var correctionItemKey = String(itemKey || getActiveItemKeyForSection(correctionComponent) || '').toLowerCase().trim();
                    var correctionRow = getAssignedRowForComponent(REPORT_PAYLOAD || {}, correctionComponent);
                    if (correctionRow) {
                        if (!correctionRow.workflow || typeof correctionRow.workflow !== 'object') correctionRow.workflow = {};
                        correctionRow.workflow[correctionStage] = correctionStatus;
                        correctionRow.current_stage = computeComponentStageLabel(correctionRow.workflow);
                    }
                    if (REPORT_PAYLOAD) {
                        if (!REPORT_PAYLOAD.component_workflow || typeof REPORT_PAYLOAD.component_workflow !== 'object') {
                            REPORT_PAYLOAD.component_workflow = {};
                        }
                        if (!REPORT_PAYLOAD.component_workflow[correctionComponent] || typeof REPORT_PAYLOAD.component_workflow[correctionComponent] !== 'object') {
                            REPORT_PAYLOAD.component_workflow[correctionComponent] = {};
                        }
                        REPORT_PAYLOAD.component_workflow[correctionComponent][correctionStage] = { status: correctionStatus };
                        if (correctionItemKey) {
                            if (!REPORT_PAYLOAD.component_item_workflow || typeof REPORT_PAYLOAD.component_item_workflow !== 'object') {
                                REPORT_PAYLOAD.component_item_workflow = {};
                            }
                            if (!REPORT_PAYLOAD.component_item_workflow[correctionComponent] || typeof REPORT_PAYLOAD.component_item_workflow[correctionComponent] !== 'object') {
                                REPORT_PAYLOAD.component_item_workflow[correctionComponent] = {};
                            }
                            if (!REPORT_PAYLOAD.component_item_workflow[correctionComponent][correctionItemKey] || typeof REPORT_PAYLOAD.component_item_workflow[correctionComponent][correctionItemKey] !== 'object') {
                                REPORT_PAYLOAD.component_item_workflow[correctionComponent][correctionItemKey] = {};
                            }
                            REPORT_PAYLOAD.component_item_workflow[correctionComponent][correctionItemKey][correctionStage] = { status: correctionStatus };
                        }
                    }
                    CURRENT_SECTION_KEY = correctionComponent;
                    LAST_COMPONENT_SECTION_KEY = correctionComponent;
                    try { updateSectionBadges(REPORT_PAYLOAD || {}); } catch (_eBadge) {}
                    try { applyComponentActionLock(); } catch (_eLock) {}
                    try { updateValidatorWorkspace(correctionComponent); } catch (_eWs) {}
                    setBoxMessage('cvTopMessage', (out.payload.message || 'Correction request sent.'), 'success');
                    try { loadTimeline(applicationId || '', { sync: false }).catch(function () {}); } catch (_eTl) {}
                    try { loadCorrectionHistory(applicationId || '', caseId || 0).catch(function () {}); } catch (_eHist) {}
                    try { loadReport({ preserveUi: true, section: correctionComponent }).catch(function () {}); } catch (_eLoad) {}
                    try {
                        if (window.WF_STATUS_DEBUG_LOGS === 1 || window.WF_STATUS_DEBUG_LOGS === '1') {
                            console.debug('[Need Docs correction access]', {
                                component: correctionComponent,
                                session_id: correctionData.correction_session_id || null,
                                mail_sent: correctionData.mail_sent || 0,
                                workflow_rows_changed: correctionData.workflow_rows_changed || 0
                            });
                        }
                    } catch (_eDbg) {}
                    return true;
                }
                if (isWorkflowMutation && isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve' || action === 'insufficient_documents')) {
                    var group2 = null;
                    if (role === 'verifier') {
                        group2 = getVerifierGroup() || null;
                    }
                    var expectedVersion = REPORT_PAYLOAD && REPORT_PAYLOAD.case && REPORT_PAYLOAD.case.workflow_version != null
                        ? parseInt(REPORT_PAYLOAD.case.workflow_version, 10)
                        : null;
                    var transitionRequestId = 'trn-' + applicationId + '-' + (componentKey || 'case') + '-' + (itemKey || 'na') + '-' + action + '-' + Date.now();
                    out = await postJson(compUrl, {
                        application_id: applicationId,
                        case_id: caseId || null,
                        component_key: componentKey,
                        item_key: itemKey || null,
                        action: action,
                        group: group2,
                        reason: overrideReason || null,
                        override_reason: overrideReason || null,
                        send_mail: 0,
                        expected_workflow_version: (Number.isFinite(expectedVersion) ? expectedVersion : -1),
                        transition_request_id: transitionRequestId
                    });
                } else {
                    var group = getVerifierGroup();
                    out = await postJson(url, {
                        application_id: applicationId,
                        action: action,
                        case_id: caseId || null,
                        group: group || null
                    });
                }
                if (!out.res.ok || !out.payload || out.payload.status !== 1) {
                    var code = String((out.payload && out.payload.code) ? out.payload.code : '').trim();
                    if (code === 'WF_DECISION_REPLACEMENT_REASON_REQUIRED') {
                        setBoxMessage('cvTopMessage', 'Reason is required when changing a previous decision.', 'warning');
                        return false;
                    }
                    var msg = (out.payload && out.payload.message) ? out.payload.message : 'Action failed.';
                    var msgLower = String(msg || '').toLowerCase();
                    if (msgLower.indexOf('validator rejected') !== -1) {
                        if (msgLower.indexOf('reason is required') !== -1 || msgLower.indexOf('reason required') !== -1) {
                            msg = 'Reason is required to approve validator rejected item.';
                        } else {
                            msg = 'Validator rejected this item. Add reason and approve.';
                        }
                    } else if (msgLower.indexOf('verifier rejected') !== -1) {
                        if (msgLower.indexOf('reason is required') !== -1 || msgLower.indexOf('reason required') !== -1) {
                            msg = 'Reason is required for QA action on verifier rejected item.';
                        } else {
                            msg = 'Verifier rejected this item. Add QA reason to proceed.';
                        }
                    }
                    setBoxMessage('cvTopMessage', msg, 'danger');
                    return false;
                }

                var d = out.payload.data || {};
                try {
                    if (window.WF_STATUS_DEBUG_LOGS === 1 || window.WF_STATUS_DEBUG_LOGS === '1') {
                        console.debug('[WF action result]', {
                            action: action,
                            workflow_status: d.workflow_status || d.component_status || null,
                            case_status: d.case_status || null,
                            application_status: d.application_status || null,
                            transition_invoked: !!isWorkflowMutation,
                            communication_side_effect: (isWorkflowMutation && communicationMode === 'workflow')
                        });
                    }
                } catch (_dbg2) {}
                var statusLabel = d.application_status || d.case_status || '';
                if ((role === 'qa' || role === 'team_lead') && action === 'reject') {
                    statusLabel = 'QA Rejected';
                }
                if ((role === 'qa' || role === 'team_lead') && action === 'approve' && statusLabel) {
                    var sl = String(statusLabel).toLowerCase();
                    if (sl === 'approved' || sl === 'completed' || sl === 'clear' || sl === 'verified') {
                        statusLabel = 'QA Approved';
                    }
                }
                if (statusLabel) {
                    setText('cvHeaderStatus', statusLabel);
                }

                function actionToWorkflowStatus(a) {
                    if (a === 'approve') return 'approved';
                    if (a === 'reject') return 'rejected';
                    if (a === 'hold') return 'hold';
                    if (a === 'insufficient_documents') return 'insufficient_documents';
                    return '';
                }

                // Update local workflow state so buttons lock immediately without reload
                if (isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve' || action === 'insufficient_documents')) {
                    // Use the exact acted keys from request context to avoid UI drift
                    // when nav focus/currentSection changes during async action flow.
                    var componentKey2 = normSection(componentKey || currentSectionKey());
                    var itemKey2 = String(itemKey || getActiveItemKeyForSection(componentKey2) || '').toLowerCase().trim();
                    var stage2 = roleToStage(role);
                    var stageStatusForItem = actionToWorkflowStatus(action);
                    var stageStatusForComponent = String((d && d.component_status) ? d.component_status : stageStatusForItem).toLowerCase().trim();
                    if (!stageStatusForComponent) stageStatusForComponent = stageStatusForItem;
                    var row2 = getAssignedRowForComponent(REPORT_PAYLOAD || {}, componentKey2);
                    if (!row2) {
                        if (!REPORT_PAYLOAD.assigned_components || !Array.isArray(REPORT_PAYLOAD.assigned_components)) {
                            REPORT_PAYLOAD.assigned_components = [];
                        }
                        row2 = { component_key: componentKey2, workflow: {} };
                        REPORT_PAYLOAD.assigned_components.push(row2);
                    }
                    if (!row2.workflow || typeof row2.workflow !== 'object') row2.workflow = {};
                    row2.workflow[stage2] = stageStatusForComponent;
                    row2.current_stage = computeComponentStageLabel(row2.workflow);

                    if (!REPORT_PAYLOAD.component_workflow || typeof REPORT_PAYLOAD.component_workflow !== 'object') {
                        REPORT_PAYLOAD.component_workflow = {};
                    }
                    if (!REPORT_PAYLOAD.component_workflow[componentKey2] || typeof REPORT_PAYLOAD.component_workflow[componentKey2] !== 'object') {
                        REPORT_PAYLOAD.component_workflow[componentKey2] = {};
                    }
                    REPORT_PAYLOAD.component_workflow[componentKey2][stage2] = { status: stageStatusForComponent };

                    if (itemKey2) {
                        if (!REPORT_PAYLOAD.component_item_workflow || typeof REPORT_PAYLOAD.component_item_workflow !== 'object') {
                            REPORT_PAYLOAD.component_item_workflow = {};
                        }
                        if (!REPORT_PAYLOAD.component_item_workflow[componentKey2] || typeof REPORT_PAYLOAD.component_item_workflow[componentKey2] !== 'object') {
                            REPORT_PAYLOAD.component_item_workflow[componentKey2] = {};
                        }
                        if (!REPORT_PAYLOAD.component_item_workflow[componentKey2][itemKey2] || typeof REPORT_PAYLOAD.component_item_workflow[componentKey2][itemKey2] !== 'object') {
                            REPORT_PAYLOAD.component_item_workflow[componentKey2][itemKey2] = {};
                        }
                        REPORT_PAYLOAD.component_item_workflow[componentKey2][itemKey2][stage2] = { status: stageStatusForItem };
                    }
                    CURRENT_SECTION_KEY = componentKey2;
                    LAST_COMPONENT_SECTION_KEY = componentKey2;
                    try {
                        updateSectionBadges(REPORT_PAYLOAD || {});
                    } catch (_e) {
                    }
                    applyComponentActionLock();
                    updateValidatorWorkspace(componentKey2);
                }
                // Canonical re-sync to eliminate stale derived local state after mutation.
                try {
                    loadReport({ preserveUi: true, section: componentKey2 }).catch(function () {});
                } catch (_syncErr) {}
                if (Array.isArray(d.invalidated_stages) && d.invalidated_stages.length > 0) {
                    setBoxMessage('cvTopMessage', 'Decision updated. Downstream review was invalidated for reconciliation.', 'success');
                } else if ((stageStatusForComponent || '') && isEvaluatedWorkflowStatus(stageStatusForComponent)) {
                    setBoxMessage('cvTopMessage', 'Decision updated successfully.', 'success');
                } else {
                    setBoxMessage('cvTopMessage', 'Updated successfully.', 'success');
                }

                // Communication side-effect only after canonical mutation succeeds.
                if (isWorkflowMutation && communicationMode === 'workflow' && isComponentRole && action === 'insufficient_documents') {
                    try {
                        if (!fromUnifiedModal && !skipUnifiedModalAuto && typeof window.__CR_OPEN_UNIFIED_COMM === 'function') {
                            window.__CR_OPEN_UNIFIED_COMM({
                                mode: 'workflow',
                                action: 'insufficient_documents',
                                component: componentKey2 || componentKey || '',
                                notes: '',
                                requiresMutation: false
                            });
                        }
                    } catch (_eComm) {}
                }
            } catch (e) {
                setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Action failed.', 'danger');
                return false;
            } finally {
                setActionsDisabled(false);
                actionInFlight = false;
            }
            return true;
        }

        window.__CR_RUN_ACTION = run;

        var insufficientBtn = document.getElementById('cvActionInsufficient');
        var holdBtn = document.getElementById('cvActionHold');
        var rejectBtn = document.getElementById('cvActionReject');
        var stopBtn = document.getElementById('cvActionStopBgv');
        var approveBtn = document.getElementById('cvActionApprove');
        var validatorInsufficientBtn = document.getElementById('cvValidatorActionInsufficient');
        var validatorHoldBtn = document.getElementById('cvValidatorActionHold');
        var validatorRejectBtn = document.getElementById('cvValidatorActionReject');
        var validatorApproveBtn = document.getElementById('cvValidatorActionApprove');

        if (insufficientBtn && !insufficientBtn.dataset.bound) {
            insufficientBtn.dataset.bound = '1';
            insufficientBtn.addEventListener('click', function () { run('insufficient_documents', 'Insufficient Documents'); });
        }
        if (holdBtn && !holdBtn.dataset.bound) {
            holdBtn.dataset.bound = '1';
            holdBtn.addEventListener('click', function () { run('hold', 'Hold'); });
        }
        if (rejectBtn && !rejectBtn.dataset.bound) {
            rejectBtn.dataset.bound = '1';
            rejectBtn.addEventListener('click', function () { run('reject', 'Reject'); });
        }
        if (stopBtn && !stopBtn.dataset.bound) {
            stopBtn.dataset.bound = '1';
            stopBtn.addEventListener('click', function () { run('stop_bgv', 'Stop BGV'); });
        }
        if (approveBtn && !approveBtn.dataset.bound) {
            approveBtn.dataset.bound = '1';
            approveBtn.addEventListener('click', function () { run('approve', 'Approve'); });
        }
        if (validatorInsufficientBtn && !validatorInsufficientBtn.dataset.bound) {
            validatorInsufficientBtn.dataset.bound = '1';
            validatorInsufficientBtn.addEventListener('click', function () { run('insufficient_documents', 'Insufficient Documents'); });
        }
        if (validatorHoldBtn && !validatorHoldBtn.dataset.bound) {
            validatorHoldBtn.dataset.bound = '1';
            validatorHoldBtn.addEventListener('click', function () { run('hold', 'Hold'); });
        }
        if (validatorRejectBtn && !validatorRejectBtn.dataset.bound) {
            validatorRejectBtn.dataset.bound = '1';
            validatorRejectBtn.addEventListener('click', function () { run('reject', 'Reject'); });
        }
        if (validatorApproveBtn && !validatorApproveBtn.dataset.bound) {
            validatorApproveBtn.dataset.bound = '1';
            validatorApproveBtn.addEventListener('click', function () { run('approve', 'Approve'); });
        }

        bindSectionChangeLock();
        updateReviewActionbarTitle(currentSectionKey());
        syncSectionActionVisibility();
        applyComponentActionLock();
    }

    function initSectionNav() {
        var role = getRole();
        if (role === 'verifier' && REPORT_PAYLOAD) {
            renderVerifierWorkflowSidebar(REPORT_PAYLOAD);
        }
        var items = Array.prototype.slice.call(document.querySelectorAll('.list-group-item[data-section]'));
        if (!items.length) return;
        var reviewTabHost = document.getElementById('cvReviewTabs');
        var reviewTabButtons = reviewTabHost ? Array.prototype.slice.call(reviewTabHost.querySelectorAll('[data-review-section]')) : [];

        var isAssignmentScopedRole = (role === 'verifier' || role === 'db_verifier');
        var hasBackendVisibleSections = false;
        if ((role === 'verifier' || role === 'db_verifier' || role === 'validator') && !REPORT_PAYLOAD) {
            // Avoid pre-payload flicker/override; render once with resolved assigned components.
            return;
        }
        var assignedKeys = [];
        if (REPORT_PAYLOAD) {
            hasBackendVisibleSections = Array.isArray(REPORT_PAYLOAD.visible_sections) || Array.isArray(REPORT_PAYLOAD.visibleSections);
            assignedKeys = getAssignedComponentKeys(REPORT_PAYLOAD);

            if (assignedKeys.length || hasBackendVisibleSections) {
                items.forEach(function (btn) {
                    var s = (btn.getAttribute('data-section') || '').toLowerCase();
                    var ok = assignedKeys.indexOf(s) !== -1 || s === 'timeline';
                    btn.style.display = ok ? '' : 'none';
                });

                var panelsAll = Array.prototype.slice.call(document.querySelectorAll('.candidate-section'));
                panelsAll.forEach(function (p) {
                    var id = (p && p.id) ? String(p.id) : '';
                    var section = id.replace(/^section-/, '').toLowerCase();
                    var ok = assignedKeys.indexOf(section) !== -1 || section === 'timeline';
                    if (!ok) {
                        p.style.display = 'none';
                        p.classList.remove('cr-active');
                    }
                });
            }
        }

        var uploadTypeEl = document.getElementById('cvUploadDocType');
        var currentSection = null;

        function setReviewTabsActive(section) {
            if (!reviewTabButtons.length) return;
            var s = normSection(section);
            reviewTabButtons.forEach(function (btn) {
                btn.classList.toggle('active', normSection(btn.getAttribute('data-review-section') || '') === s);
            });
        }

        function sectionVisibleInNav(section) {
            var s = normSection(section);
            if (!s) return false;
            var btn = items.find(function (it) { return normSection(it.getAttribute('data-section') || '') === s; });
            return !!(btn && btn.style.display !== 'none');
        }

        function syncUploadType(section) {
            if (!uploadTypeEl) return;
            var v = String(section || 'general');
            var supported = Array.prototype.slice.call(uploadTypeEl.options).some(function (o) { return String(o.value) === v; });
            uploadTypeEl.value = supported ? v : 'general';
        }

        function show(section) {
            section = normSection(section);
            if (!section) return;
            var panelSection = panelSectionForComponent(section);
            CURRENT_SECTION_KEY = section;
            if (section !== 'timeline') {
                LAST_COMPONENT_SECTION_KEY = section;
            }

            if (section === 'timeline') {
                openBsModal('cvTimelineModal');
                return;
            }

            syncUploadType(panelSection);
            setMiniTimelineFilter(section);
            items.forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-section') === section);
            });
            setReviewTabsActive(section);

            var panels = Array.prototype.slice.call(document.querySelectorAll('.candidate-section'));
            panels.forEach(function (p) {
                var id = (p.id || '').replace(/^section-/, '').toLowerCase();
                var on = id === panelSection;
                p.style.display = on ? '' : 'none';
                p.classList.toggle('cr-active', on);
            });

            setCompNavActive(section);
            if (panelSection === 'reference') {
                renderReferencePanelForSection(REPORT_PAYLOAD, section);
            }
            ensureComponentTableRendered(section);
            ensureComponentToolbar(section);

            try {
                var panel = document.getElementById('section-' + String(panelSection));
                if (!document.querySelector('.cr-report-root.cr-validator-workspace')) {
                    ensureComponentChat(panel, section);
                }
            } catch (_e) {
            }

            try {
                renderRemarksPanel(section);
            } catch (_e) {
            }

            try {
                updateValidatorWorkspace(section);
            } catch (_e) {
            }

            try {
                document.dispatchEvent(new CustomEvent('cv:section-changed', { detail: { section: section } }));
            } catch (_e) {
            }
        }

        items.forEach(function (btn) {
            if (!btn.dataset.boundNav) {
                btn.dataset.boundNav = '1';
                btn.addEventListener('click', function () {
                    var target = btn.getAttribute('data-section');
                    if (!target) return;
                    show(target);
                });
            }
        });

        if (reviewTabButtons.length && !reviewTabHost.dataset.bound) {
            reviewTabHost.dataset.bound = '1';
            reviewTabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var section = normSection(btn.getAttribute('data-review-section') || '');
                    if (!section || !sectionVisibleInNav(section)) return;
                    show(section);
                });
            });
        }

        var allowSet = allowedSectionsSet();
        if (!(assignedKeys.length || hasBackendVisibleSections)) {
            items.forEach(function (btn) {
                var s = String(btn.getAttribute('data-section') || '').toLowerCase();
                if (s && s !== 'timeline' && !canSeeSection(s, allowSet)) {
                    btn.style.display = 'none';
                }
                if (s === 'timeline' && !canSeeSection('timeline', allowSet)) {
                    btn.style.display = 'none';
                }
            });
        }

        if (reviewTabButtons.length) {
            reviewTabButtons.forEach(function (btn) {
                var s = normSection(btn.getAttribute('data-review-section') || '');
                var visible = !!s && sectionVisibleInNav(s);
                btn.style.display = visible ? '' : 'none';
            });
            var hasVisibleTabs = reviewTabButtons.some(function (btn) { return btn.style.display !== 'none'; });
            reviewTabHost.style.display = hasVisibleTabs ? '' : 'none';
        }

        var active = items.find(function (b) { return b.classList.contains('active') && b.style.display !== 'none'; });
        var initial = active ? active.getAttribute('data-section') : 'basic';
        if (!(assignedKeys.length || hasBackendVisibleSections) && !canSeeSection(initial, allowSet)) {
            var firstVisible = items.find(function (b) { return b.style.display !== 'none'; });
            initial = firstVisible ? firstVisible.getAttribute('data-section') : '';
        }
        var preferred = normSection(CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || '');
        if (preferred && sectionVisibleInNav(preferred)) {
            initial = preferred;
        } else if (assignedKeys && assignedKeys.length) {
            initial = assignedKeys[0] || initial;
        }

        if (initial) show(initial);
    }

    function initVerifierCompleteNext(getPayload) {
        var btn = document.getElementById('cvCompleteGroupBtn');
        if (!btn) return;

        var role = getRole();
        if (role === 'qa' || role === 'team_lead') {
            btn.addEventListener('click', function () {
                var payload = getPayload ? getPayload() : null;
                var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
                var clientId = payload && payload.case && payload.case.client_id ? parseInt(payload.case.client_id, 10) : 0;
                var appId = payload && payload.case && payload.case.application_id ? String(payload.case.application_id) : (qs('application_id') || '');

                if (!appId) {
                    var msg = document.getElementById('cvTopMessage');
                    if (msg) msg.textContent = 'Application ID not found.';
                    return;
                }

                btn.disabled = true;
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                var approvalWarning = '';

                fetch(base + '/api/shared/case_action.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        application_id: appId,
                        action: 'approve',
                        case_id: caseId || null,
                        group: null
                    })
                })
                    .then(function (res) {
                        return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                    })
                    .then(function (data) {
                        if (!data || data.status !== 1) {
                            approvalWarning = (data && data.message) ? String(data.message) : 'Failed to complete.';
                        }

                        var nextUrl = base + '/api/qa/cases_list.php?view=ready';
                        if (clientId > 0) {
                            nextUrl += '&client_id=' + encodeURIComponent(String(clientId));
                        }

                        return fetch(nextUrl, { credentials: 'same-origin' })
                            .then(function (res) {
                                return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                            })
                            .then(function (nextData) {
                                if (!nextData || nextData.status !== 1 || !Array.isArray(nextData.data)) {
                                    var msg = document.getElementById('cvTopMessage');
                                    if (msg) msg.textContent = (nextData && nextData.message) ? nextData.message : 'Completed. No next case.';
                                    return;
                                }

                                var next = null;
                                for (var i = 0; i < nextData.data.length; i++) {
                                    var row = nextData.data[i] || {};
                                    var nextApp = String(row.application_id || '').trim();
                                    if (nextApp && nextApp !== appId) {
                                        next = row;
                                        break;
                                    }
                                }

                                if (!next) {
                                    var msg = document.getElementById('cvTopMessage');
                                    if (msg) {
                                        msg.textContent = approvalWarning
                                            ? ('No next case. Approval warning: ' + approvalWarning)
                                            : 'Completed. No next case.';
                                    }
                                    return;
                                }

                                var target = base + '/modules/qa/case_review.php?application_id=' + encodeURIComponent(String(next.application_id || ''));
                                var nextClientId = parseInt(next.client_id, 10);
                                if (isFinite(nextClientId) && nextClientId > 0) {
                                    target += '&client_id=' + encodeURIComponent(String(nextClientId));
                                }

                                // In QA case-review embed mode, redirect parent.
                                try {
                                    if (window.parent && window.parent !== window) {
                                        window.parent.location.href = target;
                                        return;
                                    }
                                } catch (_e) {
                                }
                                window.location.href = target;
                            });
                    })
                    .catch(function () {
                        var msg = document.getElementById('cvTopMessage');
                        if (msg) msg.textContent = 'Network error. Please try again.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
            return;
        }

        if (role === 'validator') {
            btn.addEventListener('click', function () {
                var payload = getPayload ? getPayload() : null;
                var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
                var clientId = payload && payload.case && payload.case.client_id ? parseInt(payload.case.client_id, 10) : 0;

                if (!caseId) {
                    var msg = document.getElementById('cvTopMessage');
                    if (msg) msg.textContent = 'Case ID not found.';
                    return;
                }

                btn.disabled = true;
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

                fetch(base + '/api/validator/queue_complete.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ case_id: caseId })
                })
                    .then(function (res) {
                        return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                    })
                    .then(function (data) {
                        if (!data || data.status !== 1) {
                            var msg = document.getElementById('cvTopMessage');
                            if (msg) msg.textContent = (data && data.message) ? data.message : 'Failed to complete.';
                            return;
                        }

                        return fetch(base + '/api/validator/queue_next.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'same-origin',
                            body: JSON.stringify({ client_id: clientId })
                        })
                            .then(function (res) {
                                return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                            })
                            .then(function (nextData) {
                                if (!nextData || nextData.status !== 1) {
                                    var msg = document.getElementById('cvTopMessage');
                                    if (msg) msg.textContent = (nextData && nextData.message) ? nextData.message : 'Completed. No next case.';
                                    return;
                                }
                                var url = nextData && nextData.data ? nextData.data.url : null;
                                if (!url) {
                                    window.location.href = (window.APP_BASE_URL || '') + '/modules/validator/dashboard.php';
                                    return;
                                }
                                window.location.href = url;
                            });
                    })
                    .catch(function () {
                        var msg = document.getElementById('cvTopMessage');
                        if (msg) msg.textContent = 'Network error. Please try again.';
                    })
                    .finally(function () {
                        btn.disabled = false;
                    });
            });
            return;
        }

        var group = getVerifierGroup();
        if (!group) {
            btn.style.display = 'none';
            return;
        }

        btn.addEventListener('click', function () {
            var payload = getPayload ? getPayload() : null;
            var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
            var clientId = payload && payload.case && payload.case.client_id ? parseInt(payload.case.client_id, 10) : 0;

            if (!caseId) {
                var msg = document.getElementById('cvTopMessage');
                if (msg) msg.textContent = 'Case ID not found.';
                return;
            }

            btn.disabled = true;
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

            fetch(base + '/api/verifier/queue_complete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ case_id: caseId, group: group })
            })
                .then(function (res) {
                    return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                })
                .then(function (data) {
                    if (!data || data.status !== 1) {
                        var msg = document.getElementById('cvTopMessage');
                        if (msg) msg.textContent = (data && data.message) ? data.message : 'Failed to complete.';
                        return;
                    }

                    return fetch(base + '/api/verifier/queue_next.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'same-origin',
                        body: JSON.stringify({ group: group, client_id: clientId })
                    })
                        .then(function (res) {
                            return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; });
                        })
                        .then(function (nextData) {
                            if (!nextData || nextData.status !== 1) {
                                var msg = document.getElementById('cvTopMessage');
                                if (msg) msg.textContent = (nextData && nextData.message) ? nextData.message : 'Completed. No next case.';
                                return;
                            }
                            var url = nextData && nextData.data ? nextData.data.url : null;
                            if (!url) {
                                window.location.href = (window.APP_BASE_URL || '') + '/modules/verifier/dashboard.php';
                                return;
                            }
                            window.location.href = url;
                        });
                })
                .catch(function () {
                    var msg = document.getElementById('cvTopMessage');
                    if (msg) msg.textContent = 'Network error. Please try again.';
                })
                .finally(function () {
                    btn.disabled = false;
                });
        });
    }

    function openDocInModal(href, label, sourceEl) {
        href = String(href || '').trim();
        if (!href || href === '#') return;

        var ctx = detectContextFromDocLink(sourceEl);
        if (openDocViewer(href, {
            applicationId: ctx.applicationId || '',
            componentKey: ctx.componentKey || '',
            itemKey: ctx.itemKey || '',
            mimeType: ''
        })) {
            return;
        }
        showCrToast('Unable to open preview for this file.', 'warning');
    }

    function initDocViewModal() {
        if (document.body && document.body.dataset && document.body.dataset.cvDocModalBound === '1') {
            return;
        }
        if (document.body && document.body.dataset) {
            document.body.dataset.cvDocModalBound = '1';
        }

        document.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t || !t.closest) return;
            var link = t.closest('a.js-cv-doc-view');
            if (!link) return;

            var href = link.getAttribute('href') || '';
            if (!href || href === '#') return;

            e.preventDefault();
            var label = link.getAttribute('data-doc-label') || link.textContent || 'Document';
            openDocInModal(href, label, link);
        });
    }

    function renderUploadedDocs(rows) {
        var host = document.getElementById('cvUploadedDocs');
        if (!host) return;

        if (!Array.isArray(rows) || rows.length === 0) {
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No uploaded documents.</div>';
            return;
        }

        host.innerHTML = '<div class="table-scroll"><table class="table">' +
            '<thead><tr><th>Type</th><th>File</th><th>Uploaded By</th><th>Created</th></tr></thead>' +
            '<tbody>' + rows.map(function (r) {
                var href = docHref(r);
                var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : '';
                return '<tr>' +
                    '<td>' + esc(r.doc_type || '') + '</td>' +
                    '<td><a href="' + esc(href || '#') + '" class="js-cv-doc-view" data-doc-label="' + esc(label || '') + '" style="text-decoration:none; color:#2563eb;">' + esc(label) + '</a></td>' +
                    '<td>' + esc(r.uploaded_by_role || '') + '</td>' +
                    '<td>' + esc(r.created_at || '') + '</td>' +
                '</tr>';
            }).join('') +
            '</tbody></table></div>';
    }

    async function loadUploadedDocs(applicationId, docType) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/verification_docs_list.php?application_id=' + encodeURIComponent(applicationId);
        if (docType) url += '&doc_type=' + encodeURIComponent(docType);

        var res = await fetch(url, { credentials: 'same-origin' });
        var data = await res.json().catch(function () { return null; });
        if (!res.ok || !data || data.status !== 1) {
            renderUploadedDocs([]);
            return;
        }
        renderUploadedDocs(data.data || []);
    }

    async function uploadDocs(applicationId) {
        var btn = document.getElementById('cvUploadBtn');
        var filesEl = document.getElementById('cvUploadFiles');
        var typeEl = document.getElementById('cvUploadDocType');
        var roleNow = getRole();
        var activeSection = normSection(CURRENT_SECTION_KEY || activeComponentSectionKey() || '');
        if (roleNow === 'validator' && activeSection && !canValidatorActOnComponent(activeSection, 'upload_docs', 'upload')) {
            return;
        }

        if (!filesEl || !typeEl) return;

        var files = filesEl.files;
        if (!files || files.length === 0) {
            setBoxMessage('cvUploadMessage', 'Please select file(s) to upload.', 'danger');
            return;
        }

        setBoxMessage('cvUploadMessage', '', '');

        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Uploading...';
        }

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/shared/verification_docs_upload.php';

            var fd = new FormData();
            fd.append('application_id', applicationId);
            fd.append('doc_type', String(typeEl.value || 'general'));
            fd.append('role', String(qs('role') || ''));
            var clientId = qs('client_id');
            if (clientId) fd.append('client_id', String(clientId));

            for (var i = 0; i < files.length; i++) {
                fd.append('files[]', files[i]);
            }

            var res = await fetch(url, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            });

            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : 'Upload failed');
            }

            setBoxMessage('cvUploadMessage', 'Uploaded successfully.', 'success');
            filesEl.value = '';
            setSelectedFilesUi([]);
            await loadUploadedDocs(applicationId, '');
        } catch (e) {
            setBoxMessage('cvUploadMessage', e && e.message ? e.message : 'Upload failed', 'danger');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || 'Upload';
            }
        }
    }

    function reportWorkflowVersionFromPayload(payload) {
        var data = payload && typeof payload === 'object' ? payload : {};
        var cs = data.case && typeof data.case === 'object' ? data.case : {};
        var version = cs.workflow_version;
        if (version === null || typeof version === 'undefined' || version === '') {
            version = data.workflow_version;
        }
        if (version === null || typeof version === 'undefined' || version === '') return null;
        return String(version);
    }

    function cssAttrValue(value) {
        var raw = String(value || '');
        if (window.CSS && typeof window.CSS.escape === 'function') return window.CSS.escape(raw);
        return raw.replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function captureReportUiState() {
        var section = '';
        try {
            section = activeComponentSectionKey() || CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || '';
        } catch (_e) {
            section = CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || '';
        }
        var tabs = [];
        try {
            document.querySelectorAll('.nav-link.active, [role="tab"].active, [data-bs-toggle="tab"].active, [data-toggle="tab"].active').forEach(function (el) {
                var selector = '';
                if (el.id) {
                    selector = '#' + cssAttrValue(el.id);
                } else {
                    var target = el.getAttribute('data-bs-target') || el.getAttribute('data-target') || el.getAttribute('href') || '';
                    if (target && target.charAt(0) === '#') {
                        var safeTarget = target.replace(/"/g, '\\"');
                        selector = '[data-bs-target="' + safeTarget + '"], [data-target="' + safeTarget + '"], [href="' + safeTarget + '"]';
                    }
                }
                if (selector) tabs.push(selector);
            });
        } catch (_e2) {
        }
        var sectionsScroll = document.getElementById('crSectionsScroll');
        return {
            section: String(section || '').toLowerCase(),
            scrollX: window.pageXOffset || document.documentElement.scrollLeft || 0,
            scrollY: window.pageYOffset || document.documentElement.scrollTop || 0,
            sectionsScrollTop: sectionsScroll ? sectionsScroll.scrollTop : null,
            tabs: tabs
        };
    }

    function restoreReportUiState(state) {
        if (!state || typeof state !== 'object') return;
        setTimeout(function () {
            try {
                var section = String(state.section || '').toLowerCase();
                if (section) {
                    CURRENT_SECTION_KEY = normSection(section);
                    LAST_COMPONENT_SECTION_KEY = CURRENT_SECTION_KEY;
                    var sectionSelector = '[data-section="' + cssAttrValue(section) + '"]';
                    var sectionEl = document.querySelector('.list-group-item' + sectionSelector + ', ' + sectionSelector);
                    if (sectionEl && typeof sectionEl.click === 'function' && !sectionEl.classList.contains('active')) {
                        sectionEl.click();
                    }
                    ensureComponentTableRendered(CURRENT_SECTION_KEY || section);
                    ensureComponentToolbar(CURRENT_SECTION_KEY || section);
                }
            } catch (_e) {
            }
            try {
                (state.tabs || []).forEach(function (selector) {
                    var tab = document.querySelector(selector);
                    if (tab && typeof tab.click === 'function' && !tab.classList.contains('active')) tab.click();
                });
            } catch (_e2) {
            }
            try {
                var sectionsScroll = document.getElementById('crSectionsScroll');
                if (sectionsScroll && state.sectionsScrollTop !== null && typeof state.sectionsScrollTop !== 'undefined') {
                    sectionsScroll.scrollTop = state.sectionsScrollTop;
                }
                window.scrollTo(state.scrollX || 0, state.scrollY || 0);
            } catch (_e3) {
            }
        }, 0);
    }

    function isEditableElement(el) {
        if (!el || el === document.body) return false;
        var tag = String(el.tagName || '').toLowerCase();
        if (el.isContentEditable) return true;
        if (tag === 'textarea' || tag === 'select') return !(el.disabled || el.readOnly);
        if (tag === 'input') {
            var type = String(el.type || 'text').toLowerCase();
            if (['button', 'checkbox', 'radio', 'submit', 'reset', 'hidden'].indexOf(type) !== -1) return false;
            return !(el.disabled || el.readOnly);
        }
        return false;
    }

    function isReportRefreshUnsafe() {
        try {
            if (document.querySelector('.modal.show, dialog[open], [aria-modal="true"]')) return true;
            if (isEditableElement(document.activeElement)) return true;
            var uploadBtn = document.getElementById('cvUploadBtn');
            if (uploadBtn && uploadBtn.disabled) return true;
        } catch (_e) {
            return true;
        }
        return false;
    }

    function queueDeferredReportRefresh() {
        REPORT_VERSION_DEFERRED = true;
        if (REPORT_VERSION_DEFERRED_TIMER) return;
        REPORT_VERSION_DEFERRED_TIMER = setTimeout(function retryDeferredRefresh() {
            REPORT_VERSION_DEFERRED_TIMER = null;
            if (!REPORT_VERSION_DEFERRED) return;
            if (isReportRefreshUnsafe()) {
                queueDeferredReportRefresh();
                return;
            }
            refreshReportAfterVersionChange().catch(function () {});
        }, 1500);
    }

    async function refreshReportAfterVersionChange() {
        if (REPORT_VERSION_REFRESH_IN_FLIGHT || LOAD_REPORT_IN_FLIGHT) {
            queueDeferredReportRefresh();
            return;
        }
        if (isReportRefreshUnsafe()) {
            queueDeferredReportRefresh();
            return;
        }
        REPORT_VERSION_DEFERRED = false;
        REPORT_VERSION_REFRESH_IN_FLIGHT = true;
        var state = captureReportUiState();
        try {
            var refreshed = await loadReport({ preserveUi: true, silentVersionRefresh: true, section: state.section || '' });
            if (refreshed) {
                var nextVersion = reportWorkflowVersionFromPayload(refreshed);
                if (nextVersion !== null) REPORT_VERSION_CURRENT = nextVersion;
            }
        } finally {
            REPORT_VERSION_REFRESH_IN_FLIGHT = false;
            restoreReportUiState(state);
            if (REPORT_VERSION_DEFERRED && !isReportRefreshUnsafe()) {
                queueDeferredReportRefresh();
            }
        }
    }

    async function pollReportVersion() {
        if (REPORT_VERSION_POLL_IN_FLIGHT || REPORT_VERSION_REFRESH_IN_FLIGHT || LOAD_REPORT_IN_FLIGHT) return;
        if (!REPORT_PAYLOAD || !REPORT_PAYLOAD.case) return;
        var cs = REPORT_PAYLOAD.case || {};
        var applicationId = String(cs.application_id || CURRENT_APP_ID || qs('application_id') || '').trim();
        var caseId = String(cs.case_id || qs('case_id') || '').trim();
        if (!applicationId && !caseId) return;
        REPORT_VERSION_POLL_IN_FLIGHT = true;
        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/shared/candidate_report_version.php?';
            url += applicationId ? ('application_id=' + encodeURIComponent(applicationId)) : ('case_id=' + encodeURIComponent(caseId));
            url += '&_=' + encodeURIComponent(String(Date.now()));
            var res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
            var payload = await res.json().catch(function () { return null; });
            if (!res.ok || !payload || payload.status !== 1 || !payload.data) return;
            var nextVersion = payload.data.workflow_version;
            if (nextVersion === null || typeof nextVersion === 'undefined' || nextVersion === '') return;
            nextVersion = String(nextVersion);
            if (REPORT_VERSION_CURRENT === null) {
                REPORT_VERSION_CURRENT = nextVersion;
                return;
            }
            if (nextVersion !== REPORT_VERSION_CURRENT) {
                refreshReportAfterVersionChange().catch(function () {});
            }
        } catch (_e) {
        } finally {
            REPORT_VERSION_POLL_IN_FLIGHT = false;
        }
    }

    function startReportVersionPolling(payload) {
        if (String(qs('print') || '') === '1') return;
        var role = getRole();
        var pollRoles = ['verifier', 'validator', 'qa', 'db_verifier', 'client_admin', 'gss_admin'];
        if (pollRoles.indexOf(role) === -1) return;
        var version = reportWorkflowVersionFromPayload(payload || REPORT_PAYLOAD);
        if (version !== null) REPORT_VERSION_CURRENT = version;
        if (REPORT_VERSION_POLL_TIMER) return;
        REPORT_VERSION_POLL_TIMER = setInterval(function () {
            pollReportVersion().catch(function () {});
        }, REPORT_VERSION_POLL_MS);
    }

    async function loadReport(options) {
        if (LOAD_REPORT_IN_FLIGHT) {
            LOAD_REPORT_PENDING_OPTS = options || {};
            return REPORT_PAYLOAD || null;
        }
        LOAD_REPORT_IN_FLIGHT = true;
        options = options || {};
        if (options.section) {
            CURRENT_SECTION_KEY = normSection(String(options.section || ''));
            LAST_COMPONENT_SECTION_KEY = CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY;
        }
        try {
        var root = document.querySelector('.cr-report-root');
        var hasExistingPayload = !!(REPORT_PAYLOAD && typeof REPORT_PAYLOAD === 'object');
        var shouldHideUi = !options.preserveUi && !hasExistingPayload;
        if (root && shouldHideUi) root.setAttribute('data-ui-ready', '0');

        var applicationId = qs('application_id') || '';
        var caseId = qs('case_id') || '';
        var clientId = qs('client_id') || '';
        var role = getRole();

        function qaAudit(event, meta) {
            if (String(role || '').toLowerCase().trim() !== 'qa') return;
            if (!applicationId) return;
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            fetch(base + '/api/qa/report_audit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ application_id: applicationId, event: event, meta: meta || null })
            }).catch(function () {
            });
        }

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = '';
        if (applicationId) {
            url = base + '/api/shared/candidate_report_get.php?application_id=' + encodeURIComponent(applicationId);
        } else if (caseId) {
            url = base + '/api/shared/candidate_report_get.php?case_id=' + encodeURIComponent(caseId);
        } else {
            setText('cvTopMessage', 'application_id is required in URL');
            if (root) root.setAttribute('data-ui-ready', '1');
            return;
        }

        var role2 = getRole();
        if (role2 === 'verifier') {
            var g = getVerifierGroup();
            if (g) {
                url += '&group=' + encodeURIComponent(g);
            }
            var cid = (qs('client_id') || '').toString().trim();
            if (cid) {
                url += '&client_id=' + encodeURIComponent(cid);
            }
            var priorityBucket = (qs('priority_bucket') || '').toString().trim().toLowerCase();
            if (priorityBucket) {
                url += '&priority_bucket=' + encodeURIComponent(priorityBucket);
            }
            var reportMode = (qs('report_mode') || '').toString().trim().toLowerCase();
            if (reportMode) {
                url += '&report_mode=' + encodeURIComponent(reportMode);
            }
        }


        var holidaysPromise = loadHolidaysOnce();
        function hasSnapshotContract(data) {
            if (!data || typeof data !== 'object') return false;
            var vis = Array.isArray(data.visible_sections) || Array.isArray(data.visibleSections);
            var asg = Array.isArray(data.assigned_components) || Array.isArray(data.assignedComponents);
            var wf = data.component_workflow && typeof data.component_workflow === 'object';
            return vis && asg && wf;
        }

        var res = await fetch(url, { credentials: 'same-origin' });
        var payload = await res.json().catch(function () { return null; });

        if (!res.ok || !payload || payload.status !== 1) {
            var msg = (payload && payload.message) ? payload.message : 'Failed to load report.';
            setText('cvTopMessage', msg);
            if (root) root.setAttribute('data-ui-ready', '1');
            return;
        }

        if (!document.body.dataset.qaAuditOpenLogged) {
            document.body.dataset.qaAuditOpenLogged = '1';
            qaAudit('open', { source: 'candidate_report', embed: String(qs('embed') || '') === '1' ? 1 : 0 });
        }

        setText('cvTopMessage', '');

        var d = payload.data || {};
        if (!hasSnapshotContract(d)) {
            setText('cvTopMessage', 'Workflow snapshot is unavailable for this case. Please refresh.');
            if (root) root.setAttribute('data-ui-ready', '1');
            return;
        }
        REPORT_PAYLOAD = d;
        startReportVersionPolling(d);
        applyRoleActionabilityFromPayload(d);
        resetComponentTableRenderState(d);
        applyCaseActionCardVisibility();

        initSectionNav();
        renderComponentNav(REPORT_PAYLOAD);
        var basic = d.basic || {};
        var contact = d.contact || {};
        var ref = d.reference || {};
        var social = d.social_media || {};
        var ecourt = d.ecourt || {};
        var app = d.application || {};
        var cs = d.case || {};
        var auth = d.authorization || {};

        if (!applicationId && cs && cs.application_id) {
            applicationId = String(cs.application_id);
        }

        CURRENT_APP_ID = applicationId || '';

        initHeaderModals(applicationId);
        initValidatorWorkspace();

        renderDocPreviewPanel(d.uploaded_docs || []);

        updateSectionBadges(d);

        var isPrint = String(qs('print') || '') === '1';
        if (isPrint) {
            if (!document.body.dataset.qaAuditPrintLogged) {
                document.body.dataset.qaAuditPrintLogged = '1';
                qaAudit('print', { source: 'candidate_report', print: 1 });
            }
            var coverName = ((cs.candidate_first_name || '') + ' ' + (cs.candidate_last_name || '')).trim() || (basic.first_name || '') + ' ' + (basic.last_name || '');
            var coverApp = applicationId;
            var coverCase = cs.case_id ? String(cs.case_id) : '';
            var coverClient = cs.client_id ? String(cs.client_id) : '';

            setText('cvPdfCoverCandidate', coverName);
            setHtml('cvPdfCoverMeta',
                '<div><b>Application:</b> ' + esc(coverApp) + '</div>' +
                '<div><b>Case ID:</b> ' + esc(coverCase) + '</div>' +
                '<div><b>Client ID:</b> ' + esc(coverClient) + '</div>' +
                '<div><b>Generated:</b> ' + esc(new Date().toLocaleString()) + '</div>'
            );
            setHtml('cvPdfCoverNote',
                'This report is confidential and intended solely for background verification purposes. ' +
                'Access and usage must comply with applicable laws and client authorization.'
            );
            setText('cvPdfCoverFooterLeft', 'Application: ' + coverApp);

            var metaHtml =
                '<div><b>Candidate:</b> ' + esc(coverName) + '</div>' +
                '<div><b>Application:</b> ' + esc(coverApp) + '</div>' +
                '<div><b>Status:</b> ' + esc(displayCaseStatus(app.status, cs.case_status) || '') + '</div>';
            setHtml('cvPdfSummaryMeta', metaHtml);
            setHtml('cvPdfChecklistMeta', metaHtml);
            setHtml('cvPdfAllFieldsMeta', metaHtml);
            setHtml('cvPdfDocsMeta', metaHtml);

            var hostId = 'cvPrintAllFields';
            var host = document.getElementById(hostId);
            if (host) host.innerHTML = '';

            renderKeyValue(hostId, 'Case', cs);
            renderKeyValue(hostId, 'Application', app);
            renderKeyValue(hostId, 'Basic Details', basic);
            renderKeyValue(hostId, 'Contact Details', contact);
            renderKeyValue(hostId, 'Reference Details', ref);
            renderKeyValue(hostId, 'Social Media Details', social);
            renderKeyValue(hostId, 'E-Court Details', ecourt);
            renderKeyValue(hostId, 'Authorization', auth);

            renderArray(hostId, 'Identification Details', d.identification || []);
            renderArray(hostId, 'Education Details', d.education || []);
            renderArray(hostId, 'Employment Details', d.employment || []);

            renderDocsForPrint('cvPrintAllDocs', d.uploaded_docs || []);

            var summary = [];
            summary.push(kvBox('Candidate', coverName));
            summary.push(kvBox('Email', (basic.email || cs.candidate_email || '') + ''));
            summary.push(kvBox('Mobile', (basic.mobile || cs.candidate_mobile || '') + ''));
            summary.push(kvBox('Case ID', coverCase));
            summary.push(kvBox('Application ID', coverApp));
            summary.push(kvBox('Flow', workflowModeLabel(getWorkflowModeValue(d))));
            summary.push(kvBox('Status', (displayCaseStatus(app.status, cs.case_status) || '') + ''));
            setHtml('cvPdfSummaryGrid', summary.join(''));

            renderExecutive('cvPdfExecutive', d);
            renderChecklist('cvPdfChecklist', d.uploaded_docs || []);
            renderDocsGrouped('cvPdfDocsGrouped', d.uploaded_docs || []);
        }

        setText('cvHeaderCandidate', (cs.candidate_first_name || '') + ' ' + (cs.candidate_last_name || ''));
        setText('cvHeaderAppId', applicationId);
        setText('cvHeaderStatus', (displayCaseStatus(app.status, cs.case_status) || ''));
        setText('cvHeaderWorkflowMode', workflowModeLabel(getWorkflowModeValue(d)));
        setCandidateAvatar(basic, cs);
        var tatDays = cs && typeof cs.internal_tat !== 'undefined' ? (parseInt(cs.internal_tat || '20', 10) || 20) : 20;
        var rules = cs && cs.weekend_rules ? cs.weekend_rules : 'exclude';
        setText('cvHeaderTat', tatLabelFromCreated(cs.created_at || '', { internal_tat: tatDays, weekend_rules: rules }));
        setValidatorWorkspaceSummary(d);

        function applyTatSectionLabels() {
            var tatLabel = tatLabelFromCreated(cs.created_at || '', { internal_tat: tatDays, weekend_rules: rules });
            setText('cvSectionTatBasic', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatId', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatContact', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatEducation', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatEmployment', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatReference', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatSocialmedia', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatEcourt', tatLabel ? ('Component TAT: ' + tatLabel) : '');
            setText('cvSectionTatReports', tatLabel ? ('Component TAT: ' + tatLabel) : '');
        }

        applyTatSectionLabels();
        holidaysPromise.then(function () {
            applyTatSectionLabels();
            setText('cvHeaderTat', tatLabelFromCreated(cs.created_at || '', { internal_tat: tatDays, weekend_rules: rules }));
        }).catch(function () {
        });

        // If case is already approved, prevent further action changes from this view
        var statusStr = String(displayCaseStatus(app.status, cs.case_status) || '').toUpperCase();
        if (statusStr === 'APPROVED' || statusStr.indexOf('APPROVE') !== -1) {
            var insufficientBtn = document.getElementById('cvActionInsufficient');
            var holdBtn = document.getElementById('cvActionHold');
            var rejectBtn = document.getElementById('cvActionReject');
            var stopBtn = document.getElementById('cvActionStopBgv');
            var validatorInsufficientBtn = document.getElementById('cvValidatorActionInsufficient');
            var validatorHoldBtn = document.getElementById('cvValidatorActionHold');
            var validatorRejectBtn = document.getElementById('cvValidatorActionReject');
            if (holdBtn) holdBtn.style.display = 'none';
            if (rejectBtn) rejectBtn.style.display = 'none';
            if (stopBtn) stopBtn.style.display = 'none';
            if (insufficientBtn) insufficientBtn.style.display = 'none';
            if (validatorInsufficientBtn) validatorInsufficientBtn.style.display = 'none';
            if (validatorHoldBtn) validatorHoldBtn.style.display = 'none';
            if (validatorRejectBtn) validatorRejectBtn.style.display = 'none';
        }

        initCaseActions(applicationId);
        initVerifierMailAndPrint(function () { return REPORT_PAYLOAD; });
        initSectionVerificationMail(function () { return REPORT_PAYLOAD; });
        initCandidateAccessResend(function () { return REPORT_PAYLOAD; });
        initCandidateCorrectionRequest(function () { return REPORT_PAYLOAD; });

        setVal('cv_basic_first_name', basic.first_name || cs.candidate_first_name || '');
        setVal('cv_basic_last_name', basic.last_name || cs.candidate_last_name || '');
        setVal('cv_basic_dob', window.GSS_DATE.formatDbDateTime(basic.dob || ''));
        setVal('cv_basic_mobile', basic.mobile || cs.candidate_mobile || '');
        setVal('cv_basic_email', basic.email || cs.candidate_email || '');
        setVal('cv_basic_gender', basic.gender || '');
        setVal('cv_basic_father_name', basic.father_name || '');
        setVal('cv_basic_mother_name', basic.mother_name || '');
        setVal('cv_basic_country', basic.country || '');
        setVal('cv_basic_state', basic.state || '');
        setVal('cv_basic_nationality', basic.nationality || '');
        setVal('cv_basic_marital_status', basic.marital_status || '');

        renderTable('cv_basic_table', [{
            first_name: basic.first_name || cs.candidate_first_name || '',
            last_name: basic.last_name || cs.candidate_last_name || '',
            dob: window.GSS_DATE.formatDbDateTime(basic.dob || ''),
            mobile: basic.mobile || cs.candidate_mobile || '',
            email: basic.email || cs.candidate_email || '',
            gender: basic.gender || '',
            father_name: basic.father_name || '',
            mother_name: basic.mother_name || '',
            country: basic.country || '',
            state: basic.state || '',
            nationality: basic.nationality || '',
            marital_status: basic.marital_status || ''
        }], [
            { key: 'first_name', label: 'First Name' },
            { key: 'last_name', label: 'Last Name' },
            { key: 'dob', label: 'DOB' },
            { key: 'mobile', label: 'Mobile' },
            { key: 'email', label: 'Email' },
            { key: 'gender', label: 'Gender' },
            { key: 'father_name', label: 'Father Name' },
            { key: 'mother_name', label: 'Mother Name' },
            { key: 'country', label: 'Country' },
            { key: 'state', label: 'State' },
            { key: 'nationality', label: 'Nationality' },
            { key: 'marital_status', label: 'Marital Status' }
        ]);

        renderTable('cv_socialmedia_table', [{
            linkedin_url: social.linkedin_url || '',
            facebook_url: social.facebook_url || '',
            instagram_url: social.instagram_url || '',
            twitter_url: social.twitter_url || '',
            other_url: social.other_url || '',
            consent_bgv: Number(social.consent_bgv || 0) === 1 ? 'Yes' : (social.consent_bgv === null || typeof social.consent_bgv === 'undefined' ? '' : 'No'),
            content: social.content || ''
        }], [
            { key: 'linkedin_url', label: 'LinkedIn', forceText: true },
            { key: 'facebook_url', label: 'Facebook', forceText: true },
            { key: 'instagram_url', label: 'Instagram', forceText: true },
            { key: 'twitter_url', label: 'Twitter', forceText: true },
            { key: 'other_url', label: 'Other URL', forceText: true },
            { key: 'consent_bgv', label: 'Consent', forceText: true },
            { key: 'content', label: 'Content', forceText: true }
        ]);

        renderTable('cv_ecourt_table', [{
            current_address: ecourt.current_address || '',
            permanent_address: ecourt.permanent_address || '',
            evidence_document: ecourt.evidence_document || '',
            period_from_date: window.GSS_DATE.formatDbDateTime(ecourt.period_from_date || ''),
            period_to_date: window.GSS_DATE.formatDbDateTime(ecourt.period_to_date || ''),
            period_duration_years: ecourt.period_duration_years || '',
            dob: window.GSS_DATE.formatDbDateTime(ecourt.dob || ''),
            comments: ecourt.comments || ''
        }], [
            { key: 'current_address', label: 'Current Address' },
            { key: 'permanent_address', label: 'Permanent Address' },
            { key: 'evidence_document', label: 'Evidence Document' },
            { key: 'period_from_date', label: 'Period From' },
            { key: 'period_to_date', label: 'Period To' },
            { key: 'period_duration_years', label: 'Duration (Years)' },
            { key: 'dob', label: 'Date Of Birth' },
            { key: 'comments', label: 'Comments' }
        ]);

        renderTable('cv_reports_table', [{
            submitted_at: window.GSS_DATE.formatDbDateTime(app.submitted_at || ''),
            auth_signature: auth.digital_signature || auth.signature || auth.authorization_signature || auth.auth_signature || '',
            auth_file_name: auth.file_name || auth.authorization_file_name || auth.auth_file_name || auth.filename || '',
            auth_uploaded_at: window.GSS_DATE.formatDbDateTime(auth.uploaded_at || auth.authorization_uploaded_at || auth.auth_uploaded_at || auth.uploadedAt || '')
        }], [
            { key: 'submitted_at', label: 'Application Submitted At' },
            { key: 'auth_signature', label: 'Authorization Signature' },
            { key: 'auth_file_name', label: 'Authorization File Name' },
            { key: 'auth_uploaded_at', label: 'Authorization Uploaded At' }
        ]);

        setVal('cv_contact_current_address', [contact.address1, contact.address2, contact.city, contact.state, contact.country, contact.postal_code].filter(Boolean).join(', '));
        setVal('cv_contact_permanent_address', [contact.permanent_address1, contact.permanent_address2, contact.permanent_city, contact.permanent_state, contact.permanent_country, contact.permanent_postal_code].filter(Boolean).join(', '));
        setVal('cv_contact_proof_type', contact.proof_type || '');
        var contactProofFile = contact.proof_file || contact.address_proof_file || contact.address_proof || contact.proof || contact.proof_document || contact.proof_path || '';
        setFileField('cv_contact_proof_file', 'proof_file', contactProofFile || '');

        renderReferencePanelForSection(d, CURRENT_SECTION_KEY || LAST_COMPONENT_SECTION_KEY || 'reference');

        setVal('cv_social_linkedin_url', social.linkedin_url || '');
        setVal('cv_social_facebook_url', social.facebook_url || '');
        setVal('cv_social_instagram_url', social.instagram_url || '');
        setVal('cv_social_twitter_url', social.twitter_url || '');
        setVal('cv_social_other_url', social.other_url || '');
        setVal('cv_social_consent_bgv', Number(social.consent_bgv || 0) === 1 ? 'Yes' : (social.consent_bgv === null || typeof social.consent_bgv === 'undefined' ? '' : 'No'));
        setVal('cv_social_content', social.content || '');

        setVal('cv_ecourt_current_address', ecourt.current_address || '');
        setVal('cv_ecourt_permanent_address', ecourt.permanent_address || '');
        setVal('cv_ecourt_evidence_document', ecourt.evidence_document || '');
        setVal('cv_ecourt_period_from_date', window.GSS_DATE.formatDbDateTime(ecourt.period_from_date || ''));
        setVal('cv_ecourt_period_to_date', window.GSS_DATE.formatDbDateTime(ecourt.period_to_date || ''));
        setVal('cv_ecourt_period_duration_years', ecourt.period_duration_years || '');
        setVal('cv_ecourt_dob', window.GSS_DATE.formatDbDateTime(ecourt.dob || ''));
        setVal('cv_ecourt_comments', ecourt.comments || '');

        var authSignature = auth.digital_signature || auth.signature || auth.authorization_signature || auth.auth_signature || '';
        var authFileName = auth.file_name || auth.authorization_file_name || auth.auth_file_name || auth.filename || '';
        var authUploadedAt = auth.uploaded_at || auth.authorization_uploaded_at || auth.auth_uploaded_at || auth.uploadedAt || '';

        setVal('cv_auth_signature', authSignature || '');
        setVal('cv_auth_file_name', authFileName || '');
        setVal('cv_auth_uploaded_at', window.GSS_DATE.formatDbDateTime(authUploadedAt || ''));
        setVal('cv_app_submitted_at', window.GSS_DATE.formatDbDateTime(app.submitted_at || ''));

        setTimeout(function () {
            simplifyAllReadonlyFields();
        }, 0);

        ensureComponentTableRendered(CURRENT_SECTION_KEY || 'basic');
        setTimeout(function () { ensureComponentTableRendered('id'); }, 40);
        setTimeout(function () { ensureComponentTableRendered('education'); }, 90);
        setTimeout(function () { ensureComponentTableRendered('employment'); }, 140);

        var uploadTypeEl = document.getElementById('cvUploadDocType');
        var currentType = uploadTypeEl ? String(uploadTypeEl.value || '') : '';
        loadUploadedDocs(applicationId, currentType).catch(function () {
        });

        if (uploadTypeEl && !uploadTypeEl.dataset.bound) {
            uploadTypeEl.dataset.bound = '1';
            uploadTypeEl.addEventListener('change', function () {
                loadUploadedDocs(applicationId, String(uploadTypeEl.value || ''));
            });
        }

        var uploadBtn = document.getElementById('cvUploadBtn');
        if (uploadBtn) {
            uploadBtn.addEventListener('click', function () {
                uploadDocs(applicationId);
            });
        }

        try {
            var activeBtn = document.querySelector('.list-group-item[data-section].active');
            var activeSection = activeBtn ? String(activeBtn.getAttribute('data-section') || '').toLowerCase() : 'basic';
            if (activeSection) ensureComponentToolbar(activeSection);
            if (activeSection) {
                CURRENT_SECTION_KEY = normSection(activeSection);
                LAST_COMPONENT_SECTION_KEY = CURRENT_SECTION_KEY;
                try {
                    document.dispatchEvent(new CustomEvent('cv:section-changed', { detail: { section: activeSection } }));
                } catch (_e2) {}
            }
        } catch (_e) {
        }

        setTimeout(function () {
            loadTimeline(applicationId, { sync: false });
        }, 120);

        if (root) root.setAttribute('data-ui-ready', '1');

        return d;
    } finally {
        LOAD_REPORT_IN_FLIGHT = false;
        if (LOAD_REPORT_PENDING_OPTS) {
            var pendingOpts = LOAD_REPORT_PENDING_OPTS;
            LOAD_REPORT_PENDING_OPTS = null;
            setTimeout(function () {
                loadReport(pendingOpts).catch(function () {});
            }, 0);
        }
    }
    }

    function initCandidateReportPage() {
        if (document.body.dataset.candidateReportInit === '1') return;
        document.body.dataset.candidateReportInit = '1';
        var deprecatedCompleteBtn = document.getElementById('cvCompleteGroupBtn');
        if (deprecatedCompleteBtn) {
            deprecatedCompleteBtn.style.display = 'none';
            deprecatedCompleteBtn.disabled = true;
        }
        initUploadPicker();
        initValidatorRemarks();
        initDocViewer();
        initDocViewModal();
        initClientAdminEscalation();
        bindRepliesSyncButton();
        bindRepliesAutoRefresh();
        window.addEventListener('resize', function () {
            updateReviewActionbarTitle(activeComponentSectionKey());
        });
        if (document.querySelector('.cr-report-root.cr-validator-workspace') && String(qs('print') || '') !== '1') {
            document.body.style.overflow = 'hidden';
        }
        loadReport().then(function (payload) {
            initVerifierCompleteNext(function () { return payload; });
        }).catch(function (e) {
            var root = document.querySelector('.cr-report-root');
            if (root) root.setAttribute('data-ui-ready', '1');
            setText('cvTopMessage', (e && e.message) ? e.message : 'Failed to load report');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCandidateReportPage);
    } else {
        initCandidateReportPage();
    }

setTimeout(function () {
    if (!isLegacyPdfViewerEnabled()) return;

    const dropZone = document.getElementById('dropZone');
    const input = document.getElementById('uploadInput');

    if (!dropZone || !input) return;

    function handleFile(file) {
        if (!file) return;

        const url = URL.createObjectURL(file);

        document.getElementById('uploadPane').style.display = 'block';
        document.getElementById('pdfViewerContent').classList.add('split');

        document.getElementById('uploadPreview').innerHTML =
            `<iframe src="${buildPdfViewerUrl(url)}" style="width:100%;height:100%"></iframe>`;
    }

    dropZone.addEventListener('click', function () {
        input.click();
    });

    dropZone.addEventListener('dragover', function (e) {
        e.preventDefault();
    });

    dropZone.addEventListener('drop', function (e) {
        e.preventDefault();
        handleFile(e.dataTransfer.files[0]);
    });

    input.addEventListener('change', function (e) {
        handleFile(e.target.files[0]);
    });

}, 500);
})();



