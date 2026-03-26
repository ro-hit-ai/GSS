document.addEventListener('DOMContentLoaded', function () {
    var refreshDashboardBtn = document.getElementById('refreshDashboard');
    var refreshing = false;
    var DASH_POLL_MS = 15000;

    var ALLOWED_GROUPS = null;
    var ALLOWED_GROUP_SECTIONS = {};
    var DEBUG = false;
    try {
        var u = new URL(window.location.href);
        DEBUG = (u.searchParams.get('debug') === '1');
    } catch (e) {
        DEBUG = false;
    }

    function initDashboardContent() {
        var kpiPending = document.getElementById('vrKpiPending');
        var kpiInProgress = document.getElementById('vrKpiInProgress');
        var kpiCompletedToday = document.getElementById('vrKpiCompletedToday');
        var tasksBody = document.getElementById('vrMyTasksBody');
        var tasksUpdated = document.getElementById('vrMyTasksUpdated');
        var startActionsHost = document.getElementById('vrStartActions');
        var messageEl = document.getElementById('vrDashMessage');
        var assignedHost = document.getElementById('vrAssignedModules');
        var statsByGroup = {};
        var ALLOWED_SECTIONS_LIST = [];

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

        function badge(text, cls) {
        return '<span class="vr-badge ' + cls + '">' + esc(text) + '</span>';
        }

        function sectionLabel(key) {
        var k = String(key || '').toLowerCase().trim();
        if (k === 'basic') return 'Basic';
        if (k === 'id') return 'Identification';
        if (k === 'contact') return 'Contact';
        if (k === 'education') return 'Education';
        if (k === 'employment') return 'Employment';
        if (k === 'reference') return 'Reference';
        if (k === 'reports') return 'Reports';
        if (k === 'timeline') return 'Timeline';
        return k ? (k.charAt(0).toUpperCase() + k.slice(1)) : '';
        }

        function parseAllowedSections(raw) {
        var s = String(raw || '').toLowerCase().trim();
        if (!s) return [];
        if (s === '*') return ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'reports', 'timeline'];
        var out = {};
        s.split(/[\s,|]+/).forEach(function (p) {
            var k = String(p || '').trim();
            if (!k) return;
            out[k] = true;
        });
        return Object.keys(out);
        }

        function sectionToGroup(sectionKey) {
        var k = String(sectionKey || '').toLowerCase().trim();
        if (k === 'basic' || k === 'id' || k === 'contact') return 'BASIC';
        if (k === 'education' || k === 'employment' || k === 'reference') return 'EDUCATION';
        return '';
        }

        function deriveGroupsFromSections(sections) {
        var out = {};
        (Array.isArray(sections) ? sections : []).forEach(function (sec) {
            var g = sectionToGroup(sec);
            if (g) out[g] = true;
        });
        return Object.keys(out);
        }

        function deriveGroupSections(sections) {
        var map = { BASIC: [], EDUCATION: [] };
        (Array.isArray(sections) ? sections : []).forEach(function (sec) {
            var g = sectionToGroup(sec);
            if (!g || !map[g]) return;
            if (map[g].indexOf(sec) === -1) map[g].push(sec);
        });
        return map;
        }

        function groupDisplayFromSections(groupKey) {
        var g = String(groupKey || '').toUpperCase();
        var list = (ALLOWED_GROUP_SECTIONS && Array.isArray(ALLOWED_GROUP_SECTIONS[g])) ? ALLOWED_GROUP_SECTIONS[g] : [];
        if (!list.length) return fmtGroup(g);
        var labels = list.map(sectionLabel);
        if (labels.length <= 2) return labels.join(' / ');
        return labels.slice(0, 2).join(' / ') + ' +' + String(labels.length - 2);
        }

        function renderAssigned(groups, sections) {
        if (!assignedHost) return;
        groups = Array.isArray(groups) ? groups : [];
        sections = Array.isArray(sections) ? sections : [];
        if (!sections.length && !groups.length) {
            assignedHost.innerHTML = '<div class="alert alert-warning" style="margin:0;">No modules assigned. Please contact Admin.</div>';
            return;
        }
        var pills = (sections.length ? sections : groups).map(function (g) {
            var label = sections.length ? sectionLabel(g) : fmtGroup(g);
            return '<span class="badge" style="background:#fff; border:1px solid rgba(148,163,184,0.30); color:#0f172a; padding:6px 10px; border-radius:999px; font-weight:800;">' + esc(label) + '</span>';
        }).join(' ');
        assignedHost.innerHTML = '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">' +
            '<div style="font-size:12px; color:#64748b; font-weight:800;">Assigned Components</div>' +
            '<div style="display:flex; gap:8px; flex-wrap:wrap;">' + pills + '</div>' +
            '</div>';
        }

        function groupLabel(g) {
        g = String(g || '').toUpperCase();
        return g === 'BASIC' ? 'BASIC' : (g === 'EDUCATION' ? 'EDUCATION' : g);
        }

        function renderStartActions() {
        if (!startActionsHost) return;
        var groups = Array.isArray(ALLOWED_GROUPS) ? ALLOWED_GROUPS.slice() : [];
        if (!groups.length) {
            startActionsHost.innerHTML = '';
            return;
        }
        startActionsHost.innerHTML = groups.map(function (g, idx) {
            var key = String(g || '').toUpperCase();
            var s = statsByGroup[key] || {};
            var pending = (parseInt(s.pending || '0', 10) || 0) + (parseInt(s.followup || '0', 10) || 0);
            var txt = 'Start ' + groupDisplayFromSections(key) + ' Verify' + (pending > 0 ? (' (' + pending + ')') : '');
            var cls = idx === 0 ? 'vr-btn vr-btn-primary' : 'vr-btn vr-btn-soft';
            return '<button type="button" class="' + cls + '" data-start-group="' + esc(key) + '">' + esc(txt) + '</button>';
        }).join('');
        }

        function loadAllowedConfig() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/verifier/allowed_config.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data) {
                    ALLOWED_GROUPS = [];
                    ALLOWED_SECTIONS_LIST = [];
                    renderAssigned([], []);
                    renderStartActions();
                    return;
                }
                ALLOWED_SECTIONS_LIST = parseAllowedSections(data.data.allowed_sections || '');
                ALLOWED_GROUPS = deriveGroupsFromSections(ALLOWED_SECTIONS_LIST);
                ALLOWED_GROUP_SECTIONS = deriveGroupSections(ALLOWED_SECTIONS_LIST);
                renderAssigned(ALLOWED_GROUPS, ALLOWED_SECTIONS_LIST);
                renderStartActions();
            })
            .catch(function () {
                ALLOWED_GROUPS = [];
                ALLOWED_SECTIONS_LIST = [];
                ALLOWED_GROUP_SECTIONS = {};
                renderAssigned([], []);
                renderStartActions();
            });
        }

        function fmtGroup(g) {
        g = String(g || '').toUpperCase();
        if (g === 'BASIC') return 'Basic';
        if (g === 'EDUCATION') return 'Education';
        return g || '-';
        }

        function fmtStatus(row) {
        if (!row) return badge('Pending', 'vr-badge-p');
        if (row.completed_at) return badge('Completed', 'vr-badge-d');
        if (row.assigned_user_id) return badge('In Progress', 'vr-badge-i');
        return badge('Pending', 'vr-badge-p');
        }

        function buildOpenUrl(row) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var appId = row && row.application_id ? String(row.application_id) : '';
        var clientId = row && row.client_id ? String(row.client_id) : '';
        var group = row && row.group_key ? String(row.group_key) : '';
        var caseId = row && row.case_id ? String(row.case_id) : '';
        function addParam(u, k, v) {
            if (!v) return u;
            return u + (u.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(k) + '=' + encodeURIComponent(String(v));
        }

        var url = base + '/modules/verifier/candidate_view.php';
        if (appId) {
            url = addParam(url, 'application_id', appId);
        } else if (caseId) {
            url = addParam(url, 'case_id', caseId);
        } else {
            url = base + '/modules/verifier/dashboard.php';
        }
        url = addParam(url, 'client_id', clientId);
        url = addParam(url, 'group', group);
        return url;
        }

        function loadStats() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/verifier/queue_stats.php?scope=mine&_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) return;

                var totals = { pending: 0, in_progress: 0, followup: 0, completed_today: 0, completed_total: 0 };
                data.data.forEach(function (r) {
                    totals.pending += parseInt(r.pending || '0', 10) || 0;
                    totals.in_progress += parseInt(r.in_progress || '0', 10) || 0;
                    totals.followup += parseInt(r.followup || '0', 10) || 0;
                    totals.completed_today += parseInt(r.completed_today || '0', 10) || 0;
                    totals.completed_total += parseInt(r.completed_total || '0', 10) || 0;
                    var gk = String(r.group_key || '').toUpperCase();
                    if (gk) {
                        statsByGroup[gk] = {
                            pending: parseInt(r.pending || '0', 10) || 0,
                            followup: parseInt(r.followup || '0', 10) || 0,
                            in_progress: parseInt(r.in_progress || '0', 10) || 0,
                            completed_today: parseInt(r.completed_today || '0', 10) || 0
                        };
                    }
                });

                if (kpiPending) kpiPending.textContent = String(totals.pending + totals.followup);
                if (kpiInProgress) kpiInProgress.textContent = String(totals.in_progress);
                if (kpiCompletedToday) kpiCompletedToday.textContent = String(totals.completed_today);
                renderStartActions();
            })
            .catch(function () {
            });
        }

        function loadMyTasks() {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/verifier/queue_my_tasks.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!tasksBody) return;
                tasksBody.innerHTML = '';

                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    tasksBody.innerHTML = '<tr><td colspan="5" style="color:#64748b;">No tasks.</td></tr>';
                    return;
                }

                var rows = data.data;
                if (!rows.length) {
                    tasksBody.innerHTML = '<tr><td colspan="5" style="color:#64748b;">No tasks assigned to you.</td></tr>';
                    return;
                }

                tasksBody.innerHTML = rows.map(function (r) {
                    var name = ((r.candidate_first_name || '') + ' ' + (r.candidate_last_name || '')).trim();
                    var comp = groupDisplayFromSections(r.group_key);
                    var st = fmtStatus(r);
                    var open = buildOpenUrl(r);
                    var action = '<a href="' + esc(open) + '" style="text-decoration:none; color:#2563eb; font-weight:700;">' + (r.assigned_user_id ? 'Continue' : 'Open') + '</a>';
                    return '<tr>' +
                        '<td>' + esc(name || '-') + '</td>' +
                        '<td>' + esc(comp) + '</td>' +
                        '<td>' + badge('Normal', 'vr-badge-i') + '</td>' +
                        '<td>' + st + '</td>' +
                        '<td>' + action + '</td>' +
                        '</tr>';
                }).join('');

                if (tasksUpdated) {
                    tasksUpdated.textContent = 'Updated: just now';
                }
            })
            .catch(function () {
                if (tasksBody) {
                    tasksBody.innerHTML = '<tr><td colspan="5" style="color:#ef4444;">Failed to load tasks.</td></tr>';
                }
            });
        }

        function reloadDashboardData() {
        return Promise.all([loadAllowedConfig(), loadStats(), loadMyTasks()]).catch(function () {
        });
        }

        function bindAutoRefresh() {
        try {
            if (window.__vrDashAutoTimer) {
            clearInterval(window.__vrDashAutoTimer);
            window.__vrDashAutoTimer = null;
            }
            window.__vrDashAutoTimer = setInterval(function () {
            if (document.visibilityState === 'hidden') return;
            reloadDashboardData();
            }, DASH_POLL_MS);
        } catch (e) {
        }
        }

        if (!document.body.dataset.vrDashVisBound) {
        document.body.dataset.vrDashVisBound = '1';
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
            reloadDashboardData();
            }
        });
        }

        function startNext(group) {
        setMessage('', '');
        if (Array.isArray(ALLOWED_GROUPS) && ALLOWED_GROUPS.length && ALLOWED_GROUPS.indexOf(String(group || '').toUpperCase()) === -1) {
            setMessage('Access denied: module not assigned.', 'danger');
            return;
        }
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

        var payload = { group: group };
        if (DEBUG) payload.debug = 1;

        fetch(base + '/api/verifier/queue_next.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { status: 0, message: 'Invalid server response.' };
                });
            })
            .then(function (data) {
                if (DEBUG && window.console && console.log) {
                    console.log('queue_next response:', data);
                }
                if (!data || data.status !== 1) {
                    setMessage((data && data.message) ? data.message : 'Failed to fetch next case.', 'danger');
                    return;
                }
                var url = data && data.data ? data.data.url : null;
                if (!url) {
                    setMessage(data.message || 'No pending cases for this group.', 'info');
                    loadStats();
                    loadMyTasks();
                    return;
                }
                window.location.href = url;
            })
            .catch(function () {
                setMessage('Network error. Please try again.', 'danger');
            });
        }

        if (startActionsHost && !startActionsHost.dataset.bound) {
            startActionsHost.dataset.bound = '1';
            startActionsHost.addEventListener('click', function (e) {
            var t = e && e.target ? e.target : null;
            var btn = t && t.closest ? t.closest('[data-start-group]') : null;
            if (!btn) return;
            var g = String(btn.getAttribute('data-start-group') || '').toUpperCase();
            if (!g) return;
            startNext(g);
            });
        }

        reloadDashboardData();
        bindAutoRefresh();
    }

    function refreshDashboardContent() {
        var dashboardContent = document.getElementById('dashboardContent');
        if (!dashboardContent || refreshing) return;

        refreshing = true;
        if (refreshDashboardBtn) {
            refreshDashboardBtn.disabled = true;
            refreshDashboardBtn.textContent = 'Refreshing...';
        }

        var url = new URL(window.location.href);
        url.searchParams.set('refresh', '1');
        url.searchParams.set('_ts', String(Date.now()));

        fetch(url.toString(), { credentials: 'same-origin' })
            .then(function (res) { return res.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var freshContent = doc.getElementById('dashboardContent');
                if (!freshContent) throw new Error('dashboardContent not found in refresh response.');
                dashboardContent.innerHTML = freshContent.innerHTML;
                initDashboardContent();
            })
            .catch(function () {
                var msg = document.getElementById('vrDashMessage');
                if (msg) {
                    msg.textContent = 'Failed to refresh dashboard. Please try again.';
                    msg.className = 'alert alert-danger';
                    msg.style.display = 'block';
                }
            })
            .finally(function () {
                refreshing = false;
                if (refreshDashboardBtn) {
                    refreshDashboardBtn.disabled = false;
                    refreshDashboardBtn.textContent = 'Refresh';
                }
            });
    }

    if (refreshDashboardBtn) {
        refreshDashboardBtn.addEventListener('click', refreshDashboardContent);
    }

    initDashboardContent();
});
