(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var msgEl = document.getElementById('qaDashMessage');
        var refreshBtn = document.getElementById('qaDashRefreshBtn');
        var autoEl = document.getElementById('qaDashAutoRefresh');

        var kUsersTotal = document.getElementById('qaKpiUsersTotal');
        var kQaUsers = document.getElementById('qaKpiQaUsers');
        var kVrOpen = document.getElementById('qaKpiVrOpen');
        var kDbvOpen = document.getElementById('qaKpiDbvOpen');
        var kSupervisoryReopens = document.getElementById('qaKpiSupervisoryReopens');
        var kInvalidatedVerifier = document.getElementById('qaKpiInvalidatedVerifier');
        var kInvalidatedQa = document.getElementById('qaKpiInvalidatedQa');
        var kReopenedWorkflows = document.getElementById('qaKpiReopenedWorkflows');
        var vrHost = document.getElementById('qaWorkloadVrBody');
        var dbvHost = document.getElementById('qaWorkloadDbvBody');
        var asgHost = document.getElementById('qaAssignmentsBody');

        var timer = null;
        var DASH_POLL_MS = 15000;

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

        function roleCount(map, key) {
            if (!map) return 0;
            var k = String(key || '').toLowerCase();
            return n(map[k]);
        }

        function fmtName(r) {
            var name = (String(r.first_name || '') + ' ' + String(r.last_name || '')).trim();
            return name || String(r.username || '');
        }

        function renderWorkload(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = '<tr><td colspan="3" style="color:#64748b;">No active workload.</td></tr>';
                return;
            }
            host.innerHTML = rows.map(function (r) {
                return '<tr>' +
                    '<td><div style="font-weight:800; color:#0f172a;">' + esc(fmtName(r)) + '</div><div style="font-size:11px; color:#64748b;">' + esc(String(r.username || '')) + ' • ' + esc(String(r.role || '')) + '</div></td>' +
                    '<td style="white-space:nowrap;"><span class="badge" style="background:#0ea5e9; color:#fff;">' + esc(String(r.open_items || '0')) + '</span></td>' +
                    '<td style="font-size:12px; color:#64748b;">' + esc(String((r && (r.stage_status_label || r.operational_status_label)) ? (r.stage_status_label || r.operational_status_label) : ((window.WF_UI && typeof window.WF_UI.labelByRole === 'function') ? window.WF_UI.labelByRole('pending', 'qa') : 'QA PENDING'))) + '</td>' +
                '</tr>';
            }).join('');
        }

        function renderAssignments(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = '<tr><td colspan="6" style="color:#64748b;">No active assignments.</td></tr>';
                return;
            }
            host.innerHTML = rows.map(function (r) {
                var who = fmtName(r);
                var q = String(r.queue_type || '');
                var group = r.group_key ? String(r.group_key) : '-';
                var st = String((r && (r.stage_status_label || r.operational_status_label)) ? (r.stage_status_label || r.operational_status_label) : '');
                if (!st) {
                    var raw = r.queue_status ? String(r.queue_status) : '';
                    st = (window.WF_UI && typeof window.WF_UI.labelByRole === 'function')
                        ? String(window.WF_UI.labelByRole(raw || 'pending', 'qa'))
                        : (raw || '-');
                }
                return '<tr>' +
                    '<td style="font-weight:800;">' + esc(q) + '</td>' +
                    '<td>' + esc(String(r.application_id || '')) + '<div style="font-size:11px; color:#64748b;">Case #' + esc(String(r.case_id || '')) + '</div></td>' +
                    '<td>' + esc(group) + '</td>' +
                    '<td><span class="badge" style="background:#f1f5f9; color:#0f172a; border:1px solid rgba(148,163,184,0.28);">' + esc(st) + '</span></td>' +
                    '<td><div style="font-weight:800; color:#0f172a;">' + esc(who) + '</div><div style="font-size:11px; color:#64748b;">' + esc(String(r.role || '')) + '</div></td>' +
                    '<td style="font-size:12px; color:#64748b;">' + esc(String(r.stage_status_label || r.operational_status_label || r.case_status || '')) + '</td>' +
                '</tr>';
            }).join('');
        }

        function setKpi(el, val) {
            if (!el) return;
            el.textContent = String(val == null ? '' : val);
        }

        function load() {
            setMessage('', '');
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            fetch(base + '/api/qa/dashboard_stats.php?_ts=' + Date.now(), { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 1) throw new Error((data && data.message) ? data.message : 'Failed');

                    var d = data.data || {};
                    var kpis = d.kpis || {};

                    setKpi(kUsersTotal, n(kpis.users_total));
                    setKpi(kQaUsers, roleCount(kpis.users_by_role, 'qa'));
                    setKpi(kVrOpen, n(kpis.verifier_queue_open_total));
                    setKpi(kDbvOpen, n(kpis.dbv_open_total));
                    setKpi(kSupervisoryReopens, n(kpis.supervisory_reopens_today));
                    setKpi(kInvalidatedVerifier, n(kpis.invalidated_verifier_total));
                    setKpi(kInvalidatedQa, n(kpis.invalidated_qa_total));
                    setKpi(kReopenedWorkflows, n(kpis.reopened_workflows_total));

                    renderWorkload(vrHost, d.workload && d.workload.vr ? d.workload.vr : []);
                    renderWorkload(dbvHost, d.workload && d.workload.dbv ? d.workload.dbv : []);
                    renderAssignments(asgHost, d.assignments || []);
                })
                .catch(function (e) {
                    setMessage(e.message, 'danger');
                });
        }

        function applyAuto() {
            var on = !autoEl || !!autoEl.checked;
            if (timer) {
                clearInterval(timer);
                timer = null;
            }
            if (on) {
                timer = setInterval(function () {
                    if (document.visibilityState === 'hidden') return;
                    load();
                }, DASH_POLL_MS);
            }
        }

        if (refreshBtn) refreshBtn.addEventListener('click', load);
        if (autoEl) autoEl.addEventListener('change', applyAuto);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') load();
        });

        load();
        applyAuto();
    });
})();
