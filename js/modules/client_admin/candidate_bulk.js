(function () {
    function el(id) {
        return document.getElementById(id);
    }

    async function loadClientJobRoles() {
        var roleSelect = el('bulk_job_role');
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
                var name = (r && r.role_name) ? String(r.role_name) : '';
                if (!name) return;
                var opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                roleSelect.appendChild(opt);
            });
        } catch (e) {
            roleSelect.innerHTML = '<option value="">-- Select --</option>';
        }
    }

    async function loadClientLocations() {
        var locationSelect = el('bulk_joining_location');
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

    function setMessage(text, type) {
        var box = el('candidateBulkMessage');
        if (!box) return;

        box.style.display = text ? 'block' : 'none';
        box.className = 'alert ' + (type === 'success' ? 'alert-success' : 'alert-danger');
        box.textContent = text || '';
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getSelectedFile() {
        var input = el('bulk_file');
        if (!input || !input.files || !input.files.length) return null;
        return input.files[0] || null;
    }

    function isCsvFile(file) {
        if (!file || !file.name) return false;
        return /\.csv$/i.test(String(file.name));
    }

    function renderResults(items) {
        var card = el('bulkResultsCard');
        var table = el('bulkResultsTable');
        if (!card || !table) return;

        var tbody = table.querySelector('tbody');
        if (!tbody) return;

        tbody.innerHTML = '';

        (items || []).forEach(function (r, idx) {
            var tr = document.createElement('tr');

            var inviteCell = '';
            if (r.invite_url) {
                inviteCell = '<a href="' + escapeHtml(r.invite_url) + '" target="_blank">Open</a>';
            }

            tr.innerHTML =
                '<td>' + escapeHtml(String(idx + 1)) + '</td>' +
                '<td>' + escapeHtml(r.candidate) + '</td>' +
                '<td>' + escapeHtml(r.email) + '</td>' +
                '<td>' + escapeHtml(r.status) + '</td>' +
                '<td>' + inviteCell + '</td>' +
                '<td>' + escapeHtml(r.message) + '</td>';

            tbody.appendChild(tr);
        });

        card.style.display = 'block';
    }

    async function uploadBulk() {
        var form = el('candidateBulkForm');
        if (!form) return;

        setMessage('', '');

        var selectedFile = getSelectedFile();
        if (!selectedFile) {
            setMessage('Please choose a CSV file to upload.', 'error');
            return;
        }

        if (!isCsvFile(selectedFile)) {
            setMessage('Only CSV files are supported on this page right now.', 'error');
            return;
        }

        var btn = el('btnBulkUpload');
        if (btn) {
            btn.disabled = true;
            btn.dataset.originalText = btn.dataset.originalText || btn.textContent;
            btn.textContent = 'Uploading...';
        }

        try {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/client_admin/bulk_upload_cases.php';

            var fd = new FormData(form);

            var res = await fetch(url, {
                method: 'POST',
                body: fd
            });

            var data = await res.json().catch(function () { return null; });
            if (!res.ok || !data || data.status !== 1) {
                var msg = (data && data.message) ? data.message : 'Bulk upload failed.';
                throw new Error(msg);
            }

            setMessage(data.message || 'Bulk upload completed.', 'success');
            renderResults((data.data && data.data.results) ? data.data.results : []);
        } catch (e) {
            setMessage(e && e.message ? e.message : 'Bulk upload failed.', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset.originalText || 'Upload & Send Invites';
            }
        }
    }

    function init() {
        var btn = el('btnBulkUpload');
        if (btn) btn.addEventListener('click', uploadBulk);

        loadClientLocations();
        loadClientJobRoles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
