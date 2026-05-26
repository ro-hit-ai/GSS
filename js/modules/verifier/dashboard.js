document.addEventListener('DOMContentLoaded', function () {
    var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
    var messageEl = document.getElementById('vrBoardMessage');
    var rowsHost = document.getElementById('vrBoardRows');
    var refreshBtn = document.getElementById('vrBoardRefreshBtn');
    var bucketHost = document.getElementById('vrBucketChips');
    var familyHost = document.getElementById('vrFamilyChips');
    var compatNoteEl = document.getElementById('vrBoardCompatNote');

    var state = {
        rows: [],
        bucket: 'pending',
        family: 'all',
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
        if (key === 'pending') return 'Pending';
        if (key === 'completed') return 'Completed';
        if (key === 'followup') return 'Follow Up';
        if (key === 'insuff_docs') return 'Insuff. Docs';
        return key || '-';
    }

    function claimStateLabel(row) {
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        if (s === 'available') return 'Available';
        if (s === 'mine_active') return 'My Active Case';
        if (s === 'claimed_by_other') return 'Claimed By Other';
        if (s === 'followup') return 'Follow Up';
        if (s === 'completed') return 'Completed';
        return '-';
    }

    function rowClass(row) {
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        var out = ['vr-board-row', 'vr-board-data-row'];
        if (s === 'mine_active' || s === 'followup') out.push('is-mine');
        if (s === 'claimed_by_other') out.push('is-locked');
        if (s === 'completed') out.push('is-completed');
        if ((row && row.can_claim) || (row && row.can_open)) out.push('is-clickable');
        return out.join(' ');
    }

    function stateBadgeHtml(row) {
        var s = String(row && row.row_state ? row.row_state : '').toLowerCase().trim();
        var cls = s;
        if (s === 'followup') cls = 'followup_state';
        if (s === 'completed') cls = 'completed_state';
        return '<span class="vr-badge ' + esc(cls) + '">' + esc(claimStateLabel(row)) + '</span>';
    }

    function bucketBadgeHtml(row) {
        var bucket = String(row && row.board_bucket ? row.board_bucket : '').toLowerCase().trim();
        return '<span class="vr-badge ' + esc(bucket) + '">' + esc(bucketLabel(bucket)) + '</span>';
    }

    function filteredRows() {
        return state.rows.filter(function (row) {
            var bucketOk = String(row.board_bucket || '').toLowerCase() === state.bucket;
            var families = Array.isArray(row.family_keys) ? row.family_keys : [];
            var familyOk = state.family === 'all' || families.indexOf(state.family) >= 0;
            return bucketOk && familyOk;
        });
    }

    function updateChipLabels() {
        if (!bucketHost) return;
        var counts = { pending: 0, completed: 0, followup: 0, insuff_docs: 0 };
        state.rows.forEach(function (row) {
            var familyOk = state.family === 'all' || String(row.family_key || '').toLowerCase() === state.family;
            if (!familyOk) return;
            var key = String(row.board_bucket || '').toLowerCase().trim();
            if (Object.prototype.hasOwnProperty.call(counts, key)) counts[key] += 1;
        });
        Array.prototype.slice.call(bucketHost.querySelectorAll('[data-bucket]')).forEach(function (btn) {
            var key = String(btn.getAttribute('data-bucket') || '').toLowerCase().trim();
            var baseLabel = bucketLabel(key).toUpperCase();
            btn.textContent = baseLabel + ' (' + String(counts[key] || 0) + ')';
            btn.classList.toggle('active', key === state.bucket);
        });
        if (compatNoteEl) compatNoteEl.style.display = state.rows.length ? 'block' : 'none';
    }

    function updateFamilyLabels() {
        if (!familyHost) return;
        Array.prototype.slice.call(familyHost.querySelectorAll('[data-family]')).forEach(function (btn) {
            var key = String(btn.getAttribute('data-family') || '').toLowerCase().trim();
            btn.classList.toggle('active', key === state.family);
        });
    }

    function renderRows() {
        if (!rowsHost) return;
        updateChipLabels();
        updateFamilyLabels();
        var rows = filteredRows();
        if (!rows.length) {
            rowsHost.innerHTML = '<div class="vr-empty">No cases in this bucket for the selected family.</div>';
            return;
        }
        rowsHost.innerHTML = rows.map(function (row) {
            var key = String(row.queue_row_id || '') + '|' + String(row.case_id || '');
            var actionHtml = '';
            if (row.can_claim) {
                var disabled = state.claimInFlightKey && state.claimInFlightKey !== key;
                actionHtml = '<button type="button" class="vr-action-btn claim" data-action="claim" data-row-key="' + esc(key) + '" data-case-id="' + esc(String(row.case_id || '')) + '"' + (disabled ? ' disabled' : '') + '>Claim</button>';
            } else if (row.can_open) {
                actionHtml = '<button type="button" class="vr-action-btn ' + (String(row.row_state || '') === 'completed' ? 'view' : 'open') + '" data-action="open" data-url="' + esc(String(row.open_url || '')) + '">' + (String(row.row_state || '') === 'completed' ? 'View' : 'Open') + '</button>';
            } else {
                actionHtml = '<button type="button" class="vr-action-btn" disabled>Readonly</button>';
            }
            var claimedBy = String(row.assigned_user_name || '').trim();
            if (!claimedBy) claimedBy = '-';
            var claimedMeta = '';
            if (String(row.claimed_at || '').trim()) {
                claimedMeta = '<div class="vr-board-muted">' + esc(fmtDateTime(row.claimed_at)) + '</div>';
            } else if (String(row.completed_at || '').trim()) {
                claimedMeta = '<div class="vr-board-muted">Completed ' + esc(fmtDateTime(row.completed_at)) + '</div>';
            }
            return ''
                + '<div class="' + esc(rowClass(row)) + '" data-row-key="' + esc(key) + '" data-actionable="' + (row.can_claim || row.can_open ? '1' : '0') + '">'
                +   '<div class="vr-board-cell"><div class="vr-board-case">' + esc(row.application_id || '-') + '</div><div class="vr-board-muted">' + esc(String(row.workflow_mode || '').replace(/_/g, ' ')) + '</div></div>'
                +   '<div class="vr-board-cell"><div class="vr-board-name">' + esc(((row.candidate_first_name || '') + ' ' + (row.candidate_last_name || '')).trim() || '-') + '</div></div>'
                +   '<div class="vr-board-cell"><div>' + esc(row.component_summary_text || '-') + '</div><div class="vr-board-muted">' + esc((row.component_summary || []).map(function (it) { return it.label || ''; }).filter(Boolean).join(' | ')) + '</div></div>'
                +   '<div class="vr-board-cell">' + bucketBadgeHtml(row) + '</div>'
                +   '<div class="vr-board-cell">' + stateBadgeHtml(row) + '</div>'
                +   '<div class="vr-board-cell"><div>' + esc(claimedBy) + '</div>' + claimedMeta + '</div>'
                +   '<div class="vr-board-cell">' + actionHtml + '</div>'
                + '</div>';
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

    function claimRow(caseId, rowKey) {
        if (state.claimInFlightKey) return;
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
                    throw new Error((out.data && out.data.message) ? out.data.message : 'Unable to claim case');
                }
                setMessage('Case claimed successfully. You can open it now.', 'success');
                return loadBoard();
            })
            .catch(function (err) {
                setMessage((err && err.message) ? err.message : 'Unable to claim case', 'warning');
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
            state.bucket = String(btn.getAttribute('data-bucket') || 'pending').toLowerCase();
            renderRows();
        });
    }

    if (familyHost) {
        familyHost.addEventListener('click', function (e) {
            var btn = e.target && e.target.closest ? e.target.closest('[data-family]') : null;
            if (!btn) return;
            state.family = String(btn.getAttribute('data-family') || 'all').toLowerCase();
            renderRows();
        });
    }

    if (rowsHost) {
        rowsHost.addEventListener('click', function (e) {
            var actionBtn = e.target && e.target.closest ? e.target.closest('[data-action]') : null;
            if (actionBtn) {
                var action = String(actionBtn.getAttribute('data-action') || '');
                if (action === 'claim') {
                    claimRow(parseInt(String(actionBtn.getAttribute('data-case-id') || '0'), 10) || 0, String(actionBtn.getAttribute('data-row-key') || ''));
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
            if (row.can_claim) {
                claimRow(parseInt(String(row.case_id || '0'), 10) || 0, rowKey);
                return;
            }
            if (row.can_open && row.open_url) {
                window.location.assign(String(row.open_url));
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
