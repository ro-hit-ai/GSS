(function () {
    class CandidateSidebarStatus {
        static statusIcons = {
            completed: '\u2713',
            verified: '\u2713',
            needs_attention: '\u26A0',
            correction_required: '\u26A0',
            rejected: '\u26A0',
            waiting_mobile_upload: '\u27F3',
            submitted: '\u27F3',
            ready_for_review: '\u2713',
            in_progress: '\u25CB',
            not_started: '\u25CB'
        };

        static pageBySection = {
            basic: 'basic-details',
            'basic-details': 'basic-details',
            identification: 'identification',
            id: 'identification',
            contact: 'contact',
            ecourt: 'ecourt',
            education: 'education',
            employment: 'employment',
            reference: 'reference',
            social: 'social'
        };

        static current = null;
        static refreshTimer = null;
        static isLoading = false;

        static init() {
            this.ensureShell();
            this.bindTouchedTracking();
            this.refresh();
            this.refreshTimer = window.setInterval(() => this.refresh({ quiet: true }), 45000);
        }

        static apiUrl() {
            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const touched = this.getTouchedSections();
            const suffix = touched.length ? `&touched=${encodeURIComponent(touched.join(','))}` : '';
            return `${base}/api/candidate/sidebar_status.php?t=${Date.now()}${suffix}`;
        }

        static storageKey() {
            return `candidateSidebarTouched:${window.CANDIDATE_APP_ID || 'current'}`;
        }

        static pageToSection(pageId) {
            const map = {
                'basic-details': 'basic',
                identification: 'identification',
                contact: 'contact',
                education: 'education',
                employment: 'employment',
                ecourt: 'ecourt',
                social: 'social',
                reference: 'reference'
            };
            return map[pageId] || pageId || '';
        }

        static getTouchedSections() {
            try {
                const raw = window.localStorage ? localStorage.getItem(this.storageKey()) : '';
                const parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed.filter(Boolean) : [];
            } catch (_e) {
                return [];
            }
        }

        static markTouched(pageId) {
            const section = this.pageToSection(pageId);
            if (!section) return;
            const touched = new Set(this.getTouchedSections());
            touched.add(section);
            try {
                if (window.localStorage) {
                    localStorage.setItem(this.storageKey(), JSON.stringify(Array.from(touched)));
                }
            } catch (_e) {
            }
        }

        static bindTouchedTracking() {
            if (this._touchedBound) return;
            this._touchedBound = true;
            document.addEventListener('click', (event) => {
                const trigger = event.target.closest('.external-submit-btn, .save-draft-btn');
                if (!trigger) return;
                const formId = trigger.getAttribute('data-form') || '';
                let pageId = window.Router?.currentPage || '';
                if (formId) {
                    pageId = formId.replace(/Form$/, '');
                    if (pageId === 'basic-details') pageId = 'basic-details';
                }
                this.markTouched(pageId);
                window.setTimeout(() => this.refresh({ quiet: true }), 250);
            }, true);
        }

        static ensureShell() {
            const sidebar = document.getElementById('mainSidebar');
            if (!sidebar) return;

            document.getElementById('candidateSidebarAssistant')?.remove();

            const nav = sidebar.querySelector('.sidebar-nav');
            if (!nav) return;

            nav.querySelector('[data-page="review"]')?.remove();

            this.applyChecklistOrder(nav);
        }

        static attachNavigation(item) {
            if (!item || item.dataset.sidebarSmartBound === '1') return;
            item.dataset.sidebarSmartBound = '1';
            item.addEventListener('click', (event) => {
                event.preventDefault();
                const pageId = item.dataset.page;
                if (!pageId || !window.Router) return;
                if (!Router.isPageAccessible(pageId)) {
                    if (window.Toast && typeof window.Toast.warn === 'function') {
                        window.Toast.warn('Please complete the current step before proceeding.');
                    }
                    return;
                }
                Router.navigateTo(pageId);
                if (window.innerWidth <= 768) {
                    document.getElementById('mainSidebar')?.classList.remove('open');
                    document.getElementById('sidebarOverlay')?.classList.remove('show');
                }
            });
        }

        static applyChecklistOrder(nav) {
            if (!nav || nav.dataset.smartGrouped === '1') return;
            nav.dataset.smartGrouped = '1';

            const pages = ['basic-details', 'identification', 'contact', 'education', 'employment', 'ecourt', 'social', 'reference'];

            const items = new Map();
            nav.querySelectorAll('.sidebar-item[data-page]').forEach((item) => {
                items.set(item.dataset.page, item);
            });

            nav.innerHTML = '';
            pages.forEach((page) => {
                const item = items.get(page);
                if (item) nav.appendChild(item);
            });
        }

        static async refresh(options = {}) {
            if (this.isLoading) return;
            this.isLoading = true;
            try {
                const response = await fetch(this.apiUrl(), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                });
                const data = await response.json();
                if (!data || data.success === false) {
                    if (!options.quiet) console.warn('Sidebar status unavailable', data);
                    return;
                }
                this.current = data;
                this.render(data);
            } catch (error) {
                if (!options.quiet) console.warn('Sidebar status failed', error);
            } finally {
                this.isLoading = false;
            }
        }

        static render(data) {
            this.ensureShell();

            (data.sections || []).forEach((section) => {
                const page = this.pageBySection[section.key] || section.key;
                const item = document.querySelector(`.sidebar-item[data-page="${page}"]`);
                if (item) this.renderItem(item, section);
            });
        }

        static renderItem(item, section) {
            const status = String(section.status || 'not_started');
            const primaryIssue = Array.isArray(section.issues) && section.issues.length ? section.issues[0] : null;

            item.dataset.issueField = primaryIssue?.field || '';
            item.dataset.issueMessage = primaryIssue?.message || section.message || '';
            item.dataset.status = status;
            item.classList.remove(
                'completed',
                'needs-attention',
                'correction-required',
                'waiting-mobile-upload',
                'in-progress',
                'not-started',
                'submitted',
                'ready-for-review'
            );
            item.classList.add(status.replace(/_/g, '-'));
            item.classList.toggle('completed', ['completed', 'verified', 'ready_for_review'].includes(status));

            let copy = item.querySelector('.sidebar-copy');
            const label = item.querySelector('.sidebar-label');
            if (!copy && label) {
                copy = document.createElement('span');
                copy.className = 'sidebar-copy';
                label.parentNode.insertBefore(copy, label);
                copy.appendChild(label);
            }

            let helper = item.querySelector('.sidebar-helper');
            if (!helper && copy) {
                helper = document.createElement('span');
                helper.className = 'sidebar-helper';
                copy.appendChild(helper);
            }
            const problematicStatuses = ['needs_attention', 'correction_required', 'rejected', 'waiting_mobile_upload', 'submitted'];
            const showHelper = item.classList.contains('active') || problematicStatuses.includes(status);
            if (helper) {
                helper.textContent = showHelper ? (section.message || 'Pending') : '';
                helper.hidden = !showHelper;
            }

            const statusEl = item.querySelector('.sidebar-status');
            if (statusEl) {
                statusEl.textContent = this.statusIcons[status] || '\u25CB';
                statusEl.setAttribute('title', section.message || status);
            }
            item.title = section.message || '';
        }

        static focusPendingIssue(pageId) {
            const item = document.querySelector(`.sidebar-item[data-page="${pageId}"]`);
            const field = item?.dataset?.issueField || '';
            const message = item?.dataset?.issueMessage || '';
            if (!field) return;

            window.setTimeout(() => {
                const escaped = window.CSS && CSS.escape ? CSS.escape(field) : field.replace(/"/g, '\\"');
                const target = document.querySelector(`[name="${escaped}"], #${escaped}, [data-field="${escaped}"]`);
                if (!target) return;
                const box = target.closest('.form-field, .form-group, .compact-control, .file-upload-box, .single-upload-container') || target;
                box.classList.add('candidate-sidebar-focus');
                box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                if (message && window.Toast && typeof window.Toast.warn === 'function') {
                    window.Toast.warn(message);
                }
                window.setTimeout(() => box.classList.remove('candidate-sidebar-focus'), 2200);
            }, 250);
        }
    }

    window.CandidateSidebarStatus = CandidateSidebarStatus;

    document.addEventListener('DOMContentLoaded', () => {
        CandidateSidebarStatus.init();
    });
})();
