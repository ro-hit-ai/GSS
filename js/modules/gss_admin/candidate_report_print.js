(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('gssAdminPrintRoot');
        if (!root) return;

        var messageEl = document.getElementById('gssAdminPrintMessage');
        var contentEl = document.getElementById('gssAdminPrintContent');
        var summaryEl = document.getElementById('gssAdminPrintSummary');
        var metaEl = document.getElementById('gssAdminPrintMeta');
        var printBtn = document.getElementById('gssAdminPrintBtn');
        var downloadPdfBtn = document.getElementById('gssAdminDownloadPdfBtn');
        var thumbsReadyPromise = Promise.resolve();
        var uploaderContext = {
            candidateName: ''
        };

        var applicationId = String(root.getAttribute('data-application-id') || '').trim();
        var clientId = parseInt(root.getAttribute('data-client-id') || '0', 10) || 0;
        var base = (window.APP_BASE_URL || '').replace(/\/$/, '');

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                setButtonsDisabled(true);
                var href = base + '/api/gssadmin/candidate_report_tcpdf_download.php?inline=1&application_id=' + encodeURIComponent(applicationId || '');
                if (clientId > 0) href += '&client_id=' + encodeURIComponent(String(clientId));
                window.location.href = href + '&_ts=' + Date.now();
            });
        }

        function buildPdfFilename() {
            return 'GSS-Candidate-Report-' + (applicationId || 'NA') + '.pdf';
        }

        function setButtonsDisabled(disabled) {
            if (printBtn) printBtn.disabled = !!disabled;
            if (downloadPdfBtn) downloadPdfBtn.disabled = !!disabled;
        }

        if (downloadPdfBtn) {
            downloadPdfBtn.addEventListener('click', function () {
                setButtonsDisabled(true);
                setMessage('Preparing downloadable PDF...', 'info');
                var href = base + '/api/gssadmin/candidate_report_tcpdf_download.php?application_id=' + encodeURIComponent(applicationId || '');
                if (clientId > 0) href += '&client_id=' + encodeURIComponent(String(clientId));
                window.location.href = href + '&_ts=' + Date.now();
            });
        }

        function esc(v) {
            return String(v || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setMessage(text, type) {
            if (!messageEl) return;
            messageEl.textContent = text || '';
            messageEl.className = type ? ('alert alert-' + type) : 'alert alert-info';
            messageEl.style.display = text ? 'block' : 'none';
        }

        function asArray(v) {
            return Array.isArray(v) ? v : [];
        }

        function isObj(v) {
            return !!v && typeof v === 'object' && !Array.isArray(v);
        }

        function toTitle(k) {
            return String(k || '')
                .replace(/_/g, ' ')
                .replace(/\s+/g, ' ')
                .trim()
                .replace(/\b\w/g, function (m) { return m.toUpperCase(); });
        }

        function caseDisplayStatus(appStatus, caseStatus) {
            var c = String(caseStatus || '').toUpperCase().trim();
            if (c === 'REJECTED' || c === 'STOP_BGV' || c === 'APPROVED' || c === 'VERIFIED' || c === 'COMPLETED' || c === 'CLEAR') {
                return String(caseStatus || '');
            }
            return String(appStatus || caseStatus || '');
        }

        function statusTextClass(status) {
            var s = String(status || '').toLowerCase().trim();
            if (s === 'approved') return 'ok';
            if (s === 'rejected') return 'bad';
            return 'wait';
        }

        function normalizeComponentKey(v) {
            var k = String(v || '').toLowerCase().trim();
            if (k === 'identification') return 'id';
            if (k === 'address') return 'contact';
            if (!k) return 'general';
            return k;
        }

        function hasAnyToken(value, list) {
            var s = String(value || '').toLowerCase();
            return list.some(function (t) { return s.indexOf(t) !== -1; });
        }

        function detectDocGroupFromFilename(fileName) {
            var name = String(fileName || '').toLowerCase();
            if (!name) return 'general';

            if (hasAnyToken(name, ['aadhaar', 'aadhar', 'uid', 'pan', 'passport', 'voter', 'dl', 'license', 'licence', ' id'])) return 'id';
            if (hasAnyToken(name, ['electricity', 'water', 'gas', 'bill', 'rent', 'utility', 'ration', 'address'])) return 'contact';
            if (hasAnyToken(name, ['degree', 'marksheet', 'certificate', 'university', 'college', 'transcript'])) return 'education';
            if (hasAnyToken(name, ['offer', 'appointment', 'salary', 'payslip', 'experience', 'relieving', 'company'])) return 'employment';
            return 'general';
        }

        function isExplicitComponentTypeValid(type) {
            var t = normalizeComponentKey(type);
            return ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'ecourt', 'database', 'reports', 'driving_licence'].indexOf(t) !== -1;
        }

        function resolvedDocComponent(doc) {
            var explicit = normalizeComponentKey(doc && doc.doc_type ? doc.doc_type : '');
            if (isExplicitComponentTypeValid(explicit)) return explicit;

            var fallbackName = String(doc && doc.original_name ? doc.original_name : '');
            if (!fallbackName) fallbackName = String(doc && doc.file_path ? doc.file_path : '');
            var fromName = detectDocGroupFromFilename(fallbackName);
            if (fromName && fromName !== 'general') return fromName;

            var byField = normalizeComponentKey(doc && doc.source_field ? doc.source_field : '');
            if (isExplicitComponentTypeValid(byField)) return byField;

            return 'general';
        }

        function componentAliasKeys(componentKey) {
            var key = normalizeComponentKey(componentKey);
            if (key === 'id') return ['id', 'identification'];
            if (key === 'contact') return ['contact', 'address'];
            return [key];
        }

        function normalizeRole(v) {
            var r = String(v || '').toLowerCase().trim();
            if (r === 'validator' || r === 'va') return 'validator';
            if (r === 'verifier' || r === 've') return 'verifier';
            if (r === 'qa' || r === 'team_lead' || r === 'tl') return 'qa';
            if (!r) return 'unknown';
            return r;
        }

        function roleLabel(v) {
            var r = normalizeRole(v);
            if (r === 'validator') return 'Validator (VA)';
            if (r === 'verifier') return 'Verifier (VE)';
            if (r === 'qa') return 'QA';
            return toTitle(r);
        }

        function normalizeDisplayName(v) {
            return String(v || '').replace(/\s+/g, ' ').trim();
        }

        function uploaderDisplayName(doc) {
            var explicitName = normalizeDisplayName(doc && doc.uploaded_by_name ? doc.uploaded_by_name : '');
            if (explicitName) return explicitName;

            var explicitUser = normalizeDisplayName(doc && doc.uploaded_by_username ? doc.uploaded_by_username : '');
            if (explicitUser) return explicitUser;

            var role = normalizeRole(doc && doc.uploaded_by_role ? doc.uploaded_by_role : doc && doc.__role ? doc.__role : '');
            if (role === 'candidate') {
                return uploaderContext.candidateName || 'Candidate';
            }
            if (role === 'validator') return 'Validator';
            if (role === 'verifier') return 'Verifier';
            if (role === 'qa') return 'QA';
            return roleLabel(role || 'unknown');
        }

        function uploaderDisplayLabel(doc) {
            var role = normalizeRole(doc && doc.uploaded_by_role ? doc.uploaded_by_role : doc && doc.__role ? doc.__role : '');
            var name = uploaderDisplayName(doc);
            if (role === 'candidate') return 'Candidate (' + (name || 'Candidate') + ')';
            if (role === 'validator') return 'Validator (' + (name || 'Validator') + ')';
            if (role === 'verifier') return 'Verifier (' + (name || 'Verifier') + ')';
            if (role === 'qa') return 'QA (' + (name || 'QA') + ')';
            return name || roleLabel(role || 'unknown');
        }

        function componentLabel(k) {
            var n = normalizeComponentKey(k);
            if (n === 'id') return 'Identification';
            if (n === 'basic') return 'Basic';
            if (n === 'contact') return 'Contact';
            if (n === 'education') return 'Education';
            if (n === 'employment') return 'Employment';
            if (n === 'reference') return 'Reference';
            if (n === 'reports') return 'Reports';
            if (n === 'ecourt') return 'E-court';
            if (n === 'database') return 'Database';
            if (n === 'driving_licence') return 'Driving Licence';
            return toTitle(n);
        }

        function componentSectionLabel(k) {
            var n = normalizeComponentKey(k);
            if (n === 'id') return 'Identification Verification';
            if (n === 'basic') return 'Basic Verification';
            if (n === 'contact') return 'Address Verification';
            if (n === 'education') return 'Education Verification';
            if (n === 'employment') return 'Employment Verification';
            if (n === 'reference') return 'Reference Verification';
            if (n === 'reports') return 'Reports Verification';
            if (n === 'ecourt' || n === 'database') return 'eCourt / Database Verification';
            if (n === 'social') return 'Social Media Verification';
            return componentLabel(n) + ' Verification';
        }

        function annexureDocTitle(doc) {
            var comp = componentSectionLabel(doc && doc.__component ? doc.__component : (doc && doc.doc_type ? doc.doc_type : 'general'));
            var docType = String(doc && doc.original_name ? doc.original_name : (doc && doc.file_path ? doc.file_path : 'Document')).trim();
            if (!docType) docType = 'Document';
            var role = normalizeRole(doc && doc.uploaded_by_role ? doc.uploaded_by_role : (doc && doc.__role ? doc.__role : 'unknown'));
            var roleDisplay = role ? (role.charAt(0).toUpperCase() + role.slice(1)) : 'Unknown';
            return comp + ' - ' + docType + ' - ' + roleDisplay;
        }

        function valueToText(v) {
            if (v === null || typeof v === 'undefined') return '';
            if (typeof v === 'object') {
                try { return JSON.stringify(v); } catch (_e) { return String(v); }
            }
            return String(v);
        }

        function visibleKeys(obj) {
            return Object.keys(obj || {}).filter(function (k) {
                var lk = String(k || '').toLowerCase();
                if (lk === 'id') return false;
                return true;
            });
        }

        function renderObjectSection(title, obj) {
            if (!isObj(obj)) return '';
            var keys = visibleKeys(obj);
            if (!keys.length) return '';

            var rows = keys.map(function (k) {
                return '<tr><th style="width:280px;">' + esc(toTitle(k)) + '</th><td>' + esc(valueToText(obj[k])) + '</td></tr>';
            }).join('');

            return '<div class="sec">' +
                '<div class="sec-h">' + esc(title) + '</div>' +
                '<div class="sec-b"><table><tbody>' + rows + '</tbody></table></div>' +
            '</div>';
        }

        function renderVerticalItemsSection(title, list, itemLabel) {
            var rows = asArray(list).filter(isObj);
            if (!rows.length) return '';

            var body = rows.map(function (row, idx) {
                var keys = visibleKeys(row);
                var kv = keys.map(function (k) {
                    return '<tr><th style="width:280px;">' + esc(toTitle(k)) + '</th><td>' + esc(valueToText(row[k])) + '</td></tr>';
                }).join('');
                return '<div style="border:1px solid #dbe3ed; border-radius:8px; margin-bottom:10px; overflow:hidden;">' +
                    '<div style="padding:8px 10px; background:#f8fafc; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:#334155;">' + esc(itemLabel + ' ' + String(idx + 1)) + '</div>' +
                    '<div style="padding:8px;"><table><tbody>' + kv + '</tbody></table></div>' +
                '</div>';
            }).join('');

            return '<div class="sec">' +
                '<div class="sec-h">' + esc(title) + '</div>' +
                '<div class="sec-b">' + body + '</div>' +
            '</div>';
        }

        function basicProfilePhotoSectionHtml(basic) {
            basic = isObj(basic) ? basic : {};
            var fp = String(basic.profile_photo || basic.photo_path || basic.photo || basic.candidate_photo || '').trim();
            if (!fp) return '';
            var href = docHref({
                file_path: fp,
                doc_type: 'basic',
                source_field: 'photo_path'
            });
            if (!href) return '';
            return '<div class="sec">' +
                '<div class="sec-h">Basic Profile Photo</div>' +
                '<div class="sec-b">' +
                    '<div style="max-width:360px; border:1px solid #dbe3ed; border-radius:10px; overflow:hidden; background:#fff;">' +
                        '<a href="' + esc(href) + '" target="_blank" rel="noopener">' +
                            '<img src="' + esc(href) + '" alt="Profile Photo" style="display:block; width:100%; height:auto;">' +
                        '</a>' +
                    '</div>' +
                '</div>' +
            '</div>';
        }

        function inferUploadFolder(doc) {
            var field = String(doc && doc.source_field ? doc.source_field : '').toLowerCase().trim();
            var type = normalizeComponentKey(doc && doc.doc_type ? doc.doc_type : '');
            if (field === 'upload_document' || type === 'id') return '/uploads/identification/';
            if (field === 'proof_file' || type === 'contact') return '/uploads/address/';
            if (field === 'marksheet_file' || field === 'degree_file' || type === 'education') return '/uploads/education/';
            if (field === 'employment_doc' || type === 'employment') return '/uploads/employment/';
            if (field === 'photo_path' || type === 'basic') return '/uploads/candidate_photos/';
            return '/uploads/';
        }

        function docHref(doc) {
            var fp = String(doc && doc.file_path ? doc.file_path : '').trim();
            fp = fp.replace(/\\/g, '/');
            if (!fp) return '';
            if (/^https?:\/\//i.test(fp)) return fp;
            var safePath = encodeURI(fp);
            if (fp.charAt(0) === '/') return base + safePath;
            if (fp.indexOf('uploads/') === 0) return base + '/' + safePath;
            if (fp.indexOf('/') === -1) {
                return base + inferUploadFolder(doc) + encodeURIComponent(fp);
            }
            return base + '/' + safePath;
        }

        function isImageDoc(doc) {
            var mime = String(doc && doc.mime_type ? doc.mime_type : '').toLowerCase();
            var fp = String(doc && doc.file_path ? doc.file_path : '').toLowerCase();
            return mime.indexOf('image/') === 0 || /\.(png|jpg|jpeg|gif|webp)$/i.test(fp);
        }

        function isPdfDoc(doc) {
            var mime = String(doc && doc.mime_type ? doc.mime_type : '').toLowerCase();
            var fp = String(doc && doc.file_path ? doc.file_path : '').toLowerCase();
            return mime.indexOf('pdf') !== -1 || /\.pdf$/i.test(fp);
        }

        function workflowTableHtml(flowMap) {
            var keys = ['basic', 'id', 'contact', 'education', 'employment', 'reference', 'reports', 'ecourt', 'database', 'driving_licence'];
            var rows = [];

            keys.forEach(function (k) {
                if (!flowMap[k]) return;
                rows.push({ key: k, data: flowMap[k] || {} });
            });

            Object.keys(flowMap).forEach(function (k) {
                if (keys.indexOf(k) !== -1) return;
                rows.push({ key: k, data: flowMap[k] || {} });
            });

            if (!rows.length) return '';

            var body = rows.map(function (row) {
                var va = String(row.data.validator && row.data.validator.status ? row.data.validator.status : 'pending');
                var ve = String(row.data.verifier && row.data.verifier.status ? row.data.verifier.status : 'pending');
                var qa = String(row.data.qa && row.data.qa.status ? row.data.qa.status : 'pending');
                return '<tr>' +
                    '<td>' + esc(componentLabel(row.key)) + '</td>' +
                    '<td><span class="chip ' + statusTextClass(va) + '">VA ' + esc(va.toUpperCase()) + '</span></td>' +
                    '<td><span class="chip ' + statusTextClass(ve) + '">VE ' + esc(ve.toUpperCase()) + '</span></td>' +
                    '<td><span class="chip ' + statusTextClass(qa) + '">QA ' + esc(qa.toUpperCase()) + '</span></td>' +
                '</tr>';
            }).join('');

            return '<div class="sec">' +
                '<div class="sec-h">Component Workflow</div>' +
                '<div class="sec-b"><table><thead><tr><th>Component</th><th>Validator</th><th>Verifier</th><th>QA</th></tr></thead><tbody>' + body + '</tbody></table></div>' +
            '</div>';
        }

        function latestWorkflow(workflow) {
            var best = null;
            var components = Object.keys(workflow || {});
            components.forEach(function (ck) {
                var st = workflow[ck] || {};
                ['validator', 'verifier', 'qa'].forEach(function (stage) {
                    if (!st[stage]) return;
                    var status = String(st[stage].status || '').trim();
                    var stamp = String(st[stage].updated_at || st[stage].completed_at || '').trim();
                    if (!stamp) return;
                    var t = Date.parse(stamp);
                    if (!Number.isFinite(t)) return;
                    if (!best || t > best.t) {
                        best = { t: t, stage: stage, status: status, component: ck, stamp: stamp };
                    }
                });
            });
            return best;
        }

        function normalizeDocs(rows) {
            return asArray(rows).map(function (r, idx) {
                var copy = isObj(r) ? r : {};
                copy.__idx = idx + 1;
                copy.__component = resolvedDocComponent(copy);
                copy.__role = normalizeRole(copy.uploaded_by_role || 'unknown');
                copy.__href = docHref(copy);
                copy.__uploader = uploaderDisplayName(copy);
                copy.__uploaderLabel = uploaderDisplayLabel(copy);
                return copy;
            });
        }

        function sortDocumentsForReport(rows) {
            var roleOrder = { candidate: 0, validator: 1, verifier: 2, qa: 3 };
            var compOrder = {
                basic: 0,
                id: 1,
                contact: 2,
                education: 3,
                employment: 4,
                ecourt: 5,
                database: 5,
                reference: 6,
                reports: 7
            };

            function rolePriority(doc) {
                var role = normalizeRole(doc && doc.__role ? doc.__role : (doc && doc.uploaded_by_role ? doc.uploaded_by_role : '')).toLowerCase().trim();
                return Object.prototype.hasOwnProperty.call(roleOrder, role) ? roleOrder[role] : 99;
            }

            function componentPriority(doc) {
                var comp = normalizeComponentKey(doc && doc.__component ? doc.__component : (doc && doc.doc_type ? doc.doc_type : '')).toLowerCase().trim();
                return Object.prototype.hasOwnProperty.call(compOrder, comp) ? compOrder[comp] : 99;
            }

            function createdAtTs(doc) {
                var raw = String(doc && doc.created_at ? doc.created_at : '').trim();
                var ts = raw ? new Date(raw).getTime() : NaN;
                return Number.isFinite(ts) ? ts : Number.NEGATIVE_INFINITY;
            }

            return normalizeDocs(rows).sort(function (a, b) {
                var c1 = componentPriority(a);
                var c2 = componentPriority(b);
                if (c1 !== c2) return c1 - c2;

                var r1 = rolePriority(a);
                var r2 = rolePriority(b);
                if (r1 !== r2) return r1 - r2;

                var t1 = createdAtTs(a);
                var t2 = createdAtTs(b);
                if (t1 !== t2) return t1 - t2;

                return (a.__idx || 0) - (b.__idx || 0);
            });
        }

        function groupBy(rows, field) {
            var out = {};
            asArray(rows).forEach(function (r) {
                var k = String(r && r[field] ? r[field] : 'unknown');
                if (!out[k]) out[k] = [];
                out[k].push(r);
            });
            return out;
        }

        function docsRegisterHtml(rows) {
            var docs = normalizeDocs(rows);
            if (!docs.length) {
                return '<div class="sec"><div class="sec-h">Evidence Register</div><div class="sec-b"><div class="alert alert-light" style="margin:0;">No uploaded documents found.</div></div></div>';
            }

            var body = docs.map(function (d) {
                var name = String(d.original_name || d.file_path || 'Document');
                var link = d.__href ? ('<a href="' + esc(d.__href) + '" target="_blank" rel="noopener">' + esc(name) + '</a>') : esc(name);
                return '<tr>' +
                    '<td>' + esc(String(d.__idx)) + '</td>' +
                    '<td>' + esc(componentLabel(d.__component)) + '</td>' +
                    '<td>' + esc(d.__uploaderLabel || d.__uploader || '-') + '</td>' +
                    '<td>' + link + '</td>' +
                    '<td>' + esc(String(d.mime_type || '-')) + '</td>' +
                    '<td>' + esc(String(d.created_at || '-')) + '</td>' +
                '</tr>';
            }).join('');

            return '<div class="sec">' +
                '<div class="sec-h">Evidence Register</div>' +
                '<div class="sec-b"><table><thead><tr><th>#</th><th>Component</th><th>Uploaded By</th><th>File</th><th>MIME</th><th>Created</th></tr></thead><tbody>' + body + '</tbody></table></div>' +
            '</div>';
        }

        function componentCurrentStatus(workflowMap, componentKey) {
            var k = normalizeComponentKey(componentKey);
            var flow = workflowMap && workflowMap[k] ? workflowMap[k] : {};
            var qa = String(flow.qa && flow.qa.status ? flow.qa.status : '').toLowerCase().trim();
            var ve = String(flow.verifier && flow.verifier.status ? flow.verifier.status : '').toLowerCase().trim();
            var va = String(flow.validator && flow.validator.status ? flow.validator.status : '').toLowerCase().trim();
            var s = qa || ve || va || 'pending';
            if (s === 'approved') return { text: 'Accepted', css: 'ok' };
            if (s === 'rejected') return { text: 'Rejected', css: 'bad' };
            if (s === 'hold') return { text: 'Hold', css: 'wait' };
            return { text: 'Pending', css: 'wait' };
        }

        function docCardsHtml(rows, opts) {
            opts = opts || {};
            var workflowMap = opts.workflowMap || {};
            var usePhotoLayout = !!opts.photoLayout;
            var cards = asArray(rows).map(function (doc) {
                var href = String(doc.__href || '');
                var label = String(doc.original_name || doc.file_path || 'Document');
                var by = String(doc.__uploaderLabel || uploaderDisplayLabel(doc) || '-');
                var created = String(doc.created_at || '');
                var status = componentCurrentStatus(workflowMap, doc.__component);
                var previewHtml = '';

                if (href && isImageDoc(doc)) {
                    previewHtml = '<div class="doc-fullpage"><img src="' + esc(href) + '" alt="' + esc(label) + '" loading="lazy"></div>';
                } else if (href && isPdfDoc(doc)) {
                    previewHtml = '' +
                        '<div class="js-gss-pdf-thumb" data-pdf-url="' + esc(href) + '" data-pdf-label="' + esc(label) + '"></div>';
                } else if (href) {
                    previewHtml = '<a href="' + esc(href) + '" target="_blank" rel="noopener">Open file</a>';
                } else {
                    previewHtml = '<span style="font-size:12px; color:#64748b;">No preview</span>';
                }

                if (usePhotoLayout) {
                    return '<div class="doc-card doc-card-photo">' +
                        '<div class="doc-preview doc-preview-photo">' + previewHtml + '</div>' +
                        '<div class="doc-meta doc-meta-photo">' +
                            '<div class="doc-name" title="' + esc(label) + '">' + esc(label).toUpperCase() + '</div>' +
                            '<div class="doc-meta-row"><span>Uploaded By:</span><span>' + esc(by || '-') + '</span></div>' +
                            '<div class="doc-meta-row"><span>Timestamp:</span><span>' + esc(created || '-') + '</span></div>' +
                            '<div class="doc-meta-row"><span>Status:</span><span class="' + esc(status.css) + '">' + esc(status.text) + '</span></div>' +
                        '</div>' +
                    '</div>';
                }

                return '<div class="doc-card">' +
                    '<div class="doc-preview">' + previewHtml + '</div>' +
                    '<div class="doc-meta">' +
                        '<div class="doc-name" title="' + esc(label) + '">' + esc(label) + '</div>' +
                        '<div class="doc-sub">By: ' + esc(by || '-') + '</div>' +
                        '<div class="doc-sub">At: ' + esc(created || '-') + '</div>' +
                    '</div>' +
                '</div>';
            }).join('');

            return '<div class="doc-grid">' + cards + '</div>';
        }

        function docsAnnexureHtml(rows, opts) {
            opts = opts || {};
            var docs = normalizeDocs(rows);
            if (!docs.length) return '';

            var title = String(opts.title || 'Annexure - Uploaded Evidence');
            var asSection = opts.asSection !== false;
            var isFinalAnnex = title === 'Final Documents Appendix - Full Evidence Pages';
            var body = '';

            docs.forEach(function (doc) {
                var href = String(doc.__href || '');
                var label = String(doc.original_name || doc.file_path || 'Document');
                var displayTitle = isFinalAnnex ? annexureDocTitle(doc) : label;
                if (!href) return;

                var by = String(doc.__uploaderLabel || uploaderDisplayLabel(doc) || '-');
                var created = String(doc.created_at || '-');
                var metaLine = '<div class="doc-sub">Uploaded By: ' + esc(by) + ' | Timestamp: ' + esc(created) + '</div>';

                if (isPdfDoc(doc)) {
                    body += '<div class="js-gss-pdf-thumb" data-pdf-url="' + esc(href) + '" data-pdf-label="' + esc(displayTitle) + '" data-pdf-by="' + esc(by) + '" data-pdf-created="' + esc(created) + '"></div>';
                    return;
                }

                if (isImageDoc(doc)) {
                    body += '<div class="doc-fullpage doc-embed-page">';
                    body += '<div class="doc-title">' + esc(displayTitle) + '</div>';
                    body += metaLine;
                    body += '<div class="document-page"><img src="' + esc(href) + '" alt="' + esc(label) + '" loading="lazy"></div>';
                    body += '</div>';
                    return;
                }

                body += '<div class="doc-fullpage doc-embed-page">';
                body += '<div class="doc-title">' + esc(displayTitle) + '</div>';
                body += metaLine;
                body += '<a href="' + esc(href) + '" target="_blank" rel="noopener">Open file</a>';
                body += '</div>';
            });

            if (!body) return '';
            if (!asSection) return body;
            return '<div class="sec"><div class="sec-h sec-h-evidence">' + esc(title) + '</div><div class="sec-b">' + body + '</div></div>';
        }

        function docsByComponentHtml(rows, componentKey) {
            var target = normalizeComponentKey(componentKey);
            var aliases = componentAliasKeys(target);
            var docs = normalizeDocs(rows).filter(function (doc) {
                var rawType = normalizeComponentKey(doc && doc.doc_type ? doc.doc_type : '');
                var normType = normalizeComponentKey(doc && doc.__component ? doc.__component : rawType);
                return aliases.indexOf(rawType) !== -1 || aliases.indexOf(normType) !== -1;
            });

            if (!docs.length) return '';

            var title = 'Proof Documents - ' + componentLabel(target);
            var part = docsAnnexureHtml(docs);
            if (!part) return '';
            return part.replace('Annexure - Uploaded Evidence', esc(title));
        }

        function normalizeActorRole(v) {
            var r = String(v || '').toLowerCase().trim();
            if (r === 'validator' || r === 'va') return 'validator';
            if (r === 'verifier' || r === 've' || r === 'db_verifier') return 'verifier';
            if (r === 'qa' || r === 'team_lead' || r === 'tl') return 'qa';
            return r || 'unknown';
        }

        function actorName(row) {
            row = row || {};
            var nm = normalizeDisplayName((row.first_name || '') + ' ' + (row.last_name || ''));
            if (nm) return nm;
            var un = normalizeDisplayName(row.username || '');
            if (un) return un;
            return '-';
        }

        function roleRemarksSectionHtml(rows) {
            var list = asArray(rows).filter(isObj);
            if (!list.length) return '';

            var grouped = { validator: [], verifier: [], qa: [] };
            list.forEach(function (r) {
                var rr = normalizeActorRole(r.actor_role || '');
                if (rr === 'validator' || rr === 'verifier' || rr === 'qa') {
                    grouped[rr].push(r);
                }
            });

            function roleTitle(k) {
                if (k === 'validator') return 'Validator Comments / Remarks';
                if (k === 'verifier') return 'Verifier Comments / Remarks';
                return 'QA Comments / Remarks';
            }

            function rowHtml(r) {
                return '<tr>' +
                    '<td style="width:180px;">' + esc(String(r.created_at || '-')) + '</td>' +
                    '<td style="width:180px;">' + esc(actorName(r)) + '</td>' +
                    '<td style="width:140px;">' + esc(String(r.section_key || '-')) + '</td>' +
                    '<td>' + esc(String(r.message || '-')) + '</td>' +
                '</tr>';
            }

            var parts = ['validator', 'verifier', 'qa'].map(function (k) {
                var rowsK = grouped[k] || [];
                if (!rowsK.length) {
                    return '<div class="sec">' +
                        '<div class="sec-h">' + esc(roleTitle(k)) + '</div>' +
                        '<div class="sec-b"><div class="alert alert-light" style="margin:0;">No comments.</div></div>' +
                    '</div>';
                }
                var body = rowsK.map(rowHtml).join('');
                return '<div class="sec">' +
                    '<div class="sec-h">' + esc(roleTitle(k)) + '</div>' +
                    '<div class="sec-b"><table><thead><tr><th>Time</th><th>By</th><th>Section</th><th>Comment</th></tr></thead><tbody>' + body + '</tbody></table></div>' +
                '</div>';
            }).join('');

            return parts;
        }

        function objectTableHtml(obj) {
            if (!isObj(obj)) return '';
            var keys = visibleKeys(obj);
            if (!keys.length) return '';
            var body = keys.map(function (k) {
                return '<tr><th style="width:280px;">' + esc(toTitle(k)) + '</th><td>' + esc(valueToText(obj[k])) + '</td></tr>';
            }).join('');
            return '<table><tbody>' + body + '</tbody></table>';
        }

        function arrayTableCardsHtml(list, itemLabel) {
            var rows = asArray(list).filter(isObj);
            if (!rows.length) return '';
            return rows.map(function (row, idx) {
                var tbl = objectTableHtml(row);
                if (!tbl) return '';
                return '<div style="border:1px solid #dbe3ed; border-radius:8px; margin-bottom:10px; overflow:hidden;">' +
                    '<div style="padding:8px 10px; background:#f8fafc; font-size:11px; font-weight:900; letter-spacing:.04em; text-transform:uppercase; color:#334155;">' + esc(itemLabel + ' ' + String(idx + 1)) + '</div>' +
                    '<div style="padding:8px;">' + tbl + '</div>' +
                '</div>';
            }).join('');
        }

        function workflowSystemDataHtml(workflowMap, componentKey) {
            var flow = workflowMap && workflowMap[normalizeComponentKey(componentKey)] ? workflowMap[normalizeComponentKey(componentKey)] : null;
            if (!flow || typeof flow !== 'object') return '';

            var rows = [];
            ['candidate', 'validator', 'verifier', 'qa'].forEach(function (stage) {
                var r = flow[stage];
                if (!r || typeof r !== 'object') return;
                var status = String(r.status || '').trim();
                var at = String(r.updated_at || r.completed_at || '').trim();
                if (!status && !at) return;
                rows.push('<tr><th style="width:180px;">' + esc(stage.toUpperCase()) + '</th><td>' + esc(status || '-') + '</td><td>' + esc(at || '-') + '</td></tr>');
            });
            if (!rows.length) return '';
            return '<table><thead><tr><th>Stage</th><th>Status</th><th>Timestamp</th></tr></thead><tbody>' + rows.join('') + '</tbody></table>';
        }

        function docsForComponentHtml(rows, componentKey, workflowMap) {
            var target = normalizeComponentKey(componentKey);
            var aliases = componentAliasKeys(target);
            var docs = normalizeDocs(rows).filter(function (doc) {
                var rawType = normalizeComponentKey(doc && doc.doc_type ? doc.doc_type : '');
                var normType = normalizeComponentKey(doc && doc.__component ? doc.__component : rawType);
                return aliases.indexOf(rawType) !== -1 || aliases.indexOf(normType) !== -1;
            });
            if (!docs.length) return '';
            return docsAnnexureHtml(docs, {
                asSection: false,
                title: 'Proof Documents - ' + componentLabel(target)
            });
        }

        function remarksForComponentHtml(timelineRows, componentKey) {
            var target = normalizeComponentKey(componentKey);
            var rows = asArray(timelineRows).filter(function (r) {
                var rr = normalizeActorRole(r && r.actor_role ? r.actor_role : '');
                if (!(rr === 'validator' || rr === 'verifier' || rr === 'qa')) return false;
                var sk = normalizeComponentKey(String(r && r.section_key ? r.section_key : ''));
                return sk === target;
            });
            if (!rows.length) return '';
            var body = rows.map(function (r) {
                return '<tr>' +
                    '<td style="width:170px;">' + esc(String(r.created_at || '-')) + '</td>' +
                    '<td style="width:160px;">' + esc(actorName(r)) + '</td>' +
                    '<td style="width:110px;">' + esc(normalizeActorRole(r.actor_role || '').toUpperCase()) + '</td>' +
                    '<td>' + esc(String(r.message || '-')) + '</td>' +
                '</tr>';
            }).join('');
            return '<table><thead><tr><th>Time</th><th>By</th><th>Role</th><th>Remarks</th></tr></thead><tbody>' + body + '</tbody></table>';
        }

        function statusBadgeHtml(workflowMap, componentKey) {
            var st = componentCurrentStatus(workflowMap || {}, componentKey);
            var cls = st && st.css ? st.css : 'wait';
            var txt = st && st.text ? st.text : 'Pending';
            return '<span class="chip ' + esc(cls) + '">' + esc(txt) + '</span>';
        }

        function verificationSectionHtml(title, componentKey, candidateHtml, systemHtml, docsHtml, remarksHtml, workflowMap) {
            var hasAny = !!(candidateHtml || systemHtml || docsHtml || remarksHtml);
            if (!hasAny) return '';
            return '<div class="sec">' +
                '<div class="sec-h">' + esc(title) + '</div>' +
                '<div class="sec-b">' +
                    (candidateHtml ? ('<div class="sec" style="margin-top:0;"><div class="sec-h">1. Candidate Entered Details</div><div class="sec-b">' + candidateHtml + '</div></div>') : '') +
                    (systemHtml ? ('<div class="sec"><div class="sec-h">2. System Captured / Verified Data</div><div class="sec-b">' + systemHtml + '</div></div>') : '') +
                    (docsHtml ? ('<div class="sec"><div class="sec-h">3. Uploaded Documents / Proof Images</div><div class="sec-b">' + docsHtml + '</div></div>') : '') +
                    (remarksHtml ? ('<div class="sec"><div class="sec-h">4. Agent Remarks / Verification Notes</div><div class="sec-b">' + remarksHtml + '</div></div>') : '') +
                    ('<div class="sec"><div class="sec-h">5. Final Status Badge</div><div class="sec-b">' + statusBadgeHtml(workflowMap, componentKey) + '</div></div>') +
                '</div>' +
            '</div>';
        }

        function renderPdfThumbs() {
            if (!window.pdfjsLib || typeof window.pdfjsLib.getDocument !== 'function') {
                Array.prototype.slice.call(document.querySelectorAll('.js-gss-pdf-thumb')).forEach(function (holder) {
                    var url = String(holder.getAttribute('data-pdf-url') || '');
                    var label = String(holder.getAttribute('data-pdf-label') || 'PDF');
                    var d = document.createElement('div');
                    d.style.fontSize = '12px';
                    d.style.color = '#64748b';
                    d.innerHTML = url
                        ? ('<a href="' + esc(url) + '" target="_blank" rel="noopener">Open ' + esc(label) + '</a>')
                        : 'PDF preview unavailable';
                    holder.replaceWith(d);
                });
                return Promise.resolve();
            }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

            var nodes = Array.prototype.slice.call(document.querySelectorAll('.js-gss-pdf-thumb'));
            if (!nodes.length) return Promise.resolve();

            function withTimeout(p, ms) {
                return new Promise(function (resolve, reject) {
                    var done = false;
                    var t = setTimeout(function () {
                        if (done) return;
                        done = true;
                        reject(new Error('timeout'));
                    }, ms);
                    p.then(function (v) {
                        if (done) return;
                        done = true;
                        clearTimeout(t);
                        resolve(v);
                    }).catch(function (e) {
                        if (done) return;
                        done = true;
                        clearTimeout(t);
                        reject(e);
                    });
                });
            }

            var jobs = nodes.map(function (holder) {
                var url = String(holder.getAttribute('data-pdf-url') || '');
                var label = String(holder.getAttribute('data-pdf-label') || 'PDF');
                var by = String(holder.getAttribute('data-pdf-by') || '-');
                var created = String(holder.getAttribute('data-pdf-created') || '-');
                if (!url) return Promise.resolve();

                var renderJob = window.pdfjsLib.getDocument({
                    url: url,
                    withCredentials: true,
                    disableWorker: true
                }).promise
                    .then(function (pdf) {
                        holder.innerHTML = '';
                        var seq = Promise.resolve();
                        for (var p = 1; p <= pdf.numPages; p++) {
                            (function (pageNo) {
                                seq = seq.then(function () {
                                    return pdf.getPage(pageNo).then(function (page) {
                                        var scaled = page.getViewport({ scale: 2.2 });
                                        var canvas = document.createElement('canvas');
                                        canvas.width = Math.floor(scaled.width);
                                        canvas.height = Math.floor(scaled.height);
                                        var wrapper = document.createElement('div');
                                        // 1 source PDF page -> 1 dedicated report page
                                        wrapper.className = 'doc-fullpage doc-embed-page';
                                        var title = document.createElement('div');
                                        title.className = 'doc-title';
                                        title.textContent = String(label || 'Document') + ' - Page ' + String(pageNo);
                                        var meta = document.createElement('div');
                                        meta.className = 'doc-sub';
                                        meta.textContent = 'Uploaded By: ' + String(by || '-') + ' | Timestamp: ' + String(created || '-');
                                        wrapper.appendChild(title);
                                        wrapper.appendChild(meta);
                                        wrapper.appendChild(canvas);
                                        holder.appendChild(wrapper);
                                        return page.render({ canvasContext: canvas.getContext('2d'), viewport: scaled }).promise;
                                    });
                                });
                            })(p);
                        }
                        return seq;
                    })
                    .then(function () {
                        holder.setAttribute('data-rendered', '1');
                    })
                    .catch(function () {
                        var d = document.createElement('div');
                        d.style.fontSize = '12px';
                        d.style.color = '#64748b';
                        d.innerHTML = '<a href="' + esc(url) + '" target="_blank" rel="noopener">Open ' + esc(label) + '</a>';
                        holder.replaceWith(d);
                    });

                return withTimeout(renderJob, 12000).catch(function () {
                    var d = document.createElement('div');
                    d.style.fontSize = '12px';
                    d.style.color = '#64748b';
                    d.innerHTML = '<a href="' + esc(url) + '" target="_blank" rel="noopener">Open ' + esc(label) + '</a>';
                    if (holder.parentNode) holder.replaceWith(d);
                });
            });
            return Promise.all(jobs).then(function () { return; });
        }

        function waitForImageAssets() {
            var images = Array.from(document.images || []);
            if (!images.length) return Promise.resolve();
            return Promise.all(
                images
                    .filter(function (img) { return !img.complete; })
                    .map(function (img) {
                        return new Promise(function (resolve) {
                            img.onload = function () { resolve(); };
                            img.onerror = function () { resolve(); };
                            setTimeout(resolve, 6000);
                        });
                    })
            ).then(function () { return; });
        }

        function summaryCard(label, value) {
            return '<div class="kv"><div class="k">' + esc(label) + '</div><div class="v">' + esc(value) + '</div></div>';
        }

        function renderReport(d, timelineRows) {
            var cs = isObj(d.case) ? d.case : {};
            var app = isObj(d.application) ? d.application : {};
            var basic = isObj(d.basic) ? d.basic : {};
            var workflow = isObj(d.component_workflow) ? d.component_workflow : {};
            var docs = sortDocumentsForReport(d.uploaded_docs);

            var candidateName = ((cs.candidate_first_name || '') + ' ' + (cs.candidate_last_name || '')).trim() || ((basic.first_name || '') + ' ' + (basic.last_name || '')).trim();
            uploaderContext.candidateName = normalizeDisplayName(candidateName);
            var status = caseDisplayStatus(app.status, cs.case_status);
            var latest = latestWorkflow(workflow);

            if (metaEl) {
                metaEl.innerHTML = '' +
                    '<div><b>Application:</b> ' + esc(applicationId || '-') + '</div>' +
                    '<div><b>Case ID:</b> ' + esc(cs.case_id || '-') + '</div>' +
                    '<div><b>Generated:</b> ' + esc(new Date().toLocaleString()) + '</div>';
            }

            if (summaryEl) {
                summaryEl.innerHTML = [
                    summaryCard('Candidate', candidateName || '-'),
                    summaryCard('Email', basic.email || cs.candidate_email || '-'),
                    summaryCard('Mobile', basic.mobile || cs.candidate_mobile || '-'),
                    summaryCard('Final Status', status || '-'),
                    summaryCard('Latest Action', latest ? ((latest.stage || '').toUpperCase() + ' ' + (latest.status || '').toUpperCase()) : '-'),
                    summaryCard('Latest Component', latest ? componentLabel(latest.component || '') : '-'),
                    summaryCard('Total Evidence Files', String(docs.length)),
                    summaryCard('Generated By', 'GSS Admin')
                ].join('');
            }

            var html = '';
            // Executive + high-level context
            html += workflowTableHtml(d.component_workflow);
            html += renderObjectSection('Case Details', d.case);
            html += renderObjectSection('Application Details', d.application);

            // 3. Basic Details
            var basicCandidate = objectTableHtml(d.basic) + basicProfilePhotoSectionHtml(d.basic);
            var basicSystem = workflowSystemDataHtml(workflow, 'basic');
            var basicDocs = docsForComponentHtml(docs, 'basic', workflow);
            var basicRemarks = remarksForComponentHtml(timelineRows, 'basic');
            html += verificationSectionHtml('Basic Details', 'basic', basicCandidate, basicSystem, basicDocs, basicRemarks, workflow);

            // 4. Identification Verification
            var idCandidate = arrayTableCardsHtml(d.identification, 'Identification Record');
            var idSystem = workflowSystemDataHtml(workflow, 'id');
            var idDocs = docsForComponentHtml(docs, 'id', workflow);
            var idRemarks = remarksForComponentHtml(timelineRows, 'id');
            html += verificationSectionHtml('Identification Verification', 'id', idCandidate, idSystem, idDocs, idRemarks, workflow);

            // 5. Address Verification (Contact)
            var contactCandidate = objectTableHtml(d.contact);
            var contactSystem = workflowSystemDataHtml(workflow, 'contact');
            var contactDocs = docsForComponentHtml(docs, 'contact', workflow);
            var contactRemarks = remarksForComponentHtml(timelineRows, 'contact');
            html += verificationSectionHtml('Address Verification', 'contact', contactCandidate, contactSystem, contactDocs, contactRemarks, workflow);

            // 6. Employment Verification
            var empCandidate = arrayTableCardsHtml(d.employment, 'Employment Record');
            var empSystem = workflowSystemDataHtml(workflow, 'employment');
            var empDocs = docsForComponentHtml(docs, 'employment', workflow);
            var empRemarks = remarksForComponentHtml(timelineRows, 'employment');
            html += verificationSectionHtml('Employment Verification', 'employment', empCandidate, empSystem, empDocs, empRemarks, workflow);

            // 7. Education Verification
            var eduCandidate = arrayTableCardsHtml(d.education, 'Education Record');
            var eduSystem = workflowSystemDataHtml(workflow, 'education');
            var eduDocs = docsForComponentHtml(docs, 'education', workflow);
            var eduRemarks = remarksForComponentHtml(timelineRows, 'education');
            html += verificationSectionHtml('Education Verification', 'education', eduCandidate, eduSystem, eduDocs, eduRemarks, workflow);

            // 8. Reference Check
            var refCandidate = objectTableHtml(d.reference);
            var refSystem = workflowSystemDataHtml(workflow, 'reference');
            var refDocs = docsForComponentHtml(docs, 'reference', workflow);
            var refRemarks = remarksForComponentHtml(timelineRows, 'reference');
            html += verificationSectionHtml('Reference Check', 'reference', refCandidate, refSystem, refDocs, refRemarks, workflow);

            // 9. Court / database check
            var courtMerged = {};
            var ecObj = isObj(d.ecourt || d.e_court) ? (d.ecourt || d.e_court) : {};
            var dbObj = isObj(d.database) ? d.database : {};
            Object.keys(ecObj).forEach(function (k) { courtMerged[k] = ecObj[k]; });
            Object.keys(dbObj).forEach(function (k) { if (typeof courtMerged[k] === 'undefined') courtMerged[k] = dbObj[k]; });
            var courtCandidate = objectTableHtml(courtMerged);
            var courtSystem = workflowSystemDataHtml(workflow, 'ecourt') + workflowSystemDataHtml(workflow, 'database');
            var courtDocs = docsForComponentHtml(docs, 'ecourt', workflow) + docsForComponentHtml(docs, 'database', workflow);
            var courtRemarks = remarksForComponentHtml(timelineRows, 'ecourt') + remarksForComponentHtml(timelineRows, 'database');
            html += verificationSectionHtml('Court / Database Check', 'ecourt', courtCandidate, courtSystem, courtDocs, courtRemarks, workflow);

            // 10. General Documents (fallback grouped)
            var generalDocs = docsForComponentHtml(docs, 'general', workflow);
            if (generalDocs) {
                html += verificationSectionHtml('General Documents', 'general', '', '', generalDocs, '', workflow);
            }

            // 11. Final documents appendix
            html += '<div class="sec"><div class="sec-h">Final Documents Appendix</div><div class="sec-b">' + docsRegisterHtml(docs) + '</div></div>';
            html += docsAnnexureHtml(docs, { title: 'Final Documents Appendix - Full Evidence Pages' });

            contentEl.innerHTML = html || '<div class="alert alert-light">No report data found.</div>';
            thumbsReadyPromise = renderPdfThumbs();
        }

        function fetchJson(url) {
            return fetch(url, { credentials: 'same-origin' })
                .then(function (res) { return res.json().catch(function () { return null; }); });
        }

        function mergeDocs(primaryRows, fallbackRows) {
            var out = [];
            var seen = {};

            function pushRows(rows) {
                asArray(rows).forEach(function (r) {
                    if (!r || typeof r !== 'object') return;
                    var key = String(r.id || '') + '|' + String(r.file_path || '') + '|' + String(r.original_name || '');
                    if (seen[key]) return;
                    seen[key] = true;
                    out.push(r);
                });
            }

            pushRows(primaryRows);
            pushRows(fallbackRows);
            return out;
        }

        function looksLikeFilePath(v) {
            var s = String(v || '').trim();
            if (!s) return false;
            if (/^https?:\/\//i.test(s)) return true;
            if (s.indexOf('/') !== -1 || s.indexOf('\\') !== -1) return true;
            return /\.(pdf|png|jpe?g|webp|gif|bmp|tiff?)$/i.test(s);
        }

        function inferMimeFromPath(path) {
            var p = String(path || '').toLowerCase();
            if (/\.pdf($|\?)/.test(p)) return 'application/pdf';
            if (/\.png($|\?)/.test(p)) return 'image/png';
            if (/\.jpe?g($|\?)/.test(p)) return 'image/jpeg';
            if (/\.webp($|\?)/.test(p)) return 'image/webp';
            if (/\.gif($|\?)/.test(p)) return 'image/gif';
            if (/\.bmp($|\?)/.test(p)) return 'image/bmp';
            if (/\.tiff?($|\?)/.test(p)) return 'image/tiff';
            return '';
        }

        function pushDerivedDoc(out, seen, docType, role, filePath, originalName, createdAt, sourceField) {
            var fp = String(filePath || '').trim();
            if (!looksLikeFilePath(fp)) return;
            var key = 'derived|' + fp + '|' + String(docType || '');
            if (seen[key]) return;
            seen[key] = true;
            out.push({
                id: 0,
                application_id: applicationId,
                doc_type: docType || 'general',
                file_path: fp,
                original_name: originalName || fp.split(/[\/\\]/).pop() || 'Document',
                mime_type: inferMimeFromPath(fp),
                uploaded_by_role: role || 'candidate',
                created_at: createdAt || '',
                source_field: sourceField || ''
            });
        }

        function deriveDocsFromSections(reportData) {
            var out = [];
            var seen = {};
            var d = reportData || {};

            asArray(d.identification).forEach(function (row) {
                if (!row || typeof row !== 'object') return;
                pushDerivedDoc(out, seen, 'id', 'candidate', row.upload_document || row.document_file || row.file_path, row.documentId_type || row.id_number, row.created_at, 'upload_document');
            });

            asArray(d.education).forEach(function (row) {
                if (!row || typeof row !== 'object') return;
                pushDerivedDoc(out, seen, 'education', 'candidate', row.marksheet_file, (row.qualification ? String(row.qualification) + ' Marksheet' : 'Marksheet'), row.created_at, 'marksheet_file');
                pushDerivedDoc(out, seen, 'education', 'candidate', row.degree_file, (row.qualification ? String(row.qualification) + ' Degree' : 'Degree'), row.created_at, 'degree_file');
            });

            asArray(d.employment).forEach(function (row) {
                if (!row || typeof row !== 'object') return;
                pushDerivedDoc(out, seen, 'employment', 'candidate', row.employment_doc || row.document_file || row.proof_file, (row.employer_name ? String(row.employer_name) + ' Employment Proof' : 'Employment Proof'), row.created_at, 'employment_doc');
            });

            var contact = d.contact || {};
            if (contact && typeof contact === 'object') {
                pushDerivedDoc(out, seen, 'contact', 'candidate', contact.proof_file || contact.address_proof_file || contact.address_proof || contact.proof_document, 'Address Proof', contact.created_at, 'proof_file');
            }

            var auth = d.authorization || {};
            if (auth && typeof auth === 'object') {
                pushDerivedDoc(out, seen, 'reports', 'candidate', auth.file_name || auth.authorization_file_name || auth.auth_file_name, 'Authorization', auth.uploaded_at || auth.created_at, 'authorization_file');
            }

            var basic = d.basic || {};
            if (basic && typeof basic === 'object') {
                pushDerivedDoc(out, seen, 'basic', 'candidate', basic.profile_photo || basic.photo_path || basic.photo || basic.candidate_photo, 'Profile Photo', basic.created_at, 'photo_path');
            }

            return out;
        }

        function loadReport() {
            if (!applicationId) {
                setMessage('application_id is required.', 'danger');
                return;
            }
            var url = base + '/api/shared/candidate_report_get.php?role=gss_admin&application_id=' + encodeURIComponent(applicationId);
            var docsUrl = base + '/api/shared/verification_docs_list.php?application_id=' + encodeURIComponent(applicationId);
            var timelineUrl = base + '/api/shared/case_timeline_list.php?application_id=' + encodeURIComponent(applicationId) + '&limit=500';
            if (clientId > 0) {
                url += '&client_id=' + encodeURIComponent(String(clientId));
                timelineUrl += '&client_id=' + encodeURIComponent(String(clientId));
            }

            setMessage('Loading report...', 'info');
            Promise.all([
                fetchJson(url),
                fetchJson(docsUrl),
                fetchJson(timelineUrl)
            ])
                .then(function (arr) {
                    var payload = arr[0];
                    var docsPayload = arr[1];
                    var timelinePayload = arr[2];
                    if (!payload || payload.status !== 1 || !payload.data) {
                        throw new Error((payload && payload.message) ? payload.message : 'Failed to load report');
                    }
                    var reportDocs = asArray(payload.data.uploaded_docs);
                    var verificationDocs = (docsPayload && docsPayload.status === 1) ? asArray(docsPayload.data) : [];
                    var derivedDocs = deriveDocsFromSections(payload.data);
                    var timelineRows = (timelinePayload && timelinePayload.status === 1) ? asArray(timelinePayload.data) : [];
                    payload.data.uploaded_docs = mergeDocs(mergeDocs(verificationDocs, reportDocs), derivedDocs);
                    renderReport(payload.data, timelineRows);
                    setMessage('', '');
                })
                .catch(function (e) {
                    setMessage(e && e.message ? e.message : 'Failed to load report', 'danger');
                });
        }

        loadReport();
    });
})();
