(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var searchEl = document.getElementById('overallReportSearch');
        var refreshBtn = document.getElementById('overallReportRefreshBtn');
        var bodyEl = document.getElementById('overallReportBody');
        var msgEl = document.getElementById('overallReportMessage');

        function setMessage(text, type) {
            if (!msgEl) return;
            msgEl.textContent = text || '';
            msgEl.className = text ? ('alert alert-' + (type || 'info')) : '';
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

        function fmtDate(v) {
            if (window.GSS_DATE && typeof window.GSS_DATE.formatDbDateTime === 'function') {
                return window.GSS_DATE.formatDbDateTime(v);
            }
            return String(v || '-');
        }

        function slaBadge(createdAt, tatDays) {
            var days = parseInt(tatDays || '20', 10);
            if (!isFinite(days) || days <= 0) days = 20;
            var dt = createdAt ? new Date(String(createdAt).replace(' ', 'T')) : null;
            if (!(dt instanceof Date) || isNaN(dt.getTime())) {
                return '<span class="badge bg-secondary">Unknown</span>';
            }
            var now = new Date();
            var elapsed = Math.max(0, Math.floor((now - dt) / 86400000));
            var remain = days - elapsed;
            if (remain < 0) return '<span class="badge bg-danger">Breached (' + esc(String(Math.abs(remain))) + 'd)</span>';
            if (remain <= 3) return '<span class="badge bg-warning text-dark">At Risk (' + esc(String(remain)) + 'd)</span>';
            return '<span class="badge bg-success">On Track (' + esc(String(remain)) + 'd)</span>';
        }

        function rowHtml(row) {
            var appId = String((row && row.application_id) || '');
            var name = ((row && row.candidate_first_name) ? row.candidate_first_name : '') + ' ' + ((row && row.candidate_last_name) ? row.candidate_last_name : '');
            var href = 'candidate_view.php?application_id=' + encodeURIComponent(appId);
            return '<tr>' +
                '<td>' + esc(appId) + '</td>' +
                '<td><a href="' + esc(href) + '" style="text-decoration:none;color:#2563eb;">' + esc(name.trim()) + '</a></td>' +
                '<td>' + esc((row && row.candidate_email) || '-') + '</td>' +
                '<td>' + esc((row && row.candidate_mobile) || '-') + '</td>' +
                '<td>' + esc((row && row.current_stage) || '-') + '</td>' +
                '<td>' + esc((row && row.case_status) || '-') + '</td>' +
                '<td>' + slaBadge(row && row.created_at, 20) + '</td>' +
                '<td>' + esc(fmtDate(row && row.invite_sent_at)) + '</td>' +
                '<td>' + esc(fmtDate(row && row.created_at)) + '</td>' +
                '<td><a href="' + esc(href) + '" class="btn btn-sm" style="padding:4px 8px;">Open</a></td>' +
            '</tr>';
        }

        function render(rows) {
            rows = Array.isArray(rows) ? rows : [];
            if (!bodyEl) return;
            if (!rows.length) {
                bodyEl.innerHTML = '<tr><td colspan="10" style="color:#64748b;">No cases found.</td></tr>';
                return;
            }
            bodyEl.innerHTML = rows.map(rowHtml).join('');
        }

        function load() {
            setMessage('', '');
            if (bodyEl) bodyEl.innerHTML = '<tr><td colspan="10" style="color:#64748b;">Loading...</td></tr>';
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var q = searchEl ? String(searchEl.value || '').trim() : '';
            var url = base + '/api/client_admin/cases_list.php?search=' + encodeURIComponent(q);
            fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 1) {
                        render([]);
                        setMessage((data && data.message) ? data.message : 'Failed to load report.', 'danger');
                        return;
                    }
                    render(data.data || []);
                })
                .catch(function () {
                    render([]);
                    setMessage('Network error. Please try again.', 'danger');
                });
        }

        var timer = null;
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(load, 250);
            });
        }
        if (refreshBtn) refreshBtn.addEventListener('click', load);
        load();
    });
})();
