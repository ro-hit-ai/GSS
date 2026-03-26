(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('gssPdfPreviewRoot');
        if (!root) return;

        var messageEl = document.getElementById('gssPdfPreviewMessage');
        var frame = document.getElementById('gssPdfLayoutFrame');
        var openPrint = document.getElementById('gssPdfOpenPrint');
        var imagesHost = document.getElementById('gssPdfImagesHost');

        var applicationId = String(root.getAttribute('data-application-id') || '').trim();
        var clientId = parseInt(root.getAttribute('data-client-id') || '0', 10) || 0;

        function setMessage(text, type) {
            if (!messageEl) return;
            messageEl.textContent = text || '';
            messageEl.className = type ? ('alert alert-' + type) : '';
            messageEl.style.display = text ? 'block' : 'none';
        }

        function escapeHtml(str) {
            return String(str || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function normalizeDocType(v) {
            var t = String(v || '').toLowerCase().trim();
            if (t === 'identification') return 'id';
            if (t === 'address') return 'contact';
            return t || 'general';
        }

        function docHref(row) {
            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var fp = row && row.file_path ? String(row.file_path) : '';
            if (!fp) return '';
            if (/^https?:\/\//i.test(fp)) return fp;
            if (fp.indexOf('/') === 0) return base + fp;
            return base + '/' + fp;
        }

        function isImageRow(r) {
            var mime = String(r && r.mime_type ? r.mime_type : '').toLowerCase();
            var fp = String(r && r.file_path ? r.file_path : '').toLowerCase();
            return mime.indexOf('image/') === 0 || /\.(jpg|jpeg|png|webp|gif)$/i.test(fp);
        }

        function printPageHref() {
            var href = '../shared/candidate_report.php?role=gss_admin&print=1&application_id=' + encodeURIComponent(applicationId);
            if (clientId > 0) href += '&client_id=' + encodeURIComponent(String(clientId));
            return href;
        }

        function renderImages(rows) {
            if (!imagesHost) return;
            var list = Array.isArray(rows) ? rows.filter(isImageRow) : [];
            if (!list.length) {
                imagesHost.innerHTML = '<div style="color:#64748b; font-size:13px;">No JPG/PNG/WEBP images found in uploaded verification documents.</div>';
                return;
            }

            var groups = {};
            list.forEach(function (r) {
                var k = normalizeDocType(r && r.doc_type ? r.doc_type : 'general');
                if (!groups[k]) groups[k] = [];
                groups[k].push(r);
            });

            var order = ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'reports', 'general'];
            var keys = Object.keys(groups).sort(function (a, b) {
                var ia = order.indexOf(a);
                var ib = order.indexOf(b);
                if (ia === -1 && ib === -1) return a.localeCompare(b);
                if (ia === -1) return 1;
                if (ib === -1) return -1;
                return ia - ib;
            });

            imagesHost.innerHTML = keys.map(function (k) {
                var label = k.charAt(0).toUpperCase() + k.slice(1);
                var cards = (groups[k] || []).map(function (r) {
                    var href = docHref(r);
                    var name = r && (r.original_name || r.file_path) ? String(r.original_name || r.file_path) : 'Image';
                    return '' +
                        '<a href="' + escapeHtml(href) + '" target="_blank" rel="noopener" style="display:block; text-decoration:none; color:inherit;">' +
                            '<div style="border:1px solid rgba(148,163,184,0.28); border-radius:10px; overflow:hidden; background:#fff;">' +
                                '<img src="' + escapeHtml(href) + '" alt="' + escapeHtml(name) + '" style="width:100%; height:160px; object-fit:cover; display:block;">' +
                                '<div style="padding:8px; font-size:12px; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + escapeHtml(name) + '</div>' +
                            '</div>' +
                        '</a>';
                }).join('');

                return '' +
                    '<div style="margin-bottom:14px;">' +
                        '<div style="font-size:12px; font-weight:900; letter-spacing:.08em; text-transform:uppercase; color:#334155; margin-bottom:8px;">' + escapeHtml(label) + '</div>' +
                        '<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:10px;">' + cards + '</div>' +
                    '</div>';
            }).join('');
        }

        function loadImages() {
            if (!applicationId) {
                setMessage('application_id is required.', 'danger');
                renderImages([]);
                return;
            }

            var base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            var url = base + '/api/shared/verification_docs_list.php?application_id=' + encodeURIComponent(applicationId);
            fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json().catch(function () { return null; }); })
                .then(function (data) {
                    if (!data || data.status !== 1) {
                        throw new Error((data && data.message) ? data.message : 'Failed to load images');
                    }
                    renderImages(data.data || []);
                })
                .catch(function (e) {
                    setMessage(e && e.message ? e.message : 'Failed to load images', 'danger');
                    renderImages([]);
                });
        }

        if (!applicationId) {
            setMessage('application_id is required in URL.', 'danger');
            return;
        }

        var previewHref = printPageHref();
        if (frame) frame.src = previewHref;
        if (openPrint) openPrint.href = previewHref;

        loadImages();
    });
})();

