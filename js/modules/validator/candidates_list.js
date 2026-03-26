(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var viewSelect = document.getElementById('valCasesViewSelect');
        var searchEl = document.getElementById('valCasesListSearch');
        var refreshBtn = document.getElementById('valCasesListRefreshBtn');
        var tableEl = document.getElementById('valCasesListTable');
        var exportButtonsHostEl = document.getElementById('valCasesListExportButtons');
        var messageEl = document.getElementById('valCasesListMessage');
        var dataTable = null;

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

        function getSelectedView() {
            if (!viewSelect) return 'available';
            var v = String(viewSelect.value || 'available').toLowerCase();
            if (v === 'available') return 'available';
            if (v === 'completed') return 'completed';
            return 'mine';
        }

        function statusLabelForValidator(row) {
            row = row || {};
            var raw = String(row.status || '').trim();
            var caseStatus = String(row.case_status || '').trim();
            var appStatus = String(row.__app_status || '').toLowerCase().trim();

            var lowRaw = raw.toLowerCase();
            var lowCase = caseStatus.toLowerCase();

            if (lowRaw === 'pending_candidate' || lowRaw === 'candidate_pending' || lowRaw === 'pending candidate') {
                if (appStatus === 'submitted') return 'Pending Validator';
            }
            if (lowCase === 'pending_candidate' || lowCase === 'candidate_pending' || lowCase === 'pending candidate') {
                if (appStatus === 'submitted') return 'Pending Validator';
            }
            if ((lowRaw === 'pending' || lowRaw === '') && (lowCase === 'pending_candidate' || lowCase === 'candidate_pending') && appStatus === 'submitted') {
                return 'Pending Validator';
            }

            if (lowRaw === 'in_progress') return 'In Progress';
            if (lowRaw === 'completed') return 'Completed';
            if (lowRaw === 'followup') return 'Follow Up';
            if (lowRaw === 'stop_bgv' || lowCase === 'stop_bgv') return 'Stopped BGV';

            return raw || caseStatus || '-';
        }

        function buildReportHref(applicationId, caseId) {
            var appId = String(applicationId || '').trim();
            var href = 'candidate_view.php';
            if (appId) {
                href += '?application_id=' + encodeURIComponent(appId);
                return href;
            }
            if (caseId) {
                href += '?case_id=' + encodeURIComponent(String(caseId));
            }
            return href;
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

        function ensureButtonsStyles() {
            if (document.getElementById('vatiPayfillerCasesDtButtonsStylesVal')) return;
            var style = document.createElement('style');
            style.id = 'vatiPayfillerCasesDtButtonsStylesVal';
            style.textContent = [
                '#valCasesListExportButtons .dt-buttons{display:inline-flex; gap:6px; align-items:center;}',
                '#valCasesListExportButtons .dt-buttons .dt-button{margin:0;}',
                '#valCasesListExportButtons .dt-buttons .btn{border-radius:6px; padding:6px 10px; font-size:12px; line-height:1; border:1px solid transparent; cursor:pointer;}',
                '#valCasesListExportButtons .dt-buttons .btn-secondary{background:#64748b; border-color:#64748b; color:#fff;}',
                '#valCasesListExportButtons .dt-buttons .btn-success{background:#16a34a; border-color:#16a34a; color:#fff;}',
                '#valCasesListExportButtons .dt-buttons .btn-dark{background:#0f172a; border-color:#0f172a; color:#fff;}',
                '#valCasesListExportButtons .dt-buttons .btn-outline{background:#fff; border-color:#cbd5e1; color:#0f172a;}',
                '#valCasesListExportButtons .dt-buttons .btn:hover{filter:brightness(0.95);}'
            ].join('\n');
            document.head.appendChild(style);
        }

        function reloadTable() {
            if (dataTable) dataTable.ajax.reload(null, false);
        }

        function initDataTable() {
            if (!tableEl) return;

            if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) {
                setMessage('DataTables is not available. Please refresh.', 'danger');
                return;
            }

            // Avoid "Cannot reinitialise DataTable" when this script runs more than once (cached navigation/refresh).
            try {
                if (dataTable) {
                    dataTable.destroy(true);
                    dataTable = null;
                } else if (jQuery.fn.DataTable.isDataTable(tableEl)) {
                    jQuery(tableEl).DataTable().destroy(true);
                }
            } catch (_e) {
            }

            ensureButtonsStyles();

            dataTable = jQuery(tableEl).DataTable({
                processing: true,
                pageLength: 10,
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
                    var view = getSelectedView();
                    var url = base + '/api/validator/cases_list.php?view=' + encodeURIComponent(view) + '&search=' + encodeURIComponent(search || '');

                    fetch(url, { credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                setMessage((data && data.message) ? data.message : 'Failed to load cases.', 'danger');
                                callback({ data: [] });
                                return;
                            }
                            var rows = data.data || [];
                            if (!Array.isArray(rows) || rows.length === 0) {
                                var v = getSelectedView();
                                if (v === 'mine') {
                                    setMessage('No cases in My Tasks. Switch View to Available to see pending queue.', 'info');
                                } else if (v === 'available') {
                                    setMessage('No pending cases available right now.', 'info');
                                } else {
                                    setMessage('No completed cases found for the selected filter.', 'info');
                                }
                            }
                            callback({ data: rows });
                        })
                        .catch(function () {
                            setMessage('Network error. Please try again.', 'danger');
                            callback({ data: [] });
                        });
                },
                columns: [
                    { data: 'case_id', defaultContent: '' },
                    { data: 'application_id', defaultContent: '' },
                    {
                        data: null,
                        render: function (_d, _t, row) {
                            var name = ((row && row.candidate_first_name) ? row.candidate_first_name : '') + ' ' + ((row && row.candidate_last_name) ? row.candidate_last_name : '');
                            var appId = row && row.application_id ? row.application_id : '';
                            var caseId = row && row.case_id ? row.case_id : '';
                            var href = buildReportHref(appId, caseId);
                            return '<a href="' + href + '" style="text-decoration:none; color:#2563eb;">' + escapeHtml(name.trim()) + '</a>';
                        }
                    },
                    { data: 'candidate_email', defaultContent: '' },
                    { data: 'candidate_mobile', defaultContent: '' },
                    {
                        data: null,
                        render: function (_d, _t, row) {
                            return escapeHtml(statusLabelForValidator(row));
                        }
                    },
                    {
                        data: 'created_at',
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
                } catch (_e) {}
            }
        }

        if (viewSelect) viewSelect.addEventListener('change', reloadTable);
        if (refreshBtn) refreshBtn.addEventListener('click', reloadTable);

        if (searchEl) {
            var t = null;
            searchEl.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(reloadTable, 250);
            });
        }

        var css1 = 'https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css';
        var css2 = 'https://cdn.datatables.net/buttons/3.1.2/css/buttons.dataTables.min.css';
        var js1 = 'https://cdn.datatables.net/2.1.8/js/dataTables.min.js';
        var js2 = 'https://cdn.datatables.net/buttons/3.1.2/js/dataTables.buttons.min.js';
        var js3 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.html5.min.js';
        var js4 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.print.min.js';
        var js5 = 'https://cdn.datatables.net/buttons/3.1.2/js/buttons.colVis.min.js';
        var jsZip = 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js';

        Promise.all([
            loadCss(css1),
            loadCss(css2)
        ])
            .then(function () { return loadScript(js1); })
            .then(function () { return loadScript(jsZip); })
            .then(function () { return loadScript(js2); })
            .then(function () { return loadScript(js3); })
            .then(function () { return loadScript(js4); })
            .then(function () { return loadScript(js5); })
            .then(function () {
                initDataTable();
                if (dataTable) dataTable.ajax.reload(null, false);
            })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
            });
    });
})();
