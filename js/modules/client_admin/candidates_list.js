(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var searchEl = document.getElementById('casesListSearch');
        var refreshBtn = document.getElementById('casesListRefreshBtn');
        var tableEl = document.getElementById('casesListTable');
        var exportButtonsHostEl = document.getElementById('casesListExportButtons');
        var messageEl = document.getElementById('casesListMessage');
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

        function getQueryParam(name) {
            try {
                return new URLSearchParams(window.location.search || '').get(name);
            } catch (e) {
                return null;
            }
        }

        function buildPdfHref(applicationId) {
            var appId = String(applicationId || '');
            var href = '../shared/candidate_report.php?role=client_admin&print=1&application_id=' + encodeURIComponent(appId);
            return href;
        }

        function slaBadge(createdAt, tatDays) {
            var days = parseInt(tatDays || '20', 10);
            if (!isFinite(days) || days <= 0) days = 20;
            var dt = createdAt ? new Date(String(createdAt).replace(' ', 'T')) : null;
            if (!(dt instanceof Date) || isNaN(dt.getTime())) return '<span class="badge bg-secondary">Unknown</span>';
            var elapsed = Math.max(0, Math.floor((Date.now() - dt.getTime()) / 86400000));
            var remain = days - elapsed;
            if (remain < 0) return '<span class="badge bg-danger">Breached (' + escapeHtml(String(Math.abs(remain))) + 'd)</span>';
            if (remain <= 3) return '<span class="badge bg-warning text-dark">At Risk (' + escapeHtml(String(remain)) + 'd)</span>';
            return '<span class="badge bg-success">On Track (' + escapeHtml(String(remain)) + 'd)</span>';
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
            if (dataTable) {
                dataTable.ajax.reload(null, false);
            }
        }

        function ensureButtonsStyles() {
            if (document.getElementById('vatiPayfillerCasesDtButtonsStylesClient')) return;
            var style = document.createElement('style');
            style.id = 'vatiPayfillerCasesDtButtonsStylesClient';
            style.textContent = [
                '#casesListExportButtons .dt-buttons{display:inline-flex; gap:6px; align-items:center;}',
                '#casesListExportButtons .dt-buttons .dt-button{margin:0;}',
                '#casesListExportButtons .dt-buttons .btn{border-radius:6px; padding:6px 10px; font-size:12px; line-height:1; border:1px solid transparent; cursor:pointer;}',
                '#casesListExportButtons .dt-buttons .btn-secondary{background:#64748b; border-color:#64748b; color:#fff;}',
                '#casesListExportButtons .dt-buttons .btn-success{background:#16a34a; border-color:#16a34a; color:#fff;}',
                '#casesListExportButtons .dt-buttons .btn-dark{background:#0f172a; border-color:#0f172a; color:#fff;}',
                '#casesListExportButtons .dt-buttons .btn-outline{background:#fff; border-color:#cbd5e1; color:#0f172a;}',
                '#casesListExportButtons .dt-buttons .btn:hover{filter:brightness(0.95);}'
            ].join('\n');
            document.head.appendChild(style);
        }

        function initDataTable() {
            if (!tableEl) return;

            if (!window.jQuery || !jQuery.fn || !jQuery.fn.DataTable) {
                setMessage('DataTables is not available. Please refresh.', 'danger');
                return;
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
                    var url = base + '/api/client_admin/cases_list.php?search=' + encodeURIComponent(search || '');

                    fetch(url, { credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                setMessage((data && data.message) ? data.message : 'Failed to load cases.', 'danger');
                                callback({ data: [] });
                                return;
                            }
                            callback({ data: data.data || [] });
                        })
                        .catch(function () {
                            setMessage('Network error. Please try again.', 'danger');
                            callback({ data: [] });
                        });
                },
                columns: [
                    { data: 'case_id' },
                    { data: 'application_id' },
                    {
                        data: null,
                        render: function (_d, _t, row) {
                            var name = ((row && row.candidate_first_name) ? row.candidate_first_name : '') + ' ' + ((row && row.candidate_last_name) ? row.candidate_last_name : '');
                            var appId = row && row.application_id ? String(row.application_id) : '';
                            var href = '../shared/candidate_report.php?role=client_admin&application_id=' + encodeURIComponent(appId);
                            return '<a href="' + href + '" style="text-decoration:none; color:#2563eb;">' + escapeHtml(name.trim()) + '</a>';
                        }
                    },
                    { data: 'candidate_email' },
                    { data: 'candidate_mobile' },
                    { data: 'current_stage' },
                    { data: 'case_status' },
                    {
                        data: 'created_at',
                        render: function (d) { return slaBadge(d, 20); }
                    },
                    {
                        data: null,
                        orderable: false,
                        render: function (_d, _t, row) {
                            var appId = row && row.application_id ? String(row.application_id) : '';
                            var status = String((row && row.case_status) ? row.case_status : '').toUpperCase();
                            var ok = (status === 'APPROVED' || status === 'READY_FOR_VERIFIER' || status === 'VERIFIED');
                            if (!ok) return '';
                            var href = buildPdfHref(appId);
                            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color:#2563eb;">PDF</a>';
                        }
                    },
                    {
                        data: 'invite_sent_at',
                        render: function (d) {
                            return escapeHtml(window.GSS_DATE.formatDbDateTime(d));
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
                } catch (_e) {
                }
            }
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', reloadTable);
        }

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
            .then(function () {
                return loadScript(js1);
            })
            .then(function () {
                return loadScript(jsZip);
            })
            .then(function () {
                return loadScript(js2);
            })
            .then(function () {
                return loadScript(js3);
            })
            .then(function () {
                return loadScript(js4);
            })
            .then(function () {
                return loadScript(js5);
            })
            .then(function () {
                initDataTable();
            })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
            });
    });
})();
