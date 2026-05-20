(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var viewSelect = document.getElementById('qaCasesViewSelect');
        var stateFilterEl = document.getElementById('qaCasesStateFilter');
        var clientSelect = document.getElementById('qaCasesClientSelect');
        var validatorSelect = document.getElementById('qaCasesValidatorSelect');
        var verifierSelect = document.getElementById('qaCasesVerifierSelect');
        var verifierGroupSelect = document.getElementById('qaCasesVerifierGroupSelect');
        var searchEl = document.getElementById('qaCasesListSearch');
        var refreshBtn = document.getElementById('qaCasesListRefreshBtn');
        var autoRefreshEl = document.getElementById('qaCasesAutoRefresh');
        var lastUpdatedEl = document.getElementById('qaCasesLastUpdated');
        var tableEl = document.getElementById('qaCasesListTable');
        var exportButtonsHostEl = document.getElementById('qaCasesListExportButtons');
        var messageEl = document.getElementById('qaCasesListMessage');
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

        function getSelectedClientId() {
            if (!clientSelect) return 0;
            return parseInt(clientSelect.value || '0', 10) || 0;
        }

        function getSelectedValidatorId() {
            if (!validatorSelect) return 0;
            return parseInt(validatorSelect.value || '0', 10) || 0;
        }

        function getSelectedVerifierId() {
            if (!verifierSelect) return 0;
            return parseInt(verifierSelect.value || '0', 10) || 0;
        }

        function getSelectedVerifierGroup() {
            if (!verifierGroupSelect) return '';
            return String(verifierGroupSelect.value || '').trim();
        }

        function getSelectedView() {
            if (!viewSelect) return 'ready';
            var v = String(viewSelect.value || 'ready').toLowerCase();
            if (v === 'all') return 'all';
            if (v === 'pending') return 'pending';
            if (v === 'completed') return 'completed';
            return 'ready';
        }

        function buildReportHref(applicationId) {
            var href = 'case_review.php?application_id=' + encodeURIComponent(String(applicationId || ''));
            return href;
        }

        function qaChipLabel(rowOrStatus) {
            var row = (rowOrStatus && typeof rowOrStatus === 'object') ? rowOrStatus : null;
            var canonical = row ? String(row.stage_status_label || row.operational_status_label || '').trim() : '';
            if (canonical) return canonical;
            var rawStatus = row ? String(row.case_status || row.status || '') : String(rowOrStatus || '');
            if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
                return window.WF_UI.labelByRole(rawStatus, 'qa');
            }
            return String(rawStatus || '-');
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
            if (document.getElementById('vatiPayfillerCasesDtButtonsStylesQa')) return;
            var style = document.createElement('style');
            style.id = 'vatiPayfillerCasesDtButtonsStylesQa';
            style.textContent = [
                '#qaCasesListExportButtons .dt-buttons{display:inline-flex; gap:6px; align-items:center;}',
                '#qaCasesListExportButtons .dt-buttons .dt-button{margin:0;}',
                '#qaCasesListExportButtons .dt-buttons .btn{border-radius:6px; padding:6px 10px; font-size:12px; line-height:1; border:1px solid transparent; cursor:pointer;}',
                '#qaCasesListExportButtons .dt-buttons .btn-secondary{background:#64748b; border-color:#64748b; color:#fff;}',
                '#qaCasesListExportButtons .dt-buttons .btn-success{background:#16a34a; border-color:#16a34a; color:#fff;}',
                '#qaCasesListExportButtons .dt-buttons .btn-dark{background:#0f172a; border-color:#0f172a; color:#fff;}',
                '#qaCasesListExportButtons .dt-buttons .btn-outline{background:#fff; border-color:#cbd5e1; color:#0f172a;}',
                '#qaCasesListExportButtons .dt-buttons .btn:hover{filter:brightness(0.95);}'
            ].join('\n');
            document.head.appendChild(style);
        }

        function reloadTable() {
            if (dataTable) {
                dataTable.ajax.reload(null, false);
            }
        }

        function setLastUpdatedNow() {
            if (!lastUpdatedEl || !window.GSS_DATE || typeof window.GSS_DATE.formatDbDateTime !== 'function') return;
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
                    var view = getSelectedView();
                    var validatorId = getSelectedValidatorId();
                    var verifierId = getSelectedVerifierId();
                    var verifierGroup = getSelectedVerifierGroup();
                    var url = base + '/api/qa/cases_list.php?view=' + encodeURIComponent(view)
                        + '&client_id=' + encodeURIComponent(clientId || 0)
                        + '&validator_user_id=' + encodeURIComponent(validatorId || 0)
                        + '&verifier_user_id=' + encodeURIComponent(verifierId || 0)
                        + '&verifier_group=' + encodeURIComponent(verifierGroup || '')
                        + '&search=' + encodeURIComponent(search || '');

                    fetch(url, { credentials: 'same-origin' })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                setMessage((data && data.message) ? data.message : 'Failed to load cases.', 'danger');
                                callback({ data: [] });
                                return;
                            }
                            setLastUpdatedNow();
                            var rows = Array.isArray(data.data) ? data.data : [];
                            var filterKey = stateFilterEl ? String(stateFilterEl.value || 'all') : 'all';
                            if (window.WF_UI && typeof window.WF_UI.matchesFilter === 'function') {
                                rows = rows.filter(function (r) { return window.WF_UI.matchesFilter(r, filterKey); });
                            }
                            callback({ data: rows });
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
                        render: function (_d, _t, row) {
                            var t = String((row && (row.stage_status_label || row.operational_status_label)) ? (row.stage_status_label || row.operational_status_label) : '');
                            if (!t) {
                                var raw = String((row && row.case_status) ? row.case_status : '');
                                t = (window.WF_UI && typeof window.WF_UI.labelByRole === 'function')
                                    ? String(window.WF_UI.labelByRole(raw, 'qa') || '')
                                    : String((row && row.current_stage) || '');
                            }
                            t = t.trim();
                            var bg = '#e2e8f0';
                            var fg = '#0f172a';
                            var u = t.toUpperCase();
                            if (u === 'PENDING READY') { bg = '#dcfce7'; fg = '#166534'; }
                            else if (u === 'PENDING NOT READY') { bg = '#fee2e2'; fg = '#991b1b'; }
                            else if (u === 'COMPLETED' || u === 'QA COMPLETED') { bg = '#dbeafe'; fg = '#1e40af'; }
                            else if (u === 'QA PENDING') { bg = '#fef9c3'; fg = '#854d0e'; }
                            return '<span style="display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; background:' + bg + '; color:' + fg + ';">' + escapeHtml(t || '-') + '</span>';
                        }
                    },
                    {
                        data: 'validator_assigned_name',
                        render: function (d) {
                            var v = String(d || '').trim();
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
                    {
                        data: 'case_status',
                        render: function (_d, _t, row) {
                            return escapeHtml(qaChipLabel(row));
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
            return fetch(base + '/api/qa/clients_dropdown.php', { credentials: 'same-origin' })
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

        function setStaffOptions(selectEl, items, placeholder) {
            if (!selectEl) return;
            selectEl.innerHTML = '';
            var opt0 = document.createElement('option');
            opt0.value = '0';
            opt0.textContent = placeholder || 'All';
            selectEl.appendChild(opt0);
            (items || []).forEach(function (u) {
                var opt = document.createElement('option');
                opt.value = String(u.user_id || '0');
                opt.textContent = u.name || ('User #' + (u.user_id || ''));
                selectEl.appendChild(opt);
            });
        }

        function loadStaffDropdowns() {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var clientId = getSelectedClientId();

            var p1 = Promise.resolve();
            var p2 = Promise.resolve();

            if (validatorSelect) {
                p1 = fetch(base + '/api/qa/staff_dropdown.php?role=validator&client_id=' + encodeURIComponent(clientId || 0), { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        var rows = (data && data.status === 1 && Array.isArray(data.data)) ? data.data : [];
                        setStaffOptions(validatorSelect, rows, 'All');
                    })
                    .catch(function () {
                        setStaffOptions(validatorSelect, [], 'All');
                    });
            }

            if (verifierSelect) {
                p2 = fetch(base + '/api/qa/staff_dropdown.php?role=verifier&client_id=' + encodeURIComponent(clientId || 0), { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        var rows = (data && data.status === 1 && Array.isArray(data.data)) ? data.data : [];
                        setStaffOptions(verifierSelect, rows, 'All');
                    })
                    .catch(function () {
                        setStaffOptions(verifierSelect, [], 'All');
                    });
            }

            return Promise.all([p1, p2]);
        }

        if (clientSelect) {
            clientSelect.addEventListener('change', function () {
                loadStaffDropdowns().then(function () {
                    reloadTable();
                });
            });
        }

        if (viewSelect) {
            viewSelect.addEventListener('change', reloadTable);
        }
        if (stateFilterEl) {
            stateFilterEl.addEventListener('change', reloadTable);
        }

        if (validatorSelect) {
            validatorSelect.addEventListener('change', reloadTable);
        }

        if (verifierSelect) {
            verifierSelect.addEventListener('change', reloadTable);
        }

        if (verifierGroupSelect) {
            verifierGroupSelect.addEventListener('change', reloadTable);
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
            .then(function () { return loadStaffDropdowns(); })
            .then(function () { return loadScript(js1); })
            .then(function () { return loadScript(jsZip); })
            .then(function () { return loadScript(js2); })
            .then(function () { return loadScript(js3); })
            .then(function () { return loadScript(js4); })
            .then(function () { return loadScript(js5); })
            .then(function () { initDataTable(); })
            .then(function () {
                setAutoRefreshEnabled(!autoRefreshEl || !!autoRefreshEl.checked);
            })
            .catch(function (e) {
                setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
            });
    });
})();
