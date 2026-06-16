document.addEventListener('DOMContentLoaded', function () {
    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

    /* ── DOM refs ─────────────────────────────────────────────────── */
    var messageEl     = document.getElementById('vrBoardMessage');
    var rowsHost      = document.getElementById('vrBoardRows');
    var refreshBtn    = document.getElementById('vrBoardRefreshBtn');
    var bucketHost    = document.getElementById('vrBucketChips');
    var compatNoteEl  = document.getElementById('vrBoardCompatNote');
    var kpiPendingEl  = document.getElementById('vrKpiPending');
    var kpiActiveEl   = document.getElementById('vrKpiInProgress');
    var kpiAllEl      = document.getElementById('vrKpiFollowUp');
    var kpiDoneEl     = document.getElementById('vrKpiCompleted');
    var searchEl      = document.getElementById('vrSearch');
    var pageSizeEl    = document.getElementById('vrPageSize');
    var tblInfoEl     = document.getElementById('vrTblInfo');
    var pagerEl       = document.getElementById('vrPager');
    var pagerInfoEl   = document.getElementById('vrPagerInfo');
    var paginationEl  = document.getElementById('vrPagination');
    var tableEl       = document.getElementById('vrTable');

    /* ── State ────────────────────────────────────────────────────── */
    var state = {
        rows: [],
        bucketCounts: {},
        bucket: 'claimable',
        loading: false,
        claimInFlightKey: '',
        dashboardVersion: '',
        cacheTimestamp: 0,
        cachePendingValidation: false,
        versionCheckInFlight: false,
        pendingRefreshAfterCheck: false,
        /* table UI */
        search: '',
        sortCol: '',
        sortDir: 'asc',
        page: 1,
        pageSize: 25,
        expandedKeys: {}        /* rowKey → true */
    };

    var CACHE_SCHEMA  = 1;
    var CACHE_KEY     = 'vrDashboardCache:v' + CACHE_SCHEMA + ':u' + String(window.AUTH_USER_ID || 0);
    var POLL_INTERVAL = 60000;
    var pollTimer     = null;

    /* ── Utilities ────────────────────────────────────────────────── */
    function esc(s) {
        return String(s || '')
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function fmtDateTime(v) {
        var raw = String(v || '').trim();
        if (!raw) return '-';
        try {
            if (window.GSS_DATE && typeof window.GSS_DATE.formatDbDateTime === 'function')
                return window.GSS_DATE.formatDbDateTime(raw);
        } catch (_e) {}
        return raw;
    }

    function setMessage(text, type) {
        if (!messageEl) return;
        messageEl.textContent = text || '';
        messageEl.className   = text ? ('vr-msg ' + (type || 'info')) : 'vr-msg';
        messageEl.style.display = text ? 'block' : 'none';
    }

    function bucketLabel(key) {
        key = String(key || '').toLowerCase().trim();
        if (key === 'claimable')  return 'To Be Claimed';
        if (key === 'active')     return 'Active';
        if (key === 'followup')   return 'Follow Up';
        if (key === 'completed')  return 'Completed';
        if (key === 'all')        return 'All';
        return key || '-';
    }

    /* ── Data helpers (unchanged from original) ───────────────────── */
    function componentLockReason(item) {
        var reason = String(item && item.reason ? item.reason : '').trim();
        var code   = String(item && item.reason_code ? item.reason_code : '').toLowerCase().trim();
        if (reason) return reason;
        if (code === 'case_gate_incomplete')              return 'Finish earlier priority first';
        if (code === 'higher_priority_bucket_pending')    return 'Higher-priority workload pending';
        if (code === 'already_assigned')                  return 'Already assigned';
        if (code === 'no_capability')                     return 'No routing capability';
        if (code === 'completed')                         return 'Completed';
        return '';
    }

    function routingComponentEntries(row) {
        var states = row && row.routing_component_states &&
                     typeof row.routing_component_states === 'object'
            ? row.routing_component_states : {};
        return Object.keys(states).map(function (key) {
            var item = states[key] || {};
            return {
                key:           key,
                label:         String(item.label || key || '').trim(),
                priority:      item.priority ? parseInt(String(item.priority), 10) || 0 : 0,
                state:         String(item.state || '').toLowerCase().trim(),
                reason:        componentLockReason(item),
                displayStatus: String(item.display_status || '').trim(),
                history:       Array.isArray(item.history) ? item.history : []
            };
        }).filter(function (e) {
            return e.label && e.state !== 'hidden_unrelated';
        }).sort(function (a, b) {
            if (a.state === 'context' && b.state !== 'context') return -1;
            if (b.state === 'context' && a.state !== 'context') return 1;
            if (a.priority !== b.priority) return a.priority - b.priority;
            return a.label.localeCompare(b.label);
        });
    }

    function priorityComponentGroups(row) {
        var groups = {};
        routingComponentEntries(row).forEach(function (e) {
            if (!e.priority || e.state === 'context' || e.state === 'hidden_unrelated') return;
            var k = String(e.priority);
            if (!groups[k]) groups[k] = [];
            groups[k].push(e);
        });
        return Object.keys(groups)
            .sort(function (a, b) { return parseInt(a,10) - parseInt(b,10); })
            .map(function (p) {
                return {
                    priority: p,
                    entries: groups[p].sort(function (a, b) { return a.label.localeCompare(b.label); })
                };
            });
    }

    function priorityGroupState(group) {
        var states = (group && Array.isArray(group.entries) ? group.entries : []).map(function (e) { return e.state; });
        if (states.indexOf('owned_active')   >= 0) return 'owned_active';
        if (states.indexOf('claimable_next') >= 0) return 'claimable_next';
        if (states.indexOf('locked_future')  >= 0) return 'locked_future';
        if (states.length && states.every(function (s) { return s === 'completed'; })) return 'completed';
        return states[0] || '';
    }

    function appendPriorityBucket(url, priority) {
        url      = String(url || '').trim();
        priority = String(priority || '').trim();
        if (!url || !priority) return url;
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'priority_bucket=' + encodeURIComponent('p' + priority);
    }

    function reportUrlForRow(row, priority) {
        row = row || {};
        var p = new URLSearchParams();
        if (row.case_id)        p.set('case_id', String(row.case_id));
        if (row.application_id) p.set('application_id', String(row.application_id));
        if (row.client_id)      p.set('client_id', String(row.client_id));
        p.set('board','1'); p.set('view','active'); p.set('filter','active_work');
        if (priority) p.set('priority_bucket', 'p' + String(priority));
        return base + '/modules/verifier/candidate_view.php?' + p.toString();
    }

    /* ── Follow-up detection ───────────────────────────────────────
       A row is "follow-up" when:
         1. The backend board_bucket is 'followup' (set when queue status is
            followup / hold / reopened / blocked), OR
         2. Any component's display_status signals a waiting/follow-up state
            (Waiting Candidate, Correction Submitted, VE Hold, Blocked,
             Decision Update) — catches cases where the queue row has not
            yet been re-bucketed but a component is already in that state.
    ─────────────────────────────────────────────────────────────── */
    var FOLLOWUP_DISPLAY_STATUSES = [
        'waiting candidate',
        'correction submitted',
        've hold',
        'va hold',
        'qa hold',
        'blocked',
        'decision update'
    ];

    function isFollowUpRow(row) {
        /* Primary: backend already bucketed it */
        if (String(row.board_bucket || '').toLowerCase() === 'followup') return true;
        /* Secondary: any component display_status matches a follow-up label */
        var states = row.routing_component_states;
        if (states && typeof states === 'object') {
            var keys = Object.keys(states);
            for (var i = 0; i < keys.length; i++) {
                var ds = String((states[keys[i]] && states[keys[i]].display_status) || '').toLowerCase().trim();
                if (ds && FOLLOWUP_DISPLAY_STATUSES.indexOf(ds) >= 0) return true;
            }
        }
        return false;
    }

    /* ── Filtering / sorting / paging ─────────────────────────────── */
    function filteredRows() {
        return state.rows.filter(function (row) {
            if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return false;
            var sk = Array.isArray(row.state_keys) ? row.state_keys : [];
            if (state.bucket === 'claimable') return sk.indexOf('claimable_next') >= 0;
            if (state.bucket === 'active')    return sk.indexOf('owned_active')   >= 0;
            if (state.bucket === 'followup')  return isFollowUpRow(row);
            if (state.bucket === 'completed') return sk.indexOf('completed')      >= 0;
            if (state.bucket === 'all')
                return sk.indexOf('owned_active') >= 0 || sk.indexOf('claimable_next') >= 0 || sk.indexOf('completed') >= 0 || isFollowUpRow(row);
            return false;
        });
    }

    /* Derive a flat "representative" group per row for the summary columns */
    function rowSummary(row) {
        var groups  = priorityComponentGroups(row);
        var entries = routingComponentEntries(row);
        /* Pick the first actionable group; fall back to first group */
        var repGroup = groups[0] || { priority: '', entries: entries };
        for (var i = 0; i < groups.length; i++) {
            var gs = priorityGroupState(groups[i]);
            if (gs === 'claimable_next' || gs === 'owned_active') { repGroup = groups[i]; break; }
        }
        var repState   = priorityGroupState(repGroup);
        var repEntries = Array.isArray(repGroup.entries) ? repGroup.entries : [];
        /* Pick first actionable component label */
        var compLabel  = '';
        for (var j = 0; j < repEntries.length; j++) {
            var e = repEntries[j];
            if (e.state !== 'hidden_unrelated' && e.state !== 'context') { compLabel = e.label; break; }
        }
        if (!compLabel && repEntries.length) compLabel = repEntries[0].label;
        /* "Last updated" = claimed_at or completed_at */
        var updated = String(row.claimed_at || row.completed_at || '').trim();
        return {
            priority:    repGroup.priority || '',
            groupState:  repState,
            compLabel:   compLabel || '-',
            groups:      groups,
            entries:     entries,
            updated:     updated
        };
    }

    /* Derive the status string for colour coding */
    function statusText(groupState, entries) {
        /* First check component-level display statuses for follow-up signals */
        for (var i = 0; i < (entries || []).length; i++) {
            var ds = String(entries[i].displayStatus || '').toLowerCase();
            if (ds.indexOf('waiting') >= 0)    return entries[i].displayStatus;
            if (ds.indexOf('correction') >= 0) return entries[i].displayStatus;
            if (ds === 've hold' || ds === 'va hold' || ds === 'qa hold') return entries[i].displayStatus;
            if (ds === 'blocked')              return 'Blocked';
            if (ds === 'decision update')      return entries[i].displayStatus;
        }
        if (groupState === 'owned_active')   return 'Active';
        if (groupState === 'claimable_next') return 'Ready';
        if (groupState === 'locked_future')  return 'Locked';
        if (groupState === 'completed')      return 'Completed';
        return 'Pending';
    }

    function statusClass(text) {
        var t = String(text || '').toLowerCase();
        if (t === 'ready')                   return 'st st-ready';
        if (t === 'active')                  return 'st st-active';
        if (t === 'locked')                  return 'st st-locked';
        if (t.indexOf('correction') >= 0)    return 'st st-correction';
        if (t.indexOf('waiting') >= 0)       return 'st st-waiting';
        if (t === 've hold' || t === 'va hold' || t === 'qa hold') return 'st st-hold';
        if (t === 'blocked')                 return 'st st-hold';
        if (t === 'decision update')         return 'st st-correction';
        if (t === 'completed')               return 'st st-completed';
        return 'st st-pending';
    }

    function queueStateText(row) {
        var s = String(row.row_state || '').toLowerCase().trim();
        if (s === 'available')        return 'Claimable';
        if (s === 'mine_active')      return 'Owned';
        if (s === 'claimed_by_other') return 'Claimed';
        if (s === 'locked_future')    return 'Locked';
        if (s === 'completed')        return 'Completed';
        if (s === 'followup')         return 'Follow Up';
        return '-';
    }

    function queueStateClass(row) {
        var s = String(row.row_state || '').toLowerCase().trim();
        if (s === 'available' || s === 'claimable_next') return 'qs qs-claimable';
        if (s === 'mine_active')      return 'qs qs-active';
        if (s === 'locked_future')    return 'qs qs-locked';
        if (s === 'completed')        return 'qs qs-completed';
        return 'qs qs-other';
    }

    function searchMatch(row, q) {
        if (!q) return true;
        q = q.toLowerCase();
        var name = ((row.candidate_first_name || '') + ' ' + (row.candidate_last_name || '')).toLowerCase();
        var appId = String(row.application_id || '').toLowerCase();
        return appId.indexOf(q) >= 0 || name.indexOf(q) >= 0;
    }

    function sortRows(rows) {
        if (!state.sortCol) return rows;
        var col = state.sortCol;
        var dir = state.sortDir === 'asc' ? 1 : -1;
        return rows.slice().sort(function (a, b) {
            var av = '', bv = '';
            if (col === 'application_id')   { av = String(a.application_id || ''); bv = String(b.application_id || ''); }
            if (col === 'candidate_name')   { av = ((a.candidate_first_name||'')+' '+(a.candidate_last_name||'')).trim(); bv = ((b.candidate_first_name||'')+' '+(b.candidate_last_name||'')).trim(); }
            if (col === 'claimed_by')       { av = String(a.assigned_user_name || ''); bv = String(b.assigned_user_name || ''); }
            if (col === 'queue_state')      { av = queueStateText(a); bv = queueStateText(b); }
            if (col === 'last_updated')     { av = String(a.claimed_at || a.completed_at || ''); bv = String(b.claimed_at || b.completed_at || ''); }
            if (col === 'status')           { av = statusText(priorityGroupState((priorityComponentGroups(a)||[{}])[0]), []); bv = statusText(priorityGroupState((priorityComponentGroups(b)||[{}])[0]), []); }
            if (av < bv) return -1 * dir;
            if (av > bv) return  1 * dir;
            return 0;
        });
    }

    function getViewRows() {
        var rows = filteredRows();
        rows = rows.filter(function (row) { return searchMatch(row, state.search); });
        rows = sortRows(rows);
        return rows;
    }

    function rowKey(row) {
        return String(row.queue_row_id || '') + '|' + String(row.case_id || '');
    }

    /* ── KPI + chip labels ────────────────────────────────────────── */
    function updateKpis() {
        var c = { claimable: 0, active: 0, followup: 0, all: 0, completed: 0 };
        state.rows.forEach(function (row) {
            if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return;
            var sk = Array.isArray(row.state_keys) ? row.state_keys : [];
            if (sk.indexOf('claimable_next') >= 0) c.claimable++;
            if (sk.indexOf('owned_active')   >= 0) c.active++;
            if (sk.indexOf('completed')      >= 0) c.completed++;
            if (isFollowUpRow(row))                c.followup++;
            if (sk.indexOf('owned_active') >= 0 || sk.indexOf('claimable_next') >= 0 ||
                sk.indexOf('completed') >= 0 || isFollowUpRow(row)) c.all++;
        });
        if (kpiPendingEl) kpiPendingEl.textContent = String(c.claimable);
        if (kpiActiveEl)  kpiActiveEl.textContent  = String(c.active);
        if (kpiAllEl)     kpiAllEl.textContent     = String(c.all);
        if (kpiDoneEl)    kpiDoneEl.textContent    = String(c.completed);
    }

    function updateChipLabels() {
        if (!bucketHost) return;
        var c = { claimable: 0, active: 0, followup: 0, completed: 0, all: 0 };
        state.rows.forEach(function (row) {
            if (String(row.row_state || '').toLowerCase().trim() === 'hidden_unrelated') return;
            var sk = Array.isArray(row.state_keys) ? row.state_keys : [];
            if (sk.indexOf('claimable_next') >= 0) c.claimable++;
            if (sk.indexOf('owned_active')   >= 0) c.active++;
            if (sk.indexOf('completed')      >= 0) c.completed++;
            if (isFollowUpRow(row))                c.followup++;
            if (sk.indexOf('owned_active') >= 0 || sk.indexOf('claimable_next') >= 0 ||
                sk.indexOf('completed') >= 0 || isFollowUpRow(row)) c.all++;
        });
        Array.prototype.slice.call(bucketHost.querySelectorAll('[data-bucket]')).forEach(function (btn) {
            var k = String(btn.getAttribute('data-bucket') || '').toLowerCase().trim();
            btn.textContent = bucketLabel(k).toUpperCase() + ' (' + String(c[k] || 0) + ')';
            btn.classList.toggle('active', k === state.bucket);
        });
        if (compatNoteEl) compatNoteEl.style.display = state.rows.length ? 'block' : 'none';
    }

    /* ── Priority: plain text, no pill ──────────────────────────────
       Returns a span with class vr-p1 / vr-p2 / vr-p3 / vr-p-other.
       Used in summary rows. Accordion child table uses same helper.   */
    function priorityHtml(priority, groupState) {
        if (!priority && priority !== 0) return '<span style="color:#9ca3af;font-size:12px;">–</span>';
        var p = parseInt(String(priority), 10);
        var cls = p === 1 ? 'vr-p1' : p === 2 ? 'vr-p2' : p === 3 ? 'vr-p3' : 'vr-p-other';
        /* dim if locked or completed */
        if (groupState === 'locked_future' || groupState === 'completed') cls = 'vr-p-other';
        return '<span class="' + cls + '">P' + esc(String(p)) + '</span>';
    }

    /* ── Status: dot + text span ─────────────────────────────────── */
    function statusHtml(sText) {
        var cls = statusDotClass(sText);
        return '<span class="vr-status ' + cls + '">'
            + '<span class="vr-status-dot"></span>'
            + esc(sText)
            + '</span>';
    }

    /* Returns the vr-s-* modifier class for a status string */
    function statusDotClass(text) {
        var t = String(text || '').toLowerCase();
        if (t === 'ready')                   return 'vr-s-ready';
        if (t === 'active')                  return 'vr-s-active';
        if (t === 'locked')                  return 'vr-s-locked';
        if (t.indexOf('correction') >= 0)    return 'vr-s-correction';
        if (t.indexOf('waiting') >= 0)       return 'vr-s-waiting';
        if (t === 've hold' || t === 'va hold' || t === 'qa hold') return 'vr-s-hold';
        if (t === 'blocked')                 return 'vr-s-hold';
        if (t === 'decision update')         return 'vr-s-correction';
        if (t === 'completed')               return 'vr-s-completed';
        return 'vr-s-pending';
    }
    function groupActionHtml(row, group, key) {
        var gs = priorityGroupState(group);
        if (state.cachePendingValidation && (row.can_claim || row.can_open))
            return '<button type="button" class="vr-action-btn" disabled>Checking…</button>';
        if (state.bucket === 'claimable' && row.can_claim && gs === 'claimable_next') {
            var dis = state.claimInFlightKey && state.claimInFlightKey !== key;
            return '<button type="button" class="vr-action-btn claim"'
                + ' data-action="claim"'
                + ' data-row-key="' + esc(key) + '"'
                + ' data-case-id="' + esc(String(row.case_id || '')) + '"'
                + ' data-priority="' + esc(String(group.priority || '')) + '"'
                + (dis ? ' disabled' : '') + '>Claim</button>';
        }
        if (row.can_open && (gs === 'owned_active' || gs === 'completed' || state.bucket === 'followup')) {
            var label = state.bucket === 'completed' ? 'Open Report' : 'Open';
            var cls   = state.bucket === 'completed' ? 'view' : 'open';
            return '<button type="button" class="vr-action-btn ' + cls + '"'
                + ' data-action="open"'
                + ' data-url="' + esc(appendPriorityBucket(row.open_url, group.priority)) + '">'
                + label + '</button>';
        }
        if (gs === 'locked_future') return '<span class="vr-locked-label">Locked</span>';
        return '<button type="button" class="vr-action-btn" disabled>Readonly</button>';
    }

    /* ── Accordion child table HTML ───────────────────────────────── */
    function accordionHtml(row, key) {
        var groups  = priorityComponentGroups(row);
        var entries = routingComponentEntries(row);

        /* Build flat list of component rows for child table */
        var childRows = [];
        if (groups.length) {
            groups.forEach(function (g) {
                g.entries.forEach(function (e) {
                    childRows.push({ entry: e, group: g });
                });
            });
        } else {
            entries.forEach(function (e) {
                childRows.push({ entry: e, group: { priority: e.priority || '', entries: [e] } });
            });
        }

        if (!childRows.length) {
            return '<div class="vr-accordion-inner"><div style="font-size:12px;color:#94a3b8;">No component details available.</div></div>';
        }

        var rowsHtml = childRows.map(function (cr) {
            var e  = cr.entry;
            var g  = cr.group;
            var gs = priorityGroupState(g);
            /* Priority — plain text */
            var pHtml = priorityHtml(g.priority, gs);
            /* Status */
            var sText = e.displayStatus || (
                e.state === 'claimable_next' ? 'Ready' :
                e.state === 'owned_active'   ? 'Active' :
                e.state === 'locked_future'  ? 'Locked' :
                e.state === 'completed'      ? 'Completed' :
                e.state === 'context'        ? 'Context' :
                e.state.replace(/_/g, ' ')
            );
            var sCls = statusClass(sText);
            /* Reason / history tooltip */
            var reason = e.reason || '';
            var historyTitle = e.history.length
                ? e.history.map(function (it) {
                    return [fmtDateTime(it.at || ''), String(it.status || it.event || ''), String(it.message || '')].filter(Boolean).join(' – ');
                }).join('\n')
                : '';
            var tooltip = historyTitle || reason;
            /* Per-component action */
            var actionHtml = groupActionHtml(row, g, key);
            return '<tr>'
                + '<td>' + pHtml + '</td>'
                + '<td title="' + esc(tooltip) + '">' + esc(e.label) + '</td>'
                + '<td><span class="' + esc(sCls) + '">' + esc(sText) + '</span></td>'
                + '<td style="font-size:12px;color:#64748b;">' + esc(reason || '-') + '</td>'
                + '<td>' + actionHtml + '</td>'
                + '</tr>';
        }).join('');

        return '<div class="vr-accordion-inner">'
            + '<div class="vr-accordion-label">Component Details</div>'
            + '<table class="vr-child-tbl">'
            +   '<thead><tr>'
            +     '<th style="width:48px;">Priority</th>'
            +     '<th>Component</th>'
            +     '<th style="width:130px;">Status</th>'
            +     '<th>Notes</th>'
            +     '<th style="width:120px;">Action</th>'
            +   '</tr></thead>'
            +   '<tbody>' + rowsHtml + '</tbody>'
            + '</table>'
            + '</div>';
    }

    /* ── Render table ─────────────────────────────────────────────── */
    function renderRows() {
        if (!rowsHost) return;
        updateKpis();
        updateChipLabels();

        var allRows  = getViewRows();
        var total    = allRows.length;
        var pageSize = state.pageSize;
        var page     = state.page;
        var totalPages = Math.max(1, Math.ceil(total / pageSize));
        if (page > totalPages) { state.page = page = totalPages; }

        var start   = (page - 1) * pageSize;
        var pageRows = allRows.slice(start, start + pageSize);

        /* Info label */
        var infoText = total === 0
            ? 'No results'
            : (start + 1) + '–' + Math.min(start + pageSize, total) + ' of ' + total;
        if (tblInfoEl)   tblInfoEl.textContent  = infoText;
        if (pagerInfoEl) pagerInfoEl.textContent = infoText;

        /* Sort indicators */
        if (tableEl) {
            Array.prototype.slice.call(tableEl.querySelectorAll('th[data-col]')).forEach(function (th) {
                var col = th.getAttribute('data-col');
                if (col === state.sortCol) {
                    th.setAttribute('data-sort-dir', state.sortDir);
                } else {
                    th.removeAttribute('data-sort-dir');
                }
            });
        }

        /* Empty state */
        if (!pageRows.length) {
            rowsHost.innerHTML = '<tr class="vr-empty-row"><td colspan="10">'
                + (state.search ? 'No results match your search.' : 'No cases in this work bucket.')
                + '</td></tr>';
            if (paginationEl) paginationEl.style.display = 'none';
            return;
        }

        /* Build rows */
        var html = '';
        pageRows.forEach(function (row) {
            var key       = rowKey(row);
            var sum       = rowSummary(row);
            var name      = ((row.candidate_first_name || '') + ' ' + (row.candidate_last_name || '')).trim() || '-';
            var claimedBy = String(row.assigned_user_name || '-').trim() || '-';
            var updated   = sum.updated ? fmtDateTime(sum.updated) : '-';
            var sText     = statusText(sum.groupState, sum.entries);
            var sCls      = statusClass(sText);    /* kept for accordion child */
            var sHtml     = statusHtml(sText);     /* dot+text for summary row */
            var qText     = queueStateText(row);
            var qCls      = queueStateClass(row);
            var expanded  = !!state.expandedKeys[key];

            /* Row modifiers — only expansion state; color differentiation via zebra CSS */
            var rowMods = 'vr-data-row';
            if (expanded) rowMods += ' is-expanded';

            /* Rep group for action in summary row — use first claimable/active group */
            var repGroup = sum.groups[0] || { priority: sum.priority, entries: sum.entries };
            for (var i = 0; i < sum.groups.length; i++) {
                var gs2 = priorityGroupState(sum.groups[i]);
                if (gs2 === 'claimable_next' || gs2 === 'owned_active') { repGroup = sum.groups[i]; break; }
            }

            /* Priority — plain text */
            var pHtml = priorityHtml(sum.priority, sum.groupState);

            var actionHtml = groupActionHtml(row, repGroup, key);

            /* Data row */
            html += '<tr class="' + rowMods + '" data-row-key="' + esc(key) + '"'
                + ' data-case-id="' + esc(String(row.case_id || '')) + '"'
                + ' data-can-open="' + (row.can_open ? '1' : '0') + '"'
                + ' data-open-url="' + esc(row.open_url || '') + '">'
                /* expand */
                + '<td style="text-align:center;"><button type="button" class="vr-expand-btn" data-expand="' + esc(key) + '" title="Toggle details">'
                + (expanded ? '&#8722;' : '&#43;') + '</button></td>'
                /* app id */
                + '<td><span class="vr-appid">' + esc(row.application_id || '-') + '</span></td>'
                /* candidate */
                + '<td title="' + esc(name) + '">' + esc(name) + '</td>'
                /* priority */
                + '<td style="text-align:center;">' + pHtml + '</td>'
                /* component summary */
                + '<td title="' + esc(sum.compLabel) + '">' + esc(sum.compLabel) + '</td>'
                /* status */
                + '<td>' + sHtml + '</td>'
                /* claimed by */
                + '<td title="' + esc(claimedBy) + '">' + esc(claimedBy) + '</td>'
                /* queue state */
                + '<td><span class="' + esc(qCls) + '">' + esc(qText) + '</span></td>'
                /* last updated */
                + '<td style="font-size:12px;color:#64748b;">' + esc(updated) + '</td>'
                /* action */
                + '<td>' + actionHtml + '</td>'
                + '</tr>';

            /* Accordion row */
            html += '<tr class="vr-accordion-row' + (expanded ? ' open' : '') + '" data-acc-key="' + esc(key) + '">'
                + '<td colspan="10">' + (expanded ? accordionHtml(row, key) : '') + '</td>'
                + '</tr>';
        });

        rowsHost.innerHTML = html;

        /* Pagination */
        renderPager(page, totalPages);
        if (paginationEl) paginationEl.style.display = total > pageSize ? 'flex' : 'none';
    }

    /* ── Pagination controls ──────────────────────────────────────── */
    function renderPager(page, totalPages) {
        if (!pagerEl) return;
        var html = '';
        /* Prev */
        html += '<button type="button" class="vr-pager-btn" data-page="' + (page - 1) + '"'
            + (page <= 1 ? ' disabled' : '') + '>&#8249;</button>';
        /* Pages — show window around current */
        var start = Math.max(1, page - 2);
        var end   = Math.min(totalPages, page + 2);
        if (start > 1) html += '<button type="button" class="vr-pager-btn" data-page="1">1</button>'
            + (start > 2 ? '<span style="padding:0 4px;color:#94a3b8;font-size:12px;">…</span>' : '');
        for (var p = start; p <= end; p++) {
            html += '<button type="button" class="vr-pager-btn' + (p === page ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }
        if (end < totalPages) {
            html += (end < totalPages - 1 ? '<span style="padding:0 4px;color:#94a3b8;font-size:12px;">…</span>' : '')
                + '<button type="button" class="vr-pager-btn" data-page="' + totalPages + '">' + totalPages + '</button>';
        }
        /* Next */
        html += '<button type="button" class="vr-pager-btn" data-page="' + (page + 1) + '"'
            + (page >= totalPages ? ' disabled' : '') + '>&#8250;</button>';
        pagerEl.innerHTML = html;
    }

    /* ── Cache ────────────────────────────────────────────────────── */
    function storageAvailable() {
        try { return !!window.sessionStorage; } catch (_e) { return false; }
    }
    function readDashboardCache() {
        if (!storageAvailable()) return null;
        try {
            var raw = window.sessionStorage.getItem(CACHE_KEY);
            if (!raw) return null;
            var p = JSON.parse(raw);
            if (!p || p.schema !== CACHE_SCHEMA || !Array.isArray(p.rows)) return null;
            return p;
        } catch (_e) { return null; }
    }
    function writeDashboardCache() {
        if (!storageAvailable()) return;
        try {
            window.sessionStorage.setItem(CACHE_KEY, JSON.stringify({
                schema: CACHE_SCHEMA,
                rows: state.rows,
                counts: state.bucketCounts || {},
                filters: { bucket: state.bucket },
                version: state.dashboardVersion || '',
                timestamp: state.cacheTimestamp || Date.now()
            }));
        } catch (_e) {}
    }
    function hydrateFromCache() {
        var cached = readDashboardCache();
        if (!cached) return false;
        state.rows             = cached.rows;
        state.bucketCounts     = cached.counts || {};
        state.bucket           = cached.filters && cached.filters.bucket ? String(cached.filters.bucket).toLowerCase() : state.bucket;
        state.dashboardVersion = String(cached.version || '');
        state.cacheTimestamp   = parseInt(String(cached.timestamp || '0'), 10) || Date.now();
        state.cachePendingValidation = true;
        state.page = 1;
        renderRows();
        setMessage('Showing cached dashboard while checking for updates.', 'info');
        return true;
    }
    function markCacheValidated() {
        if (!state.cachePendingValidation) return;
        state.cachePendingValidation = false;
        renderRows();
        setMessage('', '');
    }
    function dashboardVersionFromPayload(data) {
        var p = data && data.data ? data.data : {};
        return String(p.dashboard_version || p.version || '').trim();
    }

    /* ── Network ──────────────────────────────────────────────────── */
    function loadBoard(options) {
        options = options || {};
        if (state.loading) return Promise.resolve();
        state.loading = true;
        if (!options.silent) setMessage('', '');
        return fetch(base + '/api/verifier/dashboard_board.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data || !Array.isArray(data.data.rows))
                    throw new Error((data && data.message) ? data.message : 'Failed to load verifier dashboard');
                state.rows             = data.data.rows;
                state.bucketCounts     = data.data.bucket_counts || {};
                state.dashboardVersion = String(options.version || state.dashboardVersion || '');
                state.cacheTimestamp   = Date.now();
                state.cachePendingValidation = false;
                state.page = 1;
                writeDashboardCache();
                renderRows();
            })
            .catch(function (err) {
                if (!options.silent && !state.rows.length) { state.rows = []; renderRows(); }
                setMessage((err && err.message) ? err.message : 'Failed to load verifier dashboard', 'danger');
            })
            .finally(function () { state.loading = false; });
    }

    function checkDashboardVersion(options) {
        options = options || {};
        if (state.versionCheckInFlight) {
            if (options.forceRefresh) state.pendingRefreshAfterCheck = true;
            return Promise.resolve();
        }
        if (document.visibilityState === 'hidden' && !options.forceWhenHidden) return Promise.resolve();
        if (state.claimInFlightKey) return Promise.resolve();
        state.versionCheckInFlight = true;
        return fetch(base + '/api/verifier/dashboard_version.php?_ts=' + Date.now(), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data)
                    throw new Error((data && data.message) ? data.message : 'Failed to check dashboard version');
                var next = dashboardVersionFromPayload(data);
                if (!next) {
                    if (state.cachePendingValidation) return loadBoard({ silent: true });
                    return null;
                }
                if (options.forceRefresh || !state.dashboardVersion || state.dashboardVersion !== next)
                    return loadBoard({ silent: state.rows.length > 0, version: next });
                markCacheValidated();
                state.dashboardVersion = next;
                state.cacheTimestamp   = Date.now();
                writeDashboardCache();
                return null;
            })
            .catch(function (err) {
                if (!state.rows.length) return loadBoard({ silent: false });
                setMessage((err && err.message) ? err.message : 'Unable to confirm dashboard freshness. Use Refresh if actions look stale.', 'warning');
                return null;
            })
            .finally(function () {
                state.versionCheckInFlight = false;
                if (state.pendingRefreshAfterCheck) {
                    state.pendingRefreshAfterCheck = false;
                    checkDashboardVersion({ forceRefresh: true });
                }
            });
    }

    function startPolling() {
        if (pollTimer) window.clearInterval(pollTimer);
        pollTimer = window.setInterval(function () {
            if (document.visibilityState === 'hidden') return;
            checkDashboardVersion();
        }, POLL_INTERVAL);
    }

    /* ── Claim workflow ───────────────────────────────────────────── */
    function rowByKey(key) {
        return state.rows.find(function (it) {
            return (String(it.queue_row_id || '') + '|' + String(it.case_id || '')) === String(key || '');
        }) || null;
    }

    function claimRow(caseId, key, priority) {
        if (state.claimInFlightKey) return;
        var targetRow = rowByKey(key);
        state.claimInFlightKey = key;
        renderRows();
        setMessage('', '');
        fetch(base + '/api/verifier/queue_claim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ case_id: caseId })
        })
        .then(function (res) {
            return res.json().catch(function () { return null; })
                .then(function (data) { return { ok: res.ok, data: data }; });
        })
        .then(function (out) {
            if (!out.ok || !out.data || out.data.status !== 1)
                throw new Error((out.data && out.data.message) ? out.data.message : 'Unable to claim components');
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

    /* ── Event delegation ─────────────────────────────────────────── */

    /* Bucket tabs */
    if (bucketHost) {
        bucketHost.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-bucket]') : null;
            if (!btn) return;
            state.bucket = String(btn.getAttribute('data-bucket') || 'claimable').toLowerCase();
            state.page   = 1;
            state.expandedKeys = {};
            renderRows();
            writeDashboardCache();
        });
    }

    /* Table clicks — expand, claim, open */
    if (rowsHost) {
        rowsHost.addEventListener('click', function (e) {
            if (state.cachePendingValidation) return;

            /* Action buttons inside accordion or summary row */
            var actionBtn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (actionBtn) {
                var action = String(actionBtn.getAttribute('data-action') || '');
                if (action === 'claim') {
                    claimRow(
                        parseInt(String(actionBtn.getAttribute('data-case-id') || '0'), 10) || 0,
                        String(actionBtn.getAttribute('data-row-key') || ''),
                        String(actionBtn.getAttribute('data-priority') || '')
                    );
                    return;
                }
                if (action === 'open') {
                    var url = String(actionBtn.getAttribute('data-url') || '').trim();
                    if (url) window.location.assign(url);
                    return;
                }
            }

            /* Expand button */
            var expandBtn = e.target && e.target.closest ? e.target.closest('[data-expand]') : null;
            if (expandBtn) {
                e.stopPropagation();
                var key = String(expandBtn.getAttribute('data-expand') || '');
                if (!key) return;
                if (state.expandedKeys[key]) {
                    delete state.expandedKeys[key];
                } else {
                    state.expandedKeys[key] = true;
                }
                /* Toggle in DOM without full re-render for performance */
                var dataRow = rowsHost.querySelector('tr.vr-data-row[data-row-key="' + key.replace(/"/g,'') + '"]');
                var accRow  = rowsHost.querySelector('tr.vr-accordion-row[data-acc-key="'  + key.replace(/"/g,'') + '"]');
                if (dataRow) dataRow.classList.toggle('is-expanded', !!state.expandedKeys[key]);
                if (accRow) {
                    var open = !!state.expandedKeys[key];
                    accRow.classList.toggle('open', open);
                    if (open && accRow.querySelector('td').innerHTML.trim() === '') {
                        var row = rowByKey(key);
                        if (row) accRow.querySelector('td').innerHTML = accordionHtml(row, key);
                    }
                }
                if (expandBtn) expandBtn.innerHTML = state.expandedKeys[key] ? '&#8722;' : '&#43;';
                return;
            }

            /* Row click → expand toggle (exclude action cell) */
            var dataRow = e.target && e.target.closest ? e.target.closest('tr.vr-data-row') : null;
            if (!dataRow) return;
            /* Don't expand when clicking the action cell */
            var td = e.target && e.target.closest ? e.target.closest('td') : null;
            if (td && td === dataRow.cells[dataRow.cells.length - 1]) return; /* last col = action */
            var rowKeyVal = String(dataRow.getAttribute('data-row-key') || '');
            if (!rowKeyVal) return;
            /* Simulate expand btn click */
            var eb = dataRow.querySelector('.vr-expand-btn');
            if (eb) eb.click();
        });
    }

    /* Pager */
    if (pagerEl) {
        pagerEl.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-page]') : null;
            if (!btn || btn.disabled || btn.classList.contains('active')) return;
            var p = parseInt(String(btn.getAttribute('data-page') || '1'), 10) || 1;
            state.page = p;
            renderRows();
        });
    }

    /* Search */
    var searchTimer = null;
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(function () {
                state.search = String(searchEl.value || '').trim();
                state.page   = 1;
                renderRows();
            }, 220);
        });
    }

    /* Page size */
    if (pageSizeEl) {
        pageSizeEl.addEventListener('change', function () {
            state.pageSize = parseInt(String(pageSizeEl.value || '25'), 10) || 25;
            state.page     = 1;
            renderRows();
        });
    }

    /* Column sort */
    if (tableEl) {
        tableEl.querySelector('thead').addEventListener('click', function (e) {
            var th = e.target && e.target.closest ? e.target.closest('th[data-col]') : null;
            if (!th) return;
            var col = String(th.getAttribute('data-col') || '');
            if (!col) return;
            if (state.sortCol === col) {
                state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
            } else {
                state.sortCol = col;
                state.sortDir = 'asc';
            }
            state.page = 1;
            renderRows();
        });
    }

    /* Refresh */
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            state.expandedKeys = {};
            checkDashboardVersion({ forceRefresh: true });
        });
    }

    /* Visibility / focus polling */
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'hidden') checkDashboardVersion();
    });
    window.addEventListener('focus',    function () { checkDashboardVersion(); });
    window.addEventListener('pageshow', function () { checkDashboardVersion(); });

    /* ── Boot ─────────────────────────────────────────────────────── */
    if (!hydrateFromCache()) {
        checkDashboardVersion({ forceRefresh: true });
    } else {
        checkDashboardVersion();
    }
    startPolling();
});
