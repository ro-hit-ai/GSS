document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('userCreateForm');
    var messageEl = document.getElementById('userCreateMessage');
    var clientSelect = document.getElementById('userClientSelect');
    var clientDropdown = document.getElementById('userClientDropdown');
    var clientDropdownToggle = document.getElementById('userClientDropdownToggle');
    var clientDropdownMenu = document.getElementById('userClientDropdownMenu');
    var clientDropdownList = document.getElementById('userClientDropdownList');
    var clientSearch = document.getElementById('userClientSearch');
    var locationSelect = document.getElementById('userLocationSelect');
    var formActionField = document.getElementById('userFormAction');
    var saveNextBtn = document.getElementById('userSaveNextBtn');
    var finalSubmitBtn = document.getElementById('userFinalSubmitBtn');
    var userIdField = document.getElementById('userId');
    var tabButtons = document.querySelectorAll('.tab');
    var allClients = [];

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

    function fillForm(data) {
        if (!form || !data) return;
        Object.keys(data).forEach(function (k) {
            if (k === 'locations') return;
            var el = form.querySelector('[name="' + CSS.escape(k) + '"]');
            if (!el) return;
            el.value = (data[k] === null || typeof data[k] === 'undefined') ? '' : String(data[k]);
        });
    }

    function renderClientOptions(selectedClientId, searchText) {
        if (!clientSelect) return;

        var selectedValue = String(selectedClientId || clientSelect.value || '');
        var term = String(searchText || '').trim().toLowerCase();

        clientSelect.innerHTML = '<option value="">Select Client</option>';
        if (clientDropdownList) {
            clientDropdownList.innerHTML = '';
        }

        allClients.forEach(function (c) {
            var cid = String(c.client_id || '');
            var label = String(c.customer_name || ('Client #' + cid));
            if (term && label.toLowerCase().indexOf(term) === -1) return;

            var opt = document.createElement('option');
            opt.value = cid;
            opt.textContent = label;
            clientSelect.appendChild(opt);

            if (clientDropdownList) {
                var item = document.createElement('button');
                item.type = 'button';
                item.setAttribute('data-client-id', cid);
                item.style.width = '100%';
                item.style.textAlign = 'left';
                item.style.padding = '10px 12px';
                item.style.border = '0';
                item.style.borderRadius = '10px';
                item.style.background = cid === selectedValue ? '#e8f1ff' : '#fff';
                item.style.cursor = 'pointer';
                item.textContent = label;
                item.addEventListener('click', function () {
                    selectClient(cid);
                    closeClientDropdown();
                    try { clientDropdownToggle.focus(); } catch (e) {}
                });
                clientDropdownList.appendChild(item);
            }
        });

        if (selectedValue) {
            clientSelect.value = selectedValue;
        }
        updateClientToggleLabel();
    }

    function updateClientToggleLabel() {
        if (!clientDropdownToggle || !clientSelect) return;
        var selectedOption = clientSelect.options[clientSelect.selectedIndex];
        clientDropdownToggle.textContent = selectedOption && selectedOption.value
            ? selectedOption.textContent
            : 'Select Client';
    }

    function openClientDropdown() {
        if (!clientDropdownMenu) return;
        clientDropdownMenu.style.display = 'block';
        if (clientSearch) {
            clientSearch.value = '';
            renderClientOptions(clientSelect ? clientSelect.value : '', '');
            try { clientSearch.focus(); } catch (e) {}
        }
    }

    function closeClientDropdown() {
        if (!clientDropdownMenu) return;
        clientDropdownMenu.style.display = 'none';
    }

    function selectClient(clientId) {
        if (!clientSelect) return;
        clientSelect.value = String(clientId || '');
        updateClientToggleLabel();
        renderClientOptions(clientSelect.value, clientSearch ? clientSearch.value : '');
        loadLocationsForClient(clientSelect.value, null);
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
    }

    function loadClients(selectedClientId) {
        if (!clientSelect) return Promise.resolve();
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        return fetch(base + '/api/gssadmin/clients_dropdown.php', { credentials: 'same-origin' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 1 || !Array.isArray(data.data)) {
                    throw new Error((data && data.message) ? data.message : 'Failed to load clients');
                }

                allClients = [];
                data.data.forEach(function (c) {
                    if (parseInt(c.client_id || '0', 10) === 1) return;
                    allClients.push({
                        client_id: String(c.client_id || ''),
                        customer_name: c.customer_name || ('Client #' + c.client_id)
                    });
                });
                renderClientOptions(selectedClientId, clientSearch ? clientSearch.value : '');

                if (selectedClientId && selectedClientId > 0) {
                    clientSelect.value = String(selectedClientId);
                } else {
                    clientSelect.disabled = false;
                }
                updateClientToggleLabel();
            });
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

    if (!form) return;

    var clientId = parseInt(getQueryParam('client_id') || '0', 10) || 0;
    var userId = parseInt(getQueryParam('user_id') || '0', 10) || 0;

    if (userIdField && userId > 0) {
        userIdField.value = String(userId);
    }

    loadClients(clientId)
        .then(function () {
            if (clientSelect && clientSelect.value) {
                return loadLocationsForClient(clientSelect.value, null);
            }
            return;
        })
        .then(function () {
            if (userId > 0) {
                var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
                return fetch(base + '/api/gssadmin/get_user.php?user_id=' + encodeURIComponent(userId), { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || data.status !== 1 || !data.data) {
                            throw new Error((data && data.message) ? data.message : 'Failed to load user');
                        }
                        fillForm(data.data);
                        if (clientSelect && data.data.client_id) {
                            clientSelect.value = String(data.data.client_id);
                            updateClientToggleLabel();
                            renderClientOptions(clientSelect.value, clientSearch ? clientSearch.value : '');
                        }
                        loadLocationsForClient(clientSelect ? clientSelect.value : 0, (data.data.locations && Array.isArray(data.data.locations) ? data.data.locations : (data.data.location || '')));
                        setMessage('Edit mode: loaded from database.', 'success');
                    });
            }
        })
        .catch(function (e) {
            setMessage(e && e.message ? e.message : 'Failed to load clients.', 'danger');
        });

    if (clientSelect) {
        clientSelect.addEventListener('change', function () {
            updateClientToggleLabel();
            loadLocationsForClient(clientSelect.value, null);
        });
    }

    if (clientSearch) {
        clientSearch.addEventListener('input', function () {
            renderClientOptions(clientSelect ? clientSelect.value : '', clientSearch.value);
        });
    }

    if (clientDropdownToggle) {
        clientDropdownToggle.addEventListener('click', function () {
            if (clientDropdownMenu && clientDropdownMenu.style.display === 'block') {
                closeClientDropdown();
            } else {
                openClientDropdown();
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (!clientDropdown) return;
        if (clientDropdown.contains(e.target)) return;
        closeClientDropdown();
    });

    function setFormAction(val) {
        if (!formActionField) return;
        formActionField.value = val;
    }

    showTab('personal');

    if (tabButtons && tabButtons.length) {
        tabButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var key = btn.getAttribute('data-tab');
                if (key) showTab(key);
            });
        });
    }

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

        var fd = new FormData(form);

        var isEdit = userId > 0;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
        var apiUrl = isEdit ? (base + '/api/gssadmin/update_user.php') : (base + '/api/gssadmin/create_user.php');

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
                    setMessage((data && data.message) ? data.message : 'Failed to save user.', 'danger');
                    return;
                }

                if (!isEdit && data && data.data && data.data.temp_password) {
                    var msg = 'User created successfully. Temporary Password: ' + String(data.data.temp_password);
                    if (String(data.data.email_sent || '0') === '1') {
                        msg += ' (Email sent)';
                    }
                    setMessage(msg, 'success');
                } else {
                    setMessage(isEdit ? 'User updated successfully.' : 'User saved successfully.', 'success');
                }

                var action = (fd.get('form_action') || 'save').toString();
                if (action === 'final_submit' || action === 'save_next') {
                    var cid = fd.get('client_id') || clientId;
                    setTimeout(function () {
                        if (cid) {
                            window.location.href = 'users_list.php?client_id=' + encodeURIComponent(String(cid));
                        } else {
                            window.location.href = 'users_list.php';
                        }
                    }, 600);
                }
            })
            .catch(function () {
                setMessage('Network error. Please try again.', 'danger');
            });
    });
});
