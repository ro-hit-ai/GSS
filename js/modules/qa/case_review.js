document.addEventListener('DOMContentLoaded', function () {
    var shell = document.getElementById('qaCaseReviewShell');
    var frame = document.getElementById('qaReportFrame');
    var msgEl = document.getElementById('qaCaseMessage');

    var sectionEl = document.getElementById('qaCommentSection');
    var commentEl = document.getElementById('qaCommentText');
    var addBtn = document.getElementById('qaCommentAddBtn');

    var timelineEl = document.getElementById('qaTimeline');
    var refreshBtn = document.getElementById('qaTimelineRefresh');
    var emptyEl = document.getElementById('qaReportEmpty');
    var openReportLink = document.getElementById('qaOpenReport');
    var completeNextBtn = document.getElementById('qaCompleteNextBtn');

    var filterBtn = document.getElementById('qaRemarksFilterBtn');
    var filterLabelEl = document.getElementById('qaRemarksFilterLabel');
    var filterMenu = document.getElementById('qaRemarksFilterMenu');

    // QA Evidence upload (right panel)
    var evidenceMsgEl = document.getElementById('qaEvidenceMessage');
    var evidenceDocTypeEl = document.getElementById('qaEvidenceDocType');
    var evidenceFilesEl = document.getElementById('qaEvidenceFiles');
    var evidenceUploadBtn = document.getElementById('qaEvidenceUploadBtn');
    var evidenceListEl = document.getElementById('qaEvidenceList');

    var TL_CACHE = [];
    var ACTIVE_FILTER = 'all';
    var SELF_ROLE = '';

    function setMessage(text, type) {
        if (!msgEl) return;
        msgEl.textContent = text || '';
        msgEl.className = type ? ('alert alert-' + type) : '';
        msgEl.style.display = text ? 'block' : 'none';
    }

    function setEvidenceMessage(text, type) {
        if (!evidenceMsgEl) return;
        evidenceMsgEl.textContent = text || '';
        evidenceMsgEl.className = type ? ('alert alert-' + type) : '';
        evidenceMsgEl.style.display = text ? 'block' : 'none';
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function pad2(n) {
        n = parseInt(n, 10);
        if (!isFinite(n)) n = 0;
        return (n < 10 ? '0' : '') + n;
    }

    function safeDate(d) {
        try {
            var dt = (d instanceof Date) ? d : new Date(d);
            if (!dt || isNaN(dt.getTime())) return null;
            return dt;
        } catch (e) {
            return null;
        }
    }

    function dayKey(dt) {
        return dt.getFullYear() + '-' + pad2(dt.getMonth() + 1) + '-' + pad2(dt.getDate());
    }

    function dayLabel(dt) {
        var now = new Date();
        var todayKey = dayKey(now);
        var dKey = dayKey(dt);
        var y = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
        var yKey = dayKey(y);
        if (dKey === todayKey) return 'Today';
        if (dKey === yKey) return 'Yesterday';
        return pad2(dt.getDate()) + '-' + pad2(dt.getMonth() + 1) + '-' + dt.getFullYear();
    }

    function timeLabel(dt) {
        return pad2(dt.getHours()) + ':' + pad2(dt.getMinutes());
    }

    function fmtDate(d) {
        if (!d) return '-';
        try {
            if (window.GSS_DATE && typeof window.GSS_DATE.formatDbDateTime === 'function') {
                return window.GSS_DATE.formatDbDateTime(d);
            }
        } catch (e) {
        }
        return String(d);
    }

    function getVal(el, def) {
        if (!el) return def;
        return String(el.value || def);
    }

    function getAppId() {
        return shell ? (shell.getAttribute('data-application-id') || '') : '';
    }

    function getClientId() {
        var v = shell ? (shell.getAttribute('data-client-id') || '') : '';
        return parseInt(v, 10) || 0;
    }

    function setDisabled(disabled) {
        var els = [addBtn, sectionEl, commentEl, refreshBtn, filterBtn, evidenceDocTypeEl, evidenceFilesEl, evidenceUploadBtn, completeNextBtn];
        els.forEach(function (el) {
            if (!el) return;
            try {
                el.disabled = !!disabled;
            } catch (e) {
            }
        });
    }

    function renderEvidenceList(list) {
        if (!evidenceListEl) return;
        if (!Array.isArray(list) || !list.length) {
            evidenceListEl.innerHTML = '<div class="qa-evidence-empty">No uploaded documents.</div>';
            return;
        }

        evidenceListEl.innerHTML = list.map(function (r) {
            var href = '';
            if (r && (r.file_url || r.file_path)) {
                href = String(r.file_url || r.file_path);
            }
            var label = (r && (r.original_name || r.file_path)) ? String(r.original_name || r.file_path) : '';
            var meta = [];
            if (r.doc_type) meta.push(String(r.doc_type));
            if (r.uploaded_by_role) meta.push(String(r.uploaded_by_role));
            if (r.created_at) meta.push(String(r.created_at));

            return '' +
                '<div class="qa-evidence-item">' +
                    '<div class="qa-evidence-file">' +
                        '<a href="' + escapeHtml(href || '#') + '" target="_blank" rel="noopener">' + escapeHtml(label || 'Download') + '</a>' +
                    '</div>' +
                    (meta.length ? ('<div class="qa-evidence-meta">' + escapeHtml(meta.join(' • ')) + '</div>') : '') +
                '</div>';
        }).join('');
    }

    function getEvidenceDocType() {
        if (!evidenceDocTypeEl) return 'qa_evidence';
        var v = String(evidenceDocTypeEl.value || '').trim();
        return v || 'qa_evidence';
    }

    function loadEvidenceList() {
        if (!evidenceListEl) return;
        var appId = getAppId();
        if (!appId) {
            renderEvidenceList([]);
            return;
        }

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/verification_docs_list.php?application_id=' + encodeURIComponent(appId) +
            '&doc_type=' + encodeURIComponent(getEvidenceDocType());

        fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    renderEvidenceList([]);
                    return;
                }
                renderEvidenceList(data.data || []);
            })
            .catch(function () {
                renderEvidenceList([]);
            });
    }

    function uploadEvidence() {
        if (!evidenceFilesEl) return;
        var appId = getAppId();
        if (!appId) {
            setEvidenceMessage('application_id missing.', 'danger');
            return;
        }

        var files = evidenceFilesEl.files;
        if (!files || !files.length) {
            setEvidenceMessage('Please select file(s) to upload.', 'warning');
            return;
        }

        setEvidenceMessage('', '');

        var btn = evidenceUploadBtn;
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Uploading...';
        }

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/shared/verification_docs_upload.php';

        var fd = new FormData();
        fd.append('application_id', appId);
        fd.append('doc_type', getEvidenceDocType());
        fd.append('role', 'qa');
        var cid = getClientId();
        if (cid > 0) {
            fd.append('client_id', String(cid));
        }
        for (var i = 0; i < files.length; i++) {
            fd.append('files[]', files[i]);
        }

        fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Upload failed');
                }
                setEvidenceMessage('Uploaded successfully.', 'success');
                evidenceFilesEl.value = '';
                loadEvidenceList();
            })
            .catch(function (e) {
                setEvidenceMessage(e && e.message ? e.message : 'Upload failed', 'danger');
            })
            .finally(function () {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.dataset.originalText || 'Upload';
                }
            });
    }

    function sectionLabel(k) {
        k = normalizeSection(k);
        if (k === 'all') return 'All';
        if (k === 'basic') return 'Basic';
        if (k === 'id') return 'Identification';
        if (k === 'contact') return 'Contact';
        if (k === 'employment') return 'Employment';
        if (k === 'education') return 'Education';
        if (k === 'reference') return 'Reference';
        if (k === 'documents') return 'Documents';
        if (k === 'general') return 'General';
        return k ? (k.charAt(0).toUpperCase() + k.slice(1)) : 'General';
    }

    function normalizeSection(v) {
        var key = String(v || '').toLowerCase().trim();
        if (!key) return 'general';
        if (key === 'identification') return 'id';
        if (key === 'address') return 'contact';
        if (key === 'references') return 'reference';
        return key;
    }

    function setActiveFilter(sec) {
        sec = normalizeSection(sec);
        if (sec === 'all') {
            ACTIVE_FILTER = 'all';
        } else {
            ACTIVE_FILTER = sec;
        }

        if (filterLabelEl) {
            filterLabelEl.textContent = sectionLabel(ACTIVE_FILTER);
        }

        if (filterMenu) {
            Array.prototype.forEach.call(filterMenu.querySelectorAll('.qa-filter-item[data-filter]'), function (el) {
                var k = normalizeSection(el.getAttribute('data-filter') || 'all');
                el.classList.toggle('active', k === ACTIVE_FILTER);
            });
        }

        if (sectionEl) {
            sectionEl.value = (ACTIVE_FILTER === 'all') ? 'general' : ACTIVE_FILTER;
        }

        renderTimeline(TL_CACHE);
    }

    function bindFilterMenu() {
        if (!filterBtn || !filterMenu) return;
        if (filterBtn.dataset.bound) return;
        filterBtn.dataset.bound = '1';

        function closeMenu() {
            filterMenu.classList.remove('open');
            filterBtn.setAttribute('aria-expanded', 'false');
        }

        filterBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var open = filterMenu.classList.contains('open');
            if (open) {
                closeMenu();
            } else {
                filterMenu.classList.add('open');
                filterBtn.setAttribute('aria-expanded', 'true');
            }
        });

        filterMenu.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t) return;
            var item = t.closest ? t.closest('.qa-filter-item[data-filter]') : null;
            if (!item) return;
            setActiveFilter(item.getAttribute('data-filter') || 'all');
            closeMenu();
        });

        document.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            if (!t) return;
            if (filterMenu.contains(t) || filterBtn.contains(t)) return;
            closeMenu();
        });
    }

    function buildReportUrl() {
        var appId = getAppId();
        var cid = getClientId();
        var href = '../shared/candidate_report.php?role=qa&embed=1&application_id=' + encodeURIComponent(appId);
        if (cid > 0) {
            href += '&client_id=' + encodeURIComponent(String(cid));
        }
        return href;
    }

    function buildReportFullUrl() {
        var appId = getAppId();
        var cid = getClientId();
        var href = '../shared/candidate_report.php?role=qa&application_id=' + encodeURIComponent(appId);
        if (cid > 0) href += '&client_id=' + encodeURIComponent(String(cid));
        return href;
    }

    function audit(event, meta) {
        var appId = getAppId();
        if (!appId) return;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        fetch(base + '/api/qa/report_audit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ application_id: appId, event: event, meta: meta || null })
        }).catch(function () {
        });
    }

    function filteredTimeline(items) {
        var list = Array.isArray(items) ? items.slice() : [];
        if (ACTIVE_FILTER !== 'all') {
            list = list.filter(function (it) {
                return normalizeSection(it && it.section_key ? it.section_key : 'general') === ACTIVE_FILTER;
            });
        }

        list.sort(function (a, b) {
            var ad = safeDate(a && a.created_at ? a.created_at : null);
            var bd = safeDate(b && b.created_at ? b.created_at : null);
            var at = ad ? ad.getTime() : 0;
            var bt = bd ? bd.getTime() : 0;
            return bt - at;
        });
        return list;
    }

    function isMine(it) {
        var actorRole = String(it && it.actor_role ? it.actor_role : '').toLowerCase();
        if (SELF_ROLE && actorRole && SELF_ROLE === actorRole) return true;
        if (actorRole === 'qa' || actorRole === 'team_lead') return true;
        return false;
    }

    function renderTimeline(items) {
        if (!timelineEl) return;
        if (items === null) {
            timelineEl.innerHTML = '<div class="qa-chat-empty">Loading timeline...</div>';
            return;
        }

        var list = filteredTimeline(items);
        if (!list.length) {
            timelineEl.innerHTML = '<div class="qa-chat-empty">No remarks for this filter yet.</div>';
            return;
        }

        var html = [];
        var lastDay = '';
        list.forEach(function (it) {
            var dt = safeDate(it && it.created_at ? it.created_at : null);
            var dKey = dt ? dayKey(dt) : '';
            if (dKey && dKey !== lastDay) {
                lastDay = dKey;
                html.push('<div class="qa-chat-day"><span>' + escapeHtml(dayLabel(dt)) + '</span></div>');
            }

            var actor = '';
            if (it && (it.first_name || it.last_name)) actor = (String(it.first_name || '') + ' ' + String(it.last_name || '')).trim();
            if (!actor && it && it.username) actor = String(it.username);
            if (!actor) actor = it && it.actor_role ? String(it.actor_role).toUpperCase() : 'SYSTEM';

            var when = dt ? timeLabel(dt) : fmtDate(it && it.created_at ? it.created_at : '');
            var msg = it && it.message ? String(it.message) : '';
            var sec = normalizeSection(it && it.section_key ? it.section_key : 'general');
            var mine = isMine(it);

            html.push(
                '<div class="qa-chat-item' + (mine ? ' mine' : '') + '">' +
                    '<div class="qa-chat-bubble">' +
                        '<div class="qa-chat-meta"><span>' + escapeHtml(actor) + '</span><span>' + escapeHtml(when) + '</span></div>' +
                        '<div class="qa-chat-msg">' + escapeHtml(msg) + '</div>' +
                        '<div class="qa-chat-sec">' + escapeHtml(sectionLabel(sec)) + '</div>' +
                    '</div>' +
                '</div>'
            );
        });

        timelineEl.innerHTML = html.join('');
    }

    function loadTimeline() {
        var appId = getAppId();
        if (!appId) {
            TL_CACHE = [];
            renderTimeline([]);
            return;
        }

        renderTimeline(null);

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        fetch(base + '/api/shared/case_timeline_list.php?application_id=' + encodeURIComponent(appId), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load timeline');
                }
                TL_CACHE = Array.isArray(data.data) ? data.data : [];
                renderTimeline(TL_CACHE);
            })
            .catch(function (e) {
                setMessage('Timeline error: ' + e.message, 'danger');
                TL_CACHE = [];
                renderTimeline([]);
            });
    }

    function addComment() {
        var appId = getAppId();
        if (!appId) {
            setMessage('application_id missing.', 'danger');
            return;
        }

        var text = commentEl ? String(commentEl.value || '').trim() : '';
        if (!text) {
            setMessage('Please enter comment.', 'warning');
            return;
        }

        var payload = {
            application_id: appId,
            event_type: 'comment',
            section_key: getVal(sectionEl, 'general'),
            message: text
        };

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        fetch(base + '/api/shared/case_timeline_add.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to add comment');
                }
                if (commentEl) commentEl.value = '';
                setMessage('Comment added.', 'success');
                loadTimeline();
            })
            .catch(function (e) {
                setMessage(e.message, 'danger');
            });
    }

    function completeAndNext() {
        var appId = getAppId();
        if (!appId) {
            setMessage('application_id missing.', 'danger');
            return;
        }

        var ok = true;
        if (window.GSSDialog && typeof window.GSSDialog.confirm === 'function') {
            ok = window.confirm('Confirm: Complete current case and open next ready case?');
        } else {
            ok = window.confirm('Confirm: Complete current case and open next ready case?');
        }
        if (!ok) return;

        if (completeNextBtn) {
            completeNextBtn.disabled = true;
            completeNextBtn.dataset.originalText = completeNextBtn.dataset.originalText || completeNextBtn.textContent;
            completeNextBtn.textContent = 'Completing...';
        }

        setMessage('', '');
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var cid = getClientId();
        var approvalWarning = '';

        fetch(base + '/api/shared/case_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({
                application_id: appId,
                action: 'approve',
                case_id: null,
                group: null
            })
        })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    approvalWarning = (data && data.message) ? String(data.message) : 'Failed to complete case';
                }

                var nextUrl = base + '/api/qa/cases_list.php?view=ready';
                if (cid > 0) {
                    nextUrl += '&client_id=' + encodeURIComponent(String(cid));
                }
                return fetch(nextUrl, { credentials: 'same-origin' })
                    .then(function (res) { return res.json().catch(function () { return null; }); });
            })
            .then(function (listData) {
                if (!listData || listData.status !== 1 || !Array.isArray(listData.data)) {
                    throw new Error((listData && listData.message) ? listData.message : 'Failed to fetch next case');
                }

                var next = null;
                for (var i = 0; i < listData.data.length; i++) {
                    var row = listData.data[i] || {};
                    var nextApp = String(row.application_id || '').trim();
                    if (nextApp && nextApp !== appId) {
                        next = row;
                        break;
                    }
                }

                if (!next) {
                    if (approvalWarning) {
                        setMessage('No next ready case found. Approval warning: ' + approvalWarning, 'warning');
                    } else {
                        setMessage('Case completed. No next ready case found.', 'success');
                    }
                    return;
                }

                var target = 'case_review.php?application_id=' + encodeURIComponent(String(next.application_id || ''));
                var nextClientId = parseInt(next.client_id, 10);
                if (isFinite(nextClientId) && nextClientId > 0) {
                    target += '&client_id=' + encodeURIComponent(String(nextClientId));
                }
                window.location.href = target;
            })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Complete & Next failed', 'danger');
            })
            .finally(function () {
                if (completeNextBtn) {
                    completeNextBtn.disabled = false;
                    completeNextBtn.textContent = completeNextBtn.dataset.originalText || 'Complete and Next';
                }
            });
    }

    function loadReportIntoFrame() {
        var appId = getAppId();
        if (!appId) {
            if (emptyEl) emptyEl.style.display = 'block';
            if (frame) frame.style.display = 'none';
            return;
        }

        if (emptyEl) emptyEl.style.display = 'none';
        if (frame) {
            frame.style.display = 'block';
            frame.src = buildReportUrl();
        }

        if (openReportLink) {
            openReportLink.href = buildReportFullUrl();
            if (!openReportLink.dataset.auditBound) {
                openReportLink.dataset.auditBound = '1';
                openReportLink.addEventListener('click', function () {
                    audit('open', { source: 'qa_case_review' });
                });
            }
        }

        audit('view', { source: 'qa_case_review', embed: 1 });
    }

    function applyEmbeddedReportCompactMode() {
    if (!frame) return;
    try {
        var doc = frame.contentDocument || (frame.contentWindow ? frame.contentWindow.document : null);
        if (!doc) return;

        var root = doc.querySelector('.cr-report-root');
        if (!root) return;

        // ONLY responsibility of JS:
        // tell iframe it is in QA mode
        root.classList.add('qa-case-review-mode');

    } catch (e) {
        // silent fail (iframe not ready)
    }
    }


    if (shell) {
        var selfRoleAttr = String(shell.getAttribute('data-role') || '').toLowerCase().trim();
        if (selfRoleAttr) {
            SELF_ROLE = selfRoleAttr;
        } else {
            SELF_ROLE = 'qa';
        }
    }

    bindFilterMenu();
    setActiveFilter('all');

    if (frame && !frame.dataset.compactBound) {
        frame.dataset.compactBound = '1';
        frame.addEventListener('load', function () {
            applyEmbeddedReportCompactMode();
        });
    }

    if (frame) {
        var appId = getAppId();
        if (!appId) {
            if (emptyEl) emptyEl.style.display = 'block';
            frame.style.display = 'none';
            setDisabled(true);
            setMessage('Please open a case from Review List.', 'warning');
        } else {
            if (emptyEl) emptyEl.style.display = 'none';
            frame.style.display = 'block';
            setDisabled(false);
            loadReportIntoFrame();
        }
    }

    if (addBtn) addBtn.addEventListener('click', addComment);
    if (commentEl) {
        commentEl.addEventListener('keydown', function (e) {
            if (e && e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                addComment();
            }
        });
    }

    if (refreshBtn) refreshBtn.addEventListener('click', loadTimeline);
    if (completeNextBtn) completeNextBtn.addEventListener('click', completeAndNext);

    // Evidence upload bindings
    if (evidenceUploadBtn) {
        evidenceUploadBtn.addEventListener('click', uploadEvidence);
    }
    if (evidenceDocTypeEl) {
        evidenceDocTypeEl.addEventListener('change', function () {
            loadEvidenceList();
        });
    }

    var hasAppId = !!getAppId();
    if (hasAppId) {
        if (evidenceListEl) {
            loadEvidenceList();
        }
        loadTimeline();
    } else {
        TL_CACHE = [];
        renderTimeline([]);
        if (evidenceListEl) {
            renderEvidenceList([]);
        }
    }
});
