document.addEventListener('DOMContentLoaded', function () {
    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
    var messageEl = document.getElementById('vrBoardMessage');
    var rowsHost = document.getElementById('vrBoardRows');
    var refreshBtn = document.getElementById('vrBoardRefreshBtn');
    var bucketHost = document.getElementById('vrBucketChips');
    var compatNoteEl = document.getElementById('vrBoardCompatNote');
    var kpiPendingEl = document.getElementById('vrKpiPending');
    var kpiInProgressEl = document.getElementById('vrKpiInProgress');
    var kpiFollowUpEl = document.getElementById('vrKpiFollowUp');
    var kpiCompletedEl = document.getElementById('vrKpiCompleted');

    var state = {
        rows: [],
        bucket: 'claimable',
        loading: false,
        claimInFlightKey: ''
    };

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

    function fmtDateTime(v) {
        var raw = String(v || '').trim();
        if (!raw) return '-';
        try {
            if (window.GSS_DATE && typeof window.GSS_DATE.formatDbDateTime === 'function') {
                return window.GSS_DATE.formatDbDateTime(raw);
            }
        } catch (_e) {}
        return raw;
    }

    function bucketLabel(bucket) {
        var key = String(bucket || '').toLowerCase().trim();
        if (key === 'claimable') return 'To Be Claimed';
        if (key === 'active') return 'Active';
        if (key === 'all') return 'All';
        if (key === 'pending') return 'Pending';
        if (key === 'completed') return 'Completed';
        if (key === 'followup') return 'Follow Up';
        if (key === 'insuff_docs') return 'Insuff. Docs';
        return key || '-';
    }

    function claimStateLabel(row) {
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        if (s === 'available') return 'Claimable';
        if (s === 'mine_active') return 'Owned Active';
        if (s === 'claimed_by_other') return 'Claimed By Other';
        if (s === 'locked_future') return 'Locked';
        if (s === 'hidden_unrelated') return 'Unavailable';
        if (s === 'followup') return 'Follow Up';
        if (s === 'completed') return 'Completed';
        return '-';
    }

    function rowClass(row) {
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        var out = ['vr-board-row', 'vr-board-data-row'];
        if (s === 'mine_active' || s === 'followup') out.push('is-mine');
        if (s === 'claimed_by_other' || s === 'locked_future' || s === 'hidden_unrelated') out.push('is-locked');
        if (s === 'completed') out.push('is-completed');
        if (row && row.can_open) out.push('is-clickable');
        return out.join(' ');
    }

    function stateBadgeHtml(row, bucket) {
        var view = String(bucket || state.bucket || '').toLowerCase().trim();
        if (view === 'claimable') return '<span class="vr-badge available">Claimable</span>';
        if (view === 'active') return '<span class="vr-badge mine_active">Owned Active</span>';
        if (view === 'completed') return '<span class="vr-badge completed_state">Completed</span>';
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        var cls = s;
        if (s === 'followup') cls = 'followup_state';
        if (s === 'completed') cls = 'completed_state';
        return '<span class="vr-badge ' + esc(cls) + '">' + esc(claimStateLabel(row)) + '</span>';
    }

    function bucketBadgeHtml(row, bucketOverride) {
        var bucket = String(bucketOverride || (row && row.board_bucket ? row.board_bucket : '')).toLowerCase().trim();
        return '<span class="vr-badge ' + esc(bucket) + '">' + esc(bucketLabel(bucket)) + '</span>';
    }

    function routingTierText(row) {
        var states = row && row.routing_component_states && typeof row.routing_component_states === 'object'
            ? row.routing_component_states
            : {};
        var parts = [];
        Object.keys(states).forEach(function (key) {
            var item = states[key] || {};
            var priority = item.priority ? ('P' + String(item.priority)) : 'P-';
            var label = String(item.label || key || '').trim();
            var stateText = String(item.state || '').replace(/_/g, ' ');
            var reason = String(item.reason || '').trim();
            if (!label) return;
            parts.push(priority + ' ' + label + ' — ' + stateText + (reason && stateText !== 'owned active' ? ' (' + reason + ')' : ''));
        });
        return parts.join(' · ');
    }

    function filteredRows() {
        return state.rows.filter(function (row) {
        var stateKeys = Array.isArray(row.state_keys) ? row.state_keys : [];
        if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return false;
        if (state.bucket === 'claimable') return stateKeys.indexOf('claimable_next') >= 0;
        if (state.bucket === 'active') return stateKeys.indexOf('owned_active') >= 0;
        if (state.bucket === 'completed') return stateKeys.indexOf('completed') >= 0;
        if (state.bucket === 'all') {
            return stateKeys.indexOf('owned_active') >= 0
                || stateKeys.indexOf('claimable_next') >= 0
                || stateKeys.indexOf('completed') >= 0;
        }
        return false;
        });
    }

    function componentStateLabel(stateKey) {
        var s = String(stateKey || '').toLowerCase().trim();
        if (s === 'context') return 'Context';
        if (s === 'owned_active') return 'Owned';
        if (s === 'claimable_next') return 'Claimable';
        if (s === 'locked_future') return 'Locked';
        if (s === 'completed') return 'Completed';
        return s ? s.replace(/_/g, ' ') : '-';
    }

    function componentLockReason(item) {
        var reason = String(item && item.reason ? item.reason : '').trim();
        var code = String(item && item.reason_code ? item.reason_code : '').toLowerCase().trim();
        if (reason) return reason;
        if (code === 'case_gate_incomplete') return 'Finish earlier priority first';
        if (code === 'higher_priority_bucket_pending') return 'Higher-priority workload pending';
        if (code === 'already_assigned') return 'Already assigned';
        if (code === 'no_capability') return 'No routing capability';
        if (code === 'completed') return 'Completed';
        return '';
    }

    function routingComponentEntries(row) {
        var states = row && row.routing_component_states && typeof row.routing_component_states === 'object'
            ? row.routing_component_states
            : {};
        return Object.keys(states).map(function (key) {
            var item = states[key] || {};
            return {
                key: key,
                label: String(item.label || key || '').trim(),
                priority: item.priority ? parseInt(String(item.priority), 10) || 0 : 0,
                state: String(item.state || '').toLowerCase().trim(),
                reason: componentLockReason(item),
                displayStatus: String(item.display_status || '').trim(),
                history: Array.isArray(item.history) ? item.history : []
            };
        }).filter(function (entry) {
            return entry.label && entry.state !== 'hidden_unrelated';
        }).sort(function (a, b) {
            if (a.state === 'context' && b.state !== 'context') return -1;
            if (b.state === 'context' && a.state !== 'context') return 1;
            if (a.priority !== b.priority) return a.priority - b.priority;
            return a.label.localeCompare(b.label);
        });
    }

    function priorityComponentGroups(row) {
        var groups = {};
        routingComponentEntries(row).forEach(function (entry) {
            if (!entry.priority || entry.state === 'context' || entry.state === 'hidden_unrelated') return;
            var key = String(entry.priority);
            if (!groups[key]) groups[key] = [];
            groups[key].push(entry);
        });
        return Object.keys(groups).sort(function (a, b) {
            return parseInt(a, 10) - parseInt(b, 10);
        }).map(function (priority) {
            return {
                priority: priority,
                entries: groups[priority].sort(function (a, b) {
                    return a.label.localeCompare(b.label);
                })
            };
        });
    }

    function componentEntriesStripHtml(entries) {
        entries = Array.isArray(entries) ? entries.filter(function (entry) {
            return entry && entry.label && entry.state !== 'hidden_unrelated';
        }) : [];
        if (!entries.length) return '<div class="vr-board-muted">No components in this work bucket</div>';

        function displayStatus(entry) {
            if (entry.displayStatus) return entry.displayStatus;
            if (entry.state === 'claimable_next') return 'Claimable';
            if (entry.state === 'owned_active') return 'Active';
            if (entry.state === 'locked_future') return 'Locked';
            return componentStateLabel(entry.state);
        }

        function componentPill(entry) {
            var historyTitle = entry.history.length
                ? entry.history.map(function (it) {
                    return [fmtDateTime(it.at || ''), String(it.status || it.event || '').trim(), String(it.message || '').trim()].filter(Boolean).join(' - ');
                }).join('\n')
                : '';
            var title = historyTitle || entry.reason || entry.label;
            return '<span class="vr-component-pill vr-work-component is-' + esc(entry.state || '') + '">' +
                '<span class="vr-component-name" title="' + esc(entry.label) + '">' + esc(entry.label) + '</span>' +
                '<span class="vr-component-status" title="' + esc(title) + '">' + esc(displayStatus(entry)) + '</span>' +
            '</span>';
        }

        return '<div class="vr-component-strip vr-work-components">' + entries.map(componentPill).join('') + '</div>';
    }

    function componentStateStripHtml(row, group) {
        var entries = group && Array.isArray(group.entries) ? group.entries : routingComponentEntries(row);
        var priority = group && group.priority ? String(group.priority) : '';
        var priorityHtml = priority ? '<span class="vr-component-tier">P' + esc(priority) + '</span>' : '';
        return '<div class="vr-priority-line">' + priorityHtml + componentEntriesStripHtml(entries) + '</div>';
    }

    function priorityGroupState(group) {
        var entries = group && Array.isArray(group.entries) ? group.entries : [];
        var states = entries.map(function (entry) { return entry.state; });
        if (states.indexOf('owned_active') >= 0) return 'owned_active';
        if (states.indexOf('claimable_next') >= 0) return 'claimable_next';
        if (states.indexOf('locked_future') >= 0) return 'locked_future';
        if (states.length && states.every(function (stateKey) { return stateKey === 'completed'; })) return 'completed';
        return states[0] || '';
    }

    function priorityGroupBadgeHtml(group) {
        var groupState = priorityGroupState(group);
        var label = groupState === 'owned_active'
            ? 'Active'
            : groupState === 'claimable_next'
                ? 'Claimable'
                : groupState === 'locked_future'
                    ? 'Locked'
                    : groupState === 'completed'
                        ? 'Completed'
                        : 'Pending';
        var cls = groupState || 'pending';
        return '<span class="vr-badge ' + esc(cls) + '">' + esc(label) + '</span>';
    }

    function claimButtonLabel(row) {
        return 'Claim';
    }

    function appendPriorityBucket(url, priority) {
        url = String(url || '').trim();
        priority = String(priority || '').trim();
        if (!url || !priority) return url;
        var glue = url.indexOf('?') >= 0 ? '&' : '?';
        return url + glue + 'priority_bucket=' + encodeURIComponent('p' + priority);
    }

    function priorityChildRowsHtml(row, key) {
        return '';
    }

    function updateKpis() {
        var counts = { claimable: 0, active: 0, all: 0, completed: 0 };
        // Preserve the existing KPI semantics: counts are based on dashboard API rows
        // with matching routing state keys, not the rendered priority sections.
        state.rows.forEach(function (row) {
            if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return;
            var stateKeys = Array.isArray(row.state_keys) ? row.state_keys : [];
            if (stateKeys.indexOf('claimable_next') >= 0) counts.claimable += 1;
            if (stateKeys.indexOf('owned_active') >= 0) counts.active += 1;
            if (stateKeys.indexOf('completed') >= 0) counts.completed += 1;
            if (stateKeys.indexOf('claimable_next') >= 0 || stateKeys.indexOf('owned_active') >= 0 || stateKeys.indexOf('completed') >= 0) counts.all += 1;
        });
        if (kpiPendingEl) kpiPendingEl.textContent = String(counts.claimable);
        if (kpiInProgressEl) kpiInProgressEl.textContent = String(counts.active);
        if (kpiFollowUpEl) kpiFollowUpEl.textContent = String(counts.all);
        if (kpiCompletedEl) kpiCompletedEl.textContent = String(counts.completed);
    }

    function updateChipLabels() {
        if (!bucketHost) return;
        var counts = { claimable: 0, active: 0, completed: 0, all: 0 };
        // Preserve the existing tab-count semantics: one count per visible API row
        // containing the state key, even when the row renders multiple sections.
        state.rows.forEach(function (row) {
            if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return;
            var stateKeys = Array.isArray(row.state_keys) ? row.state_keys : [];
            if (stateKeys.indexOf('claimable_next') >= 0) counts.claimable += 1;
            if (stateKeys.indexOf('owned_active') >= 0) counts.active += 1;
            if (stateKeys.indexOf('completed') >= 0) counts.completed += 1;
            if (stateKeys.indexOf('claimable_next') >= 0 || stateKeys.indexOf('owned_active') >= 0 || stateKeys.indexOf('completed') >= 0) counts.all += 1;
        });
        Array.prototype.slice.call(bucketHost.querySelectorAll('[data-bucket]')).forEach(function (btn) {
            var key = String(btn.getAttribute('data-bucket') || '').toLowerCase().trim();
            var baseLabel = bucketLabel(key).toUpperCase();
            btn.textContent = baseLabel + ' (' + String(counts[key] || 0) + ')';
            btn.classList.toggle('active', key === state.bucket);
        });
        if (compatNoteEl) compatNoteEl.style.display = state.rows.length ? 'block' : 'none';
    }

    function groupActionHtml(row, group, key) {
        var groupState = priorityGroupState(group);
        if (state.bucket === 'claimable' && row.can_claim && groupState === 'claimable_next') {
            var disabled = state.claimInFlightKey && state.claimInFlightKey !== key;
            return '<button type="button" class="vr-action-btn claim" data-action="claim" data-row-key="' + esc(key) + '" data-case-id="' + esc(String(row.case_id || '')) + '" data-priority="' + esc(String(group.priority || '')) + '"' + (disabled ? ' disabled' : '') + '>' + esc(claimButtonLabel(row)) + '</button>';
        }
        if (row.can_open && (groupState === 'owned_active' || groupState === 'completed')) {
            var openText = state.bucket === 'completed' ? 'Open Report' : 'Open';
            return '<button type="button" class="vr-action-btn ' + (state.bucket === 'completed' ? 'view' : 'open') + '" data-action="open" data-url="' + esc(appendPriorityBucket(row.open_url, group.priority)) + '">' + esc(openText) + '</button>';
        }
        if (groupState === 'locked_future') {
            return '<span class="vr-board-child-state">Locked</span>';
        }
        return '<button type="button" class="vr-action-btn" disabled>Readonly</button>';
    }

    function prioritySectionClass(row, group) {
        var groupState = priorityGroupState(group);
        var out = ['vr-board-data-row', 'vr-priority-section'];
        if (groupState === 'owned_active' || groupState === 'followup') out.push('is-mine');
        if (groupState === 'locked_future') out.push('is-locked');
        if (groupState === 'completed') out.push('is-completed');
        if (row && row.can_open && (groupState === 'owned_active' || groupState === 'completed')) out.push('is-clickable');
        return out.join(' ');
    }

    function prioritySectionHtml(row, group, key, claimedBy, claimedMeta) {
        var groupState = priorityGroupState(group);
        var actionable = row.can_open && (groupState === 'owned_active' || groupState === 'completed');
        return ''
        + '<div class="' + esc(prioritySectionClass(row, group)) + '" data-row-key="' + esc(key) + '" data-priority="' + esc(String(group.priority || '')) + '" data-open-url="' + esc(appendPriorityBucket(row.open_url, group.priority)) + '" data-actionable="' + (actionable ? '1' : '0') + '">'
        +   '<div class="vr-priority-section-main">'
        +       componentStateStripHtml(row, group)
        +       '<div class="vr-priority-section-meta">'
        +           '<span>Status: ' + priorityGroupBadgeHtml(group) + '</span>'
        +           '<span>Claimed By: <strong>' + esc(claimedBy) + '</strong></span>'
        +           claimedMeta
        +       '</div>'
        +   '</div>'
        +   '<div class="vr-priority-section-action">' + groupActionHtml(row, group, key) + '</div>'
        + '</div>';
    }

    function renderRows() {
        if (!rowsHost) return;
        updateKpis();
        updateChipLabels();
        var rows = filteredRows();
        if (!rows.length) {
            rowsHost.innerHTML = '<div class="vr-empty">No cases in this work bucket.</div>';
            return;
        }
        rowsHost.innerHTML = rows.map(function (row) {
            var key = String(row.queue_row_id || '') + '|' + String(row.case_id || '');
            var claimedBy = String(row.assigned_user_name || '').trim();
            if (!claimedBy) claimedBy = '-';
            var claimedMeta = '';
            if (String(row.claimed_at || '').trim()) {
                claimedMeta = '<div class="vr-board-muted">' + esc(fmtDateTime(row.claimed_at)) + '</div>';
            } else if (String(row.completed_at || '').trim()) {
                claimedMeta = '<div class="vr-board-muted">Completed ' + esc(fmtDateTime(row.completed_at)) + '</div>';
            }
            var groups = priorityComponentGroups(row);
            if (!groups.length) {
                groups = [{ priority: '', entries: routingComponentEntries(row) }];
            }
            var candidateName = ((row.candidate_first_name || '') + ' ' + (row.candidate_last_name || '')).trim() || '-';
            return ''
            + '<article class="vr-case-card" data-case-id="' + esc(String(row.case_id || '')) + '">'
            +   '<header class="vr-case-card-head">'
            +       '<div>'
            +           '<div class="vr-board-case">' + esc(row.application_id || '-') + '</div>'
            +           '<div class="vr-board-name">Candidate: ' + esc(candidateName) + '</div>'
            +       '</div>'
            +       '<div class="vr-case-card-badges">'
            +           bucketBadgeHtml(row, state.bucket)
            +           '<span class="vr-board-muted">' + esc(String(row.workflow_mode || '').replace(/_/g, ' ')) + '</span>'
            +       '</div>'
            +   '</header>'
            +   '<div class="vr-priority-section-list">'
            +       groups.map(function (group) {
                        return prioritySectionHtml(row, group, key, claimedBy, claimedMeta);
                    }).join('')
            +   '</div>'
            + '</article>';
        }).join('');
    }

    function loadBoard() {
        if (state.loading) return Promise.resolve();
        state.loading = true;
        setMessage('', '');
        return fetch(base + '/api/verifier/dashboard_board.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data || !Array.isArray(data.data.rows)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load verifier dashboard');
                }
                state.rows = data.data.rows;
                renderRows();
            })
            .catch(function (err) {
                state.rows = [];
                renderRows();
                setMessage((err && err.message) ? err.message : 'Failed to load verifier dashboard', 'danger');
            })
            .finally(function () {
                state.loading = false;
            });
    }

    function reportUrlForRow(row, priority) {
        row = row || {};
        var params = new URLSearchParams();
        if (row.case_id) params.set('case_id', String(row.case_id));
        if (row.application_id) params.set('application_id', String(row.application_id));
        if (row.client_id) params.set('client_id', String(row.client_id));
        params.set('board', '1');
        params.set('view', 'active');
        params.set('filter', 'active_work');
        if (priority) params.set('priority_bucket', 'p' + String(priority));
        return base + '/modules/verifier/candidate_view.php?' + params.toString();
    }

    function rowByKey(rowKey) {
        return state.rows.find(function (it) {
            return (String(it.queue_row_id || '') + '|' + String(it.case_id || '')) === String(rowKey || '');
        }) || null;
    }

    function claimRow(caseId, rowKey, priority) {
        if (state.claimInFlightKey) return;
        var targetRow = rowByKey(rowKey);
        state.claimInFlightKey = rowKey;
        renderRows();
        setMessage('', '');
        fetch(base + '/api/verifier/queue_claim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ case_id: caseId })
        })
            .then(function (res) { return res.json().catch(function () { return null; }).then(function (data) { return { ok: res.ok, data: data }; }); })
            .then(function (out) {
                if (!out.ok || !out.data || out.data.status !== 1) {
                    throw new Error((out.data && out.data.message) ? out.data.message : 'Unable to claim components');
                }
                window.location.assign(reportUrlForRow(targetRow || { case_id: caseId }, priority));
                return null;
            })
            .catch(function (err) {
                setMessage((err && err.message) ? err.message : 'Unable to claim components', 'warning');
                return loadBoard();
            })
            .finally(function () {
                state.claimInFlightKey = '';
                renderRows();
            });
    }

    if (bucketHost) {
        bucketHost.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-bucket]') : null;
            if (!btn) return;
            state.bucket = String(btn.getAttribute('data-bucket') || 'claimable').toLowerCase();
            renderRows();
        });
    }

    if (rowsHost) {
        rowsHost.addEventListener('click', function (e) {
            var actionBtn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (actionBtn) {
                var action = String(actionBtn.getAttribute('data-action') || '');
                if (action === 'claim') {
                    claimRow(parseInt(String(actionBtn.getAttribute('data-case-id') || '0'), 10) || 0, String(actionBtn.getAttribute('data-row-key') || ''), String(actionBtn.getAttribute('data-priority') || ''));
                    return;
                }
                if (action === 'open') {
                    var url = String(actionBtn.getAttribute('data-url') || '').trim();
                    if (url) window.location.assign(url);
                    return;
                }
            }
            var rowEl = e.target && e.target.closest ? e.target.closest('.vr-board-data-row') : null;
            if (!rowEl || rowEl.getAttribute('data-actionable') !== '1') return;
            var rowKey = String(rowEl.getAttribute('data-row-key') || '');
            var row = state.rows.find(function (it) {
                return (String(it.queue_row_id || '') + '|' + String(it.case_id || '')) === rowKey;
            });
            if (!row) return;
            if (row.can_open && row.open_url) {
                window.location.assign(String(rowEl.getAttribute('data-open-url') || row.open_url));
            }
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            loadBoard();
        });
    }

    loadBoard();
    setInterval(function () {
        if (document.visibilityState === 'hidden') return;
        if (state.claimInFlightKey) return;
        loadBoard();
    }, 15000);
});
