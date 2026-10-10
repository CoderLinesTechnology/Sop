/*
 * Statementra — the only script on marketing pages. Deliberately tiny and
 * dependency-free: mobile navigation, server-timed countdowns and a
 * cookieless "form started" analytics beacon.
 */

function initNavigation() {
    const toggle = document.querySelector('[data-nav-toggle]');
    const panel = document.getElementById('mobile-nav');
    if (!toggle || !panel) return;

    const setOpen = (open) => {
        toggle.setAttribute('aria-expanded', String(open));
        panel.hidden = !open;
        document.body.classList.toggle('overflow-hidden', open);
    };

    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    panel.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
    });
}

/*
 * Countdowns use the server's clock: the page renders the server time and the
 * offset is applied locally, so a wrong device clock cannot extend an offer.
 * The price itself is always recalculated by the server at checkout.
 */
function initCountdowns() {
    const timers = document.querySelectorAll('[data-countdown]');
    if (!timers.length) return;

    const pad = (value) => String(value).padStart(2, '0');

    timers.forEach((timer) => {
        const endsAt = Date.parse(timer.dataset.endsAt);
        const serverNow = Date.parse(timer.dataset.serverNow);
        if (Number.isNaN(endsAt) || Number.isNaN(serverNow)) return;

        const offset = serverNow - Date.now();
        const parts = {
            days: timer.querySelector('[data-unit="days"]'),
            hours: timer.querySelector('[data-unit="hours"]'),
            minutes: timer.querySelector('[data-unit="minutes"]'),
            seconds: timer.querySelector('[data-unit="seconds"]'),
            compact: timer.querySelector('[data-unit="compact"]'),
        };

        const tick = () => {
            const remaining = Math.max(0, endsAt - (Date.now() + offset));
            const total = Math.floor(remaining / 1000);
            const days = Math.floor(total / 86400);
            const hours = Math.floor((total % 86400) / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;

            if (parts.days) parts.days.textContent = pad(days);
            if (parts.hours) parts.hours.textContent = pad(hours);
            if (parts.minutes) parts.minutes.textContent = pad(minutes);
            if (parts.seconds) parts.seconds.textContent = pad(seconds);
            if (parts.compact) parts.compact.textContent = (days > 0 ? days + 'd ' : '') + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);

            if (remaining <= 0) {
                clearInterval(interval);
                // The offer ended: show the regular price, as calculated by the server.
                window.setTimeout(() => window.location.reload(), 1200);
            }
        };

        const interval = window.setInterval(tick, 1000);
        tick();
    });
}

function initFormStartBeacon() {
    const form = document.querySelector('[data-track-form-start]');
    if (!form || !navigator.sendBeacon) return;

    let sent = false;
    form.addEventListener('input', () => {
        if (sent) return;
        sent = true;
        const payload = new Blob([JSON.stringify({ event: 'form_start', service: form.dataset.trackFormStart })], { type: 'application/json' });
        navigator.sendBeacon('/beacon', payload);
    }, { passive: true });
}

/*
 * Forms that must not be sent twice (a double click, or a click while the
 * first request is still on its way) carry data-submit-once: the first submit
 * goes through and the button is disabled until the next page loads.
 */
function initSubmitOnce() {
    document.querySelectorAll('form[data-submit-once]').forEach((form) => {
        let submitted = false;
        form.addEventListener('submit', (event) => {
            if (submitted) {
                event.preventDefault();
                return;
            }
            submitted = true;
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
            });
        });
    });

    // A page restored from the back/forward cache must be usable again.
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted) return;
        document.querySelectorAll('form[data-submit-once]').forEach((form) => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = false;
            });
        });
    });
}

/*
 * Answer starters: buttons under a question (data-starter) put the beginning
 * of a sentence into its textarea so the customer only has to finish it. The
 * first "…" is selected, so typing replaces it. Plain text, no AI involved.
 */
function initAnswerStarters() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-starter]');
        if (!button) return;
        const field = document.getElementById(button.dataset.starterTarget || '');
        if (!field || field.disabled || field.readOnly) return;

        const starter = button.dataset.starter || '';
        const current = field.value.trimEnd();
        const next = current ? `${current}\n${starter}` : starter;
        if (field.maxLength > 0 && next.length > field.maxLength) return;

        field.value = next;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.focus();
        const gap = next.indexOf('…', next.length - starter.length);
        field.setSelectionRange(gap >= 0 ? gap : next.length, gap >= 0 ? gap + 1 : next.length);
    });
}

/*
 * Answers survive a lost connection or an expired session: a form with
 * data-draft-key keeps its text fields in this browser (for up to three days)
 * and fills them in again when the page is opened. A page with data-draft-clear
 * removes drafts that are no longer needed (the answers were sent). While such
 * a form is open, data-keepalive-url is fetched every few minutes so the
 * session, and with it the form's security token, does not expire.
 */
const DRAFT_TTL_MS = 3 * 24 * 60 * 60 * 1000;

function storage() {
    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function initFormDrafts() {
    const store = storage();
    if (!store) return;

    document.querySelectorAll('[data-draft-clear]').forEach((element) => {
        try {
            Object.keys(store).filter((key) => key.startsWith(element.dataset.draftClear)).forEach((key) => store.removeItem(key));
        } catch {
            // Storage unavailable: nothing to clear.
        }
    });

    document.querySelectorAll('form[data-draft-key]').forEach((form) => {
        const key = form.dataset.draftKey;
        const fields = () => Array.from(form.querySelectorAll('textarea[name], input[type="text"][name]'));

        try {
            const draft = JSON.parse(store.getItem(key) || 'null');
            if (draft && Date.now() - draft.savedAt < DRAFT_TTL_MS) {
                fields().forEach((field) => {
                    if (!field.value && typeof draft.values?.[field.name] === 'string') field.value = draft.values[field.name];
                });
            } else if (draft) {
                store.removeItem(key);
            }
        } catch {
            store.removeItem(key);
        }

        form.addEventListener('input', () => {
            const values = {};
            fields().forEach((field) => {
                if (field.value) values[field.name] = field.value;
            });
            try {
                store.setItem(key, JSON.stringify({ savedAt: Date.now(), values }));
            } catch {
                // Storage full or blocked: the form still works.
            }
        });

        if (form.dataset.keepaliveUrl) {
            setInterval(() => {
                fetch(form.dataset.keepaliveUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {});
            }, 5 * 60 * 1000);
        }
    });
}

initNavigation();
initCountdowns();
initFormStartBeacon();
initSubmitOnce();
initAnswerStarters();
initFormDrafts();
