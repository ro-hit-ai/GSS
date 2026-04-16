document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('staffUserCreateForm');
    var messageEl = document.getElementById('staffUserCreateMessage');
    var clientIdField = document.getElementById('staffUserClientId');
    var locationSelect = document.getElementById('staffUserLocationSelect');
    var formActionField = document.getElementById('staffUserFormAction');
    var saveNextBtn = document.getElementById('staffUserSaveNextBtn');
    var finalSubmitBtn = document.getElementById('staffUserFinalSubmitBtn');
    var userIdField = document.getElementById('staffUserId');
    var tabButtons = document.querySelectorAll('.tab');
    var ALLOWED_SECTIONS_MASTER = [];
    var pendingAllowedSectionsValue = '';

    function setMessage(text, type) {
        if (!messageEl) return;
        messageEl.textContent = text || '';
        messageEl.className = type ? ('alert alert-' + type) : '';
        messageEl.style.display = text ? 'block' : 'none';
    }

    function getQueryParam(name) {
        try {
            var params = new URLSearchParams(window.location.search || '');
            return params.get(name);
        } catch (e) {
            return null;
        }
    }

    function showTab(tabKey) {
        tabButtons.forEach(function (t) {
            t.classList.toggle('active', t.getAttribute('data-tab') === tabKey);
        });

        var panels = document.querySelectorAll('.tab-panel');
        panels.forEach(function (p) {
            p.classList.toggle('active', p.id === ('tab-' + tabKey));
        });

        if (saveNextBtn && finalSubmitBtn) {
            if (tabKey === 'usertype') {
                saveNextBtn.style.display = 'none';
                finalSubmitBtn.style.display = 'inline-block';
            } else {
                saveNextBtn.style.display = 'inline-block';
                finalSubmitBtn.style.display = 'none';
            }
        }

        // Re-apply allowed sections enabled/disabled state on tab switch (timing-safe)
        if (tabKey === 'usertype') {
            try {
                var roleSel = form ? form.querySelector('[name="role"]') : null;
                if (roleSel) {
                    syncAllowedSectionsEnabledForRole(roleSel.value || '');
                }
            } catch (e) {
            }
        }
    }

    function getSelectedClientId() {
        if (!clientIdField) return 0;
        return parseInt(clientIdField.value || '0', 10) || 0;
    }

    function loadLocationsForClient(selectedClientId, selectedLocationName) {
        if (!locationSelect) return Promise.resolve();

        var cid = parseInt(selectedClientId || '0', 10) || 0;
        locationSelect.innerHTML = '<option value="">Select Location</option>';
        if (cid <= 0) return Promise.resolve();

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/gssadmin/client_locations_list.php?client_id=' + encodeURIComponent(String(cid)), { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
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

                if (Array.isArray(selectedLocationName) && selectedLocationName.length) {
                    var set = {};
                    selectedLocationName.forEach(function (v) { set[String(v)] = true; });
                    Array.prototype.slice.call(locationSelect.options).forEach(function (opt) {
                        opt.selected = !!set[String(opt.value)];
                    });
                } else if (selectedLocationName) {
                    Array.prototype.slice.call(locationSelect.options).forEach(function (opt) {
                        opt.selected = String(opt.value) === String(selectedLocationName);
                    });
                }
            })
            .catch(function () {
            });
    }

    function setFormAction(val) {
        if (!formActionField) return;
        formActionField.value = val;
    }

    function setInput(name, value) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        el.value = value == null ? '' : String(value);
    }

    function setSelectValue(name, value) {
        var el = form.querySelector('[name="' + name + '"]');
        if (!el) return;
        var v = value == null ? '' : String(value);
        Array.prototype.slice.call(el.options).forEach(function (opt) {
            opt.selected = String(opt.value) === v;
        });
    }

    function setAllowedSectionsFromString(s) {
        var raw = String(s || '').toLowerCase();
        var set = {};
        raw.split(/[\s,|]+/).forEach(function (p) {
            var k = String(p || '').trim();
            if (k === 'social_media' || k === 'social-media') k = 'socialmedia';
            if (k === 'e_court' || k === 'e-court') k = 'ecourt';
            if (k) set[k] = true;
        });

        var boxes = form.querySelectorAll('input[name="allowed_sections[]"]');
        Array.prototype.slice.call(boxes).forEach(function (cb) {
            cb.checked = !!set[String(cb.value || '').toLowerCase()];
        });
    }

    function getAllowedSectionsStringFromUI() {
        if (!form) return '';
        var out = {};
        var boxes = form.querySelectorAll('input[name="allowed_sections[]"]');
        Array.prototype.slice.call(boxes).forEach(function (cb) {
            if (!cb || cb.disabled) return;
            if (!cb.checked) return;
            var k = String(cb.value || '').toLowerCase().trim();
            if (k === 'social_media' || k === 'social-media') k = 'socialmedia';
            if (k === 'e_court' || k === 'e-court') k = 'ecourt';
            if (!k) return;
            out[k] = true;
        });
        return Object.keys(out).join(',');
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderAllowedSectionsMaster(items) {
        var host = document.getElementById('staffAllowedSectionsHost');
        if (!host) return;

        host.innerHTML = '';

        var roleSel = form ? form.querySelector('[name="role"]') : null;
        var roleNow = roleSel ? String(roleSel.value || '') : '';
        var roleLower = roleNow.toLowerCase().trim();
        var enabledNow = (roleLower === 'verifier' || roleLower === 'db_verifier' || roleLower === 'validator');

        if (!Array.isArray(items) || items.length === 0) {
            host.innerHTML = '<div style="grid-column:1/-1; font-size:12px; color:#6b7280;">No sections configured.</div>';
            return;
        }

        items.forEach(function (it) {
            var key = it && it.key ? String(it.key) : '';
            var label = it && it.label ? String(it.label) : key;
            if (!key) return;
            host.insertAdjacentHTML(
                'beforeend',
                '<label style="display:flex; align-items:center; gap:8px; margin:0;">' +
                '<input type="checkbox" name="allowed_sections[]" value="' + escapeHtml(key) + '"' + (enabledNow ? '' : ' disabled') + '> ' +
                escapeHtml(label) +
                '</label>'
            );
        });
    }

    function loadAllowedSectionsForClient(clientId, selectedSections) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var cid = parseInt(clientId || '0', 10) || 0;
        var url = cid > 0
            ? (base + '/api/gssadmin/client_allowed_sections.php?client_id=' + encodeURIComponent(String(cid)))
            : (base + '/api/shared/allowed_sections_master.php');
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1) {
                    renderAllowedSectionsMaster([]);
                    return;
                }
                ALLOWED_SECTIONS_MASTER = Array.isArray(data.data) ? data.data : [];
                renderAllowedSectionsMaster(ALLOWED_SECTIONS_MASTER);
                if (selectedSections) {
                    setAllowedSectionsFromString(selectedSections);
                }

                // After rendering, apply enabled/disabled state for current selected role.
                var roleSel = form ? form.querySelector('[name="role"]') : null;
                if (roleSel) {
                    syncAllowedSectionsEnabledForRole(roleSel.value || '');

                    // Timing-safe: some browsers may not update disabled state if DOM is still batching.
                    setTimeout(function () {
                        try {
                            syncAllowedSectionsEnabledForRole(roleSel.value || '');
                        } catch (e) {
                        }
                    }, 0);
                }
            })
            .catch(function () {
                renderAllowedSectionsMaster([]);
            });
    }

    function syncAllowedSectionsEnabledForRole(role) {
        var r = String(role || '').toLowerCase().trim();
        var enabled = (r === 'verifier' || r === 'db_verifier' || r === 'validator');
        var boxes = form.querySelectorAll('input[name="allowed_sections[]"]');
        Array.prototype.slice.call(boxes).forEach(function (cb) {
            cb.disabled = !enabled;
            if (enabled) {
                try { cb.removeAttribute('disabled'); } catch (e) {}
            }
            if (!enabled) cb.checked = false;
        });

    }

    function loadUserForEdit(userIdToLoad) {
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var url = base + '/api/gssadmin/get_user.php?user_id=' + encodeURIComponent(String(userIdToLoad));
        return fetch(url, { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !data.data) {
                    setMessage((data && data.message) ? data.message : 'Failed to load user.', 'danger');
                    return;
                }
                var u = data.data;
                setInput('username', u.username || '');
                setInput('first_name', u.first_name || '');
                setInput('middle_name', u.middle_name || '');
                setInput('last_name', u.last_name || '');
                setInput('phone', u.phone || '');
                setInput('email', u.email || '');
                setSelectValue('role', u.role || '');

                if (clientIdField && u.client_id) {
                    clientIdField.value = String(u.client_id);
                }

                pendingAllowedSectionsValue = String(u.allowed_sections || '');
                return loadAllowedSectionsForClient(getSelectedClientId(), pendingAllowedSectionsValue)
                    .then(function () {
                        syncAllowedSectionsEnabledForRole(u.role || '');
                        setAllowedSectionsFromString(pendingAllowedSectionsValue);

                        var selectedLocs = Array.isArray(u.locations) ? u.locations : (u.location ? [u.location] : []);
                        return loadLocationsForClient(String(getSelectedClientId()), selectedLocs);
                    });
            })
            .catch(function () {
                setMessage('Failed to load user details.', 'danger');
            });
    }

    if (!form) return;

    var clientId = parseInt(getQueryParam('client_id') || '0', 10) || 0;
    var userId = parseInt(getQueryParam('user_id') || '0', 10) || 0;

    if (userIdField && userId > 0) {
        userIdField.value = String(userId);
    }

    showTab('personal');

    // Enable/disable allowed sections checkbox list based on selected role
    var roleSelect = form.querySelector('[name="role"]');
    if (roleSelect && !roleSelect.dataset.bound) {
        roleSelect.dataset.bound = '1';
        syncAllowedSectionsEnabledForRole(roleSelect.value || '');
        roleSelect.addEventListener('change', function () {
            // Re-render so disabled attribute is always correct for the selected role
            var prev = getAllowedSectionsStringFromUI();
            if (ALLOWED_SECTIONS_MASTER && ALLOWED_SECTIONS_MASTER.length) {
                renderAllowedSectionsMaster(ALLOWED_SECTIONS_MASTER);
            }
            syncAllowedSectionsEnabledForRole(roleSelect.value || '');
            if (prev) {
                setAllowedSectionsFromString(prev);
            }
        });
    }

    if (clientIdField) {
        clientIdField.addEventListener('change', function () {
            var selectedClientId = getSelectedClientId();
            var prevSections = getAllowedSectionsStringFromUI();
            loadLocationsForClient(String(selectedClientId), null);
            loadAllowedSectionsForClient(selectedClientId, prevSections);
        });
    }

    if (tabButtons && tabButtons.length) {
        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-tab');
                if (key) showTab(key);
            });
        });
    }

    if (clientIdField && clientId > 0) {
        clientIdField.value = String(clientId);
    }

    // Load client-scoped configuration from hidden client_id, then optionally user details (edit mode)
    Promise.resolve()
        .then(function () {
            var selectedClientId = getSelectedClientId();
            return loadAllowedSectionsForClient(selectedClientId, pendingAllowedSectionsValue)
                .then(function () {
                    return loadLocationsForClient(String(selectedClientId), null);
                });
        })
        .then(function () {
            if (userId > 0) {
                return loadUserForEdit(userId);
            } 
        })
        .catch(function () {
        });

    if (saveNextBtn) {
        saveNextBtn.addEventListener('click', function () {
            setMessage('', '');

            var requiredFields = ['username', 'first_name', 'last_name', 'phone', 'email'];
            for (var i = 0; i < requiredFields.length; i++) {
                var f = requiredFields[i];
                var el = form.querySelector('[name="' + f + '"]');
                if (el && !el.value) {
                    setMessage('Please fill all required fields before continuing.', 'danger');
                    try { el.focus(); } catch (e) {}
                    return;
                }
            }

            showTab('usertype');
        });
    }

    if (finalSubmitBtn) {
        finalSubmitBtn.addEventListener('click', function () {
            setFormAction('final_submit');
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        setMessage('', '');

        var roleNow = '';
        try {
            var roleEl = form.querySelector('[name="role"]');
            roleNow = roleEl ? String(roleEl.value || '').toLowerCase().trim() : '';
        } catch (e2) {
            roleNow = '';
        }
        var fd = new FormData(form);

        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var apiUrl = userId > 0 ? (base + '/api/gssadmin/update_user.php') : (base + '/api/gssadmin/create_staff_user.php');

        fetch(apiUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { status: 0, message: 'Invalid server response.' };
                });
            })
            .then(function (data) {
                var ok = data && (data.status === 1 || data.status === '1');
                if (!ok) {
                    setMessage((data && data.message) ? data.message : 'Failed to save staff user.', 'danger');
                    return;
                }

                if (userId <= 0 && data && data.data && data.data.temp_password) {
                    var msg = 'Staff user created successfully. Temporary Password: ' + String(data.data.temp_password);
                    if (String(data.data.email_sent || '0') === '1') {
                        msg += ' (Email sent)';
                    }
                    setMessage(msg, 'success');
                } else {
                    setMessage(userId ? 'Staff user updated successfully.' : 'Staff user created successfully.', 'success');
                }

                setTimeout(function () {
                    window.location.href = 'users_list.php?view=staff';
                }, 600);
            })
            .catch(function () {
                setMessage('Network error. Please try again.', 'danger');
            });
    });
});
