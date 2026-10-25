/**
 * Progressive enhancement for verified legal PDF downloads (Batch 08).
 *
 * The buttons are ordinary links in the Blade views, so a browser without
 * JavaScript (or with a broken bundle) still reaches the real endpoint. With
 * JavaScript the click is intercepted, the PDF is fetched with
 * Accept: application/pdf, and the honest 501 / 404 NOT_CONFIGURED answers are
 * shown as a localized notice instead of leaving the visitor looking at a
 * broken download or a raw JSON error.
 *
 * The document version is read from the X-Document-Version response header the
 * export service emits. Nothing about the document is invented client-side.
 */

import {
    announce,
    csrfToken,
    readL10n,
    setText,
    text,
} from './frontend-api.js';

const FALLBACK = Object.freeze({
    notAvailable: 'This verified PDF is not available yet. Use the print / save control on this page instead.',
    notConfigured: 'The verified PDF export is not configured for this environment.',
    unsupportedLocale: 'That document language is not published for download.',
    failed: 'The document could not be downloaded. Try again later.',
    starting: 'Preparing the verified PDF…',
    ready: 'Verified PDF ready.',
    version: 'Document version',
    cached: 'You already have this document version.',
    download: 'Download verified PDF',
});

/** Parse a safe filename from Content-Disposition: attachment; filename="x.pdf". */
export function filenameFromHeader(header, fallbackName) {
    const value = String(header || '');
    const match = value.match(/filename\*?=(?:UTF-8'')?"?([A-Za-z0-9._-]+\.pdf)"?/i);
    return match ? match[1] : fallbackName;
}

function init() {
    const buttons = Array.from(document.querySelectorAll('[data-legal-download]'));
    if (buttons.length === 0) {
        return;
    }

    const l10n = readL10n('legal-downloads-l10n', FALLBACK);
    const status = document.querySelector('[data-legal-download-status]');
    const versionNode = document.querySelector('[data-legal-download-version]');
    const token = csrfToken();

    function showVersion(headers) {
        if (!versionNode) {
            return;
        }

        const version = headers.get('X-Document-Version');
        if (version) {
            setText(versionNode, text(l10n, 'version') + ': ' + version.trim());
            versionNode.hidden = false;
        } else {
            setText(versionNode, '');
            versionNode.hidden = true;
        }
    }

    async function download(button) {
        const href = button.getAttribute('href') || '';
        if (href === '' || button.getAttribute('aria-disabled') === 'true') {
            return;
        }

        const headers = { Accept: 'application/pdf' };
        if (token !== '') {
            headers['X-CSRF-TOKEN'] = token;
            headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        button.setAttribute('aria-busy', 'true');
        announce(status, text(l10n, 'starting'));

        let response;
        try {
            response = await fetch(href, { headers, credentials: 'same-origin' });
        } catch (_) {
            button.removeAttribute('aria-busy');
            announce(status, text(l10n, 'failed'));
            return;
        }

        button.removeAttribute('aria-busy');
        showVersion(response.headers);

        if (response.status === 304) {
            announce(status, text(l10n, 'cached'));
            return;
        }

        if (response.status === 501) {
            announce(status, text(l10n, 'notConfigured'));
            button.setAttribute('aria-disabled', 'true');
            return;
        }

        if (response.status === 404) {
            announce(status, text(l10n, 'notAvailable'));
            button.setAttribute('aria-disabled', 'true');
            return;
        }

        if (response.status === 422) {
            announce(status, text(l10n, 'unsupportedLocale'));
            return;
        }

        if (response.status === 503) {
            announce(status, text(l10n, 'notConfigured'));
            return;
        }

        const contentType = (response.headers.get('Content-Type') || '').toLowerCase();
        if (!response.ok || !contentType.includes('application/pdf')) {
            announce(status, text(l10n, 'failed'));
            return;
        }

        let blob;
        try {
            blob = await response.blob();
        } catch (_) {
            announce(status, text(l10n, 'failed'));
            return;
        }

        const filename = filenameFromHeader(
            response.headers.get('Content-Disposition'),
            button.getAttribute('data-filename') || 'legal-document.pdf',
        );
        const objectUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = filename;
        anchor.rel = 'noopener';
        anchor.style.display = 'none';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        setTimeout(() => URL.revokeObjectURL(objectUrl), 30000);

        announce(status, text(l10n, 'ready'));
    }

    buttons.forEach((button) => {
        button.addEventListener('click', (event) => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
                return;
            }
            event.preventDefault();
            download(button);
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { init, filenameFromHeader };
