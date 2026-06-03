class BasicDetails {
    static _initialized = false;
    static _listeners = [];
    static _mobilePhotoPoll = null;

    /* ================= ENTRY ================= */

    static onPageLoad() {
        console.log(" BasicDetails.onPageLoad");

        if (this._initialized) {
            this.cleanup();
        }

        this._initialized = true;

        this.initForm();
        this.initPhotoUpload();
        this.initMobilePhotoCapture();
        this.initResumeUpload();
        this.initFullNameSync();
        this.initInputConstraints();

        console.log(" BasicDetails ready");
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) =>
            el.removeEventListener(type, fn)
        );
        this._listeners = [];
        if (this._mobilePhotoPoll) {
            clearInterval(this._mobilePhotoPoll);
            this._mobilePhotoPoll = null;
        }
        this._initialized = false;
    }

    static on(el, type, fn) {
        if (!el) return;
        el.addEventListener(type, fn);
        this._listeners.push({ el, type, fn });
    }

    static get form() {
        return document.getElementById("basic-detailsForm");
    }

    /* ================= FORM ================= */

    static initForm() {
        const form = this.form;
        if (!form) {
            console.error(" basic-detailsForm not found");
            return;
        }

        // Prevent native submit
        this.on(form, "submit", e => {
            e.preventDefault();
            e.stopPropagation();
        });

        /* SAVE DRAFT */
        const saveBtn = form.querySelector('.save-draft-btn[data-page="basic-details"]');
        if (saveBtn) {
            this.on(saveBtn, "click", async (e) => {
                e.preventDefault();
                await this.saveDraft();
            });
        }

        /* NEXT */
        const nextBtn = form.querySelector('.external-submit-btn[data-form="basic-detailsForm"]');
        if (nextBtn) {
            nextBtn.type = "button";
            this.on(nextBtn, "click", async (e) => {
                e.preventDefault();
                await this.submitFinal();
            });
        }
    }

    static initPhotoUpload() {
        const trigger = document.getElementById("photoUploadTrigger");
        const input = document.getElementById("photoInput");

        if (!trigger || !input) return;

        // Open mobile capture by default; desktop upload is still available via button.
        this.on(trigger, "click", (e) => {
            if (e.target.closest(".photo-remove-btn")) return;
            if (e.target.closest("#desktopPhotoUploadBtn")) return;
            e.preventDefault();
            this.openMobilePhotoModal();
        });

        // HANDLE FILE SELECTION (preview only)
        this.on(input, "change", () => {
            const file = input.files[0];
            if (!file) return;

            if (!["image/jpeg", "image/png"].includes(file.type)) {
                this.showNotification("Only JPG or PNG images allowed", true);
                input.value = "";
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                this.showNotification("File must be under 5MB", true);
                input.value = "";
                return;
            }

            const reader = new FileReader();
            reader.onload = e => {
                let preview = trigger.querySelector(".photo-preview");
                let uploadBox = trigger.querySelector(".photo-upload-box");

                if (uploadBox) uploadBox.style.display = "none";

                if (!preview) {
                    preview = document.createElement("div");
                    preview.className = "photo-preview";
                    preview.innerHTML = `
                        <img src="${e.target.result}" />
                        <button type="button" class="photo-remove-btn compact-btn">
                            <i class="fas fa-times"></i>
                        </button>
                    `;
                    trigger.appendChild(preview);
                } else {
                    preview.querySelector("img").src = e.target.result;
                    preview.style.display = "block";
                }
            };

            reader.readAsDataURL(file);
        });

        this.on(trigger, "click", (e) => {
            const removeBtn = e.target.closest(".photo-remove-btn");
            if (!removeBtn) return;

            e.preventDefault();
            e.stopPropagation();

            if (!confirm("Remove photo?")) return;

            input.value = "";

            const preview = trigger.querySelector(".photo-preview");
            if (preview) preview.remove();

            const uploadBox = trigger.querySelector(".photo-upload-box");
            if (uploadBox) uploadBox.style.display = "block";

            const existing = this.form.querySelector('input[name="existing_photo"]');
            if (existing) existing.remove();

            console.log(" Photo removed");
        });
    }

    static initMobilePhotoCapture() {
        const mobileBtn = document.getElementById("mobilePhotoCaptureBtn");
        const desktopBtn = document.getElementById("desktopPhotoUploadBtn");
        const closeBtn = document.getElementById("mobilePhotoCloseBtn");
        const modal = document.getElementById("mobilePhotoModal");
        const input = document.getElementById("photoInput");

        this.on(mobileBtn, "click", (e) => {
            e.preventDefault();
            this.openMobilePhotoModal();
        });

        this.on(desktopBtn, "click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            input?.click();
        });

        this.on(closeBtn, "click", (e) => {
            e.preventDefault();
            this.closeMobilePhotoModal();
        });

        this.on(modal, "click", (e) => {
            if (e.target === modal) this.closeMobilePhotoModal();
        });
    }

    static async openMobilePhotoModal() {
        const modal = document.getElementById("mobilePhotoModal");
        const qrBox = document.getElementById("mobilePhotoQrBox");
        const status = document.getElementById("mobilePhotoStatus");
        const urlEl = document.getElementById("mobilePhotoUrl");
        if (!modal || !qrBox || !status) return;

        modal.classList.add("is-open");
        modal.setAttribute("aria-hidden", "false");
        qrBox.innerHTML = "<span>Preparing secure QR...</span>";
        if (urlEl) {
            urlEl.style.display = "none";
            urlEl.removeAttribute("href");
            urlEl.textContent = "";
        }
        status.className = "mobile-photo-status";
        status.textContent = "Secure upload session expires in 10 minutes.";

        if (this._mobilePhotoPoll) {
            clearInterval(this._mobilePhotoPoll);
            this._mobilePhotoPoll = null;
        }

        try {
            const base = (window.APP_BASE_URL || "").replace(/\/$/, "");
            const res = await fetch(`${base}/api/candidate/mobile_photo_session_create.php`, {
                method: "POST",
                credentials: "same-origin"
            });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.status !== 1) {
                throw new Error((data && data.message) ? data.message : "Unable to create QR session");
            }
            const info = data.data || {};
            qrBox.innerHTML = `<img src="${info.qr_data_uri || info.qr_url}" alt="Mobile photo capture QR">`;
            if (urlEl && info.upload_url) {
                urlEl.href = info.upload_url;
                urlEl.textContent = info.upload_url;
                urlEl.style.display = "block";
            }
            if (info.security_hint) {
                status.className = "mobile-photo-status is-error";
                status.textContent = info.security_hint;
            } else {
                status.textContent = "Scan the QR with your mobile camera. Waiting for upload...";
            }
            this.startMobilePhotoPolling(info.token);
        } catch (e) {
            qrBox.innerHTML = "<span>QR unavailable</span>";
            status.className = "mobile-photo-status is-error";
            status.textContent = (e && e.message) ? e.message : "Unable to start mobile capture.";
        }
    }

    static closeMobilePhotoModal() {
        const modal = document.getElementById("mobilePhotoModal");
        if (modal) {
            modal.classList.remove("is-open");
            modal.setAttribute("aria-hidden", "true");
        }
        if (this._mobilePhotoPoll) {
            clearInterval(this._mobilePhotoPoll);
            this._mobilePhotoPoll = null;
        }
    }

    static startMobilePhotoPolling(token) {
        if (!token) return;
        const base = (window.APP_BASE_URL || "").replace(/\/$/, "");
        const status = document.getElementById("mobilePhotoStatus");
        let attempts = 0;
        const poll = async () => {
            attempts++;
            try {
                const res = await fetch(`${base}/api/candidate/mobile_photo_status.php?token=${encodeURIComponent(token)}`, {
                    credentials: "same-origin"
                });
                const data = await res.json().catch(() => null);
                if (!res.ok || !data || data.status !== 1) {
                    throw new Error((data && data.message) ? data.message : "Unable to check upload status");
                }
                const info = data.data || {};
                if (info.upload_status === "uploaded" && info.photo_url) {
                    this.applyMobilePhoto(info.photo_url, info.photo_path);
                    if (status) {
                        status.className = "mobile-photo-status is-success";
                        status.textContent = "Photo received. Updating desktop preview...";
                    }
                    setTimeout(() => this.closeMobilePhotoModal(), 900);
                    return;
                }
                if (info.upload_status === "expired") {
                    if (status) {
                        status.className = "mobile-photo-status is-error";
                        status.textContent = "QR session expired. Please generate a new QR.";
                    }
                    clearInterval(this._mobilePhotoPoll);
                    this._mobilePhotoPoll = null;
                }
            } catch (e) {
                if (attempts > 3 && status) {
                    status.className = "mobile-photo-status is-error";
                    status.textContent = "Still waiting for mobile upload...";
                }
            }
            if (attempts >= 150 && this._mobilePhotoPoll) {
                clearInterval(this._mobilePhotoPoll);
                this._mobilePhotoPoll = null;
            }
        };
        this._mobilePhotoPoll = setInterval(poll, 2000);
        poll();
    }

    static applyMobilePhoto(photoUrl, photoPath) {
        const trigger = document.getElementById("photoUploadTrigger");
        const input = document.getElementById("photoInput");
        if (!trigger) return;
        if (input) input.value = "";

        let uploadBox = trigger.querySelector(".photo-upload-box");
        if (uploadBox) uploadBox.style.display = "none";

        let preview = trigger.querySelector(".photo-preview");
        if (!preview) {
            preview = document.createElement("div");
            preview.className = "photo-preview has-image";
            preview.innerHTML = `
                <img src="" alt="Profile Photo" />
                <button type="button" class="photo-remove-btn compact-btn">
                    <i class="fas fa-times"></i>
                </button>
            `;
            trigger.appendChild(preview);
        }
        preview.classList.add("has-image");
        preview.style.display = "block";
        const img = preview.querySelector("img");
        if (img) img.src = `${photoUrl}${photoUrl.includes("?") ? "&" : "?"}t=${Date.now()}`;

        let existing = this.form?.querySelector('input[name="existing_photo"]');
        if (!existing && this.form) {
            existing = document.createElement("input");
            existing.type = "hidden";
            existing.name = "existing_photo";
            this.form.appendChild(existing);
        }
        if (existing) existing.value = photoPath || "";
    }

    static initResumeUpload() {
        const input = document.getElementById("resumeInput");
        if (!input) return;

        const chooseBtn = document.querySelector('[data-file-target="resumeInput"]');
        const box = chooseBtn ? chooseBtn.closest('[data-file-upload]') : null;
        const nameBtn = box ? box.querySelector('[data-file-name]') : null;
        const removeBtn = box ? box.querySelector('[data-file-remove]') : null;
        const errorEl = box ? box.querySelector('[data-file-error]') : null;

        if (chooseBtn) {
            this.on(chooseBtn, "click", (e) => {
                e.preventDefault();
                input.click();
            });
        }

        this.on(input, "change", () => {
            const file = input.files && input.files[0] ? input.files[0] : null;
            if (!file) return;

            const lower = String(file.name || '').toLowerCase();
            const valid = lower.endsWith('.pdf') && (!file.type || file.type === 'application/pdf');
            if (!valid) {
                if (errorEl) errorEl.textContent = 'Only PDF resumes are allowed.';
                input.value = '';
                return;
            }
            if (file.size > 8 * 1024 * 1024) {
                if (errorEl) errorEl.textContent = 'Resume must be under 8MB.';
                input.value = '';
                return;
            }

            if (errorEl) errorEl.textContent = '';
            if (nameBtn) {
                nameBtn.textContent = file.name;
                nameBtn.disabled = false;
                nameBtn.classList.add('preview-btn');
            }
            if (removeBtn) {
                removeBtn.style.display = '';
                removeBtn.disabled = false;
                removeBtn.setAttribute('aria-hidden', 'false');
            }
        });

        if (removeBtn) {
            this.on(removeBtn, "click", (e) => {
                e.preventDefault();
                input.value = "";
                if (nameBtn) {
                    nameBtn.textContent = "No file chosen";
                    nameBtn.disabled = true;
                    nameBtn.classList.remove("preview-btn");
                }
                removeBtn.style.display = "none";
                removeBtn.disabled = true;
                removeBtn.setAttribute("aria-hidden", "true");
                this.form?.querySelector('[name="existing_resume"]')?.remove();
                this.form?.querySelector('[name="existing_resume_name"]')?.remove();
                if (errorEl) errorEl.textContent = "";
            });
        }
    }

    static initFullNameSync() {
        const display = document.getElementById("applicantFullNameDisplay");
        const form = this.form;
        if (!display || !form) return;

        const fields = [
            form.querySelector('[name="first_name"]'),
            form.querySelector('[name="middle_name"]'),
            form.querySelector('[name="last_name"]')
        ].filter(Boolean);

        let lastAutoValue = String(display.dataset.autoFullName || '').trim();
        let manuallyEdited = String(display.value || '').trim() !== '' && String(display.value || '').trim() !== lastAutoValue;

        this.on(display, "input", () => {
            manuallyEdited = String(display.value || '').trim() !== lastAutoValue;
        });

        const update = () => {
            const fullName = fields
                .map(field => String(field.value || '').trim())
                .filter(Boolean)
                .join(' ');
            if (manuallyEdited && String(display.value || '').trim() !== '') {
                lastAutoValue = fullName;
                display.dataset.autoFullName = fullName;
                return;
            }
            if ('value' in display) {
                display.value = fullName;
                display.placeholder = 'Will auto-build from name fields';
            } else {
                display.textContent = fullName || 'Will auto-build from name fields';
            }
        };

        fields.forEach(field => this.on(field, "input", update));
        update();
    }

    /* ================= VALIDATION ================= */

static validate(final = true) {
    const form = this.form;
    if (!form) return false;

    // Clear previous errors
    window.CandidateNotify.clearValidation(form);

    const errors = [];

    const get = (name) => form.querySelector(`[name="${name}"]`);

    const firstName = get("first_name");
    const lastName = get("last_name");
    const gender = get("gender");
    const dob = get("dob");
    const father = get("father_name");
    const mobile = get("mobile");
    const country = get("country");
    const state = get("state");
    const city = get("city_village");
    const district = get("district");
    const pincode = get("pincode");
    const email = get("email");

    /* ================= REQUIRED ================= */

    if (final && !firstName.value.trim()) {
        window.CandidateNotify.addFieldError(errors, firstName, "First name is required");
    }

    if (final && !lastName.value.trim()) {
        window.CandidateNotify.addFieldError(errors, lastName, "Last name is required");
    }

    if (final && !gender.value) {
        window.CandidateNotify.addFieldError(errors, gender, "Please select gender");
    }

    if (final && !father.value.trim()) {
        window.CandidateNotify.addFieldError(errors, father, "Father name is required");
    }

    if (final && !email.value.trim()) {
        window.CandidateNotify.addFieldError(errors, email, "Email is required");
    }

    if (final && !country.value) {
        window.CandidateNotify.addFieldError(errors, country, "Select country");
    }

    if (final && !state.value) {
        window.CandidateNotify.addFieldError(errors, state, "Select state");
    }

    if (final && !city.value.trim()) {
        window.CandidateNotify.addFieldError(errors, city, "City is required");
    }

    if (final && !district.value.trim()) {
        window.CandidateNotify.addFieldError(errors, district, "District is required");
    }

    /* ================= DOB ================= */

    if (final && dob.value) {
        const d = new Date(dob.value);
        const cutoff = new Date();
        cutoff.setFullYear(cutoff.getFullYear() - 18);

        if (d > cutoff) {
            window.CandidateNotify.addFieldError(errors, dob, "Must be at least 18 years old");
        }
    } else if (final) {
        window.CandidateNotify.addFieldError(errors, dob, "Date of birth is required");
    }

    /* ================= MOBILE ================= */

    if (final && !mobile.value.trim()) {
        window.CandidateNotify.addFieldError(errors, mobile, "Mobile number required");
    } else if (mobile.value && !/^\d+$/.test(mobile.value)) {
        window.CandidateNotify.addFieldError(errors, mobile, "Only digits allowed");
    }

    /* ================= PINCODE ================= */

    if (final && pincode.value && !/^\d{6}$/.test(pincode.value)) {
        window.CandidateNotify.addFieldError(errors, pincode, "Pincode must be 6 digits");
    }

    /* ================= PHOTO ================= */

    const photoInput = document.getElementById('photoInput');
    const hasPhoto = photoInput?.files?.length > 0 ||
        !!form.querySelector('input[name="existing_photo"]');

    if (final && !hasPhoto) {
        const trigger = document.getElementById('photoUploadTrigger');
        window.CandidateNotify.addFieldError(errors, trigger, "Upload profile photo");
    }

    /* ================= FINAL ================= */

    if (errors.length) {
        window.CandidateNotify.validation({
            form,
            errors,
            title: "Please fix the errors",
            message: `You have ${errors.length} issue(s)`
        });

        return false;
    }

    return true;
}

    static initInputConstraints() {
        const form = this.form;
        if (!form) return;

        const dobInput = form.querySelector('input[name="dob"]');
        if (dobInput && dobInput.max && String(dobInput.max).trim() === '') {
            // no-op, keep existing
        }

        if (dobInput && !dobInput.max) {
            const cutoff = new Date();
            cutoff.setHours(0, 0, 0, 0);
            cutoff.setFullYear(cutoff.getFullYear() - 18);
            const m = String(cutoff.getMonth() + 1).padStart(2, '0');
            const d = String(cutoff.getDate()).padStart(2, '0');
            dobInput.max = `${cutoff.getFullYear()}-${m}-${d}`;
        }

        const pincodeInput = form.querySelector('input[name="pincode"]');
        if (pincodeInput) {
            this.on(pincodeInput, 'input', () => {
                const v = String(pincodeInput.value || '').replace(/\D/g, '').slice(0, 6);
                if (pincodeInput.value !== v) pincodeInput.value = v;
            });
        }
    }

    /* ================= PREPARE FORM DATA ================= */

    static prepareFormData() {
        const form = this.form;
        const fd = new FormData(form);
        const mobileCountryCode = form.querySelector('select[name="mobile_country_code"]')?.value || '+91';
        const mobileNumber = form.querySelector('input[name="mobile"]')?.value || '';

        fd.set('mobile_country_code', mobileCountryCode.trim());
        fd.set('mobile', mobileNumber.trim());
        
        return fd;
    }

    /* ================= SAVE / SUBMIT ================= */

    static async saveDraft() {
        if (!this.validate(false)) return;

        const fd = this.prepareFormData();
        fd.append("save_draft", "1");

        await this.send(fd);
    }

    static async submitFinal() {
        if (!this.validate(true)) return;

        const fd = this.prepareFormData();
        fd.append("save_draft", "0");

        const ok = await this.send(fd);
        if (ok) {
            if (window.Router) {
                if (typeof Router.markCompleted === 'function') Router.markCompleted("basic-details");
                if (typeof Router.navigateTo === 'function') Router.navigateTo("identification");
            } else {
                window.location.href = '/candidate/identification.php';
            }
        }
    }

    /* ================= API ================= */

    static async send(formData) {
        try {
            if (!window.APP_BASE_URL) {
                throw new Error("APP_BASE_URL not defined");
            }

            const endpoint = `${window.APP_BASE_URL}/api/candidate/store_basic-details.php`;

            console.log("📡 BasicDetails API:", endpoint);
            console.log("📦 FormData contents:");
            for (let pair of formData.entries()) {
                console.log(pair[0] + ': ' + pair[1]);
            }

            const res = await fetch(endpoint, {
                method: "POST",
                body: formData,
                credentials: "include"
            });

            console.log("📥 Response status:", res.status);

            if (!res.ok) {
                const responseText = await res.text().catch(() => '');
                let serverMessage = '';
                try {
                    const parsed = JSON.parse(responseText || '{}');
                    serverMessage = String(parsed && (parsed.message || parsed.error || '') || '').trim();
                } catch (_e) {
                }
                throw new Error(serverMessage || String(responseText || '').trim() || `Request failed (HTTP ${res.status})`);
            }

            const data = await res.json().catch(() => ({}));

            if (!data.success) {
                throw new Error(data.message || "Save failed");
            }

            this.showNotification(data.message || "Saved successfully!");
            return true;

        } catch (err) {
            console.error("❌ BasicDetails error", err);
            this.showNotification(String((err && err.message) ? err.message : "Save failed"), true);
            return false;
        }
    }

    /* ================= NOTIFICATION ================= */

static showNotification(message, isError = false) {
    if (window.CandidateNotify) {
        window.CandidateNotify.show({
            type: isError ? 'error' : 'success',
            title: isError ? 'Basic details not saved' : 'Basic details saved',
            message: String(message || '').replace(/^[^\w]+/, ''),
            sticky: false,              // ✅ FIX
            timeout: isError ? 5000 : 2500             // optional
        });
        return;
    }

        const existingNotif = document.querySelector('.basic-details-notification');
        if (existingNotif) {
            existingNotif.remove();
        }
        
        const notification = document.createElement('div');
        notification.className = `basic-details-notification alert ${isError ? 'alert-danger' : 'alert-success'} alert-dismissible fade show`;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        `;
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 5 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
        
        // Also remove when manually dismissed
        const closeBtn = notification.querySelector('.btn-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                if (notification.parentNode) {
                    notification.remove();
                }
            });
        }
    }
}

/* ================= EXPORT ================= */
window.BasicDetails = BasicDetails;


