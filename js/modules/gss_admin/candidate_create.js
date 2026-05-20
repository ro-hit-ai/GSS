(function () {
    function el(id) {
        return document.getElementById(id);
    }

    var creating = false;

    function setMessage(text, type) {
        var box = el('candidateCreateMessage');
        if (!box) return;
        box.style.display = text ? 'block' : 'none';
        box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
        box.textContent = text || '';
    }

    async function loadClients() {
        var select = el('client_id');
        if (!select) return;

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/gssadmin/clients_dropdown.php';
            var res = await fetch(url);
            var data = await res.json().catch(function () { return null; });

            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : 'Failed to load clients');
            }

            select.innerHTML = '<option value="">-- Select Client --</option>';
            (data.data || []).forEach(function (c) {
                var opt = document.createElement('option');
                opt.value = String(c.client_id);
                opt.textContent = c.customer_name;
                select.appendChild(opt);
            });
        } catch (e) {
            select.innerHTML = '<option value="">Failed to load</option>';
        }
    }

    async function loadJobRolesForClient(clientId) {
        var roleSelect = el('job_role');
        if (!roleSelect) return;

        var cid = parseInt(clientId || '0', 10) || 0;
        roleSelect.innerHTML = '<option value="">-- Select --</option>';

        if (cid <= 0) {
            roleSelect.innerHTML = '<option value="">-- Select Client First --</option>';
            return;
        }

        roleSelect.innerHTML = '<option value="">Loading...</option>';

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/gssadmin/job_roles_list.php?client_id=' + encodeURIComponent(String(cid));
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            roleSelect.innerHTML = '<option value="">-- Select --</option>';
            if (!res.ok || !data || data.status !== 1 || !Array.isArray(data.data)) {
                return;
            }

            (data.data || []).forEach(function (r) {
                if (!r) return;
                if (r.is_active != null && String(r.is_active) === '0') return;

                var name = r.role_name ? String(r.role_name).trim() : '';
                var id = r.job_role_id != null ? String(r.job_role_id) : '';
                if (!name) return;

                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                if (id) opt.dataset.jobRoleId = id;
                roleSelect.appendChild(opt);
            });
        } catch (e) {
            roleSelect.innerHTML = '<option value="">-- Select --</option>';
        }
    }

    function setMappingPreviewHtml(html) {
        var box = el('jobRoleMappingPreview');
        if (!box) return;
        box.innerHTML = html;
    }

    function stageLabel(stageKey) {
        var sk = String(stageKey || '').trim().toLowerCase();
        if (sk === 'pre_interview' || sk === 'p1') return 'P1 - Pre Interview';
        if (sk === 'post_interview' || sk === 'p2') return 'P2 - Post Interview';
        if (sk === 'employee_pool' || sk === 'p3') return 'P3 - Current Employee Pool';
        return String(stageKey || '').replace(/_/g, ' ');
    }

    function setLevelOptions(levels) {
        var levelSelect = el('selected_level');
        if (!levelSelect) return;
        var arr = Array.isArray(levels) ? levels.filter(function (x) { return !!x; }) : [];
        levelSelect.innerHTML = '';
        if (!arr.length) {
            levelSelect.innerHTML = '<option value="">-- No job level configured --</option>';
            return;
        }
        levelSelect.innerHTML = '<option value="">-- Select Job Level --</option>';
        arr.forEach(function (lk) {
            var val = String(lk || '').trim();
            if (!val) return;
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val.toUpperCase();
            levelSelect.appendChild(opt);
        });
        if (arr.length === 1) levelSelect.value = String(arr[0]);
    }

    function setStageOptions(stages) {
        var stageSelect = el('stage_key');
        if (!stageSelect) return;
        var arr = Array.isArray(stages) ? stages.filter(function (x) { return !!x; }) : [];
        stageSelect.innerHTML = '';
        if (!arr.length) {
            stageSelect.innerHTML = '<option value="">-- No stage configured --</option>';
            return;
        }
        stageSelect.innerHTML = '<option value="">-- Select Stage --</option>';
        arr.forEach(function (sk) {
            var val = String(sk || '').trim();
            if (!val) return;
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = stageLabel(val);
            stageSelect.appendChild(opt);
        });
        if (arr.length === 1) stageSelect.value = String(arr[0]);
    }

    async function refreshMappingPreview() {
        var roleSelect = el('job_role');
        var clientSelect = el('client_id');
        if (!roleSelect) return;

        var opt = roleSelect.options[roleSelect.selectedIndex] || null;
        var jobRoleId = opt && opt.dataset ? (opt.dataset.jobRoleId || '') : '';
        var cid = clientSelect ? (clientSelect.value || '') : '';

        var levelSelect = el('selected_level');
        var stageSelect = el('stage_key');
        var selectedLevel = levelSelect ? String(levelSelect.value || '').trim().toLowerCase() : '';
        var selectedStage = stageSelect ? String(stageSelect.value || '').trim().toLowerCase() : '';

        if (!cid || !jobRoleId) {
            setLevelOptions([]);
            setStageOptions([]);
            setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Select a job role, job level and stage to view mapped verification checks.</div>');
            return;
        }

        setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Loading mapping...</div>');

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/shared/job_role_verification_preview.php?client_id=' + encodeURIComponent(String(cid))
                + '&job_role_id=' + encodeURIComponent(jobRoleId);
            if (selectedLevel) url += '&level_key=' + encodeURIComponent(selectedLevel);
            if (selectedStage) url += '&stage_key=' + encodeURIComponent(selectedStage);
            url += '&t=' + Date.now();
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            if (!res.ok || !data || data.status !== 1 || !data.data || !data.data.stages) {
                throw new Error((data && data.message) ? data.message : 'Failed to load mapping');
            }

            var availableLevels = Array.isArray(data.data.available_levels) ? data.data.available_levels : [];
            var availableStages = Array.isArray(data.data.available_stages) ? data.data.available_stages : [];
            setLevelOptions(availableLevels);
            setStageOptions(availableStages);

            var stages = data.data.stages || {};
            var stageKeys = Object.keys(stages);
            if (!stageKeys.length) {
                setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">No mapping found for selected role/job level/stage.</div>');
                return;
            }

            var html = '';
            stageKeys.forEach(function (sk) {
                var arr = stages[sk] || [];
                if (!Array.isArray(arr) || !arr.length) return;
                html += '<div style="margin-bottom:10px;">';
                html += '<div style="font-weight:700; font-size:12px; color:#0f172a;">' + stageLabel(sk) + '</div>';
                html += '<div style="margin-top:6px; display:flex; flex-wrap:wrap; gap:6px;">';
                arr.forEach(function (s) {
                    var name = s && s.type_name ? String(s.type_name) : '';
                    if (!name) return;
                    html += '<span style="font-size:12px; background:#e2e8f0; color:#0f172a; border-radius:999px; padding:4px 8px;">' + name + '</span>';
                });
                html += '</div>';
                html += '</div>';
            });

            setMappingPreviewHtml(html || '<div class="text-muted" style="font-size:12px;">No mapping found for selected role/job level/stage.</div>');
        } catch (e) {
            setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Unable to load mapping.</div>');
        }
    }

    async function loadJoiningLocationsForClient(clientId) {
        var locationSelect = el('joining_location');
        if (!locationSelect) return;

        var cid = parseInt(clientId || '0', 10) || 0;
        locationSelect.innerHTML = '<option value="">-- Select --</option>';

        if (cid <= 0) {
            locationSelect.innerHTML = '<option value="">-- Select Client First --</option>';
            return;
        }

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/gssadmin/client_locations_list.php?client_id=' + encodeURIComponent(String(cid));
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            if (!res.ok || !data || data.status !== 1 || !Array.isArray(data.data)) {
                throw new Error((data && data.message) ? data.message : 'Failed to load locations');
            }

            var rows = data.data || [];
            if (!rows.length) {
                locationSelect.innerHTML = '<option value="">No locations found</option>';
                return;
            }

            locationSelect.innerHTML = '<option value="">-- Select --</option>';
            rows.forEach(function (r) {
                if (!r) return;
                if (r.is_active != null && String(r.is_active) === '0') return;
                var name = r.location_name ? String(r.location_name).trim() : '';
                if (!name) return;
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                locationSelect.appendChild(opt);
            });
        } catch (e) {
            locationSelect.innerHTML = '<option value="">Failed to load locations</option>';
        }
    }

    async function createCaseAndInvite() {
        if (creating) return;
        var form = document.getElementById('candidateCreateForm');
        if (!form) return;

        setMessage('', '');

        creating = true;

        var btn = el('btnCandidateSave');
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Creating...';
        }

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var createUrl = base + '/api/client_admin/create_case.php';

            var fd = new FormData(form);

            var res = await fetch(createUrl, { method: 'POST', body: fd });
            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : 'Failed to create case');
            }

            var caseId = data && data.data ? data.data.case_id : 0;
            if (!caseId) throw new Error('case_id missing');

            var msg = data.message || 'Case created.';
            if (data.data && data.data.invite_url) {
                msg += ' Invite Link: ' + data.data.invite_url;
            }

            setMessage(msg, 'success');
            form.reset();
            await loadClients();
            await loadJoiningLocationsForClient(0);
        } catch (e) {
            setMessage(e && e.message ? e.message : 'Failed.', 'error');
        } finally {
            creating = false;
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || 'Create Case & Send Invite';
            }
        }
    }

    function init() {
        loadClients().then(function () {
            var clientSelect = el('client_id');
            if (clientSelect && clientSelect.value) {
                return loadJoiningLocationsForClient(clientSelect.value).then(function () {
                    return loadJobRolesForClient(clientSelect.value);
                }).then(refreshMappingPreview);
            }
            return loadJoiningLocationsForClient(0).then(function () {
                return loadJobRolesForClient(0);
            }).then(refreshMappingPreview);
        });
        setLevelOptions([]);
        setStageOptions([]);

        var clientSelect = el('client_id');
        if (clientSelect) {
            clientSelect.addEventListener('change', function () {
                loadJoiningLocationsForClient(clientSelect.value)
                    .then(function () { return loadJobRolesForClient(clientSelect.value); })
                    .then(refreshMappingPreview);
            });
        }

        var roleSelect = el('job_role');
        if (roleSelect) {
            roleSelect.addEventListener('change', refreshMappingPreview);
        }
        var levelSelect = el('selected_level');
        if (levelSelect) {
            levelSelect.addEventListener('change', refreshMappingPreview);
        }
        var stageSelect = el('stage_key');
        if (stageSelect) {
            stageSelect.addEventListener('change', refreshMappingPreview);
        }

        var btn = el('btnCandidateSave');
        if (btn) btn.addEventListener('click', createCaseAndInvite);

        var cancel = el('btnCandidateCancel');
        if (cancel) cancel.addEventListener('click', function () {
            window.location.href = 'dashboard.php';
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
