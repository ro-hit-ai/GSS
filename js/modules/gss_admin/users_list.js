document.addEventListener('DOMContentLoaded', function () {
    var clientSelect = document.getElementById('usersClientSelect');
    var searchEl = document.getElementById('usersListSearch');
    var refreshBtn = document.getElementById('usersListRefreshBtn');
    var createBtn = document.getElementById('usersCreateBtn');
    var staffCreateBtn = document.getElementById('staffUsersCreateBtn');
    var tableEl = document.getElementById('usersListTable');
    var exportButtonsHostEl = document.getElementById('usersListExportButtons');
    var messageEl = document.getElementById('usersListMessage');
    var dataTable = null;

    var url = new URL(window.location.href);
    var lockedClientId = parseInt(url.searchParams.get('client_id') || '0', 10) || 0;
    var view = String(url.searchParams.get('view') || '').toLowerCase();
    var isStaffView = (view === 'staff');

    function setMessage(text, type) {
        if (!messageEl) return;
        messageEl.textContent = text || '';
        messageEl.className = type ? ('alert alert-' + type) : '';
        messageEl.style.display = text ? 'block' : 'none';
    }

    function escapeHtml(str) {
        return String(str)
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

    function updateCreateLink() {
        var cid = getSelectedClientId();
        if (createBtn) {
            createBtn.href = cid > 0 ? ('user_create.php?client_id=' + encodeURIComponent(cid)) : 'user_create.php';
        }
        if (staffCreateBtn) {
            staffCreateBtn.href = cid > 0 ? ('staff_user_create.php?client_id=' + encodeURIComponent(cid)) : 'staff_user_create.php';
        }
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
        if (document.getElementById('vatiPayfillerUsersDtButtonsStyles')) return;
        var style = document.createElement('style');
        style.id = 'vatiPayfillerUsersDtButtonsStyles';
        style.textContent = [
            '#usersListExportButtons .dt-buttons{display:inline-flex; gap:6px; align-items:center;}',
            '#usersListExportButtons .dt-buttons .dt-button{margin:0;}',
            '#usersListExportButtons .dt-buttons .btn{border-radius:6px; padding:6px 10px; font-size:12px; line-height:1; border:1px solid transparent; cursor:pointer;}',
            '#usersListExportButtons .dt-buttons .btn-secondary{background:#64748b; border-color:#64748b; color:#fff;}',
            '#usersListExportButtons .dt-buttons .btn-success{background:#16a34a; border-color:#16a34a; color:#fff;}',
            '#usersListExportButtons .dt-buttons .btn-dark{background:#0f172a; border-color:#0f172a; color:#fff;}',
            '#usersListExportButtons .dt-buttons .btn-outline{background:#fff; border-color:#cbd5e1; color:#0f172a;}',
            '#usersListExportButtons .dt-buttons .btn:hover{filter:brightness(0.95);}'
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
                var search = searchEl ? (searchEl.value || '').trim() : '';
                var cid = getSelectedClientId();

                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                var url = base + '/api/gssadmin/users_list.php';
                var qs = [];
                if (cid > 0) qs.push('client_id=' + encodeURIComponent(cid));
                qs.push('group=' + encodeURIComponent(isStaffView ? 'staff' : 'client'));
                if (search) qs.push('search=' + encodeURIComponent(search));
                if (qs.length) url += '?' + qs.join('&');

                fetch(url, { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || data.status !== 1) {
                            setMessage((data && data.message) ? data.message : 'Failed to load users.', 'danger');
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
                { data: 'customer_name' },
                {
                    data: 'username',
                    render: function (_d, _t, row) {
                        var uid = row && row.user_id ? row.user_id : '';
                        var cid = row && row.client_id ? row.client_id : '';
                        var href = isStaffView
                            ? ('staff_user_create.php?user_id=' + encodeURIComponent(uid) + '&client_id=' + encodeURIComponent(cid))
                            : ('user_create.php?user_id=' + encodeURIComponent(uid) + '&client_id=' + encodeURIComponent(cid));
                        return '<a href="' + href + '" style="text-decoration:none; color:#2563eb;">' + escapeHtml(row.username || '') + '</a>';
                    }
                },
                { data: 'first_name' },
                { data: 'last_name' },
                { data: 'role' },
                {
                    data: 'is_active',
                    render: function (d) {
                        var active = (d === 1 || d === '1' || d === true);
                        return '<span class="badge">' + (active ? 'Active' : 'Inactive') + '</span>';
                    }
                },
                { data: 'location' }
            ]
        });

        if (exportButtonsHostEl && dataTable && dataTable.buttons) {
            try {
                exportButtonsHostEl.innerHTML = '';
                exportButtonsHostEl.appendChild(dataTable.buttons().container()[0]);
            } catch (_e) {
                // no-op
            }
        }
    }

    function loadClients() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/gssadmin/clients_dropdown.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load clients');
                }
                if (!clientSelect) return;

                clientSelect.innerHTML = lockedClientId > 0 ? '' : '<option value="0">All Clients</option>';
                data.data.forEach(function (c) {
                    var opt = document.createElement('option');
                    opt.value = String(c.client_id || '');
                    opt.textContent = c.customer_name || ('Client #' + c.client_id);
                    clientSelect.appendChild(opt);
                });

                if (lockedClientId > 0) {
                    clientSelect.value = String(lockedClientId);
                    clientSelect.disabled = true;
                }

                updateCreateLink();
            });
    }

    if (clientSelect) {
        clientSelect.addEventListener('change', function () {
            updateCreateLink();
            reloadTable();
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', reloadTable);
    }

    if (searchEl) {
        searchEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                reloadTable();
            }
        });
    }

    // DataTables (with Buttons) via official CDN
    // Note: layout.php already includes jQuery.
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
        .catch(function (e) {
            setMessage(e && e.message ? e.message : 'Failed to load DataTables assets.', 'danger');
        });
});
