// Shared interaction layer: mobile navigation, dialogs, tabs, toasts, inline form validation.
(function () {
    'use strict';

    // ---- Mobile sidebar -------------------------------------------------
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');

    const desktopNav = window.matchMedia('(min-width: 1024px)');

    function setSidebar(open, returnFocus) {
        if (!sidebar || !backdrop) return;
        // On desktop the sidebar is permanent, so never lock page scrolling there.
        if (desktopNav.matches) open = true;
        sidebar.classList.toggle('-translate-x-full', !open);
        backdrop.classList.toggle('hidden', !open);
        document.getElementById('sidebar-toggle')?.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('overflow-hidden', open && !desktopNav.matches);
        // Keep the off-canvas drawer out of the tab order while it is closed on phones.
        sidebar.inert = !open && !desktopNav.matches;
        if (open && !desktopNav.matches) sidebar.querySelector('a, button')?.focus();
        if (!open && returnFocus) document.getElementById('sidebar-toggle')?.focus();
    }

    setSidebar(desktopNav.matches);

    document.getElementById('sidebar-toggle')?.addEventListener('click', () => {
        setSidebar(sidebar.classList.contains('-translate-x-full'));
    });
    backdrop?.addEventListener('click', () => setSidebar(false, true));
    document.querySelectorAll('[data-sidebar-close]').forEach((btn) => {
        btn.addEventListener('click', () => setSidebar(false, true));
    });

    // Rotating or resizing across the breakpoint must release the scroll lock.
    const onBreakpointChange = () => setSidebar(desktopNav.matches);
    if (typeof desktopNav.addEventListener === 'function') {
        desktopNav.addEventListener('change', onBreakpointChange);
    } else if (typeof desktopNav.addListener === 'function') {
        desktopNav.addListener(onBreakpointChange);
    }

    // Printing is a click handler rather than an inline onclick, so a strict CSP can ban inline JS.
    document.querySelectorAll('[data-print]').forEach((btn) => {
        btn.addEventListener('click', () => window.print());
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setSidebar(false, true);
    });

    // ---- Dialogs --------------------------------------------------------
    function openDialog(id) {
        const el = typeof id === 'string' ? document.getElementById(id) : id;
        if (!el) return null;
        if (typeof el.showModal === 'function') el.showModal();
        else el.setAttribute('open', '');
        return el;
    }

    document.addEventListener('click', (e) => {
        const opener = e.target.closest('[data-dialog-target]');
        if (opener) {
            e.preventDefault();
            openDialog(opener.getAttribute('data-dialog-target'));
            return;
        }
        const closer = e.target.closest('[data-dialog-close]');
        if (closer) {
            e.preventDefault();
            closer.closest('dialog')?.close();
        }
    });

    // Autoshow dialogs (e.g. server-side confirmation fallback).
    document.querySelectorAll('dialog[data-autoshow="1"]').forEach((d) => openDialog(d));

    // Click outside the dialog panel closes it.
    document.querySelectorAll('dialog.app-dialog').forEach((d) => {
        d.addEventListener('click', (ev) => {
            if (ev.target === d) d.close();
        });
    });

    window.uiDialog = openDialog;

    // ---- Installable app shell (patient phones) --------------------------
    if ('serviceWorker' in navigator && window.isSecureContext) {
        const base = document.body.getAttribute('data-base') || '/';
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(base + 'sw.js', {scope: base}).catch(() => {
                /* caching is an optimisation; never break the page over it */
            });
        });
    }

    // ---- Tabs -----------------------------------------------------------
    document.querySelectorAll('[data-tabs]').forEach((group) => {
        const buttons = Array.from(group.querySelectorAll('[data-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-tab-panel="' + group.getAttribute('data-tabs') + '"]'));

        function activate(name) {
            buttons.forEach((b) => {
                const active = b.getAttribute('data-tab') === name;
                b.setAttribute('aria-selected', String(active));
                b.tabIndex = active ? 0 : -1;
                b.classList.toggle('bg-white', active);
                b.classList.toggle('text-foreground', active);
                b.classList.toggle('shadow-sm', active);
                b.classList.toggle('text-muted-foreground', !active);
            });
            panels.forEach((p) => {
                p.hidden = p.getAttribute('data-tab-value') !== name;
            });
            group.dispatchEvent(new CustomEvent('tabchange', { detail: { name } }));
        }

        buttons.forEach((b, i) => {
            b.addEventListener('click', () => activate(b.getAttribute('data-tab')));
            b.addEventListener('keydown', (ev) => {
                if (ev.key !== 'ArrowRight' && ev.key !== 'ArrowLeft') return;
                ev.preventDefault();
                const next = buttons[(i + (ev.key === 'ArrowRight' ? 1 : buttons.length - 1)) % buttons.length];
                next.focus();
                activate(next.getAttribute('data-tab'));
            });
        });
        activate(group.getAttribute('data-active') || buttons[0]?.getAttribute('data-tab'));
    });

    // ---- Toasts (Sonner-style) -----------------------------------------
    function toast(message, variant) {
        const region = document.getElementById('toast-region');
        if (!region) return;
        const tones = {
            success: 'border-success/30 bg-success-subtle text-success',
            warning: 'border-warning/30 bg-warning-subtle text-warning',
            danger: 'border-destructive/30 bg-destructive-subtle text-destructive',
            destructive: 'border-destructive/30 bg-destructive-subtle text-destructive',
            info: 'border-primary/30 bg-primary-subtle text-primary',
        };
        const icons = { success: 'check-circle', warning: 'alert-triangle', danger: 'alert-octagon', destructive: 'alert-octagon', info: 'info' };
        const tone = tones[variant] || tones.info;
        const el = document.createElement('div');
        el.className = 'pointer-events-auto flex w-full max-w-sm items-start gap-2.5 rounded-lg border px-4 py-3 text-sm shadow-lifted animate-toast-in ' + tone;
        el.setAttribute('role', 'status');
        el.innerHTML = '<span class="mt-0.5 shrink-0" data-icon="' + (icons[variant] || 'info') + '"></span>'
            + '<p class="flex-1 leading-relaxed"></p>'
            + '<button type="button" class="shrink-0 rounded text-current/70 hover:text-current" aria-label="Dismiss notification">&times;</button>';
        el.querySelector('p').textContent = message;
        const iconEl = el.querySelector('[data-icon]');
        const sprite = document.querySelector('[data-icon="' + iconEl.getAttribute('data-icon') + '"]');
        if (sprite) iconEl.replaceWith(sprite.cloneNode(true));
        el.querySelector('button').addEventListener('click', () => el.remove());
        region.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 200); }, 8000);
    }
    window.toast = toast;

    document.querySelectorAll('[data-toast]').forEach((t) => {
        setTimeout(() => t.remove(), 9000);
        t.querySelector('[data-toast-close]')?.addEventListener('click', () => t.remove());
    });

    // ---- Inline validation for the glucose entry form (mg/dL and mmol/L) --
    const readingForm = document.getElementById('readingForm');
    if (readingForm) {
        const valueInput = document.getElementById('glucose_value');
        const confirmed = document.getElementById('confirmed_value');
        const errorBox = document.getElementById('glucose_value-error');
        const unitLabel = document.getElementById('glucose-unit-label');
        const rangeHint = document.getElementById('glucose-range-hint');
        const confirmDialog = document.getElementById('confirm-reading-dialog');
        const confirmText = document.getElementById('confirm-reading-text');
        const confirmYes = document.getElementById('confirm-reading-yes');

        const trim = (n) => String(Math.round(n * 10) / 10).replace(/\.0$/, '');

        function limits() {
            const d = valueInput.dataset;
            const mmol = d.unit === 'mmol/L';
            return mmol
                ? { unit: 'mmol/L', min: Number(d.minMmol), max: Number(d.maxMmol), low: Number(d.lowMmol), high: Number(d.highMmol) }
                : { unit: 'mg/dL', min: Number(d.minMgdl), max: Number(d.maxMgdl), low: Number(d.lowMgdl), high: Number(d.highMgdl) };
        }

        function describe(v) {
            const l = limits();
            if (v < l.low) return 'Low';
            if (v > l.high) return 'High';
            return null;
        }

        function showError(text) {
            if (!errorBox) return;
            errorBox.querySelector('span').textContent = text;
            errorBox.hidden = !text;
            valueInput?.setAttribute('aria-invalid', text ? 'true' : 'false');
        }

        function validate() {
            const raw = valueInput.value.trim();
            if (raw === '') return true;
            const l = limits();
            const v = Number(raw);
            if (Number.isNaN(v)) {
                showError('Enter your reading as a number (' + l.unit + ').');
                return false;
            }
            if (v < l.min || v > l.max) {
                showError('Impossible reading: enter a value between ' + trim(l.min) + ' and ' + trim(l.max) + ' ' + l.unit + '. Check your meter.');
                return false;
            }
            showError('');
            return true;
        }

        function applyUnit(unit) {
            valueInput.dataset.unit = unit;
            if (unitLabel) unitLabel.textContent = unit;
            const l = limits();
            valueInput.min = l.min;
            valueInput.max = l.max;
            valueInput.placeholder = unit === 'mmol/L' ? 'e.g. 6.2' : 'e.g. 112';
            if (rangeHint) rangeHint.textContent = 'Valid range ' + trim(l.min) + '–' + trim(l.max) + ' ' + unit + '.';
            if (confirmed) confirmed.value = '0';
            validate();
        }

        document.querySelectorAll('[data-unit-option]').forEach((radio) => {
            radio.addEventListener('change', (ev) => {
                if (ev.target.checked) applyUnit(ev.target.value);
            });
        });

        valueInput?.addEventListener('input', () => {
            if (confirmed) confirmed.value = '0';
            if (valueInput.value.trim() !== '') validate();
            else showError('');
        });

        readingForm.addEventListener('submit', (ev) => {
            const raw = valueInput?.value.trim();
            if (!raw) {
                ev.preventDefault();
                showError('Enter your glucose reading before saving.');
                valueInput?.focus();
                return;
            }
            if (!validate()) {
                ev.preventDefault();
                valueInput?.focus();
                return;
            }
            const v = Number(raw);
            const l = limits();
            if (confirmed?.value !== '1' && describe(v) && confirmDialog) {
                ev.preventDefault();
                confirmText.textContent = 'You entered ' + trim(v) + ' ' + l.unit + ' (' + describe(v)
                    + '). Please confirm this glucometer reading before saving.';
                openDialog(confirmDialog);
            }
        });

        confirmYes?.addEventListener('click', () => {
            if (confirmed) confirmed.value = '1';
            confirmDialog.close();
            readingForm.requestSubmit();
        });
    }

    // ---- Threshold ordering hint ---------------------------------------
    const thresholdForm = document.getElementById('thresholdForm');
    if (thresholdForm) {
        const fields = ['severe_hypo_threshold', 'hypo_threshold', 'target_fasting_min', 'target_fasting_max', 'target_postprandial_max', 'critical_high_threshold'];
        const hint = document.getElementById('threshold-order-error');
        function check() {
            const v = fields.map((f) => Number(document.getElementById(f)?.value));
            const valid = v[0] >= 20 && v[0] < v[1] && v[1] <= v[2] && v[2] < v[3] && v[3] <= v[4] && v[4] < v[5] && v[5] <= 600;
            if (hint) hint.hidden = valid;
            return valid;
        }
        fields.forEach((f) => document.getElementById(f)?.addEventListener('input', check));
        thresholdForm.addEventListener('submit', (ev) => {
            if (!check()) {
                ev.preventDefault();
                hint?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }
        });
    }

    // ---- Triage filters (severity + confidence) ------------------------
    const triage = document.querySelector('[data-triage]');
    if (triage) {
        const severityButtons = Array.from(document.querySelectorAll('[data-triage-severity]'));
        const confidence = document.getElementById('triage-confidence');
        const search = document.getElementById('triage-search');
        const rows = Array.from(triage.querySelectorAll('tbody tr[data-severity]'));
        const counter = document.getElementById('triage-count');
        const empty = document.getElementById('triage-empty');
        let severity = 'all';

        function apply() {
            const term = (search?.value || '').toLowerCase().trim();
            const conf = confidence?.value || 'all';
            let shown = 0;
            rows.forEach((row) => {
                const okSeverity = severity === 'all' || row.getAttribute('data-severity') === severity;
                const okConf = conf === 'all' || row.getAttribute('data-confidence') === conf;
                const okTerm = !term || row.getAttribute('data-name').toLowerCase().includes(term);
                const visible = okSeverity && okConf && okTerm;
                row.hidden = !visible;
                if (visible) shown++;
            });
            if (counter) counter.textContent = String(shown);
            if (empty) empty.hidden = shown !== 0;
        }

        severityButtons.forEach((b) => b.addEventListener('click', () => {
            severity = b.getAttribute('data-triage-severity');
            severityButtons.forEach((x) => {
                const active = x === b;
                x.setAttribute('aria-pressed', String(active));
                x.classList.toggle('bg-primary', active);
                x.classList.toggle('text-primary-foreground', active);
                x.classList.toggle('hover:bg-primary/90', active);
                x.classList.toggle('bg-white', !active);
                x.classList.toggle('text-muted-foreground', !active);
            });
            apply();
        }));
        confidence?.addEventListener('change', apply);
        search?.addEventListener('input', apply);
        apply();
    }
})();
