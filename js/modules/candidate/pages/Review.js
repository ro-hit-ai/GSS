class Review {
    static initialized = false;
    static listeners = [];
    static payload = null;

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this.listeners.push({ el, type, fn });
    }

    static cleanup() {
        this.listeners.forEach(({ el, type, fn }) => el?.removeEventListener(type, fn));
        this.listeners = [];
        this.initialized = false;
        this.payload = null;
    }

    static onPageLoad() {
        if (this.initialized) return;
        this.initialized = true;
        this.bindActions();
        this.loadReview();
    }

    static bindActions() {
        this.on(document.getElementById('reviewGoBackBtn'), 'click', (e) => {
            e.preventDefault();
            if (window.Router?.navigateTo) {
                window.Router.navigateTo('basic-details');
            }
        });

        this.on(document.getElementById('reviewFinalSubmitBtn'), 'click', async (e) => {
            e.preventDefault();
            await this.submitFinal();
        });
    }

    static getRoot() {
        return document.getElementById('candidateReviewRoot');
    }

    static getApplicationId() {
        const root = this.getRoot();
        return String(root?.dataset?.applicationId || window.CANDIDATE_APP_ID || '').trim();
    }

    static async loadReview() {
        const appId = this.getApplicationId();
        const messageEl = document.getElementById('candidateReviewMessage');
        const contentEl = document.getElementById('candidateReviewContent');

        if (!appId) {
            if (messageEl) {
                messageEl.className = 'alert alert-danger';
                messageEl.textContent = 'Application ID not found.';
            }
            return;
        }

        try {
            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const url = `${base}/api/shared/candidate_report_get.php?application_id=${encodeURIComponent(appId)}&t=${Date.now()}`;
            const res = await fetch(url, { credentials: 'same-origin' });
            const json = await res.json();

            if (!json || json.status !== 1 || !json.data) {
                throw new Error((json && json.message) || 'Unable to load review data.');
            }

            this.payload = json.data;
            if (contentEl) {
                contentEl.innerHTML = this.renderAll(json.data);
                contentEl.style.display = '';
            }
            if (messageEl) {
                messageEl.style.display = 'none';
            }
        } catch (error) {
            if (messageEl) {
                messageEl.className = 'alert alert-danger';
                messageEl.textContent = error.message || 'Unable to load review data.';
            }
            if (contentEl) {
                contentEl.style.display = 'none';
            }
        }
    }

    static async submitFinal() {
        const button = document.getElementById('reviewFinalSubmitBtn');
        const messageEl = document.getElementById('candidateReviewMessage');
        const original = button ? button.innerHTML : '';

        try {
            if (button) {
                button.disabled = true;
                button.innerHTML = 'Submitting...';
            }

            const base = (window.APP_BASE_URL || '').replace(/\/$/, '');
            const res = await fetch(`${base}/api/candidate/submit.php`, {
                method: 'POST',
                credentials: 'same-origin'
            });
            const json = await res.json();

            if (!json || !json.success) {
                throw new Error((json && json.message) || 'Final submission failed.');
            }

            window.Router?.markCompleted?.('review');
            window.Router?.updateProgress?.();
            window.Router?.navigateTo
                ? window.Router.navigateTo('success')
                : window.location.assign('?page=success');
        } catch (error) {
            if (messageEl) {
                messageEl.className = 'alert alert-danger';
                messageEl.textContent = error.message || 'Final submission failed.';
                messageEl.style.display = '';
            }
        } finally {
            if (button) {
                button.disabled = false;
                button.innerHTML = original || 'Final Submit';
            }
        }
    }

    static renderAll(data) {
        const sections = [];

        sections.push(this.renderSection('Basic Details', this.renderBasic(data.basic, data.contact)));
        sections.push(this.renderSection('Identification Details', this.renderList(data.identification, (row, index) => [
            this.kv('Document', `Document ${index + 1}`),
            this.kv('Type', row.documentId_type || row.document_type),
            this.kv('ID Number', row.id_number),
            this.kv('Name on Document', row.name),
            this.kv('Issue Date', row.issue_date),
            this.kv('Expiry Date', row.expiry_date)
        ]), 'No identification details added.'));
        sections.push(this.renderSection('Contact Details', this.renderContact(data.contact)));
        sections.push(this.renderSection('Education Details', this.renderList(data.education, (row, index) => [
            this.kv('Qualification', row.qualification),
            this.kv('College', row.college_name),
            this.kv('University / Board', row.university_board),
            this.kv('Roll Number', row.roll_number),
            this.kv('From Year', row.year_from),
            this.kv('To Year', row.year_to),
            this.kv('College Address', row.college_address),
            this.kv('College Website', row.college_website)
        ]), 'No education details added.'));
        sections.push(this.renderSection('Employment Details', this.renderList(data.employment, (row, index) => [
            this.kv('Employer', row.employer_name),
            this.kv('Job Title', row.job_title),
            this.kv('Employee ID', row.employee_id),
            this.kv('Joining Date', row.joining_date),
            this.kv('Relieving Date', row.relieving_date),
            this.kv('Employer Address', row.employer_address),
            this.kv('Reason for Leaving', row.reason_leaving),
            this.kv('HR Name', row.hr_manager_name),
            this.kv('HR Phone', row.hr_manager_phone),
            this.kv('HR Email', row.hr_manager_email),
            this.kv('Manager Name', row.manager_name),
            this.kv('Manager Phone', row.manager_phone),
            this.kv('Manager Email', row.manager_email)
        ]), 'No employment details added.'));
        sections.push(this.renderSection('Reference Details', this.renderReference(data.reference)));
        sections.push(this.renderSection('Social Media', this.renderSocial(data.social_media)));
        sections.push(this.renderSection('E-Court', this.renderEcourt(data.ecourt)));
        sections.push(this.renderSection('Authorization', this.renderAuthorization(data.authorization)));

        return sections.join('');
    }

    static renderSection(title, content, emptyText = 'No data available.') {
        const safeContent = content && String(content).trim() !== '' ? content : `<div class="review-empty">${this.escape(emptyText)}</div>`;
        return `
            <section class="review-section">
                <div class="review-section-header">${this.escape(title)}</div>
                <div class="review-section-body">${safeContent}</div>
            </section>
        `;
    }

    static renderBasic(basic, contact) {
        if (!basic && !contact) return '';
        const mobile = this.joinParts([basic?.mobile, contact?.mobile_country_code, contact?.mobile], ' ');
        return this.renderGrid([
            this.kv('First Name', basic?.first_name),
            this.kv('Middle Name', basic?.middle_name),
            this.kv('Last Name', basic?.last_name),
            this.kv('Gender', basic?.gender),
            this.kv('Date of Birth', basic?.dob),
            this.kv('Blood Group', basic?.blood_group),
            this.kv("Father's Name", basic?.father_name),
            this.kv("Mother's Name", basic?.mother_name),
            this.kv('Marital Status', basic?.marital_status),
            this.kv('Spouse Name', basic?.spouse_name),
            this.kv('Mobile', mobile),
            this.kv('Email', basic?.email || contact?.email),
            this.kv('Country', basic?.country),
            this.kv('State', basic?.state),
            this.kv('City / Village', basic?.city_village),
            this.kv('District', basic?.district),
            this.kv('Pincode', basic?.pincode),
            this.kv('Citizenship', basic?.nationality),
            this.kv('Landline', basic?.landline),
            this.kv('Alias or Other Name', basic?.other_name)
        ]);
    }

    static renderContact(contact) {
        if (!contact) return '';
        return this.renderGrid([
            this.kv('Mobile', this.joinParts([contact.mobile_country_code, contact.mobile], ' ')),
            this.kv('Alternative Mobile', contact.alternative_mobile),
            this.kv('Email', contact.email),
            this.kv('Alternative Email', contact.alternative_email),
            this.kv('Current Address', this.joinParts([contact.address1, contact.address2, contact.city, contact.state, contact.country, contact.postal_code], ', ')),
            this.kv('Permanent Address', this.joinParts([contact.permanent_address1, contact.permanent_address2, contact.permanent_city, contact.permanent_state, contact.permanent_country, contact.permanent_postal_code], ', ')),
            this.kv('Same as Current', this.yesNo(contact.same_as_current))
        ]);
    }

    static renderReference(reference) {
        if (!reference) return '';
        const educationReference = {
            reference_name: reference.education_reference_name,
            reference_designation: reference.education_reference_designation,
            reference_company: reference.education_reference_company,
            relationship: reference.education_reference_relationship,
            years_known: reference.education_reference_years_known,
            reference_mobile: reference.education_reference_mobile,
            reference_email: reference.education_reference_email
        };
        const employmentReference = {
            reference_name: reference.employment_reference_name || reference.reference_name,
            reference_designation: reference.employment_reference_designation || reference.reference_designation,
            reference_company: reference.employment_reference_company || reference.reference_company,
            relationship: reference.employment_reference_relationship || reference.relationship,
            years_known: reference.employment_reference_years_known || reference.years_known,
            reference_mobile: reference.employment_reference_mobile || reference.reference_mobile,
            reference_email: reference.employment_reference_email || reference.reference_email
        };

        const renderBlock = (title, row) => {
            const hasData = Object.values(row).some((value) => value);
            if (!hasData) return '';
            return `
                <div class="review-subsection">
                    <div class="review-subsection-title">${this.escape(title)}</div>
                    ${this.renderGrid([
                        this.kv('Reference Name', row.reference_name),
                        this.kv('Designation', row.reference_designation),
                        this.kv('Company', row.reference_company),
                        this.kv('Relationship', row.relationship),
                        this.kv('Years Known', row.years_known),
                        this.kv('Mobile', row.reference_mobile),
                        this.kv('Email', row.reference_email)
                    ])}
                </div>
            `;
        };

        const educationHtml = renderBlock('Education Reference', educationReference);
        const employmentHtml = renderBlock('Employment Reference', employmentReference);

        if (educationHtml || employmentHtml) {
            return educationHtml + employmentHtml;
        }

        return this.renderGrid([
            this.kv('Reference Name', reference.reference_name),
            this.kv('Designation', reference.reference_designation),
            this.kv('Company', reference.reference_company),
            this.kv('Relationship', reference.relationship),
            this.kv('Years Known', reference.years_known),
            this.kv('Mobile', reference.reference_mobile),
            this.kv('Email', reference.reference_email)
        ]);
    }

    static renderSocial(social) {
        if (!social) return '';
        return this.renderGrid([
            this.kv('LinkedIn', social.linkedin_url),
            this.kv('Facebook', social.facebook_url),
            this.kv('Twitter', social.twitter_url),
            this.kv('Instagram', social.instagram_url),
            this.kv('Other Profile', social.other_url),
            this.kv('Additional Information', social.content),
            this.kv('Consent Given', this.yesNo(social.consent_bgv))
        ]);
    }

    static renderEcourt(ecourt) {
        if (!ecourt) return '';
        return this.renderGrid([
            this.kv('Current Address', ecourt.current_address),
            this.kv('Permanent Address', ecourt.permanent_address),
            this.kv('From Date', ecourt.period_from_date),
            this.kv('To Date', ecourt.period_to_date),
            this.kv('Duration (Years)', ecourt.period_duration_years),
            this.kv('Date of Birth', ecourt.dob)
        ]);
    }

    static renderAuthorization(authorization) {
        if (!authorization) return '';
        return this.renderGrid([
            this.kv('Digital Signature', authorization.digital_signature),
            this.kv('Uploaded At', authorization.uploaded_at)
        ]);
    }

    static renderList(rows, rowRenderer, emptyText = 'No records added.') {
        if (!Array.isArray(rows) || rows.length === 0) {
            return `<div class="review-empty">${this.escape(emptyText)}</div>`;
        }
        return rows.map((row, index) => `
            <div class="review-record">
                <div class="review-record-title">Record ${index + 1}</div>
                ${this.renderGrid(rowRenderer(row || {}, index))}
            </div>
        `).join('');
    }

    static renderGrid(items) {
        const html = (items || []).filter(Boolean).join('');
        return html ? `<div class="review-grid">${html}</div>` : '';
    }

    static kv(label, value) {
        const text = this.displayValue(value);
        return `
            <div class="review-kv">
                <div class="review-k">${this.escape(label)}</div>
                <div class="review-v">${this.escape(text)}</div>
            </div>
        `;
    }

    static displayValue(value) {
        if (value === null || value === undefined) return 'Not provided';
        const text = String(value).trim();
        return text !== '' ? text : 'Not provided';
    }

    static fileName(value) {
        const text = String(value || '').trim();
        if (!text) return 'Not uploaded';
        const clean = text.split('/').pop();
        return clean || text;
    }

    static yesNo(value) {
        return String(value || '').trim() === '1' || String(value || '').toLowerCase() === 'yes' ? 'Yes' : 'No';
    }

    static joinParts(parts, separator) {
        const values = (parts || []).map((part) => String(part || '').trim()).filter(Boolean);
        return values.length ? values.join(separator) : 'Not provided';
    }

    static escape(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
}

window.Review = Review;
