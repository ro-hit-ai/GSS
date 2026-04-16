document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('clientCreateForm');
    var finalSubmitBtn = document.getElementById('clientCreateFinalSubmitBtn');
    var messageEl = document.getElementById('clientCreateMessage');

    var verificationTabBtn = document.getElementById('clientVerificationTab');
    var verificationMsgEl = document.getElementById('clientVerificationMessage');

    var jobRoleNewEl = document.getElementById('cv_jobrole_new');
    var jobRoleAddBtn = document.getElementById('cv_jobrole_add_btn');
    var jobRoleBoxEl = document.getElementById('cv_jobrole_box');
    var levelNewEl = document.getElementById('cv_level_new');
    var levelAddBtn = document.getElementById('cv_level_add_btn');
    var levelBoxEl = document.getElementById('cv_level_box');
    var stageSelectEl = document.getElementById('cv_stage_box');
    var typesHostEl = document.getElementById('cv_types_box');
    var tatCostHostEl = document.getElementById('cv_tat_cost_box');
    var typesSaveBtn = document.getElementById('cv_verification_save_btn');

    var summaryEl = document.getElementById('cv_vp_summary');

    var SYSTEM_TYPES = [];
    var LEVELS = ['L1', 'L2', 'L3', 'L4'];

    // Per base stage key, store type IDs in the order they were added (drag/drop or checkbox)
    var STAGE_TYPE_ORDER = {
        pre_interview: [],
        post_interview: [],
        employee_pool: []
    };

    var lastTypesContextKey = '';

    var lastTatCostLoadKey = '';
    // TAT_COST[level_key][job_role_id][verification_type_id] = { internal_tat_value, ... }
    var TAT_COST = {};
    var TAT_COST_SHOW_ALL_TYPES = false;

    var clientIdField = document.getElementById('clientIdField');
    var customerLogoPathField = document.getElementById('customerLogoPathField');
    var customerLogoInput = document.getElementById('customerLogoInput');
    var customerLogoPreview = document.getElementById('customerLogoPreview');
    var customerLogoPreviewPlaceholder = document.getElementById('customerLogoPreviewPlaceholder');
    var sowPdfPathField = document.getElementById('sowPdfPathField');
    var sowPdfInput = document.getElementById('sowPdfInput');
    var sowPdfCurrent = document.getElementById('sowPdfCurrent');

    function getQueryParam(name) {
        try {
            var params = new URLSearchParams(window.location.search || '');
            return params.get(name);
        } catch (e) {
            return null;
        }
    }

    var urlClientId = parseInt(getQueryParam('client_id') || '0', 10) || 0;
    if (clientIdField && urlClientId > 0) {
        clientIdField.value = String(urlClientId);
    }

    // Enable verification tab in edit mode
    if (urlClientId > 0) {
        setVerificationClientId(urlClientId);
    } else {
        setVerificationClientId(0);
    }

    var STORAGE_KEY = 'gss_client_create_draft_v1_' + (urlClientId > 0 ? String(urlClientId) : 'new');

    function setMessage(text, type) {
        if (!messageEl) return;
        messageEl.textContent = (typeof text === 'string') ? text : (text ? JSON.stringify(text) : '');
        messageEl.className = type ? ('alert alert-' + type) : '';
        messageEl.style.display = text ? 'block' : 'none';
    }

    function setVerificationMessage(text, type) {
        if (!verificationMsgEl) return;
        try {
            if (verificationMsgEl.dataset.hideTimer) {
                clearTimeout(parseInt(verificationMsgEl.dataset.hideTimer || '0', 10) || 0);
                verificationMsgEl.dataset.hideTimer = '';
            }
        } catch (e) {
        }
        verificationMsgEl.textContent = text || '';
        verificationMsgEl.className = type ? ('alert alert-' + type) : 'alert';
        verificationMsgEl.style.display = text ? 'block' : 'none';

        // Auto-hide for non-error messages
        var t = String(type || '');
        if (text && (t === 'success' || t === 'info')) {
            try {
                var id = setTimeout(function () {
                    try {
                        verificationMsgEl.style.display = 'none';
                        verificationMsgEl.textContent = '';
                    } catch (_e) {
                    }
                }, 3500);
                verificationMsgEl.dataset.hideTimer = String(id);
            } catch (e2) {
            }
        }
    }

    function scrollToEl(el) {
        if (!el) return;
        try {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            try {
                el.scrollIntoView(true);
            } catch (_e) {
            }
        }
    }

    function setActiveTab(key) {
        document.querySelectorAll('.tab').forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === key);
        });
        document.querySelectorAll('.tab-panel').forEach(function (p) {
            p.classList.toggle('active', p.id === ('tab-' + key));
        });
    }

    function setVerificationClientId(clientId) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (verificationTabBtn) {
            verificationTabBtn.classList.toggle('is-disabled', cid <= 0);
            verificationTabBtn.setAttribute('aria-disabled', cid <= 0 ? 'true' : 'false');
        }

        if (cid > 0) {
            loadJobRoles(cid);
        } else {
            if (typesHostEl) typesHostEl.innerHTML = '';
            if (jobRoleBoxEl) jobRoleBoxEl.innerHTML = '';
        }
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function strContainsCi(haystack, needle) {
        return String(haystack || '').toLowerCase().indexOf(String(needle || '').toLowerCase()) !== -1;
    }

    function formatVerificationTypeLabel(typeName, typeCategory) {
        var name = String(typeName || '').trim();
        var category = String(typeCategory || '').trim();
        var hay = (name + ' ' + category).toLowerCase();
        var isReference = (
            strContainsCi(hay, 'reference')
            || strContainsCi(hay, 'referee')
            || strContainsCi(hay, 'ref check')
            || strContainsCi(hay, 'ref-check')
        );

        if (isReference) {
            var isEducationReference = (
                strContainsCi(hay, 'education')
                || strContainsCi(hay, 'qualification')
                || strContainsCi(hay, 'degree')
                || strContainsCi(hay, 'college')
                || strContainsCi(hay, 'university')
            );
            if (isEducationReference) return 'Education Reference';

            var isEmploymentReference = (
                strContainsCi(hay, 'employment')
                || strContainsCi(hay, 'employee')
                || strContainsCi(hay, 'employer')
                || strContainsCi(hay, 'experience')
                || strContainsCi(hay, 'work history')
            );
            if (isEmploymentReference) return 'Employment Reference';
        }

        return name;
    }

    function getAdminVerificationTypeLabel(t) {
        if (!t) return '';
        var rawName = String(t.type_name || '').trim();
        if (rawName) return rawName;
        return String(t.display_label || '').trim() || formatVerificationTypeLabel(t.type_name || '', t.type_category || '');
    }

    function setTatCostMessage(text) {
        if (!tatCostHostEl) return;
        tatCostHostEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">' + escapeHtml(text || '') + '</div>';
    }

    function getActiveTatLevel(levels) {
        if (!Array.isArray(levels) || !levels.length) return '';
        return String(levels[0] || '').trim();
    }

    function ensureTatCostLevel(levelKey) {
        var lk = String(levelKey || '').trim();
        if (!lk) return null;
        if (!TAT_COST[lk]) TAT_COST[lk] = {};
        return TAT_COST[lk];
    }

    function ensureTatCostLevelRole(levelKey, jobRoleId) {
        var lk = String(levelKey || '').trim();
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (!lk) return null;
        var lvl = ensureTatCostLevel(lk);
        if (!lvl) return null;
        var k = String(jrId);
        if (!lvl[k]) lvl[k] = {};
        return lvl[k];
    }

    function normalizeTatUnit(u) {
        var raw = String(u || '').toLowerCase().trim();
        if (raw === 'hour' || raw === 'hours' || raw === 'hr' || raw === 'hrs') return 'hours';
        return 'days';
    }

    function loadTatCost(clientId, levelKey, jobRoleId) {
        var cid = parseInt(clientId || '0', 10) || 0;
        var lk = String(levelKey || '').trim();
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (cid <= 0 || !lk) return Promise.resolve({});
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/client_type_tat_cost_get.php?client_id=' + encodeURIComponent(String(cid))
            + '&level_key=' + encodeURIComponent(lk)
            + '&job_role_id=' + encodeURIComponent(String(jrId));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load TAT & Cost');
                }
                var map = ensureTatCostLevelRole(lk, jrId) || {};
                data.data.forEach(function (r) {
                    var vtId = r && r.verification_type_id ? (parseInt(r.verification_type_id || '0', 10) || 0) : 0;
                    if (vtId <= 0) return;

                    // New schema: internal/external fields; legacy schema: tat_value/tat_unit.
                    var iVal = (typeof r.internal_tat_value !== 'undefined') ? r.internal_tat_value : (r.tat_value || '');
                    var iUnit = (typeof r.internal_tat_unit !== 'undefined') ? r.internal_tat_unit : (r.tat_unit || 'days');
                    var eVal = (typeof r.external_tat_value !== 'undefined') ? r.external_tat_value : '';
                    var eUnit = (typeof r.external_tat_unit !== 'undefined') ? r.external_tat_unit : 'days';

                    map[String(vtId)] = {
                        internal_tat_value: (typeof iVal !== 'undefined' && iVal !== null) ? String(iVal) : '',
                        internal_tat_unit: normalizeTatUnit(iUnit || 'days'),
                        external_tat_value: (typeof eVal !== 'undefined' && eVal !== null) ? String(eVal) : '',
                        external_tat_unit: normalizeTatUnit(eUnit || 'days'),
                        cost_inr: (typeof r.cost_inr !== 'undefined' && r.cost_inr !== null) ? String(r.cost_inr) : ''
                    };
                });
                return map;
            })
            .catch(function () {
                return ensureTatCostLevelRole(lk, jrId) || {};
            });
    }

    function syncInlineTatCost(levelKey, jobRoleId) {
        var lk = String(levelKey || '').trim();
        if (!lk || !typesHostEl) return;
        var map = ensureTatCostLevelRole(lk, jobRoleId) || {};

        typesHostEl.querySelectorAll('.cv-type-row[data-vt-id]').forEach(function (row) {
            var vtId = parseInt(row.getAttribute('data-vt-id') || '0', 10) || 0;
            if (vtId <= 0) return;
            var curr = map[String(vtId)] || {};

            var iTatVal = (typeof curr.internal_tat_value !== 'undefined')
                ? String(curr.internal_tat_value || '')
                : (typeof curr.tat_value !== 'undefined' ? String(curr.tat_value || '') : '');
            var iTatUnit = normalizeTatUnit(curr.internal_tat_unit || curr.tat_unit || 'days');
            var eTatVal = (typeof curr.external_tat_value !== 'undefined') ? String(curr.external_tat_value || '') : '';
            var eTatUnit = normalizeTatUnit(curr.external_tat_unit || 'days');
            var cost = (typeof curr.cost_inr !== 'undefined') ? String(curr.cost_inr || '') : '';

            var itv = row.querySelector('.cv_it_tat_val');
            var itu = row.querySelector('.cv_it_tat_unit');
            var etv = row.querySelector('.cv_et_tat_val');
            var etu = row.querySelector('.cv_et_tat_unit');
            var cst = row.querySelector('.cv_cost');

            if (itv) itv.value = iTatVal;
            if (itu) itu.value = iTatUnit;
            if (etv) etv.value = eTatVal;
            if (etu) etu.value = eTatUnit;
            if (cst) cst.value = cost;
        });
    }

    function collectTatCostInputs(activeLevelKey) {
        var lk = String(activeLevelKey || '').trim();
        if (!typesHostEl || !lk) return [];

        var out = [];
        typesHostEl.querySelectorAll('.cv-type-row[data-vt-id]').forEach(function (row) {
            var vtId = parseInt(row.getAttribute('data-vt-id') || '0', 10) || 0;
            if (vtId <= 0) return;

            var iTatValEl = row.querySelector('.cv_it_tat_val');
            var iTatUnitEl = row.querySelector('.cv_it_tat_unit');
            var eTatValEl = row.querySelector('.cv_et_tat_val');
            var eTatUnitEl = row.querySelector('.cv_et_tat_unit');
            var costEl = row.querySelector('.cv_cost');

            var iValRaw = iTatValEl ? String(iTatValEl.value || '').trim() : '';
            var iUnitRaw = iTatUnitEl ? String(iTatUnitEl.value || '').trim() : 'days';
            var eValRaw = eTatValEl ? String(eTatValEl.value || '').trim() : '';
            var eUnitRaw = eTatUnitEl ? String(eTatUnitEl.value || '').trim() : 'days';
            var costRaw = costEl ? String(costEl.value || '').trim() : '';

            if (iValRaw === '' && eValRaw === '' && costRaw === '') return;

            out.push({
                verification_type_id: vtId,
                internal_tat_value: iValRaw,
                internal_tat_unit: normalizeTatUnit(iUnitRaw),
                external_tat_value: eValRaw,
                external_tat_unit: normalizeTatUnit(eUnitRaw),
                cost_inr: costRaw
            });
        });

        var jrIds = getSelectedJobRoles();
        var jrId = jrIds.length ? (parseInt(jrIds[0] || '0', 10) || 0) : 0;
        var map = ensureTatCostLevelRole(lk, jrId) || {};
        out.forEach(function (it) {
            map[String(it.verification_type_id)] = {
                internal_tat_value: String(it.internal_tat_value || ''),
                internal_tat_unit: normalizeTatUnit(it.internal_tat_unit),
                external_tat_value: String(it.external_tat_value || ''),
                external_tat_unit: normalizeTatUnit(it.external_tat_unit),
                cost_inr: String(it.cost_inr || '')
            };
        });

        return out;
    }

    function saveTatCostForLevel(clientId, levelKey, jobRoleId, items) {
        var cid = parseInt(clientId || '0', 10) || 0;
        var lk = String(levelKey || '').trim();
        if (cid <= 0 || !lk) return Promise.resolve(true);
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/client_type_tat_cost_save.php';
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ client_id: cid, level_key: lk, job_role_id: jrId, items: items || [] })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to save TAT & Cost');
                }
                return true;
            });
    }

    function renderTatCost() {
        if (!tatCostHostEl) return;
        setTatCostMessage('Configure Internal TAT, External TAT and Cost inside the Types list.');
    }

    function getSelectedStages() {
        if (!stageSelectEl) return [];
        var cbs = stageSelectEl.querySelectorAll('.cv_stage_cb');
        var out = [];
        cbs.forEach(function (cb) {
            if (cb && cb.checked) {
                var v = String(cb.value || '').trim();
                if (v) out.push(v);
            }
        });
        return out;
    }

    function refreshStagePills() {
        if (!stageSelectEl) return;
        stageSelectEl.querySelectorAll('.cv_stage_cb').forEach(function (cb) {
            try {
                var pill = cb.closest ? cb.closest('.cv-stage-pill') : null;
                if (pill) pill.classList.toggle('is-on', !!cb.checked);
            } catch (e) {
            }
        });

        // Ensure drag/drop bindings remain active
        setupStageDropZones();
    }

    function getActiveStageKey() {
        var stages = getSelectedStages();
        if (!stages.length) return '';
        return String(stages[0] || '').trim();
    }

    function ensureStageOrder(stageKey) {
        var sk = String(stageKey || '').trim();
        if (!sk) return [];
        if (!STAGE_TYPE_ORDER[sk]) STAGE_TYPE_ORDER[sk] = [];
        return STAGE_TYPE_ORDER[sk];
    }

    function addTypeToStageOrder(stageKey, vtId) {
        var sk = String(stageKey || '').trim();
        var id = parseInt(vtId || '0', 10) || 0;
        if (!sk || id <= 0) return;
        var arr = ensureStageOrder(sk);
        var sid = String(id);
        if (arr.indexOf(sid) === -1) arr.push(sid);
    }

    function removeTypeFromStageOrder(stageKey, vtId) {
        var sk = String(stageKey || '').trim();
        var id = parseInt(vtId || '0', 10) || 0;
        if (!sk || id <= 0) return;
        var arr = ensureStageOrder(sk);
        var sid = String(id);
        var idx = arr.indexOf(sid);
        if (idx >= 0) arr.splice(idx, 1);
    }

    function getSelectedLevels() {
        if (!levelBoxEl) return [];
        var out = [];
        levelBoxEl.querySelectorAll('.cv_lvl_cb').forEach(function (cb) {
            if (!cb || !cb.checked) return;
            var v = String(cb.getAttribute('data-lvl') || '').trim();
            if (v) out.push(v);
        });
        return out;
    }

    function getSelectedLevelsSet() {
        var set = {};
        getSelectedLevels().forEach(function (l) {
            set[String(l).toLowerCase()] = true;
        });
        return set;
    }

    function setSelectedStages(stageKeys) {
        if (!stageSelectEl) return;
        var set = {};
        (Array.isArray(stageKeys) ? stageKeys : []).forEach(function (k) {
            var v = String(k || '').trim();
            if (v) set[v] = true;
        });
        stageSelectEl.querySelectorAll('.cv_stage_cb').forEach(function (cb) {
            var v2 = cb ? String(cb.value || '').trim() : '';
            cb.checked = !!(v2 && set[v2]);
        });
        refreshStagePills();
    }

    function getSelectedJobRoles() {
        if (!jobRoleBoxEl) return [];
        var out = [];
        jobRoleBoxEl.querySelectorAll('.cv_jr_cb').forEach(function (cb) {
            if (!cb || !cb.checked) return;
            var id = parseInt(cb.getAttribute('data-jr-id') || '0', 10) || 0;
            if (id > 0) out.push(id);
        });
        return out;
    }

    function clearTypesSelection() {
        if (!typesHostEl) return;
        typesHostEl.querySelectorAll('.cv_vt_cb').forEach(function (cb) {
            cb.checked = false;
        });

        // Reset sequencing for all stages
        STAGE_TYPE_ORDER.pre_interview = [];
        STAGE_TYPE_ORDER.post_interview = [];
        STAGE_TYPE_ORDER.employee_pool = [];
    }

    function applySelectedTypesSet(set) {
        if (!typesHostEl) return;
        var stageKey = getActiveStageKey();
        if (stageKey) ensureStageOrder(stageKey);

        typesHostEl.querySelectorAll('.cv_vt_cb').forEach(function (cb) {
            var id = parseInt(cb.getAttribute('data-vt-id') || '0', 10) || 0;
            var on = !!(id && set && set[String(id)]);
            cb.checked = on;
            if (on && stageKey) {
                addTypeToStageOrder(stageKey, id);
            }
        });

        refreshSelectedTypesChips();
        applyTypesSearch();
    }

    function getTypeNameById(vtId) {
        var id = parseInt(vtId || '0', 10) || 0;
        if (!id) return '';
        for (var i = 0; i < (SYSTEM_TYPES || []).length; i++) {
            var t = SYSTEM_TYPES[i];
            if (!t) continue;
            var tid = parseInt(t.verification_type_id || '0', 10) || 0;
            if (tid === id) return String(t.type_name || '');
        }
        return '';
    }

    function getSelectedTypes() {
        if (!typesHostEl) return [];
        var out = [];
        typesHostEl.querySelectorAll('.cv_vt_cb').forEach(function (cb) {
            if (!cb || !cb.checked) return;
            var id = parseInt(cb.getAttribute('data-vt-id') || '0', 10) || 0;
            if (id > 0) out.push(id);
        });
        return out;
    }

    function refreshSelectedTypesChips() {
        return;
    }

    function setTypeChecked(vtId, checked) {
        if (!typesHostEl) return;
        var cb = typesHostEl.querySelector('.cv_vt_cb[data-vt-id="' + CSS.escape(String(vtId)) + '"]');
        if (!cb) return;
        cb.checked = !!checked;

        try {
            var row = cb.closest ? cb.closest('.cv-type-row') : null;
            if (row) {
                row.querySelectorAll('.cv_it_tat_val,.cv_it_tat_unit,.cv_et_tat_val,.cv_et_tat_unit,.cv_cost,.cv_count')
                    .forEach(function (el) {
                        el.disabled = !cb.checked;
                    });
            }
        } catch (e) {
        }

        var stageKey = getActiveStageKey();
        if (stageKey) {
            if (cb.checked) addTypeToStageOrder(stageKey, vtId);
            else removeTypeFromStageOrder(stageKey, vtId);
        }
    }

    function selectStage(stageKey) {
        if (!stageSelectEl) return;
        var cb = stageSelectEl.querySelector('.cv_stage_cb[value="' + CSS.escape(String(stageKey)) + '"]');
        if (cb) {
            cb.checked = true;
            refreshStagePills();
        }
    }

    function setupStageDropZones() {
        if (!stageSelectEl) return;
        stageSelectEl.querySelectorAll('.cv-stage-pill').forEach(function (pill) {
            pill.addEventListener('dragover', function (e) {
                e.preventDefault();
                pill.classList.add('cv-drop-active');
            });
            pill.addEventListener('dragleave', function () {
                pill.classList.remove('cv-drop-active');
            });
            pill.addEventListener('drop', function (e) {
                e.preventDefault();
                pill.classList.remove('cv-drop-active');

                var stageInput = pill.querySelector('.cv_stage_cb');
                var stageKey = stageInput ? String(stageInput.value || '').trim() : '';
                if (stageKey) {
                    selectStage(stageKey);
                }

                try {
                    var vtId = (e && e.dataTransfer) ? (e.dataTransfer.getData('text/vt-id') || e.dataTransfer.getData('text/plain') || '') : '';
                    vtId = String(vtId || '').trim();
                    if (vtId) {
                        setTypeChecked(vtId, true);
                        if (stageKey) {
                            addTypeToStageOrder(stageKey, vtId);
                        }
                        onSelectionChanged();
                    }
                } catch (err) {
                }
            });
        });
    }

    function setupTypeDraggables() {
        if (!typesHostEl) return;
        typesHostEl.querySelectorAll('.cv-type-row[data-vt-id]').forEach(function (row) {
            row.setAttribute('draggable', 'true');
            row.addEventListener('dragstart', function (e) {
                row.classList.add('cv-dragging');
                var id = row.getAttribute('data-vt-id') || '';
                try {
                    if (e && e.dataTransfer) {
                        e.dataTransfer.effectAllowed = 'copy';
                        e.dataTransfer.setData('text/vt-id', String(id));
                        e.dataTransfer.setData('text/plain', String(id));
                    }
                } catch (err) {
                }
            });
            row.addEventListener('dragend', function () {
                row.classList.remove('cv-dragging');
            });
        });
    }

    function isCountApplicableType(t) {
        if (!t) return false;
        var name = t && t.type_name ? String(t.type_name || '') : '';
        var cat = t && t.type_category ? String(t.type_category || '') : '';
        var key = ((name || '') + ' ' + (cat || '')).toLowerCase();
        var idLike = (
            key.indexOf('identification') !== -1
            || key.indexOf('identity') !== -1
            || key.indexOf(' id ') !== -1
            || key.indexOf('id verification') !== -1
            || key.indexOf('kyc') !== -1
            || key.indexOf('aadhaar') !== -1
            || key.indexOf('aadhar') !== -1
            || key.indexOf('pan') !== -1
            || key.indexOf('passport') !== -1
            || key.indexOf('driving licence') !== -1
            || key.indexOf('driving license') !== -1
        );
        var referenceLike = (
            key.indexOf('reference') !== -1
            || key.indexOf('referee') !== -1
            || key.indexOf('ref check') !== -1
            || key.indexOf('ref-check') !== -1
        );
        var ecourtLike = (
            key.indexOf('ecourt') !== -1
            || key.indexOf('e-court') !== -1
            || key.indexOf('court') !== -1
            || key.indexOf('judis') !== -1
            || key.indexOf('judicial') !== -1
            || key.indexOf('manupatra') !== -1
            || key.indexOf('litigation') !== -1
        );
        var socialLike = (
            key.indexOf('social') !== -1
            || key.indexOf('social media') !== -1
            || key.indexOf('world check') !== -1
            || key.indexOf('worldcheck') !== -1
            || key.indexOf('linkedin') !== -1
            || key.indexOf('facebook') !== -1
            || key.indexOf('instagram') !== -1
            || key.indexOf('twitter') !== -1
            || key.indexOf('x.com') !== -1
        );
        return key.indexOf('education') !== -1
            || key.indexOf('employment') !== -1
            || idLike
            || referenceLike
            || ecourtLike
            || socialLike;
    }

    function getTypeCountInput(vtId) {
        if (!typesHostEl) return null;
        return typesHostEl.querySelector('.cv_count[data-vt-id="' + CSS.escape(String(vtId)) + '"]');
    }

    function setTypeRequiredCount(vtId, count) {
        var el = getTypeCountInput(vtId);
        if (!el) return;
        var n = parseInt(count || '1', 10) || 1;
        if (n <= 0) n = 1;
        el.value = String(n);
    }

    function renderTypesList(allTypes) {
        if (!typesHostEl) return;
        if (!Array.isArray(allTypes) || !allTypes.length) {
            typesHostEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">No verification types found.</div>';
            return;
        }

        var rowsAll = allTypes
            .slice()
            .filter(function (t) {
                var id = t && t.verification_type_id ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0;
                var name = t && t.type_name ? String(t.type_name || '') : '';
                return id > 0 && !!name;
            })
            .sort(function (a, b) {
                var ao = parseInt((a && a.sort_order) ? a.sort_order : '0', 10) || 0;
                var bo = parseInt((b && b.sort_order) ? b.sort_order : '0', 10) || 0;
                if (ao !== bo) return ao - bo;
                var an = String((a && a.type_name) ? a.type_name : '');
                var bn = String((b && b.type_name) ? b.type_name : '');
                return an.localeCompare(bn);
            });

        var html = '';
        html += '<div style="display:flex; flex-direction:column;">';
        rowsAll.forEach(function (t, idx) {
            var id = parseInt(t.verification_type_id || '0', 10) || 0;
            var name = String(t.type_name || '');
            var displayName = getAdminVerificationTypeLabel(t);
            if (!id || !name) return;

            html += '<div class="cv-type-row" data-vt-id="' + escapeHtml(String(id)) + '" style="display:flex; gap:10px; align-items:center; padding:10px 12px; border-top:' + (idx === 0 ? '0' : '1px') + ' solid #f1f5f9; flex-wrap:wrap; background:#fff;">';
            html += '<label style="display:flex; gap:8px; align-items:center; min-width:160px;">';
            html += '<input type="checkbox" class="cv_vt_cb" data-vt-id="' + escapeHtml(String(id)) + '">';
            html += '<span style="font-size:12px; font-weight:600; color:#0f172a;">' + escapeHtml(displayName) + '</span>';
            html += '</label>';
            html += '<div class="cv-type-inline">';
            html += '<div class="cv-type-inline-row" title="Internal TAT">';
            html += '<input class="cv_it_tat_val" type="number" step="0.5" min="0" value="" placeholder="Int" title="Internal TAT (GSS TAT)" disabled>';
            html += '<select class="cv_it_tat_unit" title="Internal TAT unit (D=Days, H=Hours)" disabled>';
            html += '<option value="days">D</option>';
            html += '<option value="hours">H</option>';
            html += '</select>';
            html += '</div>';

            html += '<div class="cv-type-inline-row" title="External TAT">';
            html += '<input class="cv_et_tat_val" type="number" step="0.5" min="0" value="" placeholder="Ext" title="External TAT (Client TAT)" disabled>';
            html += '<select class="cv_et_tat_unit" title="External TAT unit (D=Days, H=Hours)" disabled>';
            html += '<option value="days">D</option>';
            html += '<option value="hours">H</option>';
            html += '</select>';
            html += '</div>';

            html += '<input class="cv_cost" type="number" step="0.01" min="0" value="" placeholder="Cost" title="Cost per check (INR)" disabled>';

            if (isCountApplicableType(t)) {
                html += '<input class="cv_count" data-vt-id="' + escapeHtml(String(id)) + '" type="number" step="1" min="1" value="1" placeholder="Count" title="Candidate Count" disabled style="width:68px;">';
            }
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';

        typesHostEl.innerHTML = html;

        typesHostEl.querySelectorAll('.cv_vt_cb').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var vtId = cb.getAttribute('data-vt-id') || '';
                var stageKey = getActiveStageKey();
                if (stageKey) {
                    if (cb.checked) addTypeToStageOrder(stageKey, vtId);
                    else removeTypeFromStageOrder(stageKey, vtId);
                }

                try {
                    var row = cb.closest ? cb.closest('.cv-type-row') : null;
                    if (row) {
                        row.querySelectorAll('.cv_it_tat_val,.cv_it_tat_unit,.cv_et_tat_val,.cv_et_tat_unit,.cv_cost,.cv_count')
                            .forEach(function (el) {
                                el.disabled = !cb.checked;
                            });
                    }
                } catch (e) {
                }

                // Keep TAT & Cost panel in sync when types are selected/deselected.
                renderTatCost();
            });
        });

        setupTypeDraggables();

        clearTypesSelection();

        var activeTatLevel = getActiveTatLevel(getSelectedLevels());
        if (activeTatLevel) {
            syncInlineTatCost(activeTatLevel);
        }
    }

    function applyJobRoleRequiredCounts(jobRoleId, stageKey, levelKey) {
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (jrId <= 0 || !typesHostEl) return Promise.resolve();

        var url = (window.APP_BASE_URL || '').replace(/\/$/, '') + '/api/gssadmin/job_role_verification_types_get.php?job_role_id=' + encodeURIComponent(String(jrId));
        var sk = String(stageKey || '').trim();
        var lk = String(levelKey || '').trim();
        if (sk) url += '&stage_key=' + encodeURIComponent(sk);
        if (lk) url += '&level_key=' + encodeURIComponent(lk);

        return fetch(url,
            { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) return;
                if (sk) {
                    STAGE_TYPE_ORDER[sk] = data.data
                        .slice()
                        .sort(function (a, b) {
                            var ao = parseInt((a && a.sort_order) ? a.sort_order : '0', 10) || 0;
                            var bo = parseInt((b && b.sort_order) ? b.sort_order : '0', 10) || 0;
                            if (ao !== bo) return ao - bo;
                            var an = String((a && a.type_name) ? a.type_name : '');
                            var bn = String((b && b.type_name) ? b.type_name : '');
                            return an.localeCompare(bn);
                        })
                        .map(function (t) {
                            return String(t && t.verification_type_id ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0);
                        })
                        .filter(function (sid) { return sid && sid !== '0'; });
                }
                data.data.forEach(function (t) {
                    var id = t && t.verification_type_id ? parseInt(t.verification_type_id || '0', 10) || 0 : 0;
                    if (id <= 0) return;
                    if (typeof t.required_count === 'undefined') return;
                    setTypeRequiredCount(id, t.required_count);
                });
            })
            .catch(function () { });
    }

    function collectSelectedVerificationTypesWithCounts(stageKey, levelKey) {
        if (!typesHostEl) return [];
        var out = [];
        var sk = String(stageKey || getActiveStageKey() || '').trim();
        var lk = String(levelKey || getActiveTatLevel(getSelectedLevels()) || '').trim();
        var checkedById = {};
        var domOrder = [];

        typesHostEl.querySelectorAll('.cv_vt_cb').forEach(function (cb) {
            var vtId = parseInt(cb.getAttribute('data-vt-id') || '0', 10) || 0;
            if (vtId <= 0) return;
            if (!cb.checked) return;
            var sid = String(vtId);
            checkedById[sid] = cb;
            domOrder.push(sid);
        });

        var orderedIds = [];
        var seen = {};
        var stageOrder = sk ? ensureStageOrder(sk).slice() : [];
        stageOrder.forEach(function (sid) {
            if (!checkedById[sid] || seen[sid]) return;
            orderedIds.push(sid);
            seen[sid] = true;
        });
        domOrder.forEach(function (sid) {
            if (!checkedById[sid] || seen[sid]) return;
            orderedIds.push(sid);
            seen[sid] = true;
        });

        orderedIds.forEach(function (sid, idx) {
            var cb = checkedById[sid];
            var vtId = parseInt(sid || '0', 10) || 0;
            if (!cb || vtId <= 0) return;
            var cntEl = getTypeCountInput(vtId);
            var req = cntEl ? (parseInt(cntEl.value || '1', 10) || 1) : 1;
            if (req <= 0) req = 1;
            out.push({
                verification_type_id: vtId,
                is_enabled: 1,
                stage_key: sk,
                level_key: lk,
                sort_order: idx + 1,
                required_count: req
            });
        });
        return out;
    }

    function saveJobRoleVerificationTypes(jobRoleId, presetTypes, stageKey, levelKey) {
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (jrId <= 0) return Promise.resolve(true);
        var sk = String(stageKey || '').trim();
        var lk = String(levelKey || '').trim();
        var types = Array.isArray(presetTypes) ? presetTypes : collectSelectedVerificationTypesWithCounts(stageKey, levelKey);
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/job_role_verification_types_save.php';
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ job_role_id: jrId, stage_key: sk, level_key: lk, types: types }),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || d.status !== 1) return false;
                var savedCount = (typeof d.saved_count !== 'undefined') ? (parseInt(d.saved_count || '0', 10) || 0) : types.length;
                if (types.length > 0 && savedCount <= 0) {
                    return false;
                }
                return true;
            })
            .catch(function () { return false; });
    }

    function applyTypesSearch() {
        return;
    }

    function stageLabel(stageKey) {
        var k = String(stageKey || '').trim();
        if (k === 'pre_interview') return 'P1 Pre-Interview';
        if (k === 'post_interview') return 'P2 Post-Interview';
        if (k === 'employee_pool') return 'P3 Current Pool';
        return k || '-';
    }

    function parseStageKey(stageKey) {
        var raw = String(stageKey || '').trim();
        if (!raw) return { stage: '', level: '' };
        var parts = raw.split('__');
        if (parts.length >= 2) {
            return { stage: String(parts[0] || '').trim(), level: String(parts.slice(1).join('__') || '').trim() };
        }
        return { stage: raw, level: '' };
    }

    function shortTatUnit(u) {
        return normalizeTatUnit(u) === 'hours' ? 'H' : 'D';
    }

    function tatCostMiniText(levelKey, jobRoleId, vtId) {
        var lk = String(levelKey || '').trim();
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        var id = parseInt(vtId || '0', 10) || 0;
        if (!lk || id <= 0) return '';
        var map = ensureTatCostLevelRole(lk, jrId) || {};
        var curr = map[String(id)] || {};

        var iVal = (typeof curr.internal_tat_value !== 'undefined') ? String(curr.internal_tat_value || '').trim() : '';
        var iUnit = shortTatUnit(curr.internal_tat_unit || 'days');
        var eVal = (typeof curr.external_tat_value !== 'undefined') ? String(curr.external_tat_value || '').trim() : '';
        var eUnit = shortTatUnit(curr.external_tat_unit || 'days');
        var cost = (typeof curr.cost_inr !== 'undefined') ? String(curr.cost_inr || '').trim() : '';

        if (!iVal && !eVal && !cost) return '';

        var parts = [];
        var titleParts = [];
        if (iVal) {
            parts.push('I ' + iVal + iUnit);
            titleParts.push('Internal: ' + iVal + ' ' + (iUnit === 'H' ? 'hours' : 'days'));
        }
        if (eVal) {
            parts.push('E ' + eVal + eUnit);
            titleParts.push('External: ' + eVal + ' ' + (eUnit === 'H' ? 'hours' : 'days'));
        }
        if (cost) {
            parts.push('₹' + cost);
            titleParts.push('Cost: ₹' + cost);
        }

        return '<span style="margin-left:6px; font-size:10px; font-weight:800; color:#64748b; white-space:nowrap;" title="'
            + escapeHtml(titleParts.join(' | ')) + '">' + escapeHtml(parts.join(' · ')) + '</span>';
    }

    function loadTatCostForLevels(clientId, levels) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (cid <= 0) return Promise.resolve();
        var uniq = {};
        (Array.isArray(levels) ? levels : []).forEach(function (l) {
            var v = String(l || '').trim();
            if (!v) return;
            uniq[v] = true;
        });
        var keys = Object.keys(uniq);
        if (!keys.length) return Promise.resolve();
        // Backward compatible helper (no role scope). Prefer loadTatCostForLevelRoles below.
        return Promise.all(keys.map(function (lk) { return loadTatCost(cid, lk, 0); })).then(function () { return; });
    }

    function loadTatCostForLevelRoles(clientId, pairs) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (cid <= 0) return Promise.resolve();
        var uniq = {};
        (Array.isArray(pairs) ? pairs : []).forEach(function (p) {
            if (!p) return;
            var lk = String(p.level_key || '').trim();
            var jrId = parseInt(p.job_role_id || '0', 10) || 0;
            if (!lk) return;
            uniq[lk + '::' + String(jrId)] = { lk: lk, jrId: jrId };
        });
        var keys = Object.keys(uniq);
        if (!keys.length) return Promise.resolve();
        return Promise.all(keys.map(function (k) {
            var it = uniq[k];
            return loadTatCost(cid, it.lk, it.jrId);
        })).then(function () { return; });
    }

    function renderVerificationSummary(items) {
        if (!summaryEl) return;
        if (!Array.isArray(items) || items.length === 0) {
            summaryEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">No mappings saved yet.</div>';
            return;
        }

        var view = 'kanban';
        try {
            var v = window.localStorage ? window.localStorage.getItem('cv_summary_view') : null;
            if (v === 'list' || v === 'kanban') view = v;
        } catch (e) {
        }

        // Group by role -> stage_key (includes level)
        var byRole = {};
        items.forEach(function (r) {
            var rid = r && r.job_role_id ? parseInt(r.job_role_id || '0', 10) || 0 : 0;
            if (rid <= 0) return;
            if (!byRole[rid]) {
                byRole[rid] = {
                    job_role_id: rid,
                    role_name: String(r.role_name || ''),
                    mappings: {}
                };
            }

            var steps = Array.isArray(r.steps) ? r.steps : [];
            steps.forEach(function (s) {
                if (!s || parseInt(s.is_active || '1', 10) === 0) return;
                var sk = String(s.stage_key || '').trim();
                if (!sk) return;
                if (!byRole[rid].mappings[sk]) byRole[rid].mappings[sk] = [];
                byRole[rid].mappings[sk].push(s);
            });
        });

        var roleIds = Object.keys(byRole)
            .map(function (k) { return parseInt(k, 10) || 0; })
            .filter(function (n) { return n > 0; })
            .sort(function (a, b) {
                var an = (byRole[a] && byRole[a].role_name) ? byRole[a].role_name : '';
                var bn = (byRole[b] && byRole[b].role_name) ? byRole[b].role_name : '';
                return String(an).localeCompare(String(bn));
            });

        var totalMappings = 0;
        roleIds.forEach(function (rid2) {
            totalMappings += Object.keys(byRole[rid2].mappings || {}).length;
        });
        if (totalMappings === 0) {
            summaryEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">No mappings saved yet.</div>';
            return;
        }

        var stageOrder = { pre_interview: 1, post_interview: 2, employee_pool: 3 };

        function levelSort(a, b) {
            var aa = String(a || '').trim();
            var bb = String(b || '').trim();
            var ma = aa.match(/^\s*[lL]\s*(\d+)\s*$/);
            var mb = bb.match(/^\s*[lL]\s*(\d+)\s*$/);
            if (ma && mb) {
                var na = parseInt(ma[1], 10) || 0;
                var nb = parseInt(mb[1], 10) || 0;
                if (na !== nb) return na - nb;
                return aa.localeCompare(bb);
            }
            if (ma && !mb) return -1;
            if (!ma && mb) return 1;
            return aa.localeCompare(bb);
        }

        function stageKeySort(a, b) {
            var pa = parseStageKey(a);
            var pb = parseStageKey(b);
            var sa = String(pa.stage || '');
            var sb = String(pb.stage || '');
            var oa = stageOrder[sa] || 99;
            var ob = stageOrder[sb] || 99;
            if (oa !== ob) return oa - ob;
            var la = String(pa.level || '');
            var lb = String(pb.level || '');
            return levelSort(la, lb);
        }

        var html = '';
        if (view === 'kanban') {
            var cols = [
                { key: 'pre_interview', title: 'P1 Pre-Interview' },
                { key: 'post_interview', title: 'P2 Post-Interview' },
                { key: 'employee_pool', title: 'P3 Current Employee Pool' }
            ];
            var cardsByStage = { pre_interview: [], post_interview: [], employee_pool: [] };

            roleIds.forEach(function (rid) {
                var role = byRole[rid];
                var keys = Object.keys(role.mappings || {}).sort(stageKeySort);
                keys.forEach(function (sk) {
                    var parsed = parseStageKey(sk);
                    var st = String(parsed.stage || '').trim();
                    if (!cardsByStage[st]) return;
                    cardsByStage[st].push({
                        job_role_id: rid,
                        role_name: role.role_name || ('#' + rid),
                        stage_key: sk,
                        stage: st,
                        level: String(parsed.level || 'Default'),
                        rows: role.mappings[sk] || []
                    });
                });
            });

            Object.keys(cardsByStage).forEach(function (k) {
                cardsByStage[k].sort(function (a, b) {
                    var la = String(a.level || '');
                    var lb = String(b.level || '');
                    var c1 = levelSort(la, lb);
                    if (c1 !== 0) return c1;
                    return String(a.role_name || '').localeCompare(String(b.role_name || ''));
                });
            });

            html += '<div class="cv-kanban">';
            cols.forEach(function (c) {
                var list = cardsByStage[c.key] || [];
                html += '<div class="cv-kanban-col">';
                html += '<div class="cv-kanban-col-head">';
                html += '<div class="cv-kanban-col-title">' + escapeHtml(c.title) + '</div>';
                html += '<div style="display:flex; gap:8px; align-items:center;">';
                html += '<div class="cv-kanban-col-count">' + escapeHtml(String(list.length)) + '</div>';
                html += '<button type="button" class="btn cv-kanban-del-all" data-stage-base="' + escapeHtml(String(c.key)) + '" style="padding:6px 10px; background:#fee2e2; border-color:#fecaca; color:#991b1b;">Delete All</button>';
                html += '</div>';
                html += '</div>';
                html += '<div class="cv-kanban-cards">';

                if (!list.length) {
                    html += '<div style="color:#94a3b8; font-size:12px; padding:6px 2px;">No mappings</div>';
                } else {
                    list.forEach(function (card) {
                        html += '<div class="cv-card" data-stage="' + escapeHtml(String(card.stage)) + '" data-jr-id="' + escapeHtml(String(card.job_role_id)) + '" data-stage-key="' + escapeHtml(String(card.stage_key)) + '">';
                        html += '<div class="cv-card-head">';
                        html += '<div>';
                        html += '<div class="cv-card-title">' + escapeHtml(card.level) + ' → ' + escapeHtml(card.role_name) + '</div>';
                        html += '<div class="cv-card-sub">' + escapeHtml(stageLabel(card.stage)) + '</div>';
                        html += '</div>';
                        html += '<div class="cv-flow-actions" style="display:flex; gap:8px;">';
                        html += '<button type="button" class="btn cv-flow-edit" data-jr-id="' + escapeHtml(String(card.job_role_id)) + '" data-stage-key="' + escapeHtml(String(card.stage_key)) + '" style="padding:6px 10px;">Edit</button>';
                        html += '<button type="button" class="btn cv-flow-del" data-jr-id="' + escapeHtml(String(card.job_role_id)) + '" data-stage-key="' + escapeHtml(String(card.stage_key)) + '" style="padding:6px 10px; background:#fee2e2; border-color:#fecaca; color:#991b1b;">Delete</button>';
                        html += '</div>';
                        html += '</div>';

                        html += '<div class="cv-card-types">';
                        if (!card.rows || !card.rows.length) {
                            html += '<span class="cv-card-type" style="color:#94a3b8;">No types</span>';
                        } else {
                            card.rows
                                .slice()
                                .sort(function (a, b) {
                                    var ag = a && typeof a.execution_group !== 'undefined' ? (parseInt(a.execution_group || '1', 10) || 1) : 1;
                                    var bg = b && typeof b.execution_group !== 'undefined' ? (parseInt(b.execution_group || '1', 10) || 1) : 1;
                                    if (ag !== bg) return ag - bg;
                                    var an = String((a && a.type_name) ? a.type_name : '');
                                    var bn = String((b && b.type_name) ? b.type_name : '');
                                    return an.localeCompare(bn);
                                })
                                .forEach(function (t) {
                                    var tn = getAdminVerificationTypeLabel(t).trim();
                                    if (!tn) {
                                        var idFallback = t && typeof t.verification_type_id !== 'undefined' ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0;
                                        if (idFallback > 0) tn = 'Type #' + String(idFallback);
                                    }
                                    if (!tn) return;
                                    var grp = t && typeof t.execution_group !== 'undefined' ? (parseInt(t.execution_group || '1', 10) || 1) : 1;
                                    var vid = t && typeof t.verification_type_id !== 'undefined' ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0;
                                    html += '<span class="cv-card-type">'
                                        + escapeHtml(tn) + ' (G' + escapeHtml(String(grp)) + ')'
                                        + tatCostMiniText(card.level, card.job_role_id, vid)
                                        + '</span>';
                                });
                        }
                        html += '</div>';
                        html += '</div>';
                    });
                }

                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
        } else {
            html += '<div class="cv-flow">';

            roleIds.forEach(function (rid) {
                var role = byRole[rid];
                var keys = Object.keys(role.mappings || {}).sort(stageKeySort);
                if (!keys.length) return;

                html += '<div class="cv-flow-role">';
                html += '<div class="cv-flow-role-title">' + escapeHtml(role.role_name || ('Job Role #' + rid)) + '</div>';

                keys.forEach(function (sk) {
                    var parsed = parseStageKey(sk);
                    var lvl = parsed.level || 'Default';
                    var st = parsed.stage || '';
                    var rows = role.mappings[sk] || [];

                    html += '<div class="cv-flow-row" style="margin-top:10px;">';
                    html += '<div class="cv-flow-line cv-flow-line-1">';
                    html += '<span class="cv-flow-pill" style="background:#f1f5f9;">Level</span>';
                    html += '<span class="cv-flow-pill" style="font-weight:900;">' + escapeHtml(lvl) + '</span>';
                    html += '<span class="cv-flow-arrow">→</span>';
                    html += '<span class="cv-flow-pill" style="background:#f1f5f9;">Job Role</span>';
                    html += '<span class="cv-flow-pill" style="font-weight:900;">' + escapeHtml(role.role_name || ('#' + rid)) + '</span>';

                    html += '<span class="cv-flow-actions" style="margin-left:auto; display:flex; gap:8px;">';
                    html += '<button type="button" class="btn cv-flow-edit" data-jr-id="' + escapeHtml(String(rid)) + '" data-stage-key="' + escapeHtml(String(sk)) + '" style="padding:6px 10px;">Edit</button>';
                    html += '<button type="button" class="btn cv-flow-del" data-jr-id="' + escapeHtml(String(rid)) + '" data-stage-key="' + escapeHtml(String(sk)) + '" style="padding:6px 10px; background:#fee2e2; border-color:#fecaca; color:#991b1b;">Delete</button>';
                    html += '</span>';

                    html += '</div>';
                    html += '<div class="cv-flow-line cv-flow-line-2">';
                    html += '<span class="cv-flow-pill">' + escapeHtml(stageLabel(st || sk)) + '</span>';
                    html += '<span class="cv-flow-arrow">→</span>';
                    html += '<span class="cv-flow-types">';

                    if (!rows.length) {
                        html += '<span class="cv-flow-type" style="color:#94a3b8;">No types</span>';
                    } else {
                        rows
                            .slice()
                            .sort(function (a, b) {
                                var ag = a && typeof a.execution_group !== 'undefined' ? (parseInt(a.execution_group || '1', 10) || 1) : 1;
                                var bg = b && typeof b.execution_group !== 'undefined' ? (parseInt(b.execution_group || '1', 10) || 1) : 1;
                                if (ag !== bg) return ag - bg;
                                var an = String((a && a.type_name) ? a.type_name : '');
                                var bn = String((b && b.type_name) ? b.type_name : '');
                                return an.localeCompare(bn);
                            })
                            .forEach(function (t) {
                                var tn = getAdminVerificationTypeLabel(t).trim();
                                if (!tn) {
                                    var idFallback = t && typeof t.verification_type_id !== 'undefined' ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0;
                                    if (idFallback > 0) {
                                        tn = 'Type #' + String(idFallback);
                                    }
                                }
                                if (!tn) return;
                                var grp = t && typeof t.execution_group !== 'undefined' ? (parseInt(t.execution_group || '1', 10) || 1) : 1;
                                var vid = t && typeof t.verification_type_id !== 'undefined' ? (parseInt(t.verification_type_id || '0', 10) || 0) : 0;
                                html += '<span class="cv-flow-type">'
                                    + escapeHtml(tn) + ' (G' + escapeHtml(String(grp)) + ')'
                                    + tatCostMiniText(lvl, rid, vid)
                                    + '</span>';
                            });
                    }

                    html += '</span>';
                    html += '</div>';
                    html += '</div>';
                });

                html += '</div>';
            });

            html += '</div>';
        }

        summaryEl.innerHTML = html;

        summaryEl.querySelectorAll('.cv-kanban-del-all').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try {
                    var st = String(btn.getAttribute('data-stage-base') || '').trim();
                    if (!st) return;
                    bulkDeleteStageMappings(st);
                } catch (e) {
                }
            });
        });

        summaryEl.querySelectorAll('.cv-flow-edit').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try {
                    var jrId = parseInt(btn.getAttribute('data-jr-id') || '0', 10) || 0;
                    var stageKey = String(btn.getAttribute('data-stage-key') || '').trim();
                    if (jrId <= 0 || !stageKey) return;
                    editMappingFromSummary(jrId, stageKey, items);
                } catch (e) {
                }
            });
        });

        summaryEl.querySelectorAll('.cv-flow-del').forEach(function (btn) {
            btn.addEventListener('click', function () {
                try {
                    var jrId = parseInt(btn.getAttribute('data-jr-id') || '0', 10) || 0;
                    var stageKey = String(btn.getAttribute('data-stage-key') || '').trim();
                    if (jrId <= 0 || !stageKey) return;
                    if (window.GSSDialog && typeof window.GSSDialog.confirm === 'function') {
                        window.GSSDialog.confirm('Delete this mapping?', { title: 'Confirm delete', okText: 'Delete', cancelText: 'Cancel', okVariant: 'danger' })
                            .then(function (ok) {
                                if (!ok) return;
                                deleteMapping(jrId, stageKey);
                            });
                        return;
                    }
                    var ok = window.confirm('Delete this mapping?');
                    if (!ok) return;
                    deleteMapping(jrId, stageKey);
                } catch (e) {
                }
            });
        });

    }

    function setLevelSelected(levelValue) {
        if (!levelBoxEl) return;
        var target = String(levelValue || '').trim().toLowerCase();
        if (!target) return;
        levelBoxEl.querySelectorAll('.cv_lvl_cb').forEach(function (cb) {
            try {
                var v = String(cb.getAttribute('data-lvl') || '').trim().toLowerCase();
                cb.checked = (v === target);
            } catch (e) {
            }
        });
    }

    function setJobRoleSelected(jobRoleId) {
        if (!jobRoleBoxEl) return;
        var tid = String(parseInt(jobRoleId || '0', 10) || 0);
        if (tid === '0') return;
        jobRoleBoxEl.querySelectorAll('.cv_jr_cb').forEach(function (cb) {
            try {
                var v = String(cb.getAttribute('data-jr-id') || '');
                cb.checked = (v === tid);
            } catch (e) {
            }
        });
    }

    function setStageSelected(stageKey) {
        var parsed = parseStageKey(stageKey);
        var base = String(parsed.stage || '').trim();
        if (!base) return;
        document.querySelectorAll('.cv_stage_cb').forEach(function (rb) {
            rb.checked = (String(rb.value || '') === base);
        });
        refreshStagePills();
    }

    function editMappingFromSummary(jobRoleId, stageKey, summaryItems) {
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (jrId <= 0) return;
        var parsed = parseStageKey(stageKey);
        var lvl = String(parsed.level || '').trim();
        var baseStage = String(parsed.stage || '').trim();
        if (!lvl || !baseStage) return;

        setLevelSelected(lvl);
        setJobRoleSelected(jrId);
        setStageSelected(stageKey);

        var rows = [];
        (Array.isArray(summaryItems) ? summaryItems : []).forEach(function (r) {
            var rid = r && r.job_role_id ? parseInt(r.job_role_id || '0', 10) || 0 : 0;
            if (rid !== jrId) return;
            var steps = Array.isArray(r.steps) ? r.steps : [];
            steps.forEach(function (s) {
                if (!s || parseInt(s.is_active || '1', 10) === 0) return;
                if (String(s.stage_key || '').trim() !== String(stageKey)) return;
                rows.push(s);
            });
        });

        if (!SYSTEM_TYPES.length) {
            loadSystemTypes(urlClientId).then(function () {
                editMappingFromSummary(jobRoleId, stageKey, summaryItems);
            });
            return;
        }
        if (!typesHostEl || !typesHostEl.querySelector('.cv_vt_cb')) {
            renderTypesList(SYSTEM_TYPES);
        }

        clearTypesSelection();

        // Restore sequencing from saved mapping order
        var stageBase = String(baseStage || '').trim();
        if (stageBase) {
            STAGE_TYPE_ORDER[stageBase] = rows
                .slice()
                .sort(function (a, b) {
                    var ag = a && typeof a.sort_order !== 'undefined'
                        ? (parseInt(a.sort_order || '0', 10) || 0)
                        : (a && typeof a.execution_group !== 'undefined' ? (parseInt(a.execution_group || '1', 10) || 1) : 1);
                    var bg = b && typeof b.sort_order !== 'undefined'
                        ? (parseInt(b.sort_order || '0', 10) || 0)
                        : (b && typeof b.execution_group !== 'undefined' ? (parseInt(b.execution_group || '1', 10) || 1) : 1);
                    return ag - bg;
                })
                .map(function (s) {
                    return String(s && typeof s.verification_type_id !== 'undefined' ? (parseInt(s.verification_type_id || '0', 10) || 0) : 0);
                })
                .filter(function (v) { return v && v !== '0'; });
        }

        rows.forEach(function (s) {
            var vtId = s && typeof s.verification_type_id !== 'undefined' ? (parseInt(s.verification_type_id || '0', 10) || 0) : 0;
            if (vtId <= 0) return;
            setTypeChecked(vtId, true);
        });

        // Keep required-count and TAT/cost fields in sync while restoring from summary.
        applyJobRoleRequiredCounts(jrId, stageKey, lvl);
        if (lvl) {
            loadTatCost(urlClientId, lvl, jrId).then(function () {
                syncInlineTatCost(lvl, jrId);
                renderTatCost();
            });
        } else {
            renderTatCost();
        }

        refreshSelectedTypesChips();
        applyTypesSearch();

        try {
            var tab = document.querySelector('[data-tab="#tab-verification"]');
            if (tab) tab.click();
        } catch (e) {
        }

        // Bring mapping controls into view
        scrollToEl(typesHostEl || stageSelectEl || levelBoxEl || jobRoleBoxEl);
    }

    function deleteMapping(jobRoleId, stageKey) {
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        var sk = String(stageKey || '').trim();
        if (jrId <= 0 || !sk) return;

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/job_role_stage_config_delete.php';
        setVerificationMessage('Deleting...', 'info');

        deleteMappingRequest(url, jrId, sk)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to delete');
                }
                setVerificationMessage('Deleted.', 'success');
                loadVerificationSummary(urlClientId);
            })
            .catch(function (e) {
                setVerificationMessage(e && e.message ? e.message : 'Failed to delete', 'danger');
            });
    }

    function deleteMappingRequest(url, jrId, sk) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ job_role_id: jrId, stage_key: sk }),
            credentials: 'same-origin'
        });
    }

    function bulkDeleteStageMappings(stageKeyBase) {
        var st = String(stageKeyBase || '').trim();
        if (!st) return;
        if (!summaryEl) return;

        var allowedStages = { pre_interview: 1, post_interview: 1, employee_pool: 1 };
        if (!allowedStages[st]) return;

        var cards = summaryEl.querySelectorAll('.cv-card[data-stage="' + CSS.escape(st) + '"]');
        var ops = [];
        Array.prototype.slice.call(cards).forEach(function (card) {
            var jrId = parseInt(card.getAttribute('data-jr-id') || '0', 10) || 0;
            var sk = String(card.getAttribute('data-stage-key') || '').trim();
            if (jrId > 0 && sk) ops.push({ job_role_id: jrId, stage_key: sk });
        });

        if (!ops.length) {
            setVerificationMessage('No mappings to delete.', 'info');
            return;
        }

        function doDelete() {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/gssadmin/job_role_stage_config_delete.php';
            setVerificationMessage('Deleting ' + String(ops.length) + ' mappings...', 'info');

            // Sequential deletes to keep server load reasonable
            ops.reduce(function (p, it) {
                return p.then(function () {
                    return deleteMappingRequest(url, it.job_role_id, it.stage_key)
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (!data || data.status !== 1) {
                                throw new Error((data && data.message) ? data.message : 'Failed to delete');
                            }
                        });
                });
            }, Promise.resolve())
                .then(function () {
                    setVerificationMessage('Deleted ' + String(ops.length) + ' mappings.', 'success');
                    loadVerificationSummary(urlClientId);
                })
                .catch(function (e) {
                    setVerificationMessage(e && e.message ? e.message : 'Failed to delete mappings', 'danger');
                });
        }

        if (window.GSSDialog && typeof window.GSSDialog.confirm === 'function') {
            window.GSSDialog.confirm('Delete all mappings in ' + stageLabel(st) + '?', {
                title: 'Confirm delete all',
                okText: 'Delete All',
                cancelText: 'Cancel',
                okVariant: 'danger'
            }).then(function (ok) {
                if (!ok) return;
                doDelete();
            });
            return;
        }

        var ok = window.confirm('Delete all mappings in ' + stageLabel(st) + '?');
        if (!ok) return;
        doDelete();
    }

    function loadVerificationSummary(clientId) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (!summaryEl) return Promise.resolve();
        if (cid <= 0) {
            summaryEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">Save client to view mapping.</div>';
            return Promise.resolve();
        }

        summaryEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">Loading...</div>';
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/client_verification_summary.php?client_id=' + encodeURIComponent(String(cid));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load summary');
                }
                ensureLevelsFromSummary(data.data || []);

                var sumPairs = [];
                (Array.isArray(data.data) ? data.data : []).forEach(function (r) {
                    var jrId = r && r.job_role_id ? (parseInt(r.job_role_id || '0', 10) || 0) : 0;
                    var steps = Array.isArray(r && r.steps) ? r.steps : [];
                    steps.forEach(function (s) {
                        var parsed = parseStageKey(s && s.stage_key ? s.stage_key : '');
                        var lk = String(parsed.level || '').trim();
                        if (lk) sumPairs.push({ level_key: lk, job_role_id: jrId });
                    });
                });

                return loadTatCostForLevelRoles(cid, sumPairs).then(function () {
                    renderVerificationSummary(data.data || []);
                });
            })
            .catch(function (e) {
                summaryEl.innerHTML = '<div style="color:#b91c1c; font-size:12px;">' + escapeHtml(e && e.message ? e.message : 'Failed') + '</div>';
            });
    }

    function loadSystemTypes(clientId) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (cid <= 0) return Promise.resolve();
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/verification_types_list.php?client_id=' + encodeURIComponent(String(cid));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load verification types');
                }
                SYSTEM_TYPES = data.data;
                renderTypesList(SYSTEM_TYPES);
            })
            .catch(function (e) {
                if (typesHostEl) {
                    typesHostEl.innerHTML = '<div style="color:#b91c1c; font-size:12px;">' + escapeHtml(e && e.message ? e.message : 'Failed') + '</div>';
                }
            });
    }

    function loadJobRoleTypes(jobRoleId) {
        var jrId = parseInt(jobRoleId || '0', 10) || 0;
        if (jrId <= 0) return Promise.resolve({});
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/job_role_verification_types_get.php?job_role_id=' + encodeURIComponent(String(jrId));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load job role types');
                }
                var set = {};
                data.data.forEach(function (t) {
                    var id = t && t.verification_type_id ? parseInt(t.verification_type_id || '0', 10) || 0 : 0;
                    var on = t && typeof t.is_enabled !== 'undefined' ? (parseInt(t.is_enabled || '0', 10) || 0) : 0;
                    if (id > 0 && on === 1) set[String(id)] = true;
                });
                return set;
            });
    }

    function onSelectionChanged() {
        setVerificationMessage('', '');
        var jobRoles = getSelectedJobRoles();
        var levels = getSelectedLevels();
        var stages = getSelectedStages();

        if (!levels.length) {
            if (typesHostEl) typesHostEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">Select Level first.</div>';
            setTatCostMessage('Select Level first.');
            return;
        }

        if (!jobRoles.length) {
            if (typesHostEl) typesHostEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">Select Job Role to load types.</div>';
            setTatCostMessage('Select Job Role to load types.');
            return;
        }

        if (!stages.length) {
            if (typesHostEl && !typesHostEl.querySelector('.cv_vt_cb')) {
                typesHostEl.innerHTML = '<div style="color:#6b7280; font-size:12px;">Select Stage (P1 / P2 / P3) then select types.</div>';
            }
        }

        if (!SYSTEM_TYPES.length) {
            loadSystemTypes(urlClientId).then(function () {
                onSelectionChanged();
            });
            return;
        }

        if (typesHostEl && !typesHostEl.querySelector('.cv_vt_cb')) {
            renderTypesList(SYSTEM_TYPES);
        }

        // Do NOT auto-select any types. Only clear selections when the Level/Job Role context changes.
        var ctxKey = levels.slice().sort().join('|') + '::' + jobRoles.slice().sort(function (a, b) { return a - b; }).join('|');
        if (ctxKey !== lastTypesContextKey) {
            lastTypesContextKey = ctxKey;
            clearTypesSelection();
        }

        var activeTatLevel = getActiveTatLevel(levels);
        var activeTatRoleId = jobRoles.length ? (parseInt(jobRoles[0] || '0', 10) || 0) : 0;
        var loadKey = String(urlClientId) + '::' + activeTatLevel + '::' + String(activeTatRoleId);
        if (activeTatLevel && loadKey !== lastTatCostLoadKey) {
            lastTatCostLoadKey = loadKey;
            loadTatCost(urlClientId, activeTatLevel, activeTatRoleId).then(function () {
                syncInlineTatCost(activeTatLevel, activeTatRoleId);
                renderTatCost();
            });
        } else {
            if (activeTatLevel) {
                syncInlineTatCost(activeTatLevel, activeTatRoleId);
            }
            renderTatCost();
        }

        if (jobRoles.length) {
            applyJobRoleRequiredCounts(jobRoles[0], getActiveStageKey(), activeTatLevel);
        }
    }

    function loadJobRoles(clientId) {
        var cid = parseInt(clientId || '0', 10) || 0;
        if (!jobRoleBoxEl) return Promise.resolve();
        if (cid <= 0) {
            jobRoleBoxEl.innerHTML = '';
            return Promise.resolve();
        }

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/job_roles_list.php?client_id=' + encodeURIComponent(String(cid));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load job roles');
                }
                var html = '';
                html += '<div style="display:flex; flex-direction:column; gap:8px;">';
                data.data.forEach(function (r) {
                    var id = r && r.job_role_id ? parseInt(r.job_role_id || '0', 10) || 0 : 0;
                    var name = r && r.role_name ? String(r.role_name) : '';
                    if (!id || !name) return;
                    html += '<label style="display:flex; gap:8px; align-items:center; padding:8px 10px; border:1px solid #e5e7eb; border-radius:10px; background:#fff;">';
                    html += '<input type="checkbox" class="cv_jr_cb" data-jr-id="' + escapeHtml(String(id)) + '">';
                    html += '<span style="font-size:12px; font-weight:700; color:#0f172a;">' + escapeHtml(name) + '</span>';
                    html += '</label>';
                });
                html += '</div>';
                jobRoleBoxEl.innerHTML = html;

                jobRoleBoxEl.querySelectorAll('.cv_jr_cb').forEach(function (cb) {
                    cb.addEventListener('change', function () {
                        onSelectionChanged();
                    });
                });

                renderLevels(LEVELS);
                onSelectionChanged();
                loadVerificationSummary(cid);
            });
    }

    function renderLevels(levels) {
        if (!levelBoxEl) return;
        var selectedSet = getSelectedLevelsSet();
        var arr = Array.isArray(levels) ? levels : [];
        var map = {};
        var out = [];
        arr.forEach(function (l) {
            var v = String(l || '').trim();
            if (!v) return;
            var key = v.toLowerCase();
            if (map[key]) return;
            map[key] = true;
            out.push(v);
        });
        out.sort(function (a, b) {
            var aa = String(a || '').trim();
            var bb = String(b || '').trim();
            var ma = aa.match(/^\s*[lL]\s*(\d+)\s*$/);
            var mb = bb.match(/^\s*[lL]\s*(\d+)\s*$/);
            if (ma && mb) {
                var na = parseInt(ma[1], 10) || 0;
                var nb = parseInt(mb[1], 10) || 0;
                if (na !== nb) return na - nb;
                return aa.localeCompare(bb);
            }
            if (ma && !mb) return -1;
            if (!ma && mb) return 1;
            return aa.localeCompare(bb);
        });

        var html = '';
        html += '<div style="display:flex; justify-content:space-between; gap:10px; align-items:center; margin-bottom:10px; flex-wrap:wrap;">';
        html += '<div style="font-size:12px; font-weight:800; color:#0f172a;">Levels</div>';
        html += '<div style="display:flex; gap:8px; align-items:center;">';
        html += '<button type="button" class="btn" data-levels-action="select_all" style="padding:6px 10px;">Select All</button>';
        html += '<button type="button" class="btn btn-secondary" data-levels-action="clear_all" style="padding:6px 10px;">Clear</button>';
        html += '</div>';
        html += '</div>';
        html += '<div style="display:flex; flex-direction:column; gap:8px;">';
        out.forEach(function (lvl) {
            html += '<label style="display:flex; gap:8px; align-items:center; padding:8px 10px; border:1px solid #e5e7eb; border-radius:10px; background:#fff;">';
            html += '<input type="checkbox" class="cv_lvl_cb" data-lvl="' + escapeHtml(lvl) + '">';
            html += '<span style="font-size:12px; font-weight:700; color:#0f172a;">' + escapeHtml(lvl) + '</span>';
            html += '</label>';
        });
        html += '</div>';
        levelBoxEl.innerHTML = html;

        if (!levelBoxEl.dataset.bulkBound) {
            levelBoxEl.dataset.bulkBound = '1';
            levelBoxEl.addEventListener('click', function (e) {
                var t = e && e.target ? e.target : null;
                if (!t) return;
                var btn = t.closest ? t.closest('[data-levels-action]') : null;
                if (!btn) return;
                var action = String(btn.getAttribute('data-levels-action') || '');
                if (action !== 'select_all' && action !== 'clear_all') return;
                e.preventDefault();
                var check = action === 'select_all';
                levelBoxEl.querySelectorAll('.cv_lvl_cb').forEach(function (cb) {
                    cb.checked = check;
                });
                onSelectionChanged();
            });
        }

        levelBoxEl.querySelectorAll('.cv_lvl_cb').forEach(function (cb) {
            cb.addEventListener('change', function () {
                onSelectionChanged();
            });

            try {
                var v = String(cb.getAttribute('data-lvl') || '').trim().toLowerCase();
                cb.checked = !!(v && selectedSet[v]);
            } catch (e) {
            }
        });
    }

    function ensureLevelsFromSummary(items) {
        var set = {};
        (LEVELS || []).forEach(function (l) {
            var v = String(l || '').trim();
            if (v) set[v.toLowerCase()] = v;
        });

        (Array.isArray(items) ? items : []).forEach(function (r) {
            var steps = Array.isArray(r && r.steps) ? r.steps : [];
            steps.forEach(function (s) {
                var parsed = parseStageKey(s && s.stage_key ? s.stage_key : '');
                var lvl = String(parsed.level || '').trim();
                if (!lvl) return;
                set[lvl.toLowerCase()] = lvl;
            });
        });

        LEVELS = Object.keys(set).map(function (k) { return set[k]; });
        renderLevels(LEVELS);
    }

    function parseLevelInputToList(input) {
        var raw = String(input || '').trim();
        if (!raw) return [];

        // Accept: "L10", "l10", "10" => ["L1".."L10"]
        var m = raw.match(/^l?\s*(\d{1,3})$/i);
        if (m && m[1]) {
            var n = parseInt(m[1], 10) || 0;
            if (n <= 0) return [];
            var out = [];
            for (var i = 1; i <= n; i++) {
                out.push('L' + String(i));
            }
            return out;
        }

        // Custom level name (example: "Senior")
        return [raw];
    }

    function collectSelectedStageSteps() {
        if (!typesHostEl) return [];
        var out = [];

        var stageKey = getActiveStageKey();
        var order = stageKey ? ensureStageOrder(stageKey).slice() : [];

        var cbs = typesHostEl.querySelectorAll('.cv_vt_cb');
        cbs.forEach(function (cb) {
            var vtId = parseInt(cb.getAttribute('data-vt-id') || '0', 10) || 0;
            if (vtId <= 0) return;
            if (!cb.checked) return;

            // Auto-sequence based on drop/selection order for the active stage
            var sid = String(vtId);
            var idx = order.indexOf(sid);
            if (idx === -1) {
                order.push(sid);
                idx = order.length - 1;
            }
            var group = idx + 1;
            var role = 'verifier';

            out.push({
                verification_type_id: vtId,
                execution_group: group,
                assigned_role: role,
                is_enabled: 1
            });
        });

        // Persist any appended items back
        if (stageKey) {
            STAGE_TYPE_ORDER[stageKey] = order;
        }

        return out;
    }

    function saveJobRoleStageConfig(jobRoleId) {
        var levels = getSelectedLevels();
        if (!levels.length) {
            setVerificationMessage('Please select Level.', 'danger');
            return Promise.resolve(false);
        }

        var jrIds = getSelectedJobRoles();
        if (!jrIds.length) {
            setVerificationMessage('Please select Job Role.', 'danger');
            return Promise.resolve(false);
        }

        var stageKeys = getSelectedStages();
        if (!stageKeys.length) {
            setVerificationMessage('Please select Stage.', 'danger');
            return Promise.resolve(false);
        }

        var steps = collectSelectedStageSteps();
        if (!steps.length) {
            setVerificationMessage('Please select at least one Verification Type.', 'danger');
            return Promise.resolve(false);
        }

        // Take a stable snapshot before async saves begin.
        var activeTatLevel = getActiveTatLevel(levels);
        var tatItems = activeTatLevel ? collectTatCostInputs(activeTatLevel) : [];

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/job_role_stage_config_save.php';
        setVerificationMessage('Saving...', 'info');

        var chain = Promise.resolve(true);
        jrIds.forEach(function (jrId) {
            levels.forEach(function (lvl) {
                stageKeys.forEach(function (stageKey) {
                    var fullStageKey = String(stageKey || '').trim();
                    var levelKey = String(lvl || '').trim();
                    if (levelKey) {
                        fullStageKey = fullStageKey + '__' + levelKey;
                    }
                    chain = chain.then(function (ok) {
                        if (!ok) return false;
                        return fetch(url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ job_role_id: jrId, stage_key: fullStageKey, steps: steps }),
                            credentials: 'same-origin'
                        })
                            .then(function (res) { return res.json(); })
                            .then(function (data) {
                                if (!data || data.status !== 1) {
                                    throw new Error((data && data.message) ? data.message : 'Failed to save');
                                }
                                return true;
                            });
                    });
                });
            });
        });

        return chain
            .then(function (ok) {
                if (!ok) return false;

                var tatSaveChain = Promise.resolve(true);
                if (activeTatLevel) {
                    jrIds.forEach(function (jrId2) {
                        levels.forEach(function (lvl) {
                            tatSaveChain = tatSaveChain.then(function () {
                                return saveTatCostForLevel(urlClientId, lvl, jrId2, tatItems);
                            });
                        });
                    });
                }

                return tatSaveChain.then(function () {
                    return true;
                });
            })
            .then(function (okTypes) {
                if (!okTypes) return false;
                // Save type counts per job role (admin controlled)
                var saveTypesChain = Promise.resolve(true);
                jrIds.forEach(function (jrId3) {
                    levels.forEach(function (lvl2) {
                        stageKeys.forEach(function (stageKey2) {
                            var fullStageKey2 = String(stageKey2 || '').trim();
                            var levelKey2 = String(lvl2 || '').trim();
                            var selectedTypesWithCounts = collectSelectedVerificationTypesWithCounts(fullStageKey2, levelKey2);

                            saveTypesChain = saveTypesChain.then(function (prevOk) {
                                if (!prevOk) return false;
                                return saveJobRoleVerificationTypes(jrId3, selectedTypesWithCounts, fullStageKey2, levelKey2);
                            });
                        });
                    });
                });
                return saveTypesChain;
            })
            .then(function (ok2) {
                if (!ok2) return false;
                setVerificationMessage('Stage configuration saved.', 'success');
                loadVerificationSummary(urlClientId);
                scrollToEl(summaryEl);
                return true;
            })
            .catch(function (e) {
                setVerificationMessage(e && e.message ? e.message : 'Failed to save', 'danger');
                return false;
            });
    }

    function resetForm() {
        if (!form) return;
        try {
            form.reset();
        } catch (e) {
        }

        // Ensure checkboxes that might not be part of native reset state are cleared
        form.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = false;
        });

        // Recompute multi-select labels
        document.querySelectorAll('.multi-select').forEach(function (ms) {
            var labelEl = ms.querySelector('.multi-select-label');
            var defaultText = ms.getAttribute('data-label-default') || 'Select options';
            if (labelEl) labelEl.textContent = defaultText;
            ms.classList.remove('open');
        });
    }

    function serializeFormToObject() {
        if (!form) return {};
        var fd = new FormData(form);
        var obj = {};
        fd.forEach(function (val, key) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                if (!Array.isArray(obj[key])) obj[key] = [obj[key]];
                obj[key].push(val);
            } else {
                obj[key] = val;
            }
        });
        return obj;
    }

    function restoreDraftToForm() {
        if (!form) return;
        var raw = null;
        try {
            raw = window.localStorage ? window.localStorage.getItem(STORAGE_KEY) : null;
        } catch (e) {
            raw = null;
        }

        if (!raw) return;

        var draft = null;
        try {
            draft = JSON.parse(raw);
        } catch (e) {
            draft = null;
        }
        if (!draft || typeof draft !== 'object') return;

        Object.keys(draft).forEach(function (name) {
            var val = draft[name];
            var nodes = form.querySelectorAll('[name="' + CSS.escape(name) + '"]');
            if (!nodes || !nodes.length) return;

            nodes.forEach(function (el) {
                if (el.type === 'checkbox') {
                    el.checked = !!val && (val === 1 || val === '1' || val === true || val === 'on');
                    return;
                }
                if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
                    if (el.type === 'file') return;
                    el.value = (val === null || typeof val === 'undefined') ? '' : String(val);
                }
            });
        });

        // Refresh multi-select labels after restore
        document.querySelectorAll('.multi-select').forEach(function (ms) {
            try {
                var event = new Event('change');
                ms.querySelectorAll('input[type="checkbox"], input[type="radio"]').forEach(function (cb) {
                    cb.dispatchEvent(event);
                });
            } catch (e) {
            }
        });
    }

    function saveDraft() {
        try {
            if (!window.localStorage) return;
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(serializeFormToObject()));
        } catch (e) {
        }
    }

    function clearDraft() {
        try {
            if (!window.localStorage) return;
            window.localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
        }
    }

    function validateFields(fieldNames) {
        if (!form) return { ok: true };
        for (var i = 0; i < fieldNames.length; i++) {
            var name = fieldNames[i];
            var el = form.querySelector('[name="' + CSS.escape(name) + '"]');
            if (!el) continue;
            var v = '';
            if (el.type === 'checkbox') {
                v = el.checked ? '1' : '';
            } else {
                v = (el.value || '').trim();
            }
            if (!v) {
                return { ok: false, field: name, message: "Please fill required field: " + name };
            }
        }
        return { ok: true };
    }

    function validateClientTatFields() {
        if (!form) return { ok: true };

        var internalTatEl = form.querySelector('[name="internal_tat"]');
        var externalTatEl = form.querySelector('[name="external_tat"]');
        if (!internalTatEl || !externalTatEl) {
            return { ok: true };
        }

        var internalTatRaw = String(internalTatEl.value || '').trim();
        var externalTatRaw = String(externalTatEl.value || '').trim();
        if (internalTatRaw === '' || externalTatRaw === '') {
            return { ok: true };
        }

        var internalTat = parseFloat(internalTatRaw);
        var externalTat = parseFloat(externalTatRaw);
        if (!isFinite(internalTat) || !isFinite(externalTat)) {
            return { ok: true };
        }

        if (internalTat > externalTat) {
            return {
                ok: false,
                field: 'internal_tat',
                message: 'GSS TAT cannot be greater than Client TAT.'
            };
        }

        return { ok: true };
    }

    var multiSelects = document.querySelectorAll('.multi-select');

    function updateMultiSelectLabel(ms) {
        var labelEl = ms.querySelector('.multi-select-label');
        var defaultText = ms.getAttribute('data-label-default') || 'Select options';
        var checked = Array.prototype.slice.call(ms.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked'));
        if (checked.length === 0) {
            labelEl.textContent = defaultText;
        } else if (checked.length === 1) {
            var single = checked[0].closest('label');
            labelEl.textContent = single ? single.textContent.trim() : defaultText;
        } else {
            labelEl.textContent = checked.length + ' selected';
        }
    }

    multiSelects.forEach(function (ms) {
        var trigger = ms.querySelector('.multi-select-trigger');
        if (!trigger) return;

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = ms.classList.contains('open');
            document.querySelectorAll('.multi-select.open').forEach(function (other) {
                if (other !== ms) {
                    other.classList.remove('open');
                }
            });
            if (!isOpen) {
                ms.classList.add('open');
            } else {
                ms.classList.remove('open');
            }
        });

        ms.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        var checkboxes = ms.querySelectorAll('input[type="checkbox"]');
        checkboxes.forEach(function (cb) {
            cb.addEventListener('change', function () {
                updateMultiSelectLabel(ms);
            });
        });

        updateMultiSelectLabel(ms);
    });

    document.addEventListener('click', function () {
        document.querySelectorAll('.multi-select.open').forEach(function (ms) {
            ms.classList.remove('open');
        });
    });

    // Tab switching
    document.querySelectorAll('.tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = this.getAttribute('data-tab');
            if (!key) return;
            if (key === 'verification' && this.classList.contains('is-disabled')) {
                setVerificationMessage('Please save the client first to configure verification profile.', 'warning');
                return;
            }
            setVerificationMessage('', '');
            setActiveTab(key);
        });
    });

    if (stageSelectEl) {
        stageSelectEl.addEventListener('change', function () {
            onSelectionChanged();
        });
        stageSelectEl.querySelectorAll('.cv_stage_cb').forEach(function (cb) {
            cb.addEventListener('change', function () {
                setVerificationMessage('', '');
                refreshStagePills();
                onSelectionChanged();
            });

            try {
                var pill2 = cb.closest ? cb.closest('.cv-stage-pill') : null;
                if (pill2) pill2.classList.toggle('is-on', !!cb.checked);
            } catch (e) {
            }
        });

        refreshStagePills();
    }

    if (jobRoleAddBtn) {
        jobRoleAddBtn.addEventListener('click', function () {
            setVerificationMessage('', '');
            var cid = parseInt(urlClientId || '0', 10) || 0;
            if (cid <= 0) {
                setVerificationMessage('Please save the client first.', 'warning');
                return;
            }
            var name = jobRoleNewEl ? (jobRoleNewEl.value || '').trim() : '';
            if (!name) {
                setVerificationMessage('Enter job role name.', 'danger');
                return;
            }

            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/gssadmin/job_role_add.php';
            setVerificationMessage('Adding job role...', 'info');
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ client_id: cid, role_name: name }),
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data || data.status !== 1 || !data.data) {
                        throw new Error((data && data.message) ? data.message : 'Failed to add role');
                    }
                    if (jobRoleNewEl) jobRoleNewEl.value = '';
                    var newId = parseInt(data.data.job_role_id || '0', 10) || 0;
                    setVerificationMessage('Job role added.', 'success');
                    return loadJobRoles(cid).then(function () {
                        if (!jobRoleBoxEl) return;
                        var cb = jobRoleBoxEl.querySelector('.cv_jr_cb[data-jr-id="' + CSS.escape(String(newId)) + '"]');
                        if (cb) {
                            cb.checked = true;
                            onSelectionChanged();
                        }
                        loadVerificationSummary(cid);
                    });
                })
                .catch(function (e) {
                    setVerificationMessage(e && e.message ? e.message : 'Failed to add role', 'danger');
                });
        });
    }

    if (levelAddBtn) {
        levelAddBtn.addEventListener('click', function () {
            setVerificationMessage('', '');
            var name = levelNewEl ? (levelNewEl.value || '').trim() : '';
            if (!name) {
                setVerificationMessage('Enter level name.', 'danger');
                return;
            }

            var addList = parseLevelInputToList(name);
            if (!addList.length) {
                setVerificationMessage('Enter a valid level (example: L1 or L10).', 'danger');
                return;
            }

            var selected = getSelectedLevelsSet();

            var set = {};
            (LEVELS || []).forEach(function (l) {
                var v = String(l || '').trim();
                if (v) set[v.toLowerCase()] = v;
            });

            addList.forEach(function (lvl2) {
                var v2 = String(lvl2 || '').trim();
                if (v2) set[v2.toLowerCase()] = v2;
            });
            LEVELS = Object.keys(set).map(function (k) { return set[k]; });

            if (levelNewEl) levelNewEl.value = '';
            renderLevels(LEVELS);

            // Re-apply selection
            if (levelBoxEl) {
                levelBoxEl.querySelectorAll('.cv_lvl_cb').forEach(function (cb) {
                    var v2 = String(cb.getAttribute('data-lvl') || '').trim().toLowerCase();
                    cb.checked = !!(v2 && selected[v2]);
                });
            }

            onSelectionChanged();
        });
    }

    if (typesSaveBtn) {
        typesSaveBtn.addEventListener('click', function () {
            saveJobRoleStageConfig(0);
        });
    }

    var summaryViewListBtn = document.getElementById('cv_summary_view_list');
    var summaryViewKanbanBtn = document.getElementById('cv_summary_view_kanban');

    function setSummaryView(view) {
        try {
            if (window.localStorage) window.localStorage.setItem('cv_summary_view', view);
        } catch (e) {
        }
        loadVerificationSummary(urlClientId);
    }

    if (summaryViewListBtn) {
        summaryViewListBtn.addEventListener('click', function () {
            setSummaryView('list');
        });
    }
    if (summaryViewKanbanBtn) {
        summaryViewKanbanBtn.addEventListener('click', function () {
            setSummaryView('kanban');
        });
    }

    // Enable drag/drop UX on stages
    setupStageDropZones();

    // If editing an existing client, always prefer DB values.
    // Do not restore local drafts that may overwrite DB data.
    if (urlClientId > 0) {
        clearDraft();
    } else {
        restoreDraftToForm();
    }

    function fillFormFromClientData(data) {
        if (!form || !data) return;

        Object.keys(data).forEach(function (key) {
            var nodes = form.querySelectorAll('[name="' + CSS.escape(key) + '"]');
            if (!nodes || !nodes.length) return;

            nodes.forEach(function (el) {
                if (el.type === 'checkbox') {
                    el.checked = !!data[key] && (data[key] === 1 || data[key] === '1' || data[key] === true);
                    return;
                }
                if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
                    if (el.type === 'file') return;
                    el.value = (data[key] === null || typeof data[key] === 'undefined') ? '' : String(data[key]);
                }
            });
        });

        // Recompute multi-select labels
        document.querySelectorAll('.multi-select').forEach(function (ms) {
            var checkboxes = ms.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(function (cb) {
                try {
                    cb.dispatchEvent(new Event('change'));
                } catch (e) {
                }
            });
        });

        if (customerLogoPathField && typeof data.customer_logo_path !== 'undefined' && data.customer_logo_path !== null) {
            customerLogoPathField.value = String(data.customer_logo_path || '');
        }

        if (sowPdfPathField && typeof data.sow_pdf_path !== 'undefined' && data.sow_pdf_path !== null) {
            sowPdfPathField.value = String(data.sow_pdf_path || '');
        }

        if (sowPdfCurrent) {
            var sow = (data && data.sow_pdf_path) ? String(data.sow_pdf_path) : '';
            if (sow) {
                sowPdfCurrent.style.display = '';
                sowPdfCurrent.innerHTML = 'Current SOW: <a href="' + sow.replace(/"/g, '&quot;') + '" target="_blank" style="text-decoration:none; color:#2563eb;">Download PDF</a>';
            } else {
                sowPdfCurrent.style.display = 'none';
                sowPdfCurrent.innerHTML = '';
            }
        }

        if (customerLogoPreview && customerLogoPreviewPlaceholder) {
            var p = (data && data.customer_logo_path) ? String(data.customer_logo_path) : '';
            if (p) {
                customerLogoPreview.src = p;
                customerLogoPreview.style.display = '';
                customerLogoPreviewPlaceholder.style.display = 'none';
            } else {
                customerLogoPreview.removeAttribute('src');
                customerLogoPreview.style.display = 'none';
                customerLogoPreviewPlaceholder.style.display = '';
            }
        }
    }

    function previewSelectedLogo(file) {
        if (!customerLogoPreview || !customerLogoPreviewPlaceholder) return;
        if (!file) {
            customerLogoPreview.removeAttribute('src');
            customerLogoPreview.style.display = 'none';
            customerLogoPreviewPlaceholder.style.display = '';
            return;
        }
        var url = URL.createObjectURL(file);
        customerLogoPreview.src = url;
        customerLogoPreview.style.display = '';
        customerLogoPreviewPlaceholder.style.display = 'none';
    }

    // Edit mode: load existing client and prefill
    if (urlClientId > 0) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        fetch(base + '/api/gssadmin/get_client.php?client_id=' + encodeURIComponent(urlClientId), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load client.');
                }
                fillFormFromClientData(data.data);
                setMessage('Edit mode: loaded from database.', 'success');
            })
            .catch(function () {
                setMessage('Network error while loading client.', 'danger');
            });
    }

    if (!form) return;

    if (customerLogoInput) {
        customerLogoInput.addEventListener('change', function () {
            var file = this.files && this.files.length ? this.files[0] : null;
            previewSelectedLogo(file);
        });
    }

    if (sowPdfInput && sowPdfCurrent) {
        sowPdfInput.addEventListener('change', function () {
            var file = this.files && this.files.length ? this.files[0] : null;
            if (file) {
                sowPdfCurrent.style.display = '';
                sowPdfCurrent.textContent = 'Selected SOW: ' + file.name;
            }
        });
    }


    function normalizeApiResponse(data) {
        if (!data || typeof data !== 'object') {
            return { ok: false, message: 'Invalid server response.' };
        }

        if (Object.prototype.hasOwnProperty.call(data, 'success')) {
            return { ok: !!data.success, message: data.message || '' };
        }

        if (Object.prototype.hasOwnProperty.call(data, 'status')) {
            var ok = String(data.status) === '1' || data.status === 1;
            var msg = '';

            if (typeof data.message === 'string') {
                msg = data.message;
            } else if (Array.isArray(data.message) && data.message.length) {
                var first = data.message[0];
                if (first && first.status === 1 && first.result && first.result.client_id) {
                    msg = 'Client created successfully. ID: ' + first.result.client_id;
                } else if (first && first.error) {
                    msg = first.error;
                } else {
                    msg = ok ? 'Saved successfully.' : 'Failed to save.';
                }
            } else {
                msg = ok ? 'Saved successfully.' : 'Failed to save.';
            }

            return { ok: ok, message: msg };
        }

        return { ok: false, message: 'Invalid server response.' };
    }

    if (finalSubmitBtn) {
        finalSubmitBtn.addEventListener('click', function () {
            setMessage('', '');

            // Validate required fields across all steps before final submit
            var allRequired = ['customer_name'];
            var validation = validateFields(allRequired);
            if (!validation.ok) {
                setMessage(validation.message, 'danger');
                return;
            }

            var tatValidation = validateClientTatFields();
            if (!tatValidation.ok) {
                setMessage(tatValidation.message, 'danger');
                try {
                    var tatField = form.querySelector('[name="' + CSS.escape(tatValidation.field) + '"]');
                    if (tatField) tatField.focus();
                } catch (e) {
                }
                return;
            }

            saveDraft();

            var fd = new FormData(form);

            // Ensure client_id is present when editing
            if (urlClientId > 0) {
                fd.set('client_id', String(urlClientId));
            }

            finalSubmitBtn.disabled = true;
            var originalText = finalSubmitBtn.textContent;
            finalSubmitBtn.textContent = 'Submitting...';

            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var endpoint = urlClientId > 0 ? (base + '/api/gssadmin/update_client.php') : (base + '/api/gssadmin/create_client.php');

            fetch(endpoint, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
                .then(function (res) {
                    return res.json().catch(function () {
                        return { success: false, message: 'Invalid server response.' };
                    });
                })
                .then(function (data) {
                    var normalized = normalizeApiResponse(data);
                    if (!normalized.ok) {
                        setMessage(normalized.message || 'Failed to create client.', 'danger');
                        return;
                    }

                    if (urlClientId > 0) {
                        setMessage(normalized.message || 'Client updated successfully.', 'success');
                        clearDraft();
                    } else {
                        setMessage(normalized.message || 'Client created successfully.', 'success');
                        clearDraft();
                        var newClientId = 0;
                        try {
                            if (data && Array.isArray(data.message) && data.message.length) {
                                var first = data.message[0];
                                if (first && first.status === 1 && first.result && first.result.client_id) {
                                    newClientId = parseInt(first.result.client_id || '0', 10) || 0;
                                }
                            }
                        } catch (e) {
                            newClientId = 0;
                        }

                        if (newClientId > 0) {
                            urlClientId = newClientId;
                            if (clientIdField) clientIdField.value = String(newClientId);
                            setVerificationClientId(newClientId);
                            setActiveTab('verification');
                            return;
                        }

                        resetForm();
                    }
                })
                .catch(function () {
                    setMessage('Network error. Please try again.', 'danger');
                })
                .finally(function () {
                    finalSubmitBtn.disabled = false;
                    finalSubmitBtn.textContent = originalText;
                });
        });
    }

    // Default tab
    setActiveTab('client');
});
