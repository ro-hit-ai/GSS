class BasicDetails {
    static _initialized = false;
    static _listeners = [];

    /* ================= ENTRY ================= */

    static onPageLoad() {
        console.log(" BasicDetails.onPageLoad");

        if (this._initialized) {
            this.cleanup();
        }

        this._initialized = true;

        this.initForm();
        this.initPhotoUpload();
        this.initInputConstraints();

        console.log(" BasicDetails ready");
    }

    static cleanup() {
        this._listeners.forEach(({ el, type, fn }) =>
            el.removeEventListener(type, fn)
        );
        this._listeners = [];
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

        // OPEN FILE PICKER
        this.on(trigger, "click", (e) => {
            if (e.target.closest(".photo-remove-btn")) return;
            input.click();
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

        // REMOVE PHOTO
        this.on(trigger, "click", (e) => {
            const removeBtn = e.target.closest(".photo-remove-btn");
            if (!removeBtn) return;

            e.preventDefault();
            e.stopPropagation();

            if (!confirm("Remove photo?")) return;

            // Reset input
            input.value = "";

            // Remove preview
            const preview = trigger.querySelector(".photo-preview");
            if (preview) preview.remove();

            // Show upload box
            const uploadBox = trigger.querySelector(".photo-upload-box");
            if (uploadBox) uploadBox.style.display = "block";

            // Remove existing photo reference
            const existing = this.form.querySelector('input[name="existing_photo"]');
            if (existing) existing.remove();

            console.log(" Photo removed");
        });
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
        
        // Fix mobile field - combine country code and number
        const mobileCountryCode = form.querySelector('select[name="mobile_country_code"]').value;
        const mobileNumber = form.querySelector('input[name="mobile"]').value;
        
        // Remove individual fields and add combined mobile field
        fd.delete('mobile_country_code');
        fd.delete('mobile');
        fd.append('mobile', mobileCountryCode  + mobileNumber);
        
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


