/**
 * Public informational/legal pages progressive enhancement.
 *
 * - Smooth-scroll-free TOC focus management for Terms anchors.
 * - Marks JS availability on the page root for optional styling.
 * - Fees page: optional server-authoritative fee preview.
 * - No innerHTML, no inline script injection, no third-party calls.
 *
 * FEE PREVIEW — THE SERVER IS THE ONLY CALCULATOR
 * The widget never computes a fee locally. It collects a whitelisted
 * category, an optional provider and a base amount — all server-rendered
 * or plain-decimal inputs — POSTs them to /api/v1/fees/preview and renders
 * the server's answer through textContent only. A tampered client can at
 * worst ask the server a different question; it can never inject an
 * amount, because the response is built entirely from the JSON the server
 * returned.
 */

const ready = (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

const renderPreviewStatus = (statusEl, state, text) => {
    if (!statusEl) {
        return;
    }
    statusEl.setAttribute('data-pp-preview-state', state);
    // textContent only — no markup from any source is ever inserted.
    statusEl.textContent = text;
};

const feePreview = (page) => {
    const form = page.querySelector('[data-pp-fee-preview]');
    if (!form) {
        return;
    }

    const statusEl = form
        .closest('.pp-section')
        .querySelector('[data-pp-fee-preview-status]');

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const category = form.querySelector('[name="category"]');
        const provider = form.querySelector('[name="provider"]');
        const amount = form.querySelector('[name="base_amount"]');

        const payload = {
            category: category ? category.value : '',
            base_amount: amount ? amount.value.trim() : '',
        };
        if (provider && provider.value !== '') {
            payload.provider = provider.value;
        }

        renderPreviewStatus(statusEl, 'LOADING', form.dataset.ppPreviewLoading || '…');

        fetch(form.getAttribute('action') || '/api/v1/fees/preview', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        })
            .then((response) =>
                response
                    .json()
                    .catch(() => null)
                    .then((body) => ({ ok: response.ok, status: response.status, body }))
            )
            .then(({ ok, status, body }) => {
                if (!ok || !body || !body.success || !body.data) {
                    const message =
                        body && body.message
                            ? body.message
                            : form.dataset.ppPreviewError || 'Preview unavailable.';
                    renderPreviewStatus(statusEl, 'ERROR', message);
                    return;
                }

                const data = body.data;
                const state = data.state || 'CONFIGURED';
                const notConfiguredLabel = form.dataset.ppPreviewNotConfigured || 'NOT_CONFIGURED';
                const feeLabel = form.dataset.ppPreviewFeeLabel || 'Fee';
                const stateLabel = form.dataset.ppPreviewStateLabel || 'State';

                if (state === 'NOT_CONFIGURED') {
                    renderPreviewStatus(
                        statusEl,
                        'OK',
                        `${notConfiguredLabel} (${stateLabel}: ${state}, ${data.currency || ''})`.trim()
                    );
                    return;
                }

                renderPreviewStatus(
                    statusEl,
                    'OK',
                    `${feeLabel}: ${data.fee_amount || '0.00'} ${data.currency || ''} · ${stateLabel}: ${state} · ${data.fee_display || ''}`.trim()
                );
            })
            .catch(() => {
                renderPreviewStatus(
                    statusEl,
                    'ERROR',
                    form.dataset.ppPreviewNetworkError || 'The preview could not be requested. Please try again.'
                );
            });
    });
};

/**
 * Public grades page: "Discount Of Game" entitlement panels.
 *
 * PRESENTATION ONLY. The panels and their contents are rendered by the
 * server; this script only toggles their open/closed state for mouse
 * and keyboard users and mirrors the state onto the trigger button's
 * aria-expanded. Nothing is calculated, fetched or trusted from the
 * client — a user without JavaScript sees the same server-rendered
 * information (the panels are unhidden by CSS when JS is off).
 */
const gradeGamesPanels = (page) => {
    const panels = page.querySelectorAll('[data-grade-games]');
    if (panels.length === 0) {
        return;
    }

    // With JS active, the panels stay hidden until a toggle opens them.
    const setExpanded = (toggle, panel, expanded) => {
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (expanded) {
            panel.removeAttribute('hidden');
        } else {
            panel.setAttribute('hidden', '');
        }
    };

    panels.forEach((panel) => {
        const id = panel.getAttribute('id');
        if (!id) {
            return;
        }
        const toggle = page.querySelector(`[aria-controls="${id}"][data-grade-games-toggle]`);
        const close = panel.querySelector('[data-grade-games-close]');

        if (toggle) {
            toggle.addEventListener('click', () => {
                const next = toggle.getAttribute('aria-expanded') !== 'true';
                setExpanded(toggle, panel, next);
            });
        }

        if (close) {
            close.addEventListener('click', () => {
                setExpanded(toggle, panel, false);
                if (toggle) {
                    toggle.focus({ preventScroll: false });
                }
            });
        }

        panel.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setExpanded(toggle, panel, false);
                if (toggle) {
                    toggle.focus({ preventScroll: false });
                }
            }
        });
    });
};

ready(() => {
    const page = document.querySelector('[data-pp-page]');
    if (!page) {
        return;
    }

    page.setAttribute('data-pp-js', 'on');

    // Terms TOC: move focus to the target section for keyboard users.
    const toc = page.querySelector('[data-pp-terms-toc]');
    if (toc) {
        toc.addEventListener('click', (event) => {
            const anchor = event.target.closest('a[href^="#terms-"]');
            if (!anchor) {
                return;
            }
            const id = anchor.getAttribute('href').slice(1);
            const target = document.getElementById(id);
            if (!target) {
                return;
            }
            event.preventDefault();
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: false });
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', '#' + id);
            }
        });
    }

    // Skip link: focus main content region.
    const skip = page.querySelector('.pp-skip-link');
    const main = page.querySelector('#pp-main');
    if (skip && main) {
        skip.addEventListener('click', (event) => {
            event.preventDefault();
            main.focus({ preventScroll: false });
        });
    }

    // Public grades page: Discount Of Game entitlement panels.
    gradeGamesPanels(page);

    // Fees page: optional server-authoritative preview.
    feePreview(page);
});
