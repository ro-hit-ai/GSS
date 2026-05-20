document.addEventListener('DOMContentLoaded', function () {
    var kpiPending = document.getElementById('valKpiPending');
    var kpiInProgress = document.getElementById('valKpiInProgress');
    var kpiCompletedToday = document.getElementById('valKpiCompletedToday');
    var tasksBody = document.getElementById('valMyTasksBody');
    var startBtn = document.getElementById('valDashStartNextBtn');
    var refreshBtn = document.getElementById('valDashRefreshBtn');
    var messageEl = document.getElementById('valDashMessage');
    var governanceHost = document.getElementById('valGovernanceSignals');
    var governanceSummaryEl = document.getElementById('valGovernanceSummary');
    var refreshInFlight = false;

    function setMessage(text, type) {
        if (!messageEl) return;
        messageEl.textContent = text || '';
        messageEl.className = type ? ('alert alert-' + type) : '';
        messageEl.style.display = text ? 'block' : 'none';
    }

    function esc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function fmtStatus(row) {
        if (!row) return 'pending';
        if (row.stage_status_label) return String(row.stage_status_label);
        var s = String(row.status || '').toLowerCase().trim();
        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
            if (row.completed_at && (s === '' || s === 'in_progress' || s === 'pending')) s = 'completed';
            return window.WF_UI.labelByRole(s, 'validator');
        }
        if (row.completed_at || s === 'done' || s === 'completed') return 'Review Complete';
        if (s === 'waiting_candidate') return 'Waiting Candidate';
        if (s === 'blocked' || s === 'hold' || s === 'insufficient_documents') return 'On Hold';
        if (s === 'in_progress') return 'Under Evaluation';
        return 'Awaiting Evaluation';
    }

    function renderGovernanceSignals(kpi) {
        if (!governanceHost) return;
        kpi = kpi || {};
        var reopenTotal = parseInt(kpi.reopen_actions_total || '0', 10) || 0;
        var invalidatedTotal = parseInt(kpi.active_invalidated_downstream || '0', 10) || 0;
        var correctionTotal = parseInt(kpi.correction_requested || '0', 10) || 0;
        var stuckTotal = parseInt(kpi.stuck_reopened_components || '0', 10) || 0;
        if (governanceSummaryEl) {
            if (invalidatedTotal > 0) governanceSummaryEl.textContent = invalidatedTotal + ' downstream items need reconciliation';
            else if (correctionTotal > 0) governanceSummaryEl.textContent = correctionTotal + ' active correction loops in validator scope';
            else if (reopenTotal > 0 || stuckTotal > 0) governanceSummaryEl.textContent = 'Reconciliation activity recorded in validator workflow';
            else governanceSummaryEl.textContent = 'Quiet today. No elevated governance signals.';
        }
        var cards = [
            {
                h: 'Reopen actions',
                d: String(reopenTotal) + ' validator reopen events recorded in workflow audit.'
            },
            {
                h: 'Invalidated downstream',
                d: String(invalidatedTotal) + ' verifier-stage items currently invalidated after upstream reopen.'
            },
            {
                h: 'Correction pressure',
                d: String(correctionTotal) + ' live correction loops, with ' + String(parseInt(kpi.stale_corrections || '0', 10) || 0) + ' stale beyond SLA.'
            },
            {
                h: 'Workflow health',
                d: String(stuckTotal) + ' reopened validator components remain active; ' + String(parseInt(kpi.repeated_corrections || '0', 10) || 0) + ' components have repeated correction cycles.'
            }
        ];
        governanceHost.innerHTML = cards.map(function (card) {
            return '<div class="vr-mini-card"><div class="h">' + esc(card.h) + '</div><div class="d">' + esc(card.d) + '</div></div>';
        }).join('');
    }

    function buildOpenUrl(row) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var appId = row && row.application_id ? String(row.application_id) : '';
        var caseId = row && row.case_id ? String(row.case_id) : '';
        var clientId = row && row.client_id ? String(row.client_id) : '';
        function addParam(u, k, v) {
            if (!v) return u;
            return u + (u.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(k) + '=' + encodeURIComponent(String(v));
        }
        var url = base + '/modules/validator/candidate_view.php';
        if (appId) url = addParam(url, 'application_id', appId);
        else if (caseId) url = addParam(url, 'case_id', caseId);
        url = addParam(url, 'client_id', clientId);
        return url;
    }

    function loadStats() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/validator/queue_stats.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data) return;
                var d = data.data;
                if (kpiPending) kpiPending.textContent = String(d.pending || 0);
                if (kpiInProgress) kpiInProgress.textContent = String(d.in_progress || 0);
                if (kpiCompletedToday) kpiCompletedToday.textContent = String(d.completed_today || 0);
                renderGovernanceSignals(d);
            })
            .catch(function () {});
    }

    function loadMyTasks() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/validator/queue_my_tasks.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!tasksBody) return;
                tasksBody.innerHTML = '';
                if (!data || data.status !== 1 || !Array.isArray(data.data) || !data.data.length) {
                    tasksBody.innerHTML = '<tr><td colspan="4" style="color:#64748b;">Queue currently clear. Candidate List remains available.</td></tr>';
                    return;
                }
                tasksBody.innerHTML = data.data.map(function (r) {
                    var name = ((r.candidate_first_name || '') + ' ' + (r.candidate_last_name || '')).trim();
                    var app = r.application_id || '';
                    var st = fmtStatus(r);
                    var open = buildOpenUrl(r);
                    return '<tr>' +
                        '<td>' + esc(app) + '</td>' +
                        '<td>' + esc(name || '-') + '</td>' +
                        '<td>' + esc(st) + '</td>' +
                        '<td><a href="' + esc(open) + '" style="text-decoration:none; color:#2563eb; font-weight:700;">Continue</a></td>' +
                        '</tr>';
                }).join('');
            })
            .catch(function () {
                if (tasksBody) tasksBody.innerHTML = '<tr><td colspan="4" style="color:#ef4444;">Failed to load tasks.</td></tr>';
            });
    }

    function startNext() {
        setMessage('', '');
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        if (startBtn) startBtn.disabled = true;
        fetch(base + '/api/validator/queue_next.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({})
        })
            .then(function (res) { return res.json().catch(function () { return { status: 0, message: 'Invalid server response.' }; }); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    setMessage((data && data.message) ? data.message : 'Failed to fetch next case.', 'danger');
                    return;
                }
                var url = data && data.data ? data.data.url : null;
                if (!url) {
                    setMessage(data.message || 'No pending cases.', 'info');
                    loadStats();
                    loadMyTasks();
                    if (startBtn) startBtn.disabled = false;
                    return;
                }
                window.location.href = url;
            })
            .catch(function () { setMessage('Network error. Please try again.', 'danger'); })
            .finally(function () {
                if (startBtn) startBtn.disabled = false;
            });
    }

    function refreshDashboardData() {
        if (refreshInFlight) return Promise.resolve();
        refreshInFlight = true;
        return Promise.all([loadStats(), loadMyTasks()]).finally(function () {
            refreshInFlight = false;
        });
    }

    if (startBtn) startBtn.addEventListener('click', startNext);
    if (refreshBtn) refreshBtn.addEventListener('click', function () { refreshDashboardData(); });

    refreshDashboardData();
    setInterval(function () {
        if (document.visibilityState === 'hidden') return;
        refreshDashboardData();
    }, 15000);
});
