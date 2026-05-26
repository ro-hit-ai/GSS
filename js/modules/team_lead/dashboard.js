(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var msgEl = document.getElementById('tlMessage');
        var refreshBtn = document.getElementById('tlRefreshBtn');
        var autoEl = document.getElementById('tlAutoRefresh');

        var clientSelect = document.getElementById('tlClientSelect');
        var verifierSelect = document.getElementById('tlVerifierSelect');
        var vrGroupSelect = document.getElementById('tlVrGroupSelect');

        var kVr = document.getElementById('tlKpiVrUnassigned');
        var kDbv = document.getElementById('tlKpiDbvUnassigned');
        var kAsg = document.getElementById('tlKpiActiveAssignments');

        var vrBody = document.getElementById('tlVrUnassignedBody');
        var dbvBody = document.getElementById('tlDbvUnassignedBody');
        var asgBody = document.getElementById('tlAssignmentsBody');

        var timer = null;
        var staff = { verifiers: [] };

        function setMessage(text, type) {
            if (!msgEl) return;
            msgEl.textContent = text || '';
            msgEl.className = type ? ('alert alert-' + type) : '';
            msgEl.style.display = text ? 'block' : 'none';
        }

        function esc(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function n(v) {
            var x = parseInt(v, 10);
            return isFinite(x) ? x : 0;
        }

        function baseUrl() {
            return (window.APP_BASE_URL || '').replace(/\/$/, '');
        }

        function selectedInt(el) {
            if (!el) return 0;
            return parseInt(String(el.value || '0'), 10) || 0;
        }

        function selectedStr(el) {
            if (!el) return '';
            return String(el.value || '').trim();
        }

        function fillSelect(el, rows, labelAll) {
            if (!el) return;
            var html = '<option value="0">' + esc(labelAll || 'All') + '</option>';
            rows.forEach(function (r) {
                html += '<option value="' + esc(String(r.client_id || r.user_id || '0')) + '">' + esc(String(r.customer_name || r.name || '')) + '</option>';
            });
            el.innerHTML = html;
        }

        function loadClients() {
            if (!clientSelect) return Promise.resolve();
            return fetch(baseUrl() + '/api/qa/clients_dropdown.php', { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 1 || !Array.isArray(data.data)) return;
                    var html = '<option value="0">All</option>';
                    data.data.forEach(function (c) {
                        html += '<option value="' + esc(String(c.client_id || 0)) + '">' + esc(String(c.customer_name || '')) + '</option>';
                    });
                    clientSelect.innerHTML = html;
                })
                .catch(function () {
                });
        }

        function loadStaff() {
            var cid = selectedInt(clientSelect);
            var v2 = fetch(baseUrl() + '/api/qa/staff_dropdown.php?role=verifier&client_id=' + encodeURIComponent(String(cid)), { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    staff.verifiers = (data && data.status === 1 && Array.isArray(data.data)) ? data.data : [];
                })
                .catch(function () { staff.verifiers = []; });

            return Promise.all([v2]).then(function () {
                if (verifierSelect) {
                    var html2 = '<option value="0">All</option>';
                    staff.verifiers.forEach(function (u) {
                        html2 += '<option value="' + esc(String(u.user_id || 0)) + '">' + esc(String(u.name || '')) + '</option>';
                    });
                    verifierSelect.innerHTML = html2;
                }
            });
        }

        function setKpi(el, val) {
            if (!el) return;
            el.textContent = String(val == null ? '-' : val);
        }

        function userOptions(users) {
            users = Array.isArray(users) ? users : [];
            return users.map(function (u) {
                return '<option value="' + esc(String(u.user_id || 0)) + '">' + esc(String(u.name || '')) + '</option>';
            }).join('');
        }

        function renderAssignCell(queueType, row) {
            var id = n(row.case_id);
            var group = row.group_key ? String(row.group_key) : '';
            var users = staff.verifiers;
            var selectId = 'tlAssign_' + queueType + '_' + id + (group ? '_' + group : '');
            var btnId = 'tlAssignBtn_' + queueType + '_' + id + (group ? '_' + group : '');
            var opts = userOptions(users);
            if (!opts) {
                return '<span style="font-size:12px; color:#64748b;">No users</span>';
            }
            return '<div style="display:flex; gap:6px; align-items:center;">' +
                '<select id="' + esc(selectId) + '" style="font-size:12px; padding:4px 6px; min-width:170px;"><option value="0">Select</option>' + opts + '</select>' +
                '<button type="button" class="btn btn-sm" id="' + esc(btnId) + '" data-queue="' + esc(queueType) + '" data-case="' + esc(String(id)) + '" data-group="' + esc(group) + '">Assign</button>' +
                '</div>';
        }

        function bindAssignButtons() {
            document.querySelectorAll('button[id^="tlAssignBtn_"]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var queue = btn.getAttribute('data-queue') || '';
                    var caseId = parseInt(btn.getAttribute('data-case') || '0', 10) || 0;
                    var group = btn.getAttribute('data-group') || '';

                    var selectId = 'tlAssign_' + queue + '_' + caseId + (group ? '_' + group : '');
                    var sel = document.getElementById(selectId);
                    var userId = sel ? (parseInt(String(sel.value || '0'), 10) || 0) : 0;
                    if (userId <= 0) {
                        setMessage('Please select a user to assign.', 'danger');
                        return;
                    }

                    assign(queue, caseId, group, userId);
                });
            });
        }

        function renderTable(host, rows, queueType) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = '<tr><td colspan="4" style="color:#64748b;">No items.</td></tr>';
                return;
            }

            host.innerHTML = rows.map(function (r) {
                if (queueType === 'vr') {
                    return '<tr>' +
                        '<td>' + esc(String(r.application_id || '')) + '<div style="font-size:11px; color:#64748b;">Case #' + esc(String(r.case_id || '')) + '</div></td>' +
                        '<td style="font-weight:800;">' + esc(String(r.group_key || '')) + '</td>' +
                        '<td>' + esc(String(r.customer_name || '')) + '</td>' +
                        '<td>' + renderAssignCell('vr', r) + '</td>' +
                        '</tr>';
                }
                return '';
            }).join('');
        }

        function renderDbv(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = '<tr><td colspan="4" style="color:#64748b;">No items.</td></tr>';
                return;
            }

            host.innerHTML = rows.map(function (r) {
                return '<tr>' +
                    '<td>' + esc(String(r.application_id || '')) + '<div style="font-size:11px; color:#64748b;">Case #' + esc(String(r.case_id || '')) + '</div></td>' +
                    '<td>' + esc(String(r.customer_name || '')) + '</td>' +
                    '<td style="font-size:12px; color:#64748b;">' + esc(String(r.case_status || '')) + '</td>' +
                    '<td>' + renderAssignCell('dbv', r) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function renderAssignments(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = '<tr><td colspan="5" style="color:#64748b;">No assignments.</td></tr>';
                return;
            }

            host.innerHTML = rows.map(function (r) {
                var who = (String(r.first_name || '') + ' ' + String(r.last_name || '')).trim() || String(r.username || '');
                return '<tr>' +
                    '<td style="font-weight:800;">' + esc(String(r.queue_type || '')) + '</td>' +
                    '<td>' + esc(String(r.application_id || '')) + '<div style="font-size:11px; color:#64748b;">Case #' + esc(String(r.case_id || '')) + '</div></td>' +
                    '<td>' + esc(r.group_key ? String(r.group_key) : '-') + '</td>' +
                    '<td>' + esc(who) + '<div style="font-size:11px; color:#64748b;">' + esc(String(r.role || '')) + '</div></td>' +
                    '<td style="font-size:12px; color:#64748b;">' + esc(String(r.case_status || '')) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function loadDashboard() {
            setMessage('', '');

            var q = [];
            var cid = selectedInt(clientSelect);
            var verifierId = selectedInt(verifierSelect);
            var group = selectedStr(vrGroupSelect);
            if (cid > 0) q.push('client_id=' + encodeURIComponent(String(cid)));
            if (verifierId > 0) q.push('verifier_user_id=' + encodeURIComponent(String(verifierId)));
            if (group) q.push('vr_group=' + encodeURIComponent(String(group)));

            var url = baseUrl() + '/api/team_lead/dashboard_stats.php';
            if (q.length) url += '?' + q.join('&');

            fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 1) throw new Error((data && data.message) ? data.message : 'Failed');
                    var d = data.data || {};

                    setKpi(kVr, n(d.kpis && d.kpis.vr_unassigned));
                    setKpi(kDbv, n(d.kpis && d.kpis.dbv_unassigned));
                    setKpi(kAsg, n(d.kpis && d.kpis.active_assignments));

                    renderTable(vrBody, d.unassigned && d.unassigned.vr ? d.unassigned.vr : [], 'vr');
                    renderDbv(dbvBody, d.unassigned && d.unassigned.dbv ? d.unassigned.dbv : []);
                    renderAssignments(asgBody, d.assignments || []);

                    bindAssignButtons();
                })
                .catch(function (e) {
                    setMessage(e && e.message ? e.message : 'Failed to load.', 'danger');
                });
        }

        function assign(queue, caseId, group, userId) {
            setMessage('', '');

            var payload = { queue: queue, case_id: caseId, user_id: userId };
            if (queue === 'vr') payload.group_key = group;

            fetch(baseUrl() + '/api/team_lead/assign_case.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
                .then(function (res) { return res.json().catch(function () { return null; }); })
                .then(function (data) {
                    if (!data || data.status !== 1) {
                        throw new Error((data && data.message) ? data.message : 'Assign failed');
                    }
                    setMessage('Assigned successfully.', 'success');
                    loadDashboard();
                })
                .catch(function (e) {
                    setMessage(e && e.message ? e.message : 'Assign failed', 'danger');
                });
        }

        function applyAuto() {
            var on = !!(autoEl && autoEl.checked);
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (on) {
                timer = setInterval(loadDashboard, 20000);
            }
        }

        function init() {
            loadClients()
                .then(function () {
                    return loadStaff();
                })
                .then(function () {
                    loadDashboard();
                    applyAuto();
                });

            if (refreshBtn) refreshBtn.addEventListener('click', loadDashboard);
            if (autoEl) autoEl.addEventListener('change', applyAuto);
            if (clientSelect) clientSelect.addEventListener('change', function () {
                loadStaff().then(loadDashboard);
            });
            if (verifierSelect) verifierSelect.addEventListener('change', loadDashboard);
            if (vrGroupSelect) vrGroupSelect.addEventListener('change', loadDashboard);
        }

        init();
    });
})();
