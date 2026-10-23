/** Existing public checker contract only; identifiers remain strings, no prize calculation. */
import './public-next-pages.js';
import { publicText, publicNotice, readPublicResponse } from './public-response.js';

const init = () => {
    document.querySelectorAll('[data-prize-verifier]').forEach((page) => {
        const form = page.querySelector('[data-prize-form]');
        const result = page.querySelector('[data-prize-result]');
        if (!form || !result || form.dataset.verifierBound) return;
        form.dataset.verifierBound = '1';
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
                const response = await fetch(form.action, {
                    method: 'POST', body: new FormData(form),
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin', signal: controller.signal,
                });
                const data = await readPublicResponse(response, result, controller.signal);
                if (request.abandoned || !data) return;
                if (typeof data.status !== 'string') {
                    publicNotice(result, publicText(result, 'malformed', 'The service returned an unreadable response.'), true);
                    return;
                }
                publicNotice(result, `${publicText(result, 'verificationState', 'Verification state')}: ${data.status}`);
                const detail = document.createElement('span');
                detail.textContent = typeof data.reason === 'string'
                    ? data.reason : publicText(result, 'verificationResponse', 'The server returned a public verification response.');
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
