/**
 * Shared, dependency-free helpers for the Batch 08 frontend wiring.
 *
 * Every module in this batch talks to a Laravel JSON endpoint through the
 * session's CSRF token, must distinguish an honest NOT_CONFIGURED answer from
 * a real failure, and must never place a provider payload, a voucher code or
 * a full account number into the DOM through innerHTML. These helpers own
 * exactly those three rules so no individual lane can forget one of them.
 *
 * No poll, no timer and no socket lives here: callers own their own lifetime
 * and cancel their own timers. This module is deliberately side-effect free.
 */

/** The page's CSRF token, or an empty string when the page did not emit one. */
export function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute('content') || '').trim() : '';
}

/**
 * Read the server-rendered translation dictionary for a page.
 *
 * Blade writes a <script type="application/json" id="..."> element whose keys
 * are dot-free identifiers, so no raw translation key can ever leak into the
 * rendered text. The fallback keeps a module usable on a page that did not
 * render the dictionary (for example a legacy preview page).
 *
 * @param {string} id
 * @param {Record<string, string>} fallback
 * @returns {Record<string, string>}
 */
export function readL10n(id, fallback) {
    const node = document.getElementById(id);

    if (!node) {
        return Object.assign({}, fallback);
    }

    try {
        const parsed = JSON.parse(node.textContent || '{}');
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            return Object.assign({}, fallback, parsed);
        }
    } catch (_) {
        // A malformed dictionary must not stop the page from working at all.
    }

    return Object.assign({}, fallback);
}

/**
 * Translate a dictionary key with :placeholder substitution. Values are
 * always inserted as text by the caller, never as HTML.
 *
 * @param {Record<string, string>} l10n
 * @param {string} key
 * @param {Record<string, string|number>} parameters
 * @returns {string}
 */
export function text(l10n, key, parameters) {
    const raw = Object.prototype.hasOwnProperty.call(l10n, key) ? l10n[key] : (l10n[key] || '');
    let value = typeof raw === 'string' ? raw : String(raw || '');

    if (!parameters) {
        return value;
    }

    Object.keys(parameters).forEach((name) => {
        value = value.split(':' + name).join(String(parameters[name]));
    });

    return value;
}

/** Escape a value for safe use inside an HTML attribute or text node. */
export function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
}

/** Write text (never markup) into an element and announce it politely. */
export function setText(element, value) {
    if (!element) {
        return;
    }

    element.textContent = value === null || value === undefined ? '' : String(value);
}

/**
 * Announce a status change. The element must already carry role="status" and
 * aria-live="polite" in the Blade view; this only writes the sentence.
 */
export function announce(element, value) {
    if (!element) {
        return;
    }

    setText(element, value);
    element.hidden = false;
}

/**
 * Group a decimal money string for display without binary floating point.
 * The currency is appended verbatim; no exchange rate is ever applied here.
 *
 * @param {string|number} amount
 * @param {string} currency
 * @returns {string}
 */
export function formatAmount(amount, currency) {
    const raw = String(amount === null || amount === undefined ? '' : amount).trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) {
        return '';
    }

    const parts = raw.split('.');
    const grouped = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const decimals = (parts[1] || '').padEnd(2, '0');

    return grouped + '.' + decimals + (currency ? ' ' + currency : '');
}

/**
 * Perform a JSON request. The response is always returned as a normalised
 * object, including the HTTP status, so callers can branch on 501/503
 * NOT_CONFIGURED without throwing away the honest server answer.
 *
 * FormData is passed through untouched (slip upload needs multipart); plain
 * objects are JSON encoded. Nothing is logged to the console: provider
 * payloads and voucher codes must never reach the browser console.
 *
 * @param {string} url
 * @param {{method?: string, body?: any, headers?: Record<string,string>, signal?: AbortSignal}} options
 * @returns {Promise<{ok: boolean, status: number, data: any, headers: Headers}>}
 */
export async function apiRequest(url, options) {
    const config = options || {};
    const method = config.method || 'GET';
    const headers = Object.assign({}, config.headers || {});
    const token = csrfToken();

    if (token !== '') {
        headers['X-CSRF-TOKEN'] = token;
        headers['X-Requested-With'] = 'XMLHttpRequest';
    }

    let body;
    if (config.body instanceof FormData) {
        body = config.body;
    } else if (config.body !== undefined && config.body !== null) {
        headers['Content-Type'] = headers['Content-Type'] || 'application/json';
        body = JSON.stringify(config.body);
    }

    if (!Object.prototype.hasOwnProperty.call(headers, 'Accept')) {
        headers.Accept = 'application/json';
    }

    let response;
    try {
        response = await fetch(url, {
            method,
            headers,
            body,
            credentials: 'same-origin',
            signal: config.signal,
        });
    } catch (_) {
        return { ok: false, status: 0, data: null, headers: new Headers() };
    }

    let data = null;
    const contentType = response.headers.get('Content-Type') || '';
    if (contentType.toLowerCase().includes('json')) {
        try {
            data = await response.json();
        } catch (_) {
            data = null;
        }
    } else {
        data = null;
    }

    return { ok: response.ok, status: response.status, data, headers: response.headers };
}

/** True when the server answered its explicit "this lane is not configured" state. */
export function isNotConfigured(result) {
    if (!result) {
        return false;
    }

    const state = result.data && (result.data.state || result.data.status);

    return (result.status === 501 || result.status === 503 || result.status === 404)
        && (state === 'NOT_CONFIGURED' || state === 'NOT_AVAILABLE' || result.data === null);
}

/** The error code of either API envelope shape used by this application. */
export function errorCode(result) {
    if (!result || !result.data) {
        return '';
    }

    if (result.data.error && typeof result.data.error === 'object') {
        return String(result.data.error.code || '');
    }

    return String(result.data.code || '');
}

/** A safe, human-readable server message, or an empty string. */
export function serverMessage(result) {
    if (!result || !result.data) {
        return '';
    }

    if (result.data.error && typeof result.data.error === 'object') {
        return String(result.data.error.message || '');
    }

    return String(result.data.message || '');
}

/**
 * An idempotency key per checkout attempt. crypto.randomUUID is available in
 * every browser that can run the Vite bundle; the fallback keeps older
 * WebViews honest (still unique, still no server-side state).
 */
export function idempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
        return window.crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
        window.crypto.getRandomValues(bytes);
    } else {
        for (let index = 0; index < bytes.length; index += 1) {
            bytes[index] = Math.floor(Math.random() * 256);
        }
    }

    return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** A URL template's :token or __TOKEN__ marker replaced by an encoded value. */
export function fillUrl(template, token, value) {
    return String(template)
        .replace(token, encodeURIComponent(String(value)))
        .replace(':' + token.replace(/^:/, ''), encodeURIComponent(String(value)));
}

/**
 * Render a status word through the localized status dictionary. Unknown
 * statuses are shown verbatim (they are enum values, not free text) with a
 * neutral class, never hidden and never mapped to "success".
 *
 * @param {Record<string,string>} l10n
 * @param {string} status
 * @returns {string}
 */
export function statusLabel(l10n, status) {
    const key = 'status_' + String(status || '').toLowerCase().replace(/[^a-z0-9_]/g, '_');
    return Object.prototype.hasOwnProperty.call(l10n, key) ? l10n[key] : String(status || '—');
}

/** Cancel every timer id in an array and empty it in place. */
export function clearTimers(timers) {
    while (timers.length > 0) {
        const timer = timers.pop();
        if (timer !== null && timer !== undefined) {
            clearTimeout(timer);
            clearInterval(timer);
        }
    }
}
