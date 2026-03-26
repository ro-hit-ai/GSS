(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var clientSelect = document.getElementById('gssFinalReportClientSelect');
        var searchEl = document.getElementById('gssFinalReportSearch');
        var refreshBtn = document.getElementById('gssFinalReportRefreshBtn');
        var autoRefreshEl = document.getElementById('gssFinalReportAutoRefresh');
        var lastUpdatedEl = document.getElementById('gssFinalReportLastUpdated');
        var tableEl = document.getElementById('gssFinalReportTable');
        var exportButtonsHostEl = document.getElementById('gssFinalReportExportButtons');
        var messageEl = document.getElementById('gssFinalReportMessage');
        var dataTable = null;
        var autoTimer = null;
        var COMPONENT_ORDER = ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'socialmedia', 'ecourt', 'reports'];

        function setMessage(text, type) {
            if (!messageEl) return;
            messageEl.textContent = text || '';
            messageEl.className = type ? ('alert alert-' + type) : '';
            messageEl.style.display = text ? 'block' : 'none';
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function getSelectedClientId() {
            if (!clientSelect) return 0;
            return parseInt(clientSelect.value || '0', 10) || 0;
        }

        function loadCss(href) {
            return new Promise(function (resolve, reject) {
                var existing = document.querySelector('link[href="' + href + '"]');
                if (existing) return resolve();
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.onload = function () { resolve(); };
                link.onerror = function () { reject(new Error('Failed to load CSS: ' + href)); };
                document.head.appendChild(link);
            });
        }

        function loadScript(src) {
            return new Promise(function (resolve, reject) {
                var existing = document.querySelector('script[src="' + src + '"]');
                if (existing) return resolve();
                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.onload = function () { resolve(); };
                script.onerror = function () { reject(new Error('Failed to load script: ' + src)); };
                document.body.appendChild(script);
            });
        }

        function reloadTable() {
            if (dataTable) dataTable.ajax.reload(null, false);
        }

        function setLastUpdatedNow() {
            if (!lastUpdatedEl) return;
            var d = new Date();
            var pad = function (n) { return n < 10 ? ('0' + n) : String(n); };
            lastUpdatedEl.textContent =
                'Updated: ' +
                d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' +
                pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
        }

        function setAutoRefreshEnabled(enabled) {
            if (autoTimer) {
                clearInterval(autoTimer);
                autoTimer = null;
            }
            if (!enabled) return;
            autoTimer = setInterval(function () {
                reloadTable();
            }, 10000);
        }

        function buildReportHref(applicationId, caseId) {
            var cid = getSelectedClientId();
            var href = '../shared/candidate_report.php?role=gss_admin';
            if (applicationId) {
                href += '&application_id=' + encodeURIComponent(String(applicationId));
            }
            if (caseId) {
                href += '&case_id=' + encodeURIComponent(String(caseId));
            }
            if (cid > 0) href += '&client_id=' + encodeURIComponent(String(cid));
            return href;
        }

        function buildPdfPreviewHref(applicationId) {
            var cid = getSelectedClientId();
            var href = 'candidate_report.php?print=1&application_id=' + encodeURIComponent(String(applicationId || ''));
            if (cid > 0) href += '&client_id=' + encodeURIComponent(String(cid));
            return href;
        }

        function componentLabel(k) {
            var key = String(k || '').toLowerCase().trim();
            if (key === 'basic') return 'Basic';
            if (key === 'id') return 'Identification';
            if (key === 'contact') return 'Contact';
            if (key === 'education') return 'Education';
            if (key === 'employment') return 'Employment';
            if (key === 'reference') return 'Reference';
            if (key === 'socialmedia' || key === 'social_media') return 'Social Media';
            if (key === 'ecourt') return 'E-Court';
            if (key === 'reports') return 'Reports';
            return key || '-';
        }

        function normalizeStageStatus(v) {
            var s = String(v || 'pending').toLowerCase().trim();
            if (s === 'approved' || s === 'rejected' || s === 'hold') return s;
            return 'pending';
        }

        function isStoppedCaseStatus(v) {
            var s = String(v || '').toUpperCase().trim();
            return s === 'STOP_BGV' || s === 'STOPPED' || s === 'BGV STOP' || s === 'BGV_STOP';
        }

        function stagePill(stageShort, statusRaw, caseStatusRaw, tooltipText) {
            var status = normalizeStageStatus(statusRaw);
            var caseStatus = String(caseStatusRaw || '').toUpperCase().trim();
            var icon = 'bi-hourglass-split';
            var color = '#64748b';
            if (status === 'approved') {
                icon = 'bi-check-circle-fill';
                color = '#15803d';
            } else if (status === 'rejected') {
                icon = 'bi-x-circle-fill';
                color = '#b91c1c';
            } else if (status === 'hold') {
                icon = 'bi-pause-circle-fill';
                color = '#92400e';
            }

            if (isStoppedCaseStatus(caseStatus) && status === 'pending') {
                icon = 'bi-hourglass-split';
                color = '#64748b';
            }

            return '' +
                '<span class="gss-status-pill" data-tip="' + escapeHtml(tooltipText || '') + '" style="display:inline-flex; align-items:center; gap:4px; border:1px solid rgba(148,163,184,0.28); border-radius:999px; padding:1px 6px; font-size:10px; line-height:1.1; font-weight:800; color:#0f172a; background:#fff; white-space:nowrap; cursor:help;">' +
                    '<span style="font-weight:900;">' + escapeHtml(stageShort) + '</span>' +
                    '<i class="bi ' + escapeHtml(icon) + '" style="color:' + escapeHtml(color) + ';"></i>' +
                '</span>';
        }

        function statusLabelForTooltip(v) {
            var s = normalizeStageStatus(v);
            if (s === 'approved') return 'Approved';
            if (s === 'rejected') return 'Rejected';
            if (s === 'hold') return 'Hold';
            return 'Pending';
        }

        function whenLabel(v) {
            var raw = String(v || '').trim();
            if (!raw) return '-';
            try {
                if (window.GSS_DATE && typeof window.GSS_DATE.formatDbDateTime === 'function') {
                    return window.GSS_DATE.formatDbDateTime(raw);
                }
            } catch (_e) {
            }
            return raw;
        }

        function componentMatrixCell(comp, caseStatusRaw) {
            comp = comp || {};
            var qa = normalizeStageStatus(comp.qa_status);
            var stage = '';
            var status = 'pending';
            if (qa !== 'pending') {
                stage = 'QA';
                status = qa;
            } else {
                var ls = String(comp.latest_stage || '').toLowerCase().trim();
                if (ls === 'validator') stage = 'VA';
                else if (ls === 'verifier') stage = 'VE';
                else if (ls === 'qa') stage = 'QA';

                status = normalizeStageStatus(comp.latest_status);
                if (!stage) {
                    var ve = normalizeStageStatus(comp.verifier_status);
                    var va = normalizeStageStatus(comp.validator_status);
                    if (ve !== 'pending') {
                        stage = 'VE';
                        status = ve;
                    } else if (va !== 'pending') {
                        stage = 'VA';
                        status = va;
                    } else {
                        stage = 'VA';
                        status = 'pending';
                    }
                }
            }

            var tooltip = [
                'VA: ' + statusLabelForTooltip(comp.validator_status),
                'At: ' + whenLabel(comp.validator_at),
                '',
                'VE: ' + statusLabelForTooltip(comp.verifier_status),
                'At: ' + whenLabel(comp.verifier_at),
                '',
                'QA: ' + statusLabelForTooltip(comp.qa_status),
                'At: ' + whenLabel(comp.qa_at)
            ].join('\n');

            return '<div style="min-width:0;">' + stagePill(stage, status, caseStatusRaw, tooltip) + '</div>';
        }

        function latestChange(row) {
            var stage = String(row && row.latest_stage ? row.latest_stage : '').toLowerCase().trim();
            var status = String(row && row.latest_status ? row.latest_status : 'pending').toLowerCase().trim();
            if (!stage) return '-';
            var short = stage === 'validator' ? 'VA' : (stage === 'verifier' ? 'VE' : (stage === 'qa' ? 'QA' : stage.toUpperCase()));
            return stagePill(short, status, row && row.case_status, '');
        }

        function finalStatusCell(row) {
            var caseStatus = String(row && row.case_status ? row.case_status : '').toUpperCase().trim();
            var appStatus = String(row && row.application_status ? row.application_status : '').toUpperCase().trim();
            var latest = latestChange(row);

            if (isStoppedCaseStatus(caseStatus)) {
                return '<span style="display:inline-flex; align-items:center; gap:6px; color:#334155;"><i class="bi bi-hourglass-split"></i><span>Stopped BGV (Pending)</span></span>';
            }
            if (caseStatus === 'REJECTED' || appStatus === 'REJECTED') {
                return '<span style="display:inline-flex; align-items:center; gap:6px; color:#b91c1c;"><i class="bi bi-x-circle-fill"></i><span>Rejected</span></span>';
            }
            if (caseStatus === 'APPROVED' || caseStatus === 'VERIFIED' || caseStatus === 'COMPLETED' || caseStatus === 'CLEAR') {
                return '<span style="display:inline-flex; align-items:center; gap:6px; color:#15803d;"><i class="bi bi-check-circle-fill"></i><span>Completed</span></span>';
            }
            return latest;
        }

        function stoppedByCell(row) {
            var caseStatus = String(row && row.case_status ? row.case_status : '').toUpperCase().trim();
            if (!isStoppedCaseStatus(caseStatus)) return '-';
            var by = String(row && row.stopped_by_short ? row.stopped_by_short : '').toUpperCase().trim();
            if (by === 'GA' || by === 'CA') return by;
            return '-';
        }

        function stageScore(stage) {
            var s = String(stage || '').toLowerCase().trim();
            if (s === 'qa') return 3;
            if (s === 'verifier') return 2;
            if (s === 'validator') return 1;
            return 0;
        }

        function statusScore(status) {
            var s = normalizeStageStatus(status);
            if (s === 'rejected') return 4;
            if (s === 'hold') return 3;
            if (s === 'approved') return 2;
            return 1;
        }

        function pivotRows(rawRows) {
            var rows = Array.isArray(rawRows) ? rawRows : [];
            var byCase = {};
            rows.forEach(function (r) {
                var caseId = String(r && r.case_id ? r.case_id : '');
                var appId = String(r && r.application_id ? r.application_id : '');
                if (!caseId && !appId) return;
                var key = caseId + '|' + appId;
                if (!byCase[key]) {
                    byCase[key] = {
                        case_id: r.case_id,
                        application_id: r.application_id,
                        candidate_first_name: r.candidate_first_name,
                        candidate_last_name: r.candidate_last_name,
                        candidate_email: r.candidate_email,
                        candidate_mobile: r.candidate_mobile,
                        case_status: r.case_status,
                        application_status: r.application_status,
                        created_at: r.created_at,
                        stopped_by_short: r.stopped_by_short,
                        stopped_by_role: r.stopped_by_role,
                        stopped_at: r.stopped_at,
                        components: {},
                        latest_stage: '',
                        latest_status: 'pending',
                        latest_score: -1
                    };
                }
                var row = byCase[key];
                var ckey = String(r && r.component_key ? r.component_key : '').toLowerCase().trim();
                if (ckey) {
                    row.components[ckey] = {
                        validator_status: normalizeStageStatus(r.validator_status),
                        verifier_status: normalizeStageStatus(r.verifier_status),
                        qa_status: normalizeStageStatus(r.qa_status),
                        validator_at: String(r.validator_at || ''),
                        verifier_at: String(r.verifier_at || ''),
                        qa_at: String(r.qa_at || ''),
                        latest_stage: String(r.latest_stage || '').toLowerCase().trim(),
                        latest_status: normalizeStageStatus(r.latest_status)
                    };

                    var sc = stageScore(r.latest_stage) * 10 + statusScore(r.latest_status);
                    if (sc > row.latest_score) {
                        row.latest_score = sc;
                        row.latest_stage = String(r.latest_stage || '').toLowerCase().trim();
                        row.latest_status = normalizeStageStatus(r.latest_status);
                    }
                }
            });

            return Object.keys(byCase).map(function (k) { return byCase[k]; });
        }

        function ensureButtonsStyles() {
            if (document.getElementById('vatiPayfillerFinalReportButtonsStyles')) return;
            var style = document.createElement('style');
            style.id = 'vatiPayfillerFinalReportButtonsStyles';
            style.textContent = [
                '#gssFinalReportExportButtons .dt-buttons{display:inline-flex; gap:6px; align-items:center;}',
                '#gssFinalReportExportButtons .dt-buttons .dt-button{margin:0;}',
                '#gssFinalReportExportButtons .dt-buttons .btn{border-radius:6px; padding:6px 10px; font-size:12px; line-height:1; border:1px solid transparent; cursor:pointer;}',
                '#gssFinalReportExportButtons .dt-buttons .btn-secondary{background:#64748b; border-color:#64748b; color:#fff;}',
                '#gssFinalReportExportButtons .dt-buttons .btn-success{background:#16a34a; border-color:#16a34a; color:#fff;}',
                '#gssFinalReportExportButtons .dt-buttons .btn-dark{background:#0f172a; border-color:#0f172a; color:#fff;}',
                '#gssFinalReportExportButtons .dt-buttons .btn-outline{background:#fff; border-color:#cbd5e1; color:#0f172a;}',
                '#gssFinalReportExportButtons .dt-buttons .btn:hover{filter:brightness(0.95);}'
            ].join('\n');
            document.head.appendChild(style);
        }

        function ensureStatusHoverTooltip() {
            if (document.getElementById('gssStatusHoverBox')) return;

            var tipEl = document.createElement('div');
            tipEl.id = 'gssStatusHoverBox';
            tipEl.className = 'gss-status-hoverbox';
            document.body.appendChild(tipEl);

            var activePill = null;

            function showFor(pill) {
                if (!pill) return;
                var txt = String(pill.getAttribute('data-tip') || '').trim();
                if (!txt) return;
                activePill = pill;
                tipEl.textContent = txt;
                tipEl.classList.add('show');
                positionFor(pill);
            }

            function hide() {
                activePill = null;
                tipEl.classList.remove('show');
            }

            function positionFor(pill) {
                if (!pill || !tipEl.classList.contains('show')) return;
                var rect = pill.getBoundingClientRect();
                var vw = window.innerWidth || document.documentElement.clientWidth || 0;
                var vh = window.innerHeight || document.documentElement.clientHeight || 0;
                var tipW = 220;
                var gap = 10;

                // Temporarily ensure measurable height.
                var oldVis = tipEl.style.visibility;
                tipEl.style.visibility = 'hidden';
                tipEl.style.left = '0px';
                tipEl.style.top = '0px';
                var tipH = tipEl.offsetHeight || 140;
                tipEl.style.visibility = oldVis;

                var left = rect.left + (rect.width / 2) - (tipW / 2);
                left = Math.max(8, Math.min(left, vw - tipW - 8));

                var top = rect.top - tipH - gap;
                if (top < 8) {
                    top = rect.bottom + gap;
                }
                if (top + tipH > vh - 8) {
                    top = Math.max(8, vh - tipH - 8);
                }

                tipEl.style.left = left + 'px';
                tipEl.style.top = top + 'px';
            }

            document.addEventListener('mouseover', function (e) {
                var pill = e.target && e.target.closest ? e.target.closest('.gss-status-pill') : null;
                if (!pill) return;
                showFor(pill);
            });

            document.addEventListener('mousemove', function (e) {
                var pill = e.target && e.target.closest ? e.target.closest('.gss-status-pill') : null;
                if (!pill) {
                    if (activePill) hide();
                    return;
                }
                if (pill !== activePill) {
                    showFor(pill);
                    return;
                }
                positionFor(pill);
            });

            document.addEventListener('mouseout', function (e) {
                if (!activePill) return;
                var toEl = e.relatedTarget;
                if (toEl && toEl.closest && toEl.closest('.gss-status-pill') === activePill) return;
                hide();
            });

            window.addEventListener('scroll', function () {
                if (activePill) positionFor(activePill);
            }, true);
            window.addEventListener('resize', function () {
                if (activePill) positionFor(activePill);
            });
        }

        function initDataTable() {
            if (!tableEl) return;
            if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) {
                setMessage('DataTables is not available. Please refresh.', 'danger');
                return;
            }

            ensureButtonsStyles();
            ensureStatusHoverTooltip();

            dataTable = jQuery(tableEl).DataTable({
                processing: true,
                pageLength: 10,
                lengthChange: false,
                searching: false,
                dom: 'Brtip',
                buttons: [
                    { extend: 'copy', className: 'btn btn-secondary' },
                    { extend: 'csv', className: 'btn btn-success' },
                    { extend: 'excel', className: 'btn btn-success' },
                    { extend: 'print', className: 'btn btn-dark' },
                    { extend: 'colvis', className: 'btn btn-outline' }
                ],
                ajax: function (_dtParams, callback) {
                    setMessage('', '');
                    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                    var search = searchEl ? (searchEl.value || '').trim() : '';
                    var clientId = getSelectedClientId();
                    var url = base + '/api/gssadmin/candidate_component_report.php?client_id=' + encodeURIComponent(clientId || 0) + '&search=' + encodeURIComponent(search || '');
                    fetch(url, { credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                setMessage((data && data.message) ? data.message : 'Failed to load final report.', 'danger');
                                callback({ data: [] });
                                return;
                            }
                            setLastUpdatedNow();
                            callback({ data: pivotRows(data.data || []) });
                        })
                        .catch(function () {
                            setMessage('Network error. Please try again.', 'danger');
                            callback({ data: [] });
                        });
                },
                columns: [
                    { data: 'case_id', className: 'gss-case-col' },
                    { data: 'application_id', className: 'gss-app-col' },
                    {
                        data: null,
                        className: 'gss-candidate-col',
                        render: function (_d, _t, row) {
                            var name = ((row && row.candidate_first_name) ? row.candidate_first_name : '') + ' ' + ((row && row.candidate_last_name) ? row.candidate_last_name : '');
                            var appId = row && row.application_id ? row.application_id : '';
                            var caseId = row && row.case_id ? row.case_id : '';
                            var safeName = escapeHtml(name.trim() || '-');
                            if (!appId && !caseId) return safeName;
                            var href = buildReportHref(appId, caseId);
                            return '<a href="' + href + '" style="text-decoration:none; color:#2563eb;">' + safeName + '</a>';
                        }
                    },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.basic : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.id : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.contact : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.education : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.employment : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.reference : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? (row.components.socialmedia || row.components.social_media) : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.ecourt : null, row && row.case_status); } },
                    { data: null, className: 'gss-comp-col', render: function (_d, _t, row) { return componentMatrixCell(row && row.components ? row.components.reports : null, row && row.case_status); } },
                    {
                        data: null,
                        className: 'gss-final-col',
                        render: function (_d, _t, row) {
                            return finalStatusCell(row);
                        }
                    },
                    {
                        data: null,
                        className: 'gss-pdf-col',
                        orderable: false,
                        render: function (_d, _t, row) {
                            var appId = row && row.application_id ? row.application_id : '';
                            if (!appId) return '';
                            var href = buildPdfPreviewHref(appId);
                            return '<a href="' + href + '" target="_blank" rel="noopener" style="text-decoration:none; color:#2563eb;">Preview</a>';
                        }
                    },
                    {
                        data: null,
                        className: 'gss-stopped-col',
                        render: function (_d, _t, row) {
                            return escapeHtml(stoppedByCell(row));
                        }
                    },
                    {
                        data: 'created_at',
                        className: 'gss-created-col',
                        render: function (d) {
                            return escapeHtml(window.GSS_DATE.formatDbDateTime(d));
                        }
                    }
                ]
            });

            if (exportButtonsHostEl && dataTable && dataTable.buttons) {
                try {
                    exportButtonsHostEl.innerHTML = '';
                    exportButtonsHostEl.appendChild(dataTable.buttons().container()[0]);
                } catch (_e) {
                }
            }
        }

        function loadClients() {
            if (!clientSelect) return Promise.resolve();
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            return fetch(base + '/api/gssadmin/clients_dropdown.php', { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    clientSelect.innerHTML = '<option value="0">All Clients</option>';
                    if (!data || data.status !== 1 || !Array.isArray(data.data)) return;
                    data.data.forEach(function (c) {
                        var opt = document.createElement('option');
                        opt.value = String(c.client_id || '0');
                        opt.textContent = c.customer_name || ('Client #' + c.client_id);
                        clientSelect.appendChild(opt);
                    });
                })
                .catch(function () {
                });
        }

        if (clientSelect) clientSelect.addEventListener('change', reloadTable);
        if (refreshBtn) refreshBtn.addEventListener('click', reloadTable);
        if (autoRefreshEl) {
            autoRefreshEl.addEventListener('change', function () {
                setAutoRefreshEnabled(!!autoRefreshEl.checked);
            });
        }
        if (searchEl) {
            var t = null;
            searchEl.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(reloadTable, 250);
            });
        }

        var cssIcons = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css';
        var css1 = 'https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css';
        var css2 = 'https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.min.css';
        var js1 = 'https://cdn.datatables.net/2.1.8/js/dataTables.min.js';
        var js2 = 'https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js';
        var js3 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js';
        var js4 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.print.min.js';
        var js5 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js';
        var jsZip = 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';

        Promise.all([
            loadClients(),
            loadCss(cssIcons),
            loadCss(css1),
            loadCss(css2)
        ])
            .then(function () { return loadScript(js1); })
            .then(function () { return loadScript(jsZip); })
            .then(function () { return loadScript(js2); })
            .then(function () { return loadScript(js3); })
            .then(function () { return loadScript(js4); })
            .then(function () { return loadScript(js5); })
            .then(function () { initDataTable(); })
            .then(function () { setAutoRefreshEnabled(!autoRefreshEl || !!autoRefreshEl.checked); })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
            });
    });
})();
