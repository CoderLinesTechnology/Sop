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

initNavigation();
initCountdowns();
initFormStartBeacon();
