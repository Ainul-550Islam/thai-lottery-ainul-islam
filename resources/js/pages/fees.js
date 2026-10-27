/**
 * TYPE: page entry
 * PURPOSE: Fees table search, server-authoritative preview, accessible navigation and browser print enhancement.
 *
 * The preview request sends only a public category, optional provider and
 * decimal base amount. Rates and fee amounts are never calculated here.
 */
import './public-next-pages.js';

const renderPreview = (box, payload) => {
    box.hidden = false;
    box.textContent = '';

    const data = payload?.data || payload;
    const heading = document.createElement('strong');
    heading.textContent = payload?.success === false
        ? (payload.message || 'The fee preview could not be calculated.')
        : `${data.fee_display || 'NOT_CONFIGURED'} · ${data.state || 'NOT_CONFIGURED'}`;
    box.appendChild(heading);

    if (payload?.success !== false && data) {
        const detail = document.createElement('span');
        detail.textContent = `Base: ${data.base_amount || 'NOT_CONFIGURED'} ${data.currency || ''} · Rule version: ${data.rule_version || 'NOT_CONFIGURED'}`;
        box.appendChild(detail);
    }
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-next-public-page="fees"]').forEach((page) => {
        const form = page.querySelector('[data-fee-preview-form]');
        const result = page.querySelector('[data-fee-preview-result]');
        if (!form || !result) return;

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.setAttribute('aria-busy', 'true');
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: JSON.stringify({
                        category: form.elements.category.value,
                        provider: form.elements.provider.value || null,
                        base_amount: form.elements.base_amount.value,
                    }),
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                renderPreview(result, await response.json());
            } catch (error) {
                renderPreview(result, { success: false, message: 'The fee preview service could not be reached.' });
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute('aria-busy');
                }
            }
        });
    });
});
