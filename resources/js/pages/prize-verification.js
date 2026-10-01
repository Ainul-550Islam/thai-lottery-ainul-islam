/**
 * TYPE: page entry
 * PURPOSE: Progressive enhancement for the public prize checker.
 *
 * The server remains authoritative. This script only sends the already
 * validated form to the same web endpoint, displays the allow-listed JSON
 * response, and never derives a result in the browser.
 */
import './public-next-pages.js';

const renderResult = (box, payload) => {
    box.hidden = false;
    box.textContent = '';

    const title = document.createElement('strong');
    const data = payload?.data || payload;
    const state = data?.status || data?.state || (payload?.success ? 'AVAILABLE' : 'UNAVAILABLE');
    title.textContent = `Verification state: ${state}`;
    box.appendChild(title);

    const detail = document.createElement('span');
    detail.textContent = payload?.message || data?.reason || 'The server returned a public verification response.';
    box.appendChild(detail);
};

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-prize-verifier]').forEach((page) => {
        const form = page.querySelector('[data-prize-form]');
        const result = page.querySelector('[data-prize-result]');
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
                    body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                renderResult(result, payload);
            } catch (error) {
                renderResult(result, { success: false, state: 'UNAVAILABLE', message: 'Verification is temporarily unavailable.' });
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute('aria-busy');
                }
            }
        });
    });
});
