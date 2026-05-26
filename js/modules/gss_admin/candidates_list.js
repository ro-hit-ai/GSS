(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var clientSelect = document.getElementById('casesClientSelect');
        var searchEl = document.getElementById('casesListSearch');
        var refreshBtn = document.getElementById('casesListRefreshBtn');
        var autoRefreshEl = document.getElementById('casesAutoRefresh');
        var lastUpdatedEl = document.getElementById('casesLastUpdated');
        var tableEl = document.getElementById('casesListTable');
        var exportButtonsHostEl = document.getElementById('casesListExportButtons');
        var messageEl = document.getElementById('casesListMessage');
        var dataTable = null;
        var autoTimer = null;

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

        function buildInviteHref(inviteToken) {
            var token = String(inviteToken || '').trim();
            if (!token) return '';
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            return base + '/modules/candidate/login.php?token=' + encodeURIComponent(token);
        }

        function buildReportHref(applicationId) {
            var cid = getSelectedClientId();
            var href = '../shared/candidate_report.php?role=gss_admin&application_id=' + encodeURIComponent(String(applicationId || ''));
            if (cid > 0) {
                href += '&client_id=' + encodeURIComponent(String(cid));
            }
            return href;
        }

        function buildPdfHref(applicationId) {
            var cid = getSelectedClientId();
            var href = '../shared/candidate_report.php?role=gss_admin&print=1&application_id=' + encodeURIComponent(String(applicationId || ''));
            if (cid > 0) {
                href += '&client_id=' + encodeURIComponent(String(cid));
            }
            return href;
        }

        function getSelectedClientId() {
            if (!clientSelect) return 0;
            return parseInt(clientSelect.value || '0', 10) || 0;
        }

        function workflowModeLabel(mode) {
            var m = String(mode || '').trim().toLowerCase();
            if (m === 'verifier_first') return 'Verifier First';
            if (m === 'validator_first') return 'Validator First';
            return '-';
        }

        function workflowModePill(mode) {
            var m = String(mode || '').trim().toLowerCase();
            var label = workflowModeLabel(m);
            var bg = '#e2e8f0';
            var fg = '#334155';
            if (m === 'verifier_first') {
                bg = '#dcfce7';
                fg = '#166534';
            } else if (m === 'validator_first') {
                bg = '#fef3c7';
                fg = '#92400e';
            }
            return '<span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:700; background:' + bg + '; color:' + fg + ';">' + escapeHtml(label) + '</span>';
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

        function setLastUpdatedNow() {
            if (!lastUpdatedEl) return;
            var d = new Date();
            var pad = function (n) { return n < 10 ? ('0' + n) : String(n); };
            var v = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
            lastUpdatedEl.textContent = 'Updated: ' + v;
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

        function ensureButtonsStyles() {
            if (document.getElementById('vatiPayfillerCasesDtButtonsStylesGss')) return;
            var style = document.createElement('style');
            style.id = 'vatiPayfillerCasesDtButtonsStylesGss';
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
                    var clientId = getSelectedClientId();

                    var url = base + '/api/gssadmin/cases_list.php?client_id=' + encodeURIComponent(clientId || 0) + '&search=' + encodeURIComponent(search || '');

                    fetch(url, { credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                setMessage((data && data.message) ? data.message : 'Failed to load cases.', 'danger');
                                callback({ data: [] });
                                return;
                            }
                            setLastUpdatedNow();
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
                            var appId = row && row.application_id ? row.application_id : '';
                            var href = buildReportHref(appId);
                            return '<a href="' + href + '" style="text-decoration:none; color:#2563eb;">' + escapeHtml(name.trim()) + '</a>';
                        }
                    },
                    { data: 'candidate_email' },
                    { data: 'candidate_mobile' },
                    {
                        data: 'current_stage',
                        render: function (d) {
                            var t = String(d || '').trim();
                            var bg = '#e2e8f0';
                            var fg = '#0f172a';
                            var u = t.toUpperCase();
                            if (u.indexOf('QA') === 0) { bg = '#dbeafe'; fg = '#1e40af'; }
                            else if (u.indexOf('VALIDATION') === 0) { bg = '#fef9c3'; fg = '#854d0e'; }
                            else if (u.indexOf('VERIFIER') === 0) { bg = '#dcfce7'; fg = '#166534'; }
                            else if (u === 'CANDIDATE SUBMITTED') { bg = '#ede9fe'; fg = '#5b21b6'; }
                            else if (u === 'INVITED') { bg = '#e0f2fe'; fg = '#075985'; }
                            return '<span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; background:' + bg + '; color:' + fg + ';">' + escapeHtml(t || '-') + '</span>';
                        }
                    },
                    {
                        data: 'workflow_mode',
                        render: function (d) {
                            return workflowModePill(d);
                        }
                    },
                    {
                        data: 'validator_assigned_name',
                        render: function (d, _t, row) {
                            var v = String(d || '').trim();
                            var workflowMode = String(row && row.workflow_mode ? row.workflow_mode : '').trim().toLowerCase();
                            if (workflowMode === 'verifier_first') {
                                if (v !== '') {
                                    return '<span title="Validator compatibility queue only" style="color:#475569;">Compat: ' + escapeHtml(v) + '</span>';
                                }
                                return '<span title="Validator compatibility queue only" style="color:#64748b;">Compat Only</span>';
                            }
                            return escapeHtml(v !== '' ? v : '-');
                        }
                    },
                    {
                        data: 'verifier_assigned_name',
                        render: function (d) {
                            var v = String(d || '').trim();
                            return escapeHtml(v !== '' ? v : '-');
                        }
                    },
                    { data: 'case_status' },
                    {
                        data: null,
                        orderable: false,
                        render: function (_d, _t, row) {
                            var appId = row && row.application_id ? row.application_id : '';
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
                        data: null,
                        orderable: false,
                        render: function (_d, _t, row) {
                            var href = buildInviteHref(row && row.invite_token ? row.invite_token : '');
                            if (!href) return '';
                            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer" style="text-decoration:none; color:#2563eb;">Open</a>';
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

        function loadClients() {
            if (!clientSelect) return Promise.resolve();

            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            return fetch(base + '/api/gssadmin/clients_dropdown.php', { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    clientSelect.innerHTML = '<option value="0">All Clients</option>';
                    if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                        return;
                    }
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

        if (clientSelect) {
            clientSelect.addEventListener('change', reloadTable);
        }

        if (refreshBtn) {
            refreshBtn.addEventListener('click', reloadTable);
        }

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
            .then(function () {
                setAutoRefreshEnabled(!autoRefreshEl || !!autoRefreshEl.checked);
            })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
            });
    });
})();
