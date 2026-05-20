document.addEventListener('DOMContentLoaded', function () {
    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
    var kpiPending = document.getElementById('vrKpiPending');
    var kpiInProgress = document.getElementById('vrKpiInProgress');
    var kpiCompletedToday = document.getElementById('vrKpiCompletedToday');
    var tasksBody = document.getElementById('vrMyTasksBody');
    var messageEl = document.getElementById('vrDashMessage');
    var governanceHost = document.getElementById('vrGovernanceSignals');
    var governanceSummaryEl = document.getElementById('vrGovernanceSummary');
    var assignedHost = document.getElementById('vrAssignedModules');
    var startBasicBtn = document.getElementById('vrDashStartBasicBtn');
    var startEducationBtn = document.getElementById('vrDashStartEducationBtn');
    var startAdditionalBtn = document.getElementById('vrDashStartAdditionalBtn');
    var refreshBtn = document.getElementById('vrDashRefreshBtn');

    var refreshInFlight = false;
    var startInFlight = false;
    var statsByGroup = {};
    var allowedGroups = [];
    var allowedSections = [];
    var groupSections = {};

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

    function addParam(url, key, value) {
        if (value === null || value === undefined || value === '') return url;
        return url + (url.indexOf('?') === -1 ? '?' : '&') + encodeURIComponent(key) + '=' + encodeURIComponent(String(value));
    }

    function parseAllowedSections(raw) {
        var value = String(raw || '').toLowerCase().trim();
        if (!value) return [];
        if (value === '*') return ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'ecourt', 'socialmedia', 'reports', 'timeline'];
        var out = {};
        value.split(/[\s,|]+/).forEach(function (part) {
            var key = String(part || '').toLowerCase().trim();
            if (!key) return;
            if (key === 'identification') key = 'id';
            if (key === 'social' || key === 'social_media' || key === 'social-media') key = 'socialmedia';
            out[key] = true;
        });
        return Object.keys(out);
    }

    function sectionLabel(section) {
        var key = String(section || '').toLowerCase().trim();
        var labels = {
            basic: 'Basic',
            id: 'Identification',
            contact: 'Contact',
            education: 'Education',
            employment: 'Employment',
            reference: 'Reference',
            ecourt: 'E-Court',
            socialmedia: 'Social Media',
            reports: 'Reports'
        };
        return labels[key] || (key ? key.charAt(0).toUpperCase() + key.slice(1) : '');
    }

    function sectionToGroup(section) {
        var key = String(section || '').toLowerCase().trim();
        if (key === 'basic' || key === 'id' || key === 'contact') return 'BASIC';
        if (key === 'education' || key === 'employment' || key === 'reference') return 'EDUCATION';
        if (key === 'ecourt' || key === 'socialmedia') return 'ADDITIONAL';
        return '';
    }

    function deriveGroupsFromSections(sections) {
        var out = {};
        (Array.isArray(sections) ? sections : []).forEach(function (section) {
            var group = sectionToGroup(section);
            if (group) out[group] = true;
        });
        return Object.keys(out);
    }

    function deriveGroupSections(sections) {
        var map = { BASIC: [], EDUCATION: [], ADDITIONAL: [] };
        (Array.isArray(sections) ? sections : []).forEach(function (section) {
            var group = sectionToGroup(section);
            if (!group || !map[group]) return;
            if (map[group].indexOf(section) === -1) map[group].push(section);
        });
        return map;
    }

    function groupDisplay(groupKey) {
        var group = String(groupKey || '').toUpperCase();
        var sections = groupSections[group] || [];
        if (!sections.length) {
            if (group === 'BASIC') return 'Basic / Identification';
            if (group === 'EDUCATION') return 'Education / Reference';
            if (group === 'ADDITIONAL') return 'E-Court / Social Media';
            return group || '-';
        }
        var labels = sections.map(sectionLabel);
        return labels.length <= 2 ? labels.join(' / ') : (labels.slice(0, 2).join(' / ') + ' +' + String(labels.length - 2));
    }

    function renderAssignedModules() {
        if (!assignedHost) return;
        if (!allowedSections.length) {
            assignedHost.innerHTML = '<div class="alert alert-warning" style="margin:0;">No modules assigned. Please contact Admin.</div>';
            return;
        }
        var pills = allowedSections.map(function (section) {
            return '<span class="badge" style="background:#fff; border:1px solid rgba(148,163,184,0.30); color:#0f172a; padding:6px 10px; border-radius:999px; font-weight:800;">' + esc(sectionLabel(section)) + '</span>';
        }).join(' ');
        assignedHost.innerHTML = '<div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">'
            + '<div style="font-size:12px; color:#64748b; font-weight:800;">Assigned Groups</div>'
            + '<div style="display:flex; gap:8px; flex-wrap:wrap;">' + pills + '</div>'
            + '</div>';
    }

    function renderStartButtons() {
        function applyButton(button, groupKey, defaultLabel) {
            if (!button) return;
            var allowed = allowedGroups.indexOf(groupKey) !== -1;
            var counts = statsByGroup[groupKey] || {};
            var pending = (parseInt(counts.pending || '0', 10) || 0) + (parseInt(counts.followup || '0', 10) || 0);
            button.style.display = allowed ? 'inline-flex' : 'none';
            button.disabled = !allowed || startInFlight;
            button.setAttribute('data-group', groupKey);
            button.textContent = defaultLabel + (pending > 0 ? (' (' + pending + ')') : '');
        }

        applyButton(startBasicBtn, 'BASIC', 'Start ' + groupDisplay('BASIC'));
        applyButton(startEducationBtn, 'EDUCATION', 'Start ' + groupDisplay('EDUCATION'));
        applyButton(startAdditionalBtn, 'ADDITIONAL', 'Start ' + groupDisplay('ADDITIONAL'));
    }

    function renderGovernanceSignals(kpi) {
        if (!governanceHost) return;
        kpi = kpi || {};
        var reopenTotal = parseInt(kpi.reopen_actions_total || '0', 10) || 0;
        var supervisoryTotal = parseInt(kpi.supervisory_reopens_total || '0', 10) || 0;
        var invalidatedTotal = parseInt(kpi.active_invalidated_downstream || '0', 10) || 0;
        var correctionTotal = parseInt(kpi.correction_requested || '0', 10) || 0;
        if (governanceSummaryEl) {
            if (invalidatedTotal > 0) governanceSummaryEl.textContent = invalidatedTotal + ' downstream items need clean re-validation';
            else if (correctionTotal > 0) governanceSummaryEl.textContent = correctionTotal + ' active correction loops in verifier scope';
            else if (reopenTotal > 0 || supervisoryTotal > 0) governanceSummaryEl.textContent = 'Reconciliation activity recorded in verifier workflow';
            else governanceSummaryEl.textContent = 'Quiet today. No elevated governance signals.';
        }
        var cards = [
            {
                h: 'Reopen actions',
                d: String(reopenTotal) + ' governed reopen events recorded in verifier scope.'
            },
            {
                h: 'Reconciliation chain',
                d: String(supervisoryTotal) + ' downstream review chains were reopened for verifier-side re-review.'
            },
            {
                h: 'Invalidated downstream',
                d: String(invalidatedTotal) + ' downstream QA items currently need clean re-validation.'
            },
            {
                h: 'Correction pressure',
                d: String(correctionTotal) + ' live correction loops, with ' + String(parseInt(kpi.stale_corrections || '0', 10) || 0) + ' stale beyond SLA.'
            }
        ];
        governanceHost.innerHTML = cards.map(function (card) {
            return '<div class="vr-mini-card"><div class="h">' + esc(card.h) + '</div><div class="d">' + esc(card.d) + '</div></div>';
        }).join('');
    }

    function fmtStatus(row) {
        if (!row) return 'VE PENDING';
        if (row.stage_status_label) return String(row.stage_status_label);
        if (window.WF_UI && typeof window.WF_UI.labelByRole === 'function') {
            var statusCode = String(row.operational_status || row.status || '').toLowerCase().trim();
            if (row.operational_status_label) return String(row.operational_status_label);
            return window.WF_UI.labelByRole(statusCode, 'verifier');
        }
        var status = String(row.status || '').toLowerCase().trim();
        if (row.completed_at || status === 'done' || status === 'completed') return 'Review Complete';
        if (status === 'waiting_candidate') return 'Candidate Pending';
        if (status === 'blocked' || status === 'hold' || status === 'insufficient_documents') return 'On Hold';
        if (status === 'in_progress') return 'Under Review';
        return 'Awaiting Review';
    }

    function buildOpenUrl(row) {
        var url = base + '/modules/verifier/candidate_view.php';
        url = addParam(url, 'application_id', row && row.application_id ? row.application_id : '');
        url = addParam(url, 'case_id', row && row.case_id ? row.case_id : '');
        url = addParam(url, 'client_id', row && row.client_id ? row.client_id : '');
        url = addParam(url, 'group', row && row.group_key ? String(row.group_key).toUpperCase() : '');
        url = addParam(url, 'view', 'mine');
        url = addParam(url, 'filter', 'active_work');
        return url;
    }

    function loadAllowedConfig() {
        return fetch(base + '/api/verifier/allowed_config.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                var allowedRaw = data && data.status === 1 && data.data ? data.data.allowed_sections : '';
                allowedSections = parseAllowedSections(allowedRaw);
                allowedGroups = deriveGroupsFromSections(allowedSections);
                groupSections = deriveGroupSections(allowedSections);
                renderAssignedModules();
                renderStartButtons();
            })
            .catch(function () {
                allowedSections = [];
                allowedGroups = [];
                groupSections = {};
                renderAssignedModules();
                renderStartButtons();
            });
    }

    function loadStats() {
        return fetch(base + '/api/verifier/queue_stats.php?scope=mine&_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) return;
                statsByGroup = {};
                var totals = { pending: 0, in_progress: 0, followup: 0 };
                data.data.forEach(function (row) {
                    var groupKey = String(row.group_key || '').toUpperCase();
                    if (!groupKey) return;
                    statsByGroup[groupKey] = row;
                    totals.pending += parseInt(row.pending || '0', 10) || 0;
                    totals.followup += parseInt(row.followup || '0', 10) || 0;
                    totals.in_progress += parseInt(row.in_progress || '0', 10) || 0;
                });
                if (kpiPending) kpiPending.textContent = String(totals.pending + totals.followup);
                if (kpiInProgress) kpiInProgress.textContent = String(totals.in_progress);
                if (kpiCompletedToday) {
                    kpiCompletedToday.textContent = String(parseInt((data.kpi && data.kpi.participated_reviewed_today) || '0', 10) || 0);
                }
                renderGovernanceSignals(data.kpi || {});
                renderStartButtons();
            })
            .catch(function () {});
    }

    function loadMyTasks() {
        return fetch(base + '/api/verifier/queue_my_tasks.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!tasksBody) return;
        if (!data || data.status !== 1 || !Array.isArray(data.data) || !data.data.length) {
                    tasksBody.innerHTML = '<tr><td colspan="5" style="color:#64748b;">No active verifier workload. Use Reviewed / Participated for historical outcomes.</td></tr>';
                    return;
                }
                tasksBody.innerHTML = data.data.map(function (row) {
                    var app = row.application_id || '-';
                    var name = ((row.candidate_first_name || '') + ' ' + (row.candidate_last_name || '')).trim();
                    var component = groupDisplay(row.group_key);
                    var status = fmtStatus(row);
                    var openUrl = buildOpenUrl(row);
                    return '<tr>'
                        + '<td>' + esc(app) + '</td>'
                        + '<td>' + esc(name || '-') + '</td>'
                        + '<td>' + esc(component) + '</td>'
                        + '<td>' + esc(status) + '</td>'
                        + '<td><a href="' + esc(openUrl) + '" style="text-decoration:none; color:#2563eb; font-weight:700;">' + (row.assigned_user_id ? 'Continue' : 'Open') + '</a></td>'
                        + '</tr>';
                }).join('');
            })
            .catch(function () {
                if (tasksBody) {
                    tasksBody.innerHTML = '<tr><td colspan="5" style="color:#ef4444;">Failed to load tasks.</td></tr>';
                }
            });
    }

    function refreshDashboardData() {
        if (refreshInFlight) return Promise.resolve();
        refreshInFlight = true;
        return loadAllowedConfig()
            .then(function () {
                return Promise.all([loadStats(), loadMyTasks()]);
            })
            .finally(function () {
                refreshInFlight = false;
            });
    }

    function startNext(groupKey) {
        if (startInFlight) return;
        groupKey = String(groupKey || '').toUpperCase();
        if (!groupKey) return;
        if (allowedGroups.length && allowedGroups.indexOf(groupKey) === -1) {
            setMessage('Access denied: module not assigned.', 'danger');
            return;
        }

        startInFlight = true;
        renderStartButtons();
        setMessage('', '');

        fetch(base + '/api/verifier/queue_next.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ group: groupKey })
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { status: 0, message: 'Invalid server response.' };
                });
            })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    setMessage((data && data.message) ? data.message : 'Failed to fetch next case.', 'danger');
                    return;
                }
                var url = data && data.data ? data.data.url : '';
                if (!url) {
                    setMessage(data.message || 'No pending cases for this group.', 'info');
                    return refreshDashboardData();
                }
                window.location.assign(url);
            })
            .catch(function () {
                setMessage('Network error. Please try again.', 'danger');
            })
            .finally(function () {
                startInFlight = false;
                renderStartButtons();
            });
    }

    if (startBasicBtn) {
        startBasicBtn.addEventListener('click', function () {
            startNext('BASIC');
        });
    }
    if (startEducationBtn) {
        startEducationBtn.addEventListener('click', function () {
            startNext('EDUCATION');
        });
    }
    if (startAdditionalBtn) {
        startAdditionalBtn.addEventListener('click', function () {
            startNext('ADDITIONAL');
        });
    }
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshDashboardData();
        });
    }

    refreshDashboardData();
    setInterval(function () {
        if (document.visibilityState === 'hidden') return;
        refreshDashboardData();
    }, 15000);
});
