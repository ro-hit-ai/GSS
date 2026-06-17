(function () {
    function el(id) {
        return document.getElementById(id);
    }

    var saving = false;

    async function loadClientLocations() {
        var locationSelect = el('joining_location');
        if (!locationSelect) return;

        locationSelect.innerHTML = '<option value="">Loading...</option>';

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/client_admin/client_locations_list.php';

            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            locationSelect.innerHTML = '<option value="">-- Select --</option>';

            if (!res.ok || !data || data.status !== 1 || !Array.isArray(data.data)) {
                return;
            }

            data.data.forEach(function (r) {
                var name = (r && r.location_name) ? String(r.location_name) : '';
                if (!name) return;
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                locationSelect.appendChild(opt);
            });
        } catch (e) {
            locationSelect.innerHTML = '<option value="">-- Select --</option>';
        }
    }

    async function loadJobRoles() {
        var roleSelect = el('job_role');
        if (!roleSelect) return;

        roleSelect.innerHTML = '<option value="">Loading...</option>';

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/client_admin/job_roles_list.php';

            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            roleSelect.innerHTML = '<option value="">-- Select --</option>';
            if (!res.ok || !data || data.status !== 1 || !Array.isArray(data.data)) {
                return;
            }

            data.data.forEach(function (r) {
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
            levelSelect.disabled = false;
            return;
        }

        levelSelect.disabled = false;
        levelSelect.innerHTML = '<option value="">-- Select Job Level --</option>';
        arr.forEach(function (lk) {
            var val = String(lk || '').trim();
            if (!val) return;
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = val.toUpperCase();
            levelSelect.appendChild(opt);
        });

        if (arr.length === 1) {
            levelSelect.value = String(arr[0]);
        }
    }

    function setStageOptions(stages) {
        var stageSelect = el('stage_key');
        if (!stageSelect) return;
        var arr = Array.isArray(stages) ? stages.filter(function (x) { return !!x; }) : [];
        stageSelect.innerHTML = '';

        if (!arr.length) {
            stageSelect.innerHTML = '<option value="">-- No stage configured --</option>';
            stageSelect.disabled = false;
            return;
        }

        stageSelect.disabled = false;
        stageSelect.innerHTML = '<option value="">-- Select Stage --</option>';
        arr.forEach(function (sk) {
            var val = String(sk || '').trim();
            if (!val) return;
            var opt = document.createElement('option');
            opt.value = val;
            opt.textContent = stageLabel(val);
            stageSelect.appendChild(opt);
        });

        if (arr.length === 1) {
            stageSelect.value = String(arr[0]);
        }
    }

    async function refreshMappingPreview(opts) {
        opts = opts || {};
        var roleSelect = el('job_role');
        if (!roleSelect) return;
        var levelSelect = el('selected_level');
        var stageSelect = el('stage_key');

        var opt = roleSelect.options[roleSelect.selectedIndex] || null;
        var jobRoleId = opt && opt.dataset ? (opt.dataset.jobRoleId || '') : '';
        var selectedLevel = levelSelect ? String(levelSelect.value || '').trim().toLowerCase() : '';
        var selectedStage = stageSelect ? String(stageSelect.value || '').trim().toLowerCase() : '';

        if (!jobRoleId) {
            setLevelOptions([]);
            setStageOptions([]);
            setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Select a job role to view mapped verification checks.</div>');
            return;
        }

        setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Loading mapping...</div>');

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/shared/case_management/job_role_verification_preview.php?job_role_id=' + encodeURIComponent(jobRoleId);
            if (selectedLevel) {
                url += '&level_key=' + encodeURIComponent(selectedLevel);
            }
            if (selectedStage) {
                url += '&stage_key=' + encodeURIComponent(selectedStage);
            }
            url += '&t=' + Date.now();
            var res = await fetch(url, { credentials: 'same-origin' });
            var data = await res.json().catch(function () { return null; });

            if (!res.ok || !data || data.status !== 1 || !data.data || !data.data.stages) {
                throw new Error((data && data.message) ? data.message : 'Failed to load mapping');
            }

            var availableLevels = Array.isArray(data.data.available_levels) ? data.data.available_levels : [];
            var availableStages = Array.isArray(data.data.available_stages) ? data.data.available_stages : [];
            if (!opts.skipLevelSync) {
                setLevelOptions(availableLevels);
                if (levelSelect) {
                    var lv = String(levelSelect.value || '').trim().toLowerCase();
                    if (!selectedLevel && availableLevels.length === 1 && lv) {
                        return refreshMappingPreview({ skipLevelSync: true });
                    }
                }
            }
            if (!opts.skipStageSync) {
                setStageOptions(availableStages);
                if (stageSelect) {
                    var sv = String(stageSelect.value || '').trim().toLowerCase();
                    if (!selectedStage && availableStages.length === 1 && sv) {
                        return refreshMappingPreview({ skipLevelSync: true, skipStageSync: true });
                    }
                }
            }

            var stages = data.data.stages || {};
            var stageKeys = Object.keys(stages);
            if (!stageKeys.length) {
                setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">No mapping found for selected role.</div>');
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

            setMappingPreviewHtml(html || '<div class="text-muted" style="font-size:12px;">No mapping found for selected role.</div>');
        } catch (e) {
            setMappingPreviewHtml('<div class="text-muted" style="font-size:12px;">Unable to load mapping.</div>');
        }
    }

    function setMessage(text, type) {
        var box = el('candidateCreateMessage');
        if (!box) return;

        box.style.display = text ? 'block' : 'none';
        box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
        box.textContent = text || '';
    }

    function formToFormData(form) {
        return new FormData(form);
    }

    async function saveCandidate() {
        if (saving) return;
        var form = document.getElementById('candidateCreateForm');
        if (!form) return;

        setMessage('', '');

        saving = true;

        var btn = el('btnCandidateSave');
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Saving...';
        }

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var createUrl = base + '/api/client_admin/create_case.php';

            var res = await fetch(createUrl, {
                method: 'POST',
                body: formToFormData(form)
            });

            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                var msg = (data && data.message) ? data.message : 'Failed to save candidate.';
                throw new Error(msg);
            }

            var caseId = data && data.data ? data.data.case_id : 0;
            if (!caseId) {
                throw new Error('Case created but case_id missing.');
            }

            var outMsg = data.message || 'Case created.';
            if (data.data && data.data.invite_url) {
                outMsg += ' Invite Link: ' + data.data.invite_url;
            }

            setMessage(outMsg, 'success');
            form.reset();
        } catch (e) {
            setMessage(e && e.message ? e.message : 'Failed to save candidate.', 'error');
        } finally {
            saving = false;
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || 'Save';
            }
        }
    }

    function init() {
        var btn = el('btnCandidateSave');
        if (btn) {
            btn.addEventListener('click', saveCandidate);
        }

        loadClientLocations();
        setLevelOptions([]);
        setStageOptions([]);
        loadJobRoles().then(refreshMappingPreview);

        var roleSelect = el('job_role');
        if (roleSelect) {
            roleSelect.addEventListener('change', refreshMappingPreview);
        }
        var levelSelect = el('selected_level');
        if (levelSelect) {
            levelSelect.addEventListener('change', function () {
                refreshMappingPreview({ skipLevelSync: true, skipStageSync: false });
            });
        }
        var stageSelect = el('stage_key');
        if (stageSelect) {
            stageSelect.addEventListener('change', function () {
                refreshMappingPreview({ skipLevelSync: true, skipStageSync: true });
            });
        }

        var cancel = el('btnCandidateCancel');
        if (cancel) {
            cancel.addEventListener('click', function () {
                window.location.href = 'dashboard.php';
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
