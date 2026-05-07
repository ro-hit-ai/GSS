document.addEventListener('DOMContentLoaded', function () {
    var kpiPending = document.getElementById('valKpiPending');
    var kpiInProgress = document.getElementById('valKpiInProgress');
    var kpiCompletedToday = document.getElementById('valKpiCompletedToday');
    var tasksBody = document.getElementById('valMyTasksBody');
    var startBtn = document.getElementById('valDashStartNextBtn');
    var refreshBtn = document.getElementById('valDashRefreshBtn');
    var messageEl = document.getElementById('valDashMessage');

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
        var s = String(row.status || '').toLowerCase().trim();
        if (row.completed_at || s === 'done' || s === 'completed') return 'completed';
        if (s === 'waiting_candidate') return 'waiting_candidate';
        if (s === 'blocked' || s === 'hold' || s === 'insufficient_documents') return 'blocked';
        if (s === 'in_progress') return 'in_progress';
        return 'pending';
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
                    tasksBody.innerHTML = '<tr><td colspan="4" style="color:#64748b;">No tasks assigned to you.</td></tr>';
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
                        '<td><a href="' + esc(open) + '" style="text-decoration:none; color:#2563eb; font-weight:700;">Open</a></td>' +
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

    if (startBtn) startBtn.addEventListener('click', startNext);
    if (refreshBtn) refreshBtn.addEventListener('click', function () { loadStats(); loadMyTasks(); });

    loadStats();
    loadMyTasks();
    setInterval(function () { loadStats(); loadMyTasks(); }, 15000);
});
