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

        var aOldestVr = document.getElementById('qaAgingOldestVr');
        var aOldestDbv = document.getElementById('qaAgingOldestDbv');
        var aReopenedOverSla = document.getElementById('qaAgingReopenedOverSla');
        var aAttentionOverSla = document.getElementById('qaAgingAttentionOverSla');

        var sInvalidatedVerifier = document.getElementById('qaSignalInvalidatedVerifier');
        var sInvalidatedQa = document.getElementById('qaSignalInvalidatedQa');

        var vrHost = document.getElementById('qaWorkloadVrBody');
        var dbvHost = document.getElementById('qaWorkloadDbvBody');
        var asgHost = document.getElementById('qaAssignmentsBody');

        var timer = null;
        var DASH_POLL_MS = 15000;

        function setMessage(text, type) {
            if (!msgEl) return;
            msgEl.textContent = text || '';
            msgEl.className = type ? ('alert alert-' + type) : 'alert';
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
            return n(map[String(key || '').toLowerCase()]);
        }

        function fmtName(r) {
            var name = (String(r.first_name || '') + ' ' + String(r.last_name || '')).trim();
            return name || String(r.username || '');
        }

        function setKpi(el, val) {
            if (!el) return;
            el.textContent = String(val == null ? '' : val);
        }

        function formatMinutes(value) {
            if (value == null || value === '' || !isFinite(Number(value))) return '-';
            var total = Math.max(0, parseInt(value, 10) || 0);
            var days = Math.floor(total / 1440);
            var hours = Math.floor((total % 1440) / 60);
            var minutes = total % 60;
            if (days > 0) return String(days) + 'd ' + String(hours) + 'h';
            if (hours > 0) return String(hours) + 'h ' + String(minutes) + 'm';
            return String(minutes) + 'm';
        }

        function emptyRow(colspan, title, detail) {
            return '<tr><td colspan="' + colspan + '"><div class="qa-empty"><strong>' + esc(title) + '</strong>' + esc(detail) + '</div></td></tr>';
        }

        function statusTone(label) {
            var txt = String(label || '').toLowerCase();
            if (!txt) return 'muted';
            if (txt.indexOf('complete') !== -1 || txt.indexOf('approved') !== -1 || txt.indexOf('closed') !== -1) return 'success';
            if (txt.indexOf('invalid') !== -1 || txt.indexOf('reject') !== -1 || txt.indexOf('reopen') !== -1) return 'danger';
            if (txt.indexOf('hold') !== -1 || txt.indexOf('follow') !== -1 || txt.indexOf('need') !== -1 || txt.indexOf('insuff') !== -1 || txt.indexOf('wait') !== -1) return 'attention';
            if (txt.indexOf('pending') !== -1 || txt.indexOf('review') !== -1 || txt.indexOf('progress') !== -1) return 'info';
            return 'muted';
        }

        function queueTone(queueType) {
            var q = String(queueType || '').toUpperCase();
            return q === 'DBV' ? 'dbv' : 'vr';
        }

        function renderWorkload(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = emptyRow(3, 'No active workload right now.', 'This support panel will populate when queue ownership becomes active.');
                return;
            }
            host.innerHTML = rows.map(function (r) {
                var stageLabel = String((r && (r.stage_status_label || r.operational_status_label)) ? (r.stage_status_label || r.operational_status_label) : ((window.WF_UI && typeof window.WF_UI.labelByRole === 'function') ? window.WF_UI.labelByRole('pending', 'qa') : 'QA Pending'));
                var tone = statusTone(stageLabel);
                return '<tr>' +
                    '<td>' +
                        '<div class="qa-name">' + esc(fmtName(r)) + '</div>' +
                        '<div class="qa-meta">' + esc(String(r.username || '')) + ' | ' + esc(String(r.role || '')) + '</div>' +
                    '</td>' +
                    '<td><span class="qa-count-badge">' + esc(String(r.open_items || '0')) + '</span></td>' +
                    '<td><span class="qa-status-badge qa-status-badge--' + esc(tone) + '">' + esc(stageLabel) + '</span></td>' +
                '</tr>';
            }).join('');
        }

        function renderAssignments(host, rows) {
            if (!host) return;
            rows = Array.isArray(rows) ? rows : [];
            if (!rows.length) {
                host.innerHTML = emptyRow(6, 'No live assignments at the moment.', 'Once work is assigned, this table becomes the main ownership view for the dashboard.');
                return;
            }
            host.innerHTML = rows.map(function (r) {
                var who = fmtName(r);
                var queue = String(r.queue_type || '').toUpperCase();
                var group = r.group_key ? String(r.group_key) : '-';
                var st = String((r && (r.stage_status_label || r.operational_status_label)) ? (r.stage_status_label || r.operational_status_label) : '');
                if (!st) {
                    var raw = r.queue_status ? String(r.queue_status) : '';
                    st = (window.WF_UI && typeof window.WF_UI.labelByRole === 'function')
                        ? String(window.WF_UI.labelByRole(raw || 'pending', 'qa'))
                        : (raw || '-');
                }
                var stageLabel = String(r.stage_status_label || r.operational_status_label || r.case_status || '-');
                return '<tr>' +
                    '<td><span class="qa-queue-badge qa-queue-badge--' + esc(queueTone(queue)) + '">' + esc(queue || 'VR') + '</span></td>' +
                    '<td>' +
                        '<div class="qa-app-id">' + esc(String(r.application_id || '')) + '</div>' +
                        '<div class="qa-meta">Case #' + esc(String(r.case_id || '')) + '</div>' +
                    '</td>' +
                    '<td><span class="qa-group-badge">' + esc(group) + '</span></td>' +
                    '<td><span class="qa-status-badge qa-status-badge--' + esc(statusTone(st)) + '">' + esc(st) + '</span></td>' +
                    '<td>' +
                        '<div class="qa-name">' + esc(who) + '</div>' +
                        '<div class="qa-meta">' + esc(String(r.role || '')) + '</div>' +
                    '</td>' +
                    '<td><span class="qa-status-badge qa-status-badge--' + esc(statusTone(stageLabel)) + '">' + esc(stageLabel) + '</span></td>' +
                '</tr>';
            }).join('');
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
                    var aging = d.aging || {};

                    setKpi(kUsersTotal, n(kpis.users_total));
                    setKpi(kQaUsers, roleCount(kpis.users_by_role, 'qa'));
                    setKpi(kVrOpen, n(kpis.verifier_queue_open_total));
                    setKpi(kDbvOpen, n(kpis.dbv_open_total));
                    setKpi(kSupervisoryReopens, n(kpis.supervisory_reopens_today));
                    setKpi(kInvalidatedVerifier, n(kpis.invalidated_verifier_total));
                    setKpi(kInvalidatedQa, n(kpis.invalidated_qa_total));
                    setKpi(kReopenedWorkflows, n(kpis.reopened_workflows_total));

                    setKpi(aOldestVr, formatMinutes(aging.oldest_vr_pending_minutes));
                    setKpi(aOldestDbv, formatMinutes(aging.oldest_dbv_pending_minutes));
                    setKpi(aReopenedOverSla, n(aging.reopened_over_sla_count));
                    setKpi(aAttentionOverSla, n(aging.qa_attention_over_sla_count));

                    setKpi(sInvalidatedVerifier, n(kpis.invalidated_verifier_total));
                    setKpi(sInvalidatedQa, n(kpis.invalidated_qa_total));

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
