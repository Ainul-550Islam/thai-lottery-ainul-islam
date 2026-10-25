/**
 * Deterministic fixture host for the Batch 08 browser specs.
 *
 * Every request is answered by page.route(): no Laravel server, no provider,
 * no WebSocket and no external asset is contacted. The page loads the REAL
 * Vite-built app.js entry (and therefore the real code-split page modules)
 * from public/build, with the API answers supplied by the spec. Running the
 * shipped bundle rather than a hand-written copy is what makes these tests
 * detect a wiring regression instead of merely repeating the module's own
 * assertions.
 */

import fs from 'node:fs';
import path from 'node:path';

export const ORIGIN = 'http://batch08.test';

const CONTENT_TYPES = Object.freeze({
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.svg': 'image/svg+xml',
    '.png': 'image/png',
    '.woff2': 'font/woff2',
});

export function buildRoot() {
    return path.resolve(process.cwd(), 'public/build');
}

export function manifest() {
    const file = path.join(buildRoot(), 'manifest.json');
    if (!fs.existsSync(file)) {
        throw new Error('public/build/manifest.json is missing — run "npm ci && npm run build" before the browser suite.');
    }
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function jsonScript(id, value) {
    return `<script type="application/json" id="${id}">${JSON.stringify(value || {}).replace(/</g, '\\u003c')}</script>`;
}

/**
 * Mount a page whose HTML contains the Batch 08 roots, the localized JSON
 * dictionaries and the built app.js entry. `handlers` maps
 * "METHOD /path" to either a static response descriptor or a function.
 *
 * @returns {Promise<{requests: Array<{method: string, path: string, body: any}>, manifest: any}>}
 */
export async function mountBatch08Page(page, {
    route: pagePath = '/',
    body = '',
    lang = 'en',
    l10n = {},
    meta = '',
    handlers = {},
} = {}) {
    const built = manifest();
    const entry = built['resources/js/app.js']?.file;
    if (!entry) {
        throw new Error('The built manifest has no resources/js/app.js entry.');
    }

    const requests = [];
    const dictionaries = Object.entries(l10n).map(([id, value]) => jsonScript(id, value)).join('\n');
    const document = `<!doctype html>
<html lang="${lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="batch08-test-token">
<meta name="realtime-enabled" content="0">
<title>Batch 08 fixture</title>
${meta}
${dictionaries}
</head>
<body>
<main>${body}</main>
<script type="module" src="/build/${entry}"></script>
</body>
</html>`;

    await page.route(/^http:\/\/batch08\.test\//, async (route) => {
        const request = route.request();
        const url = new URL(request.url());
        const pathname = url.pathname;

        if (pathname.startsWith('/api/')) {
            let payload = null;
            if (request.method() === 'POST' || request.method() === 'PUT' || request.method() === 'PATCH') {
                const raw = request.postData() || '';
                const contentType = request.headers()['content-type'] || '';
                if (contentType.includes('application/json')) {
                    try { payload = raw === '' ? null : JSON.parse(raw); } catch (_) { payload = raw; }
                } else {
                    payload = raw;
                }
            }
            requests.push({ method: request.method(), path: pathname, body: payload, headers: request.headers() });

            const key = request.method() + ' ' + pathname;
            const handler = handlers[key] || handlers[pathname] || handlers['*'];
            const answer = typeof handler === 'function' ? handler({ request, payload, pathname }) : handler;
            const status = answer?.status ?? (answer === undefined ? 404 : 200);
            const bodyValue = answer?.body ?? (answer === undefined ? { success: false, state: 'NOT_CONFIGURED' } : answer);
            await route.fulfill({
                status,
                contentType: answer?.contentType || 'application/json; charset=utf-8',
                headers: answer?.headers || {},
                body: typeof bodyValue === 'string' ? bodyValue : JSON.stringify(bodyValue),
            });
            return;
        }

        const relative = pathname.replace(/^\/build\//, '').replace(/^\//, '');
        const candidate = path.join(buildRoot(), relative);
        if (relative !== '' && fs.existsSync(candidate) && fs.statSync(candidate).isFile()) {
            await route.fulfill({
                status: 200,
                contentType: CONTENT_TYPES[path.extname(candidate)] || 'application/octet-stream',
                body: fs.readFileSync(candidate),
            });
            return;
        }

        await route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: document });
    });

    const response = await page.goto(ORIGIN + pagePath, { waitUntil: 'load' });
    if (!response || !response.ok()) {
        throw new Error('The fixture page did not load.');
    }
    await page.waitForFunction(() => Boolean(window.notifications), null, { timeout: 10_000 });

    return { requests, manifest: built };
}

/** The deposit tab markup shared by the lane specs (mirrors the Blade view). */
export function depositMarkup({ includePromptPay = true, includeTruemoney = true, includeSlip = true } = {}) {
    const promptPay = includePromptPay ? `
    <div class="tl-lane-panel" role="tabpanel" data-lane-panel="promptpay" data-lane-state="AVAILABLE">
        <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
        <form class="tl-lane-form" data-lane-form>
            <input id="promptpay-amount" data-promptpay-amount>
            <button type="submit" data-promptpay-submit>Create PromptPay QR</button>
        </form>
        <div class="tl-result" data-promptpay-result hidden>
            <canvas data-promptpay-qr width="232" height="232" hidden></canvas>
            <span data-promptpay-amount-display></span>
            <span data-promptpay-reference></span>
            <time data-promptpay-expires-at></time>
            <span data-promptpay-countdown></span>
            <span data-promptpay-status-label role="status"></span>
            <button type="button" data-promptpay-copy>Copy reference</button>
            <p data-promptpay-status role="status" aria-live="polite"></p>
        </div>
    </div>` : '';

    const truemoney = includeTruemoney ? `
    <div class="tl-lane-panel" role="tabpanel" data-lane-panel="truemoney" data-lane-state="AVAILABLE" hidden>
        <div id="truemoney-redeem" data-redeem-url="/api/v1/deposit/truemoney/redeem" data-voucher-host="gift.truemoney.com" data-currency="THB">
            <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
            <form class="tl-lane-form" data-lane-form>
                <input id="truemoney-voucher-url" data-truemoney-url>
                <button type="submit" data-truemoney-submit>Redeem voucher</button>
            </form>
            <p data-truemoney-status role="status" aria-live="polite"></p>
            <div data-truemoney-result hidden>
                <span data-truemoney-amount></span>
                <span data-truemoney-reference></span>
            </div>
        </div>
    </div>` : '';

    const slip = includeSlip ? `
    <div class="tl-lane-panel" role="tabpanel" data-lane-panel="bank_transfer" data-lane-state="AVAILABLE" hidden>
        <div id="slip-upload" data-intent-url="/api/v1/deposit/bank-transfer/intent" data-verify-url="/api/v1/deposit/slip-verification" data-show-url="/api/v1/deposit/slip-verification/__VERIFICATION_ID__" data-poll-interval-ms="4000" data-currency="THB">
            <p data-slip-bank-notice role="status" aria-live="polite"></p>
            <div data-slip-bank-details hidden>
                <span data-slip-bank-name></span><span data-slip-account-name></span>
                <span data-slip-account-number></span><span data-slip-reference></span>
                <span data-slip-instructions></span>
            </div>
            <p class="tl-lane-notice" data-lane-notice role="status" aria-live="polite" hidden></p>
            <form class="tl-lane-form" data-lane-form enctype="multipart/form-data">
                <input id="slip-amount" data-slip-amount>
                <input id="slip-deposit-reference" data-slip-deposit-reference>
                <input type="radio" name="slip_mode" value="image" data-slip-mode="image" checked>
                <input type="radio" name="slip_mode" value="qr" data-slip-mode="qr">
                <span data-slip-image-field><input id="slip-image" type="file" data-slip-image></span>
                <span data-slip-qr-field hidden><textarea id="slip-qr-payload" data-slip-qr-payload></textarea></span>
                <img data-slip-preview hidden>
                <button type="submit" data-slip-submit>Submit slip</button>
            </form>
            <p data-slip-status role="status" aria-live="polite"></p>
            <div data-slip-result hidden>
                <span data-slip-status-label role="status"></span>
                <span data-slip-amount-display></span>
                <p data-slip-reason hidden></p>
            </div>
        </div>
    </div>` : '';

    return `
<section id="deposit-lanes" data-deposit-lanes
    data-methods-url="/api/v1/deposit/methods"
    data-deposit-url="/api/v1/wallet/deposit"
    data-status-url="/api/v1/deposit/status/__DEPOSIT_ID__"
    data-poll-interval-ms="4000" data-currency="THB">
    <p data-lanes-global-notice role="status" aria-live="polite"></p>
    <div role="tablist">
        <button type="button" role="tab" data-lane-tab="promptpay" aria-selected="true" aria-disabled="false">PromptPay</button>
        <button type="button" role="tab" data-lane-tab="truemoney" aria-selected="false" aria-disabled="false">TrueMoney</button>
        <button type="button" role="tab" data-lane-tab="bank_transfer" aria-selected="false" aria-disabled="false">Bank transfer</button>
    </div>
    ${promptPay}${truemoney}${slip}
    <span data-live-balance="wallet" data-currency="THB">0.00 THB</span>
</section>`;
}

/** Minimal dictionaries so a module never falls back to its English defaults. */
export const TEST_L10N = Object.freeze({
    'deposit-l10n': {
        notConfigured: 'Lane not available',
        notConfiguredHelp: 'Nothing was charged.',
        methodsLoading: 'Checking methods',
        methodsEmpty: 'No methods',
        methodsFailed: 'Methods failed',
        authenticationRequired: 'Sign in',
        networkError: 'Network error',
        serviceUnavailable: 'Service unavailable',
        amountInvalid: 'Invalid amount',
        noQrPayload: 'No QR',
        qrRenderFailed: 'QR failed',
        pollingHint: 'Polling',
        realtimeHint: 'Live',
        copied: 'Copied',
        copyFailed: 'Copy failed',
        status_pending: 'Pending payment',
        status_confirmed: 'Credited',
        status_failed: 'Failed',
        status_expired: 'Expired',
        expired: 'Expired request',
    },
    'truemoney-l10n': {
        notConfigured: 'Voucher lane not available',
        notConfiguredHelp: 'Nothing was credited.',
        authenticationRequired: 'Sign in',
        networkError: 'Network error',
        validationError: 'Validation failed',
        voucherUrlInvalid: 'Invalid voucher URL',
        redeeming: 'Checking voucher',
        submit: 'Redeem voucher',
        creditedTitle: 'Voucher credited',
        pendingReview: 'Do not submit again',
        error_truemoney_voucher_invalid: 'That voucher link is not valid.',
        error_truemoney_voucher_already_processed: 'This voucher has already been submitted.',
        error_truemoney_not_configured: 'Voucher redemption is not configured.',
        error_truemoney_processing_failed: 'Voucher failed',
    },
    'slip-l10n': {
        notConfigured: 'Slip lane not available',
        notConfiguredHelp: 'Nothing was credited.',
        intentNotConfigured: 'Bank details not configured',
        intentLoading: 'Loading bank details',
        intentFailed: 'Bank details failed',
        authenticationRequired: 'Sign in',
        networkError: 'Network error',
        validationError: 'Validation failed',
        imageInvalidType: 'Invalid image',
        imageTooLarge: 'Image too large',
        qrInvalid: 'Invalid QR payload',
        submitting: 'Submitting slip',
        submit: 'Submit slip',
        status_pending: 'Pending verification',
        status_verified: 'Verified — credit pending',
        status_credited: 'Credited',
        status_needs_review: 'Needs review',
        status_rejected: 'Rejected',
        status_duplicate: 'Duplicate slip',
        status_expired: 'Expired',
        reasonPrefix: 'Reason',
        reason_unknown: 'No reason published',
        reason_duplicate: 'This slip has already been used.',
        pollingHint: 'Polling',
        error_slip_verification_not_configured: 'Slip verification not configured',
        error_slip_verification_failed: 'Slip failed',
    },
    'legal-downloads-l10n': {
        notAvailable: 'This verified PDF is not available yet.',
        notConfigured: 'The verified PDF export is not configured for this environment.',
        unsupportedLocale: 'That document language is not published for download.',
        failed: 'The document could not be downloaded.',
        starting: 'Preparing the verified PDF',
        ready: 'Verified PDF ready.',
        version: 'Document version',
        cached: 'You already have this document version.',
        download: 'Download verified PDF',
    },
    'realtime-l10n': {
        dismiss: 'Dismiss notification',
        walletTitle: 'Wallet balance updated',
        walletMessage: 'Balance refreshed',
        depositTitle: 'Deposit status updated',
        withdrawalTitle: 'Withdrawal status updated',
        payoutTitle: 'Prize payout completed',
        betTitle: 'Bet recorded',
        resyncedTitle: 'Live updates restored',
        resyncedMessage: 'Refreshing',
        statusPrefix: 'Status',
        pending: 'Pending',
        confirmed: 'Credited',
        failed: 'Failed',
        burstSuffix: 'more updates',
    },
});
