/** Shared presentation helpers for the existing public fee/checker forms.
 * No endpoints, financial rules, stored responses, or alternate API client.
 */
export const publicText = (box, key, fallback) => box.dataset[key] || fallback;

export const publicNotice = (box, text, error = false) => {
    box.hidden = false;
    box.textContent = '';
    box.setAttribute('role', error ? 'alert' : 'status');
    box.setAttribute('aria-live', error ? 'assertive' : 'polite');
    const line = document.createElement('strong');
    line.textContent = text;
    box.appendChild(line);
};

export const readPublicResponse = async (response, box, signal) => {
    // Cancellation is a transport/lifecycle result, not malformed server JSON.
    // This never implies rollback/cancellation of any server-side operation.
    const ensureActive = () => {
        if (signal?.aborted) {
            const error = new Error('Request aborted.');
            error.name = 'AbortError';
            throw error;
        }
    };
    ensureActive();
    const keys = { 401: 'unauthorized', 403: 'forbidden', 419: 'expired', 422: 'invalid', 429: 'rateLimited' };
    const errorKey = keys[response.status] || (response.status >= 500 ? 'serverError' : 'requestError');
    let payload;
    try {
        payload = await response.json();
    } catch {
        ensureActive();
        publicNotice(box, publicText(box, response.ok ? 'malformed' : errorKey, 'The request could not be completed.'), true);
        return null;
    }
    ensureActive();
    if (!response.ok || payload?.success !== true) {
        let text = publicText(box, errorKey, 'The request could not be completed.');
        // Laravel's field-validation errors only; never echo server exceptions or response bodies.
        if (response.status === 422 && payload?.errors && typeof payload.errors === 'object') {
            const messages = Object.values(payload.errors).flat().filter((v) => typeof v === 'string');
            if (messages.length) text += ' ' + messages.slice(0, 4).map((v) => v.slice(0, 300)).join(' ');
        }
        publicNotice(box, text, true);
        return null;
    }
    if (!payload.data || typeof payload.data !== 'object' || Array.isArray(payload.data)) {
        publicNotice(box, publicText(box, 'malformed', 'The service returned an unreadable response.'), true);
        return null;
    }
    return payload.data;
};
