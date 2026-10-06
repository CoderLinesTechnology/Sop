/*
 * Checkout and order-page interactions (Alpine.js, CSP build — no eval, so
 * the strict Content Security Policy stays intact). Everything here is
 * progressive enhancement: the forms also work as plain HTML posts.
 */
import Alpine from '@alpinejs/csp';

const DRAFT_KEY = 'st_application_v1';

function csrfToken(root) {
    return root.querySelector('input[name="_token"]')?.value
        || document.querySelector('meta[name="csrf-token"]')?.content
        || '';
}

function humanSize(bytes) {
    return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
}

Alpine.data('applicationForm', () => ({
    config: {},
    values: {},
    labels: {},
    uploads: [],
    dragging: false,
    submitting: false,
    formError: '',

    init() {
        try {
            this.config = JSON.parse(this.$el.dataset.config || '{}');
        } catch {
            this.config = {};
        }

        this.values = Object.assign({}, this.config.values || {});
        this.uploads = (this.config.uploads || []).map((upload) => Object.assign({ id: upload.uuid, status: 'done', progress: 100, error: '' }, upload));

        // Capture what the server rendered (old input / saved answers), then fill gaps from the session draft.
        this.$el.querySelectorAll('[name^="answers["]').forEach((element) => this.capture(element));
        if (!this.config.hasErrors) {
            this.restoreDraft();
        }
    },

    keyFrom(name) {
        const match = /^answers\[([^\]]+)\]/.exec(name || '');
        return match ? match[1] : null;
    },

    capture(element) {
        const key = this.keyFrom(element.name);
        if (!key) return;

        if (element.type === 'checkbox' && element.name.endsWith('[]')) {
            const checked = Array.from(this.$el.querySelectorAll(`[name="${CSS.escape(element.name)}"]:checked`)).map((input) => input.value);
            this.values[key] = checked;
        } else if (element.type === 'checkbox') {
            this.values[key] = element.checked ? '1' : '';
        } else if (element.type === 'radio') {
            if (element.checked) this.values[key] = element.value;
        } else if (element.value !== '' || this.values[key] === undefined) {
            this.values[key] = element.value;
        }

        if (element.tagName === 'SELECT' && element.selectedOptions[0]) {
            this.labels[key] = element.value ? element.selectedOptions[0].textContent.trim() : '';
        }
    },

    onInput(event) {
        const element = event.target;
        if (!element.name) return;
        this.capture(element);
        element.removeAttribute('aria-invalid');
        const hint = element.closest('[data-field]')?.querySelector('[data-client-error]');
        if (hint) hint.hidden = true;
        this.saveDraft();
    },

    display(key, fallback) {
        const value = this.values[key];
        if (value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length)) return fallback;
        if (this.labels[key]) return this.labels[key];
        if (this.config.dateKeys && this.config.dateKeys.includes(key)) {
            const date = new Date(value + 'T00:00:00');
            if (!Number.isNaN(date.getTime())) {
                return date.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
            }
        }
        return Array.isArray(value) ? value.join(', ') : String(value);
    },

    hasValue(key) {
        const value = this.values[key];
        return !(value === undefined || value === null || value === '' || (Array.isArray(value) && !value.length));
    },

    excerpt(key, length) {
        const text = this.display(key, '');
        return text.length > length ? text.slice(0, length).trim() + '…' : text;
    },

    charCount(key) {
        return String(this.values[key] || '').length;
    },

    isVisible(key) {
        const rule = (this.config.conditions || {})[key];
        if (!rule) return true;
        const actual = this.values[rule.field];
        return Array.isArray(actual) ? actual.includes(rule.equals) : String(actual ?? '') === String(rule.equals);
    },

    hasUpload(slot) {
        return this.uploads.some((upload) => upload.slot === slot && upload.status === 'done');
    },

    uploadCount(slot) {
        return this.uploads.filter((upload) => upload.slot === slot && upload.status === 'done').length;
    },

    completedUploads() {
        return this.uploads.filter((upload) => upload.status === 'done');
    },

    slotLabel(slot) {
        return (this.config.slots || {})[slot] || 'Document';
    },

    inputName(upload) {
        return `upload_ids[${upload.slot}][]`;
    },

    progressStyle(upload) {
        return { width: upload.progress + '%' };
    },

    onPick(event) {
        const input = event.target;
        Array.from(input.files || []).forEach((file) => this.upload(file, input.dataset.slot || 'other'));
        input.value = '';
    },

    onDragOver() {
        this.dragging = true;
    },

    onDragLeave() {
        this.dragging = false;
    },

    onDrop(event) {
        this.dragging = false;
        Array.from(event.dataTransfer?.files || []).forEach((file) => this.upload(file, 'other'));
    },

    upload(file, slot) {
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        const accepted = this.config.accept || ['pdf', 'docx', 'txt', 'jpg', 'jpeg', 'png'];
        const entry = { id: 'u' + Math.random().toString(36).slice(2), uuid: null, name: file.name, size: humanSize(file.size), slot, status: 'uploading', progress: 0, error: '' };

        if (!accepted.includes(extension)) {
            entry.status = 'error';
            entry.error = 'This file type isn’t supported. Use ' + accepted.filter((e) => e !== 'jpeg').map((e) => e.toUpperCase()).join(', ') + '.';
        } else if (file.size > (this.config.maxBytes || 10485760)) {
            entry.status = 'error';
            entry.error = 'This file is larger than ' + Math.round((this.config.maxBytes || 10485760) / 1048576) + ' MB.';
        }

        this.uploads.push(entry);
        const item = this.uploads[this.uploads.length - 1];
        if (item.status === 'error') return;

        const data = new FormData();
        data.append('file', file);
        data.append('slot', slot);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', this.config.uploadUrl);
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken(this.$el));
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', (progress) => {
            if (progress.lengthComputable) item.progress = Math.round((progress.loaded / progress.total) * 100);
        });
        xhr.addEventListener('load', () => {
            let response = {};
            try {
                response = JSON.parse(xhr.responseText || '{}');
            } catch {
                response = {};
            }
            if (xhr.status === 201) {
                Object.assign(item, response, { status: 'done', progress: 100 });
            } else {
                item.status = 'error';
                item.error = response.message || (xhr.status === 429 ? 'Too many uploads. Please wait a moment.' : 'Upload failed. Please try again.');
            }
        });
        xhr.addEventListener('error', () => {
            item.status = 'error';
            item.error = 'Upload failed. Check your connection and try again.';
        });
        xhr.send(data);
    },

    remove(id) {
        const item = this.uploads.find((upload) => upload.id === id);
        if (!item) return;
        if (item.uuid) {
            fetch('/uploads/' + encodeURIComponent(item.uuid), {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': csrfToken(this.$el), Accept: 'application/json' },
                credentials: 'same-origin',
            });
        }
        this.uploads = this.uploads.filter((upload) => upload.id !== id);
    },

    onSubmit(event) {
        this.formError = '';

        if (this.uploads.some((upload) => upload.status === 'uploading')) {
            event.preventDefault();
            this.formError = 'Please wait for your files to finish uploading.';
            return;
        }

        // Friendly client-side check for required fields; the server validates again.
        let first = null;
        this.$el.querySelectorAll('[data-required="true"]').forEach((element) => {
            const wrapper = element.closest('[data-field]');
            if (!wrapper || wrapper.offsetParent === null) return;
            const key = this.keyFrom(element.name);
            const optionalBecause = element.dataset.optionalWhenUpload;
            if (optionalBecause && this.hasUpload(optionalBecause)) return;
            if (!this.hasValue(key)) {
                element.setAttribute('aria-invalid', 'true');
                const hint = wrapper.querySelector('[data-client-error]');
                if (hint) hint.hidden = false;
                first = first || element;
            }
        });

        if (first) {
            event.preventDefault();
            this.formError = 'Please complete the highlighted fields.';
            first.focus({ preventScroll: false });
            first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        this.submitting = true;
        this.clearDraft();
    },

    changeService(event) {
        this.saveDraft();
        window.location.href = event.target.value;
    },

    rememberBeforeSwitch() {
        this.saveDraft();
    },

    saveDraft() {
        try {
            const draft = {};
            Object.keys(this.values).forEach((key) => {
                if (!(this.config.privateKeys || []).includes(key)) draft[key] = this.values[key];
            });
            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
        } catch {
            // Storage unavailable (private mode): nothing to persist.
        }
    },

    restoreDraft() {
        let draft = {};
        try {
            draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || '{}');
        } catch {
            return;
        }

        Object.keys(draft).forEach((key) => {
            if (this.hasValue(key) || draft[key] === '' || draft[key] === null) return;
            const value = draft[key];
            const elements = this.$el.querySelectorAll(`[name="answers[${CSS.escape(key)}]"], [name="answers[${CSS.escape(key)}][]"]`);
            if (!elements.length) return;

            elements.forEach((element) => {
                if (element.type === 'checkbox' || element.type === 'radio') {
                    element.checked = Array.isArray(value) ? value.includes(element.value) : String(element.value) === String(value) || (element.type === 'checkbox' && value === '1');
                } else {
                    element.value = value;
                }
                this.capture(element);
            });
        });
    },

    clearDraft() {
        try {
            sessionStorage.removeItem(DRAFT_KEY);
        } catch {
            // ignore
        }
    },
}));

Alpine.data('paymentForm', () => ({
    config: {},
    coupon: '',
    couponOpen: false,
    couponMessage: '',
    couponState: '',
    applying: false,
    confirmed: false,
    submitting: false,
    price: {},

    init() {
        try {
            this.config = JSON.parse(this.$el.dataset.config || '{}');
        } catch {
            this.config = {};
        }
        this.price = this.config.quote || {};
        this.coupon = this.config.coupon || '';
        this.couponOpen = !!this.coupon;
        this.confirmed = !!this.config.confirmed;
    },

    toggleCoupon() {
        this.couponOpen = !this.couponOpen;
    },

    onCouponInput(event) {
        this.coupon = event.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, '');
    },

    onConfirm(event) {
        this.confirmed = event.target.checked;
    },

    async applyCoupon(remove) {
        if (this.applying) return;
        this.applying = true;
        this.couponMessage = '';

        const code = remove === true ? '' : this.coupon;
        try {
            const response = await fetch(this.config.quoteUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken(this.$el) },
                body: JSON.stringify({ coupon: code, email: this.$el.querySelector('input[name="email"]')?.value || null }),
            });
            const data = await response.json();
            if (!response.ok) {
                this.couponState = 'error';
                this.couponMessage = data.message || 'We couldn’t check this code right now.';
                return;
            }
            this.price = data;
            if (remove === true) {
                this.coupon = '';
                this.couponState = '';
            } else {
                this.couponState = data.coupon_status === 'applied' ? 'success' : 'error';
                this.couponMessage = data.coupon_message || '';
            }
        } catch {
            this.couponState = 'error';
            this.couponMessage = 'We couldn’t check this code right now. Please try again.';
        } finally {
            this.applying = false;
        }
    },

    removeCoupon() {
        this.applyCoupon(true);
    },

    hasDiscount() {
        return !!this.price.original;
    },

    couponApplied() {
        return !!this.price.coupon_code;
    },

    onSubmit(event) {
        if (!this.confirmed) {
            event.preventDefault();
            this.$el.querySelector('#confirm_accuracy')?.focus();
            return;
        }
        this.submitting = true;
    },
}));

Alpine.data('orderStatus', () => ({
    status: '',
    steps: [],
    timer: null,

    init() {
        const config = JSON.parse(this.$el.dataset.config || '{}');
        this.status = config.status;
        this.steps = config.steps || [];
        if (config.poll) {
            this.timer = window.setInterval(() => this.refresh(config.progressUrl), 15000);
        }
    },

    async refresh(url) {
        if (document.hidden) return;
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const data = await response.json();
            this.steps = data.steps;
            if (data.status !== this.status) {
                window.clearInterval(this.timer);
                window.location.reload();
            }
        } catch {
            // Network hiccup: try again on the next tick.
        }
    },

    stepClass(step) {
        return {
            'bg-brand-700 text-white border-brand-700': step.state === 'done',
            'border-brand-700 text-brand-700 bg-white': step.state === 'current',
            'border-line-strong text-muted bg-white': step.state === 'pending',
        };
    },

    isDone(step) {
        return step.state === 'done';
    },

    isCurrent(step) {
        return step.state === 'current';
    },
}));

Alpine.data('starRating', () => ({
    rating: 0,
    hover: 0,

    init() {
        this.rating = Number(this.$el.dataset.rating || 0);
    },

    set(value) {
        this.rating = value;
    },

    preview(value) {
        this.hover = value;
    },

    clearPreview() {
        this.hover = 0;
    },

    isActive(value) {
        return (this.hover || this.rating) >= value;
    },
}));

window.Alpine = Alpine;
Alpine.start();
