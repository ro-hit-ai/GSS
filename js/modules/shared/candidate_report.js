(function () {
    var REPORT_PAYLOAD = null;
    var CURRENT_APP_ID = '';
    var CURRENT_SECTION_KEY = '';
    var LAST_COMPONENT_SECTION_KEY = '';
    var CURRENT_MODAL_REASON_TYPE = '';
    var TL_CACHE = [];
    var TL_ACTIVE_FILTER = 'all';

    var SELECTED_UPLOAD_FILES = [];

    var HOLIDAY_SET = {};
    var HOLIDAYS_LOADED = false;

    function qs(name) {
        try {
            return new URLSearchParams(window.location.search || '').get(name);
        } catch (e) {
            return null;
        }
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

        // Get Bootstrap modal instance
        if (window.bootstrap && window.bootstrap.Modal) {
            var modal = window.bootstrap.Modal.getInstance(el);
            if (modal) {
                modal.hide();
            } else {
                // Manual hide if instance doesn't exist
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
            }
        } else {
            el.classList.remove('show');
            el.style.display = 'none';
            el.setAttribute('aria-hidden', 'true');
        }

        // Aggressive cleanup of all modal artifacts
        setTimeout(function () {
            // Remove all backdrops
            document.querySelectorAll('.modal-backdrop').forEach(function (b) {
                if (b && b.parentNode) {
                    b.parentNode.removeChild(b);
                }
            });
            
            // Reset body and html classes/styles
            document.body.classList.remove('modal-open');
            document.documentElement.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.body.style.removeProperty('overflow');
            document.documentElement.style.removeProperty('overflow');
            document.documentElement.style.removeProperty('padding-right');
            
            // Ensure no modals are still visible
            document.querySelectorAll('.modal.show').forEach(function (m) {
                m.classList.remove('show');
                m.style.display = 'none';
                m.setAttribute('aria-hidden', 'true');
            });

            // Restore validator overflow lock if needed
            if (document.querySelector('.cr-report-root.cr-role-validator') &&
                String(qs('print') || '') !== '1') {
                document.body.style.overflow = 'hidden';
            }
        }, 100);
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

    async function initVerifierMailAndPrint(getPayload) {
        var role = getRole();
        if (role !== 'verifier') return;

        var openBtn = document.getElementById('cvOpenMailModal');
        var printBtn = document.getElementById('cvPrintLetterBtn');
        var tplSel = document.getElementById('cvMailTemplateSelect');
        var toEl = document.getElementById('cvMailToEmail');
        var subjEl = document.getElementById('cvMailSubject');
        var previewEl = document.getElementById('cvMailPreview');
        var sendBtn = document.getElementById('cvMailSendBtn');

        if (!openBtn || !tplSel || !previewEl || !sendBtn) return;

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var templates = [];

        function getContext() {
            var payload = getPayload ? getPayload() : null;
            var caseId = payload && payload.case && payload.case.case_id ? parseInt(payload.case.case_id, 10) : 0;
            var appId = payload && payload.case && payload.case.application_id ? String(payload.case.application_id) : (qs('application_id') || '');
            var group = getVerifierGroup();
            return { case_id: caseId || null, application_id: appId || null, role: 'verifier', group: group || null };
        }

        async function loadTemplates(type) {
            var url = base + '/api/shared/mail_templates_list.php';
            if (type) url += '?type=' + encodeURIComponent(type);
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : 'Failed to load templates');
            }
            return Array.isArray(data.data) ? data.data : [];
        }

        function setTplOptions(list) {
            templates = Array.isArray(list) ? list : [];
            tplSel.innerHTML = '<option value="">Select Template</option>';
            templates.forEach(function (t) {
                var opt = document.createElement('option');
                opt.value = String(t.template_id || '');
                opt.textContent = (t.template_name || ('Template #' + t.template_id)) + (t.template_type ? (' (' + t.template_type + ')') : '');
                tplSel.appendChild(opt);
            });
        }

        async function renderSelected() {
            var tplId = parseInt(tplSel.value || '0', 10) || 0;
            if (!tplId) {
                if (subjEl) subjEl.value = '';
                previewEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">Select a template to preview.</div>';
                return;
            }

            previewEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">Loading preview...</div>';
            setBoxMessage('cvMailMessage', '', '');

            var ctx = getContext();

            var res = await fetch(base + '/api/shared/mail_template_render.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ template_id: tplId, application_id: ctx.application_id, case_id: ctx.case_id, role: ctx.role, group: ctx.group })
            });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1 || !data.data) {
                throw new Error((data && data.message) ? data.message : 'Failed to render');
            }

            if (subjEl) subjEl.value = data.data.subject || '';
            previewEl.innerHTML = data.data.html || ('<pre style="white-space:pre-wrap; margin:0;">' + escHtml(data.data.body || '') + '</pre>');
        }

        async function sendMail() {
            var tplId = parseInt(tplSel.value || '0', 10) || 0;
            if (!tplId) {
                setBoxMessage('cvMailMessage', 'Please select template.', 'danger');
                return;
            }
            var to = toEl ? String(toEl.value || '').trim() : '';
            if (!to) {
                setBoxMessage('cvMailMessage', 'To Email is required.', 'danger');
                return;
            }

            setBoxMessage('cvMailMessage', '', '');
            sendBtn.disabled = true;
            sendBtn.dataset.originalText = sendBtn.dataset.originalText || sendBtn.textContent;
            sendBtn.textContent = 'Sending...';

            try {
                var ctx = getContext();
                var res = await fetch(base + '/api/shared/mail_template_send.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ template_id: tplId, to_email: to, application_id: ctx.application_id, case_id: ctx.case_id, role: ctx.role, group: ctx.group })
                });
                var data = await res.json().catch(function () { return null; });
                if (!res.ok || !data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Send failed');
                }
                setBoxMessage('cvMailMessage', 'Sent successfully.', 'success');
            } catch (e) {
                setBoxMessage('cvMailMessage', (e && e.message) ? e.message : 'Send failed', 'danger');
            } finally {
                sendBtn.disabled = false;
                sendBtn.textContent = sendBtn.dataset.originalText || 'Send';
            }
        }

        async function printLetter() {
            var tplId = parseInt(tplSel.value || '0', 10) || 0;
            if (!tplId) {
                alert('Please select template from Mail modal first.');
                return;
            }
            var ctx = getContext();

            var res = await fetch(base + '/api/shared/mail_template_render.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ template_id: tplId, application_id: ctx.application_id, case_id: ctx.case_id, role: ctx.role, group: ctx.group })
            });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1 || !data.data) {
                alert((data && data.message) ? data.message : 'Failed to render letter');
                return;
            }

            var w = window.open('', '_blank');
            if (!w) return;
            var title = data.data.template_name || 'Letter';
            w.document.open();
            w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escHtml(title) + '</title>' +
                '<style>body{font-family:Arial, sans-serif; padding:18px;} @media print{body{padding:0;}}</style>' +
                '</head><body>' + (data.data.html || '') + '</body></html>');
            w.document.close();
            try { w.focus(); } catch (e) {}
            setTimeout(function () { try { w.print(); } catch (e) {} }, 300);
        }

        if (!openBtn.dataset.bound) {
            openBtn.dataset.bound = '1';
            openBtn.addEventListener('click', async function () {
                try {
                    if (!templates.length) {
                        var list = await loadTemplates('email');
                        setTplOptions(list);
                    }

                    var payload = getPayload ? getPayload() : null;
                    var basic = payload && payload.basic ? payload.basic : null;
                    if (toEl && basic && (basic.email || basic.candidate_email)) {
                        toEl.value = String(basic.email || basic.candidate_email || '');
                    }

                    previewEl.innerHTML = '<div style="color:#6b7280; font-size:13px;">Select a template to preview.</div>';
                    if (subjEl) subjEl.value = '';
                    setBoxMessage('cvMailMessage', '', '');
                    openBsModal('cvMailModal');
                } catch (e) {
                    setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Failed to open mail', 'danger');
                }
            });
        }

        if (!tplSel.dataset.bound) {
            tplSel.dataset.bound = '1';
            tplSel.addEventListener('change', function () {
                renderSelected().catch(function (e) {
                    setBoxMessage('cvMailMessage', (e && e.message) ? e.message : 'Failed to render', 'danger');
                });
            });
        }

        if (!sendBtn.dataset.bound) {
            sendBtn.dataset.bound = '1';
            sendBtn.addEventListener('click', sendMail);
        }

        if (printBtn && !printBtn.dataset.bound) {
            printBtn.dataset.bound = '1';
            printBtn.addEventListener('click', function () {
                if (!tplSel.value) {
                    openBtn.click();
                    return;
                }
                printLetter();
            });
        }
    }

    function getVerifierGroup() {
        var g = (window.VR_GROUP || qs('group') || '').toString().toUpperCase().trim();
        // Verifier queue groups
        if (g === 'BASIC' || g === 'EDUCATION') return g;
        return '';
    }

    function getRole() {
        var q = String(qs('role') || '').toLowerCase().trim();
        if (q) return q;
        return String(window.CURRENT_ROLE || '').toLowerCase().trim();
    }

    function setBoxMessage(id, text, type) {
        var el = document.getElementById(id);
        if (!el) return;
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

    function displayCaseStatus(appStatus, caseStatus) {
        var a = String(appStatus || '').trim();
        var c = String(caseStatus || '').trim();
        var role = getRole();
        var cu = c.toUpperCase();
        if (cu === 'REJECTED' || cu === 'STOP_BGV' || cu === 'APPROVED' || cu === 'VERIFIED' || cu === 'COMPLETED' || cu === 'CLEAR') {
            return c;
        }
        var au = a.toUpperCase();
        if (role === 'validator' || role === 'db_verifier') {
            if (cu === 'PENDING_VERIFIER') return 'Pending Verifier';
            if (cu === 'PENDING_QA') return 'Pending QA';
            if (cu === 'PENDING_VALIDATOR' || cu === 'IN_PROGRESS') return 'Pending Validator';
            if (cu === 'PENDING_CANDIDATE' || cu === 'CANDIDATE_PENDING' || cu === 'INVITED') {
                if (au === 'SUBMITTED' || au === 'PENDING_VALIDATOR') return 'Pending Validator';
            }
            if (au === 'SUBMITTED') return 'Pending Validator';
        }
        if (role === 'verifier') {
            if (cu === 'PENDING_VERIFIER' || au === 'PENDING_VERIFIER') return 'Pending Verifier';
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
        if (section === 'employment') return 'Employment';
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

    function canShowComponentToolbar(section) {
        section = String(section || '').toLowerCase().trim();
        if (!section || section === 'timeline') return false;
        var role = getRole();
        if (!canTakeActionRole(role)) return false;
        // Only for real components (skip reports/contact if you later remove them)
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
            // refresh docs list when switching
            var docsHost = panel.querySelector('[data-comp-docs]');
            if (docsHost) {
                loadUploadedDocsForComponent(CURRENT_APP_ID, section, docsHost);
            }
            return;
        }

        panel.dataset.compToolsBound = '1';
        var wrap = document.createElement('div');
        wrap.innerHTML = componentToolbarHtml(section);

        // Keep evidence/upload block at bottom of the left content column when chat layout exists.
        var leftCol = panel.querySelector('.cr-comp-left');
        var hostForInsert = leftCol || panel;
        var firstRemarks = hostForInsert.querySelector('.cr-remarks');
        var remarksRow = firstRemarks && firstRemarks.closest ? firstRemarks.closest('.form-control') : null;
        if (remarksRow && remarksRow.parentElement) {
            remarksRow.parentElement.insertBefore(wrap, remarksRow);
        } else if (firstRemarks && firstRemarks.parentElement) {
            firstRemarks.parentElement.insertBefore(wrap, firstRemarks);
        } else {
            hostForInsert.appendChild(wrap);
        }

        var docsHost2 = panel.querySelector('[data-comp-docs]');
        if (docsHost2) {
            loadUploadedDocsForComponent(CURRENT_APP_ID, section, docsHost2);
        }

        wrap.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t) return;

            var upBtn = t.closest ? t.closest('[data-comp-upload]') : null;
            if (upBtn) {
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
    }

    function renderComponentNav(payload) {
        var role = getRole();
        if (!canTakeActionRole(role)) return;

        var wrap = document.getElementById('cvComponentNav');
        var host = document.getElementById('cvComponentNavItems');
        if (!wrap || !host) return;

        var keys = getAssignedComponentKeys(payload);
        // Keep only real component keys we support in UI; ignore empty.
        keys = (keys || []).filter(function (k) { return !!k; });

        host.innerHTML = keys.map(function (k) {
            return '<button type="button" class="cr-compnav-btn" data-comp="' + esc(k) + '">' + esc(sectionLabel(k)) + '</button>';
        }).join('');

        if (!host.dataset.bound) {
            host.dataset.bound = '1';
            host.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var btn = t && t.closest ? t.closest('[data-comp]') : null;
                if (!btn) return;
                var sec = btn.getAttribute('data-comp') || '';

                // Reuse existing sidebar button handler (even if sidebar is hidden)
                var sidebarBtn = document.querySelector('.list-group-item[data-section="' + sec.replace(/"/g, '') + '"]');
                if (sidebarBtn) {
                    sidebarBtn.click();
                }
                setCompNavActive(sec);
            });
        }

        // Sync initial active from sidebar
        var activeSidebar = document.querySelector('.list-group-item[data-section].active');
        var activeSection = activeSidebar ? (activeSidebar.getAttribute('data-section') || '') : (keys[0] || 'basic');
        setCompNavActive(activeSection);
    }

    function getAssignedComponentKeys(payload) {
        payload = payload || {};
        var d = payload.data || payload;
        var role = getRole();
        var list = Array.isArray(d.assigned_components) ? d.assigned_components : [];
        var out = {};
        list.forEach(function (r) {
            var k = (r && r.component_key) ? String(r.component_key).toLowerCase().trim() : '';
            k = normSection(k);
            if (k) out[k] = true;
        });

        // Defensive union: if payload has section data but assigned_components is stale/partial,
        // keep those sections visible for staff users to avoid sidebar dropping valid sections.
        if (d && typeof d === 'object') {
            if (Array.isArray(d.identification) && d.identification.length) out.id = true;
            if (Array.isArray(d.education) && d.education.length) out.education = true;
            if (Array.isArray(d.employment) && d.employment.length) out.employment = true;

            if (d.basic && typeof d.basic === 'object' && Object.keys(d.basic).length) out.basic = true;
            if (d.contact && typeof d.contact === 'object' && Object.keys(d.contact).length) out.contact = true;
            if (d.reference && typeof d.reference === 'object' && Object.keys(d.reference).length) out.reference = true;
            if (d.social_media && typeof d.social_media === 'object' && Object.keys(d.social_media).length) out.socialmedia = true;
            if (d.ecourt && typeof d.ecourt === 'object' && Object.keys(d.ecourt).length) out.ecourt = true;
            if (d.authorization && typeof d.authorization === 'object' && Object.keys(d.authorization).length) out.reports = true;
        }

        // Keep validator-rejected components visible for verifier sidebar even when
        // assigned_components is filtered by queue group.
        if (role === 'verifier' && d && d.component_workflow && typeof d.component_workflow === 'object') {
            Object.keys(d.component_workflow).forEach(function (wk) {
                var k = normSection(wk);
                if (!k) return;
                var row = d.component_workflow[wk] || {};
                var validator = row && row.validator ? row.validator : null;
                var vStatus = validator && validator.status ? String(validator.status).toLowerCase().trim() : '';
                if (vStatus === 'rejected') {
                    out[k] = true;
                }
            });
        }

        return Object.keys(out);
    }

    function normSection(s) {
        s = String(s || '').toLowerCase().trim();
        if (!s) return '';
        if (s === 'identification') return 'id';
        if (s === 'address') return 'contact';
        if (s === 'references') return 'reference';
        if (s === 'social_media' || s === 'social-media') return 'socialmedia';
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
                var ts = '';
                try {
                    ts = it.created_at ? window.GSS_DATE.formatDbDateTime(it.created_at) : '';
                } catch (_e) {
                    ts = it.created_at ? String(it.created_at) : '';
                }

                var dotTone = 'blue';
                var tLower = String(type || '').toLowerCase();
                var mLower = String(msg || '').toLowerCase();
                if (tLower.indexOf('hold') !== -1) dotTone = 'amber';
                if (tLower.indexOf('reject') !== -1) dotTone = 'red';
                if (tLower.indexOf('approve') !== -1 || tLower.indexOf('complete') !== -1) dotTone = 'green';
                if (mLower.indexOf('hold') !== -1) dotTone = 'amber';
                if (mLower.indexOf('reject') !== -1) dotTone = 'red';
                if (mLower.indexOf('approve') !== -1 || mLower.indexOf('status: approved') !== -1) dotTone = 'green';

                var badges = [];
                if (type) badges.push('<span class="badge bg-secondary" style="margin-right:6px;">' + esc(type) + '</span>');
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
                            (badges.length ? ('<div class="cr-flow-badges">' + badges.join('') + '</div>') : '') +
                            (msg ? ('<div class="cr-flow-msg">' + esc(msg) + '</div>') : '') +
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

    async function loadTimeline(applicationId) {
        var host = document.getElementById('cvTimeline');
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

            var items = Array.isArray(data.data) ? data.data : [];
            TL_CACHE = items.filter(function (it) { return !isWholeCaseCompletionItem(it); });
            host.innerHTML = timelineHtml(TL_CACHE);
            renderMiniTimeline();

            try {
                var activeBtn = document.querySelector('.list-group-item[data-section].active');
                var activeSec = activeBtn ? (activeBtn.getAttribute('data-section') || '') : '';
                renderRemarksPanel(activeSec);
                if (activeSec) {
                    var activePanel = document.getElementById('section-' + String(activeSec).toLowerCase());
                    if (activePanel) ensureComponentChat(activePanel, activeSec);
                }
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

    function ensureComponentChat(panel, section) {
        if (!panel) return;
        section = normSection(section);
        if (!section) return;

        var role = getRole();
        if (!canTakeActionRole(role)) return;
        if (String(qs('print') || '') === '1') return;

        // Create right-side chat column once
        if (panel.dataset.chatBound !== '1') {
            panel.dataset.chatBound = '1';

            var secbar = panel.querySelector('.cr-secbar');
            if (!secbar) return;

            // Wrap all content after secbar into a two-column layout
            var after = [];
            var n = secbar.nextSibling;
            while (n) {
                var next = n.nextSibling;
                // skip empty text nodes
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

            // Move all existing nodes into left column
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

        // Refresh chat list for this section
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
        if (role !== 'validator') return;

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
                        section_key: String(cfg.section || 'basic'),
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

    function openBsModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (window.bootstrap && window.bootstrap.Modal && typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
            var m = window.bootstrap.Modal.getOrCreateInstance(el);
            m.show();
            return;
        }
        el.style.display = 'block';
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
        if (role !== 'validator') return;

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

        function setActive(idx) {
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
                frameHost.innerHTML = '<img src="' + esc(href) + '" alt="document" />';
                return;
            }

            if (isPdfMime(mt) || href.toLowerCase().indexOf('.pdf') !== -1) {
                frameHost.innerHTML = '<iframe src="' + esc(href) + '"></iframe>';
                return;
            }

            frameHost.innerHTML = '<div style="padding:10px; color:#0f172a; font-size:12px;">' +
                '<div style="font-weight:900; margin-bottom:6px;">Preview not available</div>' +
                '<a href="' + esc(href) + '" target="_blank" style="text-decoration:none; color:#2563eb; font-weight:800;">Open document</a>' +
                '</div>';
        }

        if (!listHost.dataset.bound) {
            listHost.dataset.bound = '1';
            listHost.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                var item = t && t.closest ? t.closest('.cr-docbar-item') : null;
                if (!item) return;
                var idx = item.getAttribute('data-doc-idx');
                setActive(idx);
            });
        }

        setActive(0);
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

        return '<a href="' + esc(href) + '" class="js-cv-doc-view" data-doc-label="' + esc(v) + '" style="text-decoration:none; color:#2563eb; font-weight:600;">View</a>' +
            '<div style="color:#64748b; font-size:12px; margin-top:2px;">' + esc(v) + '</div>';
    }

    function setBadge(id, kind, text) {
        var el = document.getElementById(id);
        if (!el) return;
        var k = String(kind || '').toLowerCase();
        el.classList.remove('bg-success', 'bg-warning', 'bg-secondary', 'bg-danger', 'text-dark');
        if (k === 'done') {
            el.classList.add('bg-success');
            el.textContent = text || '✔';
            return;
        }
        if (k === 'wip') {
            el.classList.add('bg-warning', 'text-dark');
            el.textContent = text || 'WIP';
            return;
        }
        if (k === 'rejected') {
            el.classList.add('bg-danger');
            el.textContent = text || 'Rejected';
            return;
        }
        el.classList.add('bg-secondary');
        el.textContent = text || 'Pending';
    }

    function isFilled(v) {
        return v != null && String(v).trim() !== '';
    }

    function computeComponentStageLabel(stages) {
        var cand = String(stages && stages.candidate ? stages.candidate : '').toLowerCase().trim();
        var val = String(stages && stages.validator ? stages.validator : '').toLowerCase().trim();
        var ver = String(stages && stages.verifier ? stages.verifier : '').toLowerCase().trim();
        var qa = String(stages && stages.qa ? stages.qa : '').toLowerCase().trim();
        if (qa === 'rejected') return 'QA Rejected';
        if (qa === 'approved') return 'Completed';
        if (ver === 'rejected') return 'Verifier Rejected';
        if (ver === 'approved') return 'Pending QA';
        if (val === 'rejected') return 'Validator Rejected';
        if (cand === 'rejected') return 'Candidate Rejected';
        if (val === 'approved') return 'Pending Verifier';
        if (cand === 'approved') return 'Pending Validator';
        return '';
    }

    function normalizeStageLabelForRole(stageLabel) {
        var raw = String(stageLabel || '').trim();
        var low = raw.toLowerCase();
        if (low === 'pendingverifier') return 'Pending Verifier';
        if (low === 'pendingvalidator') return 'Pending Validator';
        if (low === 'pendingqa') return 'Pending QA';
        return raw;
    }

    function getWorkflowComponentRow(d, componentKey) {
        try {
            componentKey = normSection(componentKey);
            if (!componentKey) return null;
            var cw = d && d.component_workflow ? d.component_workflow : null;
            if (!cw || typeof cw !== 'object') return null;
            if (cw[componentKey] && typeof cw[componentKey] === 'object') return cw[componentKey];

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

            // Prefer workflow table status (freshest source) when available.
            var byComp = getWorkflowComponentRow(d, componentKey);
            if (byComp && typeof byComp === 'object') {
                var stSimple = {
                    candidate: byComp.candidate && byComp.candidate.status ? String(byComp.candidate.status) : '',
                    validator: byComp.validator && byComp.validator.status ? String(byComp.validator.status) : '',
                    verifier: byComp.verifier && byComp.verifier.status ? String(byComp.verifier.status) : '',
                    qa: byComp.qa && byComp.qa.status ? String(byComp.qa.status) : ''
                };
                var fromWorkflow = normalizeStageLabelForRole(computeComponentStageLabel(stSimple));
                if (fromWorkflow) return fromWorkflow;
            }

            var list = Array.isArray(d && d.assigned_components) ? d.assigned_components : [];
            for (var i = 0; i < list.length; i++) {
                var r = list[i] || {};
                var k = r.component_key ? normSection(r.component_key) : '';
                if (k === componentKey) {
                    return normalizeStageLabelForRole(r.current_stage ? String(r.current_stage) : '');
                }
            }
            return '';
        } catch (e) {
            return '';
        }
    }

    function setStageBadge(badgeId, stageLabel) {
        stageLabel = normalizeStageLabelForRole(stageLabel);
        stageLabel = String(stageLabel || '').trim();
        if (!stageLabel) return false;

        var low = stageLabel.toLowerCase();
        if (low.indexOf('rejected') !== -1) {
            setBadge(badgeId, 'rejected', stageLabel);
            return true;
        }
        if (low === 'completed') {
            setBadge(badgeId, 'done', 'Completed');
            return true;
        }
        if (low.indexOf('pending') === 0) {
            setBadge(badgeId, 'wip', stageLabel);
            return true;
        }

        setBadge(badgeId, 'pending', stageLabel);
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

        var basicDone = isFilled(basic.first_name) || isFilled(basic.last_name) || isFilled(basic.dob);
        var idDone = identification.length > 0;
        var contactDone = isFilled(contact.address1) || isFilled(contact.permanent_address1) || isFilled(contact.city) || isFilled(contact.state);
        var eduDone = education.length > 0;
        var empDone = employment.length > 0;
        var refDone = isFilled(ref.reference_name) || isFilled(ref.reference_mobile) || isFilled(ref.reference_email);
        var socialDone = isFilled(social.linkedin_url) || isFilled(social.facebook_url) || isFilled(social.instagram_url) || isFilled(social.twitter_url) || isFilled(social.other_url);
        var ecourtDone = isFilled(ecourt.current_address) || isFilled(ecourt.permanent_address) || isFilled(ecourt.evidence_document);
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
            ecourt: isValidatorRejectedOpenState(d, 'ecourt')
        };
        if (forcedRejected.basic) setBadge('cvNavBadgeBasic', 'rejected', 'Validator Rejected');
        if (forcedRejected.id) setBadge('cvNavBadgeId', 'rejected', 'Validator Rejected');
        if (forcedRejected.contact) setBadge('cvNavBadgeContact', 'rejected', 'Validator Rejected');
        if (forcedRejected.education) setBadge('cvNavBadgeEducation', 'rejected', 'Validator Rejected');
        if (forcedRejected.employment) setBadge('cvNavBadgeEmployment', 'rejected', 'Validator Rejected');
        if (forcedRejected.reference) setBadge('cvNavBadgeReference', 'rejected', 'Validator Rejected');
        if (forcedRejected.socialmedia) setBadge('cvNavBadgeSocialmedia', 'rejected', 'Validator Rejected');
        if (forcedRejected.ecourt) setBadge('cvNavBadgeEcourt', 'rejected', 'Validator Rejected');

        // Prefer workflow stage when available
        var usedWorkflow = false;
        if (!forcedRejected.basic) usedWorkflow = setStageBadge('cvNavBadgeBasic', getWorkflowStageLabel(d, 'basic')) || usedWorkflow;
        if (!forcedRejected.id) usedWorkflow = setStageBadge('cvNavBadgeId', getWorkflowStageLabel(d, 'id')) || usedWorkflow;
        if (!forcedRejected.contact) usedWorkflow = setStageBadge('cvNavBadgeContact', getWorkflowStageLabel(d, 'contact')) || usedWorkflow;
        if (!forcedRejected.education) usedWorkflow = setStageBadge('cvNavBadgeEducation', getWorkflowStageLabel(d, 'education')) || usedWorkflow;
        if (!forcedRejected.employment) usedWorkflow = setStageBadge('cvNavBadgeEmployment', getWorkflowStageLabel(d, 'employment')) || usedWorkflow;
        if (!forcedRejected.reference) usedWorkflow = setStageBadge('cvNavBadgeReference', getWorkflowStageLabel(d, 'reference')) || usedWorkflow;
        if (!forcedRejected.socialmedia) usedWorkflow = setStageBadge('cvNavBadgeSocialmedia', getWorkflowStageLabel(d, 'socialmedia')) || usedWorkflow;
        if (!forcedRejected.ecourt) usedWorkflow = setStageBadge('cvNavBadgeEcourt', getWorkflowStageLabel(d, 'ecourt')) || usedWorkflow;

        if (!usedWorkflow) {
            setBadge('cvNavBadgeBasic', basicDone ? 'done' : 'pending');
            setBadge('cvNavBadgeId', idDone ? 'done' : 'pending');
            setBadge('cvNavBadgeContact', contactDone ? 'done' : 'pending');
            setBadge('cvNavBadgeEducation', eduDone ? 'done' : 'pending');
            setBadge('cvNavBadgeEmployment', empDone ? 'done' : 'pending');
            setBadge('cvNavBadgeReference', refDone ? 'done' : 'pending');
            setBadge('cvNavBadgeSocialmedia', socialDone ? 'done' : 'pending');
            setBadge('cvNavBadgeEcourt', ecourtDone ? 'done' : 'pending');
        }

        setBadge('cvNavBadgeReports', reportsDone ? 'done' : 'pending');
    }

    function setVal(id, value) {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = (value === null || typeof value === 'undefined') ? '' : String(value);
    }

    function setFileField(id, fieldKey, value) {
        var el = document.getElementById(id);
        if (!el) return;
        var v = (value === null || typeof value === 'undefined') ? '' : String(value);
        v = v.trim();

        var href = fileUrlForField(fieldKey, v);
        if (href) {
            var wrap = document.createElement('div');
            wrap.style.display = 'flex';
            wrap.style.alignItems = 'center';
            wrap.style.gap = '10px';

            var a = document.createElement('a');
            a.href = href;
            a.target = '_blank';
            a.rel = 'noopener';
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

        el.style.display = 'none';
        el.insertAdjacentElement('afterend', textEl);
        el.dataset.simpleDone = '1';
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
            host.innerHTML = '<div style="color:#6b7280; font-size:13px;">No data.</div>';
            return;
        }

        rows = rows.map(window.GSS_DATE.formatRowDates);
        host.innerHTML = rows.map(function (r, idx) {
            var body = columns.map(function (c) {
                var key = c && c.key ? String(c.key) : '';
                var label = c && c.label ? String(c.label) : key;
                var v = r ? r[key] : '';
                var href = fileUrlForField(key, v);
                var valHtml = href ? fileCellHtml(key, v) : ('<span style="font-weight:800; color:#0f172a;">' + esc(v) + '</span>');
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
        ['cvActionHold', 'cvActionReject', 'cvActionStopBgv', 'cvActionApprove'].forEach(function (id) {
            var b = document.getElementById(id);
            if (!b) return;
            b.disabled = !!disabled;
        });
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

        function getStageStatusFor(componentKey, stage) {
            try {
                componentKey = normSection(componentKey);
                stage = String(stage || '').toLowerCase().trim();
                if (!componentKey || !stage) return '';

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
            ['cvActionHold', 'cvActionReject', 'cvActionApprove'].forEach(function (id) {
                var b = document.getElementById(id);
                if (!b) return;
                b.disabled = !enabled;
            });
        }

        function applyComponentActionLock() {
            try {
                var role = getRole();
                var stage = roleToStage(role);
                if (!(stage === 'validator' || stage === 'verifier' || stage === 'qa')) {
                    return;
                }

                var componentKey = currentSectionKey();
                if (!componentKey || componentKey === 'timeline') return;

                var st = getStageStatusFor(componentKey, stage);
                if (role === 'verifier') {
                    if (st === 'approved' || st === 'rejected') {
                        setComponentActionButtonsEnabled(false);
                        setText('cvTopMessage', 'This component is already ' + st + ' for ' + stage + '.');
                        return;
                    }
                    var validatorSt = getStageStatusFor(componentKey, 'validator');
                    if (validatorSt === 'rejected') {
                        setComponentActionButtonsEnabled(true);
                        setText('cvTopMessage', 'Validator rejected this component. Approval requires reason.');
                    } else {
                        setComponentActionButtonsEnabled(true);
                    }
                    return;
                }
                if (role === 'qa' || role === 'team_lead') {
                    var verifierSt = getStageStatusFor(componentKey, 'verifier');
                    if (verifierSt === 'rejected' && !(st === 'approved' || st === 'rejected')) {
                        setText('cvTopMessage', 'Verifier rejected this component. QA action requires reason.');
                    }
                }
                if (st === 'approved' || st === 'rejected') {
                    setComponentActionButtonsEnabled(false);
                    setText('cvTopMessage', 'This component is already ' + st + ' for ' + stage + '.');
                } else {
                    setComponentActionButtonsEnabled(true);
                }
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

        // Fallback if modal not available
        if (!modalEl || !yesBtn || !window.bootstrap) {
            resolve(window.confirm('Confirm: ' + label + '?'));
            return;
        }

        var done = false;
        
        // Force remove any existing backdrops before showing modal
        document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
            if (backdrop && backdrop.parentNode) {
                backdrop.parentNode.removeChild(backdrop);
            }
        });
        
        // Reset all modal-related classes and styles
        document.body.classList.remove('modal-open');
        document.documentElement.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('overflow');
        document.documentElement.style.removeProperty('padding-right');

        // Get fresh modal instance
        var modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);

        function cleanupAndResolve(result) {
            if (done) return;
            done = true;

            // Remove event listeners
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            yesBtn.removeEventListener('click', onYes);
            if (noBtn) noBtn.removeEventListener('click', onNo);

            // Hide the modal
            modal.hide();

            // Aggressive cleanup after modal hide animation
            setTimeout(function () {
                // Remove all backdrop elements
                document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
                    if (backdrop && backdrop.parentNode) {
                        backdrop.parentNode.removeChild(backdrop);
                    }
                });
                
                // Reset all body and html classes/styles
                document.body.classList.remove('modal-open');
                document.documentElement.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');
                document.body.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('overflow');
                document.documentElement.style.removeProperty('padding-right');
                
                // Force hide any visible modals
                document.querySelectorAll('.modal.show').forEach(function (m) {
                    m.classList.remove('show');
                    m.style.display = 'none';
                    m.setAttribute('aria-hidden', 'true');
                });

                // Restore validator-specific overflow if needed
                if (document.querySelector('.cr-report-root.cr-role-validator') &&
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

        // Set modal content
        if (titleEl) titleEl.textContent = 'Confirm Action';
        if (textEl) {
            textEl.textContent = 'Are you sure you want to ' +
                String(label || 'continue').toLowerCase() + '?';
        }

        // Remove any existing event listeners to prevent duplicates
        yesBtn.removeEventListener('click', onYes);
        if (noBtn) noBtn.removeEventListener('click', onNo);
        modalEl.removeEventListener('hidden.bs.modal', onHidden);

        // Bind fresh event listeners
        modalEl.addEventListener('hidden.bs.modal', onHidden);
        yesBtn.addEventListener('click', onYes);
        if (noBtn) noBtn.addEventListener('click', onNo);

        // Show modal
        modal.show();
    });
}

        function bindSectionChangeLock() {
            if (document.body && document.body.dataset.cvSectionLockBound === '1') return;
            if (document.body) document.body.dataset.cvSectionLockBound = '1';
            document.addEventListener('cv:section-changed', function () {
                applyComponentActionLock();
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

        async function run(action, label) {
            if (!applicationId) return;

            try {
                if (!canTakeCaseAction()) {
                    setBoxMessage('cvTopMessage', 'You do not have permission to take action on this case.', 'warning');
                    return;
                }

                var caseId = REPORT_PAYLOAD && REPORT_PAYLOAD.case && REPORT_PAYLOAD.case.case_id ? parseInt(REPORT_PAYLOAD.case.case_id, 10) : 0;

                var role = getRole();
                var isComponentRole = canUseComponentWorkflowRole(role);
                var overrideReason = '';
                var componentKey = '';

                if (isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve')) {
                    componentKey = currentSectionKey();
                    if (!componentKey || componentKey === 'timeline') {
                        setBoxMessage('cvTopMessage', 'Please select a component first.', 'warning');
                        return;
                    }
                }

                var reasonTitle = 'Reason Required';
                var reasonPrompt = 'Enter reason to ' + String(label || action || 'continue').toLowerCase();
                if (action === 'approve' && componentKey) {
                    var validatorStatus = getStageStatusFor(componentKey, 'validator');
                    var verifierStatus = getStageStatusFor(componentKey, 'verifier');
                    if (validatorStatus === 'rejected') {
                        reasonTitle = 'Verifier Reason Required';
                        reasonPrompt = 'Validator rejected this component. Enter reason to approve';
                    } else if (verifierStatus === 'rejected') {
                        reasonTitle = 'QA Reason Required';
                        reasonPrompt = 'Verifier rejected this component. Enter reason to proceed';
                    }
                }

                var reasonPayload = await askOverrideReason(
                    componentKey || 'case',
                    reasonPrompt,
                    reasonTitle,
                    'reprocess_action'
                );
                if (reasonPayload == null) return;
                overrideReason = String(reasonPayload.reason || '').trim();
                if (!overrideReason) {
                    setBoxMessage('cvTopMessage', 'Reason is required to continue.', 'warning');
                    return;
                }

                var ok = await askActionConfirm(label);
                if (!ok) return;

                setActionsDisabled(true);
                setBoxMessage('cvTopMessage', '', '');

                var out;
                if (isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve')) {
                    var group2 = null;
                    if (role === 'verifier') {
                        group2 = getVerifierGroup() || null;
                    }
                    out = await postJson(compUrl, {
                        application_id: applicationId,
                        case_id: caseId || null,
                        component_key: componentKey,
                        action: action,
                        group: group2,
                        override_reason: overrideReason || null
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
                    var msg = (out.payload && out.payload.message) ? out.payload.message : 'Action failed.';
                    var msgLower = String(msg || '').toLowerCase();
                    if (msgLower.indexOf('validator rejected') !== -1) {
                        if (msgLower.indexOf('reason is required') !== -1 || msgLower.indexOf('reason required') !== -1) {
                            msg = 'Reason is required to approve validator rejected component.';
                        } else {
                            msg = 'Validator rejected this component. Add reason and approve.';
                        }
                    } else if (msgLower.indexOf('verifier rejected') !== -1) {
                        if (msgLower.indexOf('reason is required') !== -1 || msgLower.indexOf('reason required') !== -1) {
                            msg = 'Reason is required for QA action on verifier rejected component.';
                        } else {
                            msg = 'Verifier rejected this component. Add QA reason to proceed.';
                        }
                    }
                    setBoxMessage('cvTopMessage', msg, 'danger');
                    return;
                }

                var d = out.payload.data || {};
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

                // Update local workflow state so buttons lock immediately without reload
                if (isComponentRole && (action === 'hold' || action === 'reject' || action === 'approve')) {
                    var componentKey2 = currentSectionKey();
                    var stage2 = roleToStage(role);
                    var row2 = getAssignedRowForComponent(REPORT_PAYLOAD || {}, componentKey2);
                    if (!row2) {
                        if (!REPORT_PAYLOAD.assigned_components || !Array.isArray(REPORT_PAYLOAD.assigned_components)) {
                            REPORT_PAYLOAD.assigned_components = [];
                        }
                        row2 = { component_key: componentKey2, workflow: {} };
                        REPORT_PAYLOAD.assigned_components.push(row2);
                    }
                    if (!row2.workflow || typeof row2.workflow !== 'object') row2.workflow = {};
                    row2.workflow[stage2] = (action === 'approve') ? 'approved' : ((action === 'reject') ? 'rejected' : 'hold');
                    row2.current_stage = computeComponentStageLabel(row2.workflow);

                    if (!REPORT_PAYLOAD.component_workflow || typeof REPORT_PAYLOAD.component_workflow !== 'object') {
                        REPORT_PAYLOAD.component_workflow = {};
                    }
                    if (!REPORT_PAYLOAD.component_workflow[componentKey2] || typeof REPORT_PAYLOAD.component_workflow[componentKey2] !== 'object') {
                        REPORT_PAYLOAD.component_workflow[componentKey2] = {};
                    }
                    REPORT_PAYLOAD.component_workflow[componentKey2][stage2] = { status: row2.workflow[stage2] };
                    CURRENT_SECTION_KEY = componentKey2;
                    LAST_COMPONENT_SECTION_KEY = componentKey2;
                    try {
                        updateSectionBadges(REPORT_PAYLOAD || {});
                    } catch (_e) {
                    }
                    applyComponentActionLock();
                }
                setBoxMessage('cvTopMessage', 'Updated successfully.', 'success');
            } catch (e) {
                setBoxMessage('cvTopMessage', (e && e.message) ? e.message : 'Action failed.', 'danger');
            } finally {
                setActionsDisabled(false);
            }
        }

        // expose for per-component toolbars
        window.__CR_RUN_ACTION = run;

        var holdBtn = document.getElementById('cvActionHold');
        var rejectBtn = document.getElementById('cvActionReject');
        var stopBtn = document.getElementById('cvActionStopBgv');
        var approveBtn = document.getElementById('cvActionApprove');

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

        // Initial lock based on current component + stage status
        bindSectionChangeLock();
        applyComponentActionLock();
    }

    function initSectionNav() {
        var items = Array.prototype.slice.call(document.querySelectorAll('.list-group-item[data-section]'));
        if (!items.length) return;

        var role = getRole();
        if ((role === 'verifier' || role === 'db_verifier' || role === 'validator') && !REPORT_PAYLOAD) {
            // Avoid pre-payload flicker/override; render once with resolved assigned components.
            return;
        }
        var assignedKeys = [];
        if ((role === 'verifier' || role === 'db_verifier' || role === 'validator') && REPORT_PAYLOAD) {
            assignedKeys = getAssignedComponentKeys(REPORT_PAYLOAD);

            // Hide nav items and panels not assigned
            if (assignedKeys.length) {
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

        function syncUploadType(section) {
            if (!uploadTypeEl) return;
            var v = String(section || 'general');
            var supported = Array.prototype.slice.call(uploadTypeEl.options).some(function (o) { return String(o.value) === v; });
            uploadTypeEl.value = supported ? v : 'general';
        }

        function show(section) {
            section = normSection(section);
            if (!section) return;
            CURRENT_SECTION_KEY = section;
            if (section !== 'timeline') {
                LAST_COMPONENT_SECTION_KEY = section;
            }

            if (section === 'timeline') {
                openBsModal('cvTimelineModal');
                return;
            }

            syncUploadType(section);
            setMiniTimelineFilter(section);
            items.forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-section') === section);
            });

            var panels = Array.prototype.slice.call(document.querySelectorAll('.candidate-section'));
            panels.forEach(function (p) {
                var id = (p.id || '').replace(/^section-/, '').toLowerCase();
                var on = id === section;
                p.style.display = on ? '' : 'none';
                p.classList.toggle('cr-active', on);
            });

            setCompNavActive(section);
            ensureComponentToolbar(section);

            try {
                var panel = document.getElementById('section-' + String(section));
                ensureComponentChat(panel, section);
            } catch (_e) {
            }

            try {
                renderRemarksPanel(section);
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

        // Hide sidebar items for disallowed sections.
        // For staff roles with payload-assigned components, assignedKeys is authoritative.
        var allowSet = allowedSectionsSet();
        if (!((role === 'verifier' || role === 'db_verifier' || role === 'validator') && assignedKeys.length)) {
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

        var active = items.find(function (b) { return b.classList.contains('active') && b.style.display !== 'none'; });
        var initial = active ? active.getAttribute('data-section') : 'basic';
        if (!((role === 'verifier' || role === 'db_verifier' || role === 'validator') && assignedKeys.length) && !canSeeSection(initial, allowSet)) {
            var firstVisible = items.find(function (b) { return b.style.display !== 'none'; });
            initial = firstVisible ? firstVisible.getAttribute('data-section') : '';
        }
        if (assignedKeys && assignedKeys.length) {
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

    function openDocInModal(href, label) {
        href = String(href || '').trim();
        if (!href || href === '#') return;

        var body = document.getElementById('cvViewDocModalBody');
        if (!body) {
            // Fallback to opening in new tab if modal is not present
            try { window.open(href, '_blank'); } catch (_e) {}
            return;
        }

        var safeLabel = esc(label || href);
        var lower = href.toLowerCase();
        var isImg = lower.endsWith('.jpg') || lower.endsWith('.jpeg') || lower.endsWith('.png') || lower.endsWith('.gif') || lower.endsWith('.webp');
        var isPdf = lower.endsWith('.pdf');
        var html;

        if (isImg) {
            html = '<img src="' + esc(href) + '" alt="' + safeLabel + '" style="width:100%; max-height:65vh; object-fit:contain; background:#000;" />';
        } else if (isPdf) {
            html = '<iframe src="' + esc(href) + '" title="' + safeLabel + '" style="width:100%; height:65vh; border:0;"></iframe>';
        } else {
            html = '<div style="padding:10px; color:#0f172a; font-size:13px;">' +
                '<div style="font-weight:900; margin-bottom:6px;">Preview not available</div>' +
                '<a href="' + esc(href) + '" target="_blank" style="text-decoration:none; color:#2563eb; font-weight:800;">Open in new tab</a>' +
                '</div>';
        }

        body.innerHTML = html;
        openBsModal('cvViewDocModal');
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
            openDocInModal(href, label);
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

    async function loadReport() {
        var root = document.querySelector('.cr-report-root');
        if (root) root.setAttribute('data-ui-ready', '0');

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
            return;
        }

        var role2 = getRole();
        if (role2) {
            url += '&role=' + encodeURIComponent(role2);
        }
        if (role2 === 'verifier') {
            var g = getVerifierGroup();
            if (g) {
                url += '&group=' + encodeURIComponent(g);
            }
            var cid = (qs('client_id') || '').toString().trim();
            if (cid) {
                url += '&client_id=' + encodeURIComponent(cid);
            }
        }

        // Note: role/client_id already appended above; do not duplicate query params.

        await loadHolidaysOnce();
        var res = await fetch(url, { credentials: 'same-origin' });
        var payload = await res.json().catch(function () { return null; });

        if (!res.ok || !payload || payload.status !== 1) {
            var msg = (payload && payload.message) ? payload.message : 'Failed to load report.';
            setText('cvTopMessage', msg);
            return;
        }

        // QA/TL audit: opening the report
        if (!document.body.dataset.qaAuditOpenLogged) {
            document.body.dataset.qaAuditOpenLogged = '1';
            qaAudit('open', { source: 'candidate_report', embed: String(qs('embed') || '') === '1' ? 1 : 0 });
        }

        setText('cvTopMessage', '');

        var d = payload.data || {};
        REPORT_PAYLOAD = d;
        applyCaseActionCardVisibility();

        // Re-apply section filtering once assigned components are known
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
        loadTimeline(applicationId);

        renderDocPreviewPanel(d.uploaded_docs || []);

        updateSectionBadges(d);

        var isPrint = String(qs('print') || '') === '1';
        if (isPrint) {
            // QA/TL audit: print view opened
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

            // Summary grid
            var summary = [];
            summary.push(kvBox('Candidate', coverName));
            summary.push(kvBox('Email', (basic.email || cs.candidate_email || '') + ''));
            summary.push(kvBox('Mobile', (basic.mobile || cs.candidate_mobile || '') + ''));
            summary.push(kvBox('Case ID', coverCase));
            summary.push(kvBox('Application ID', coverApp));
            summary.push(kvBox('Status', (displayCaseStatus(app.status, cs.case_status) || '') + ''));
            setHtml('cvPdfSummaryGrid', summary.join(''));

            // Executive summary + checklist + grouped docs
            renderExecutive('cvPdfExecutive', d);
            renderChecklist('cvPdfChecklist', d.uploaded_docs || []);
            renderDocsGrouped('cvPdfDocsGrouped', d.uploaded_docs || []);
        }

        setText('cvHeaderCandidate', (cs.candidate_first_name || '') + ' ' + (cs.candidate_last_name || ''));
        setText('cvHeaderAppId', applicationId);
        setText('cvHeaderStatus', (displayCaseStatus(app.status, cs.case_status) || ''));
        var tatDays = cs && typeof cs.internal_tat !== 'undefined' ? (parseInt(cs.internal_tat || '20', 10) || 20) : 20;
        var rules = cs && cs.weekend_rules ? cs.weekend_rules : 'exclude';
        setText('cvHeaderTat', tatLabelFromCreated(cs.created_at || '', { internal_tat: tatDays, weekend_rules: rules }));

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

        // If case is already approved, prevent further action changes from this view
        var statusStr = String(displayCaseStatus(app.status, cs.case_status) || '').toUpperCase();
        if (statusStr === 'APPROVED' || statusStr.indexOf('APPROVE') !== -1) {
            var holdBtn = document.getElementById('cvActionHold');
            var rejectBtn = document.getElementById('cvActionReject');
            var stopBtn = document.getElementById('cvActionStopBgv');
            if (holdBtn) holdBtn.style.display = 'none';
            if (rejectBtn) rejectBtn.style.display = 'none';
            if (stopBtn) stopBtn.style.display = 'none';
        }

        initCaseActions(applicationId);
        initVerifierMailAndPrint(function () { return REPORT_PAYLOAD; });

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

        setVal('cv_contact_current_address', [contact.address1, contact.address2, contact.city, contact.state, contact.country, contact.postal_code].filter(Boolean).join(', '));
        setVal('cv_contact_permanent_address', [contact.permanent_address1, contact.permanent_address2, contact.permanent_city, contact.permanent_state, contact.permanent_country, contact.permanent_postal_code].filter(Boolean).join(', '));
        setVal('cv_contact_proof_type', contact.proof_type || '');
        var contactProofFile = contact.proof_file || contact.address_proof_file || contact.address_proof || contact.proof || contact.proof_document || contact.proof_path || '';
        setFileField('cv_contact_proof_file', 'proof_file', contactProofFile || '');

        setVal('cv_reference_name', ref.reference_name || '');
        setVal('cv_reference_designation', ref.reference_designation || '');
        setVal('cv_reference_company', ref.reference_company || '');
        setVal('cv_reference_mobile', ref.reference_mobile || '');
        setVal('cv_reference_email', ref.reference_email || '');
        setVal('cv_reference_relationship', ref.relationship || '');
        setVal('cv_reference_years_known', ref.years_known || '');

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

        simplifyAllReadonlyFields();

        renderTable('cv_identification_table', d.identification || [], [
            { key: 'document_index', label: '#' },
            { key: 'documentId_type', label: 'Document Type' },
            { key: 'id_number', label: 'ID Number' },
            { key: 'name', label: 'Name on ID' },
            { key: 'upload_document', label: 'Uploaded File' }
        ]);

        renderTable('cv_education_table', d.education || [], [
            { key: 'education_index', label: '#' },
            { key: 'qualification', label: 'Qualification' },
            { key: 'college_name', label: 'College' },
            { key: 'university_board', label: 'University/Board' },
            { key: 'year_from', label: 'From' },
            { key: 'year_to', label: 'To' },
            { key: 'roll_number', label: 'Roll No' },
            { key: 'marksheet_file', label: 'Marksheet' },
            { key: 'degree_file', label: 'Degree' }
        ]);

        renderTable('cv_employment_table', d.employment || [], [
            { key: 'employment_index', label: '#' },
            { key: 'employer_name', label: 'Employer' },
            { key: 'job_title', label: 'Job Title' },
            { key: 'employee_id', label: 'Employee ID' },
            { key: 'joining_date', label: 'Joining' },
            { key: 'relieving_date', label: 'Relieving' },
            { key: 'currently_employed', label: 'Currently Employed' },
            { key: 'contact_employer', label: 'Contact Employer' },
            { key: 'employment_doc', label: 'Document' }
        ]);

        var uploadTypeEl = document.getElementById('cvUploadDocType');
        var currentType = uploadTypeEl ? String(uploadTypeEl.value || '') : '';
        await loadUploadedDocs(applicationId, currentType);

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

        // Initial section (usually Basic) renders before app id is ready; re-apply section UI after data load.
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

        if (root) root.setAttribute('data-ui-ready', '1');

        return d;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var deprecatedCompleteBtn = document.getElementById('cvCompleteGroupBtn');
        if (deprecatedCompleteBtn) {
            deprecatedCompleteBtn.style.display = 'none';
            deprecatedCompleteBtn.disabled = true;
        }
        initUploadPicker();
        initValidatorRemarks();
        initDocViewModal();
        if (document.querySelector('.cr-report-root.cr-role-validator') && String(qs('print') || '') !== '1') {
            document.body.style.overflow = 'hidden';
        }
        loadReport().then(function (payload) {
            initVerifierCompleteNext(function () { return payload; });
        }).catch(function (e) {
            var root = document.querySelector('.cr-report-root');
            if (root) root.setAttribute('data-ui-ready', '1');
            setText('cvTopMessage', (e && e.message) ? e.message : 'Failed to load report');
        });
    });
})();
