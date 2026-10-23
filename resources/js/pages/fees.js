/** Public fee preview: server-owned amounts/rules; no client-side arithmetic. */
import './public-next-pages.js';
import { publicText, publicNotice, readPublicResponse } from './public-response.js';

const init = () => {
    document.querySelectorAll('[data-next-public-page="fees"]').forEach((page) => {
        const form = page.querySelector('[data-fee-preview-form]');
        const result = page.querySelector('[data-fee-preview-result]');
        if (!form || !result || form.dataset.previewBound) return;
        form.dataset.previewBound = '1';
        let pending = false;
        let activeRequest = null;
        const releaseForm = (request) => {
            // An abandoned request must never release a newer retry's controls.
            if (activeRequest !== request) return;
            activeRequest = null;
            pending = false;
            form.removeAttribute('aria-busy');
            if (request.submit) request.submit.disabled = request.submitWasDisabled;
        };
        if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
            window.addEventListener('pagehide', () => {
                const request = activeRequest;
                if (!request) return;
                request.abandoned = true;
                request.controller.abort();
                clearTimeout(request.timer);
                result.hidden = true;
                result.textContent = '';
                releaseForm(request);
            });
        }
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            const submit = form.querySelector('button[type="submit"]');
            const submitWasDisabled = submit?.disabled;
            if (submit) submit.disabled = true;
            form.setAttribute('aria-busy', 'true');
            publicNotice(result, publicText(result, 'pending', 'Request in progress…'));
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 15000);
            const request = { controller, timer, submit, submitWasDisabled, abandoned: false };
            activeRequest = request;
            try {
                const token = form.querySelector('input[name="_token"]')?.value;
                const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
                if (token) headers['X-CSRF-TOKEN'] = token;
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: JSON.stringify({
                        category: form.elements.category.value,
                        provider: form.elements.provider.value || null,
                        base_amount: form.elements.base_amount.value,
                    }),
                    headers,
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const data = await readPublicResponse(response, result, controller.signal);
                if (request.abandoned || !data) return;
                if (typeof data.fee_display !== 'string' || typeof data.state !== 'string'
                    || typeof data.base_amount !== 'string' || typeof data.currency !== 'string'
                    || typeof data.rule_version !== 'string') {
                    publicNotice(result, publicText(result, 'malformed', 'The service returned an unreadable response.'), true);
                    return;
                }
                publicNotice(result, `${data.fee_display} · ${data.state}`);
                const detail = document.createElement('span');
                detail.textContent = `${publicText(result, 'base', 'Base amount')}: ${data.base_amount} ${data.currency} · ${publicText(result, 'ruleVersion', 'Rule version')}: ${data.rule_version}`;
                result.appendChild(detail);
            } catch {
                if (!request.abandoned) {
                    publicNotice(result, publicText(result, 'networkError', 'The service could not be reached. Please try again.'), true);
                }
            } finally {
                clearTimeout(timer);
                releaseForm(request);
            }
        });
    });
};
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
else init();
