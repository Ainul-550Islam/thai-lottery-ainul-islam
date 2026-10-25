/**
 * Deposit lanes controller (Batch 08).
 *
 * The retired compatibility guard used to redirect every visitor to /deposit.
 * This is the real, authenticated deposit surface: it reads the backend's
 * method declaration, renders only lanes the backend says are AVAILABLE, and
 * treats a 501/503 NOT_CONFIGURED answer as an answer (never as a success and
 * never as a silent failure).
 *
 * Lanes:
 *   - PromptPay: POST the canonical wallet deposit endpoint with
 *     method=promptpay; render the authenticated EMV payload as a QR code;
 *     show amount, reference and an expiry countdown; follow the deposit
 *     status through the realtime event first and polling as a fallback.
 *   - TrueMoney voucher: owned by resources/js/truemoney-redeem.js.
 *   - Bank transfer + slip verification: owned by resources/js/slip-upload.js.
 *
 * SECURITY
 * The QR payload, provider metadata, account numbers and voucher codes are
 * never written into the DOM, the clipboard, a toast or the console. The QR
 * payload goes straight into a canvas encoder; the deposit reference is the
 * only identifier displayed, exactly as the backend returns it.
 */

import QRCode from 'qrcode';
import {
    apiRequest,
    announce,
    clearTimers,
    errorCode,
    fillUrl,
    formatAmount,
    idempotencyKey,
    isNotConfigured,
    readL10n,
    serverMessage,
    setText,
    statusLabel,
    text,
} from './frontend-api.js';

const LANES = Object.freeze(['promptpay', 'truemoney', 'bank_transfer']);
const TERMINAL_STATUSES = Object.freeze(['confirmed', 'approved', 'completed', 'failed', 'rejected', 'cancelled', 'canceled', 'expired', 'refunded']);
const DEPOSIT_EVENT = 'lottery:deposit-updated';
const WALLET_EVENT = 'lottery:wallet-updated';
const LANES_READY_EVENT = 'deposit:lanes-ready';

const FALLBACK = Object.freeze({
    notConfigured: 'This payment lane is not available yet.',
    notConfiguredHelp: 'The operator has not configured this payment method. Nothing was charged and no deposit was created.',
    methodsLoading: 'Checking available payment methods…',
    methodsEmpty: 'No deposit method is published for this account right now.',
    methodsFailed: 'The list of available payment methods could not be checked. Try again later.',
    authenticationRequired: 'Sign in to see the payment methods available for your account.',
    serviceUnavailable: 'The service could not be reached. No money was moved.',
    networkError: 'The request could not be completed. Check your connection and try again.',
    validationError: 'Check the amount and try again.',
    laneAvailable: 'Available',
    laneUnavailable: 'Unavailable',
    promptpaySubmit: 'Create PromptPay QR',
    promptpaySubmitting: 'Creating payment request…',
    amountInvalid: 'Enter an amount with at most two decimal places.',
    noQrPayload: 'The payment provider did not return a scannable QR code. No money was moved.',
    qrRenderFailed: 'The QR code could not be drawn on this device. Use the reference instead.',
    qrAlt: 'PromptPay QR code',
    referenceLabel: 'Reference',
    amountLabel: 'Amount',
    expiresLabel: 'Expires in',
    expired: 'This payment request has expired. Create a new one if you still want to deposit.',
    statusLabel: 'Status',
    copyReference: 'Copy reference',
    copied: 'Reference copied.',
    copyFailed: 'Select the reference and copy it manually.',
    pollingHint: 'Status updates automatically; the page also checks the server periodically.',
    realtimeHint: 'Live status updates are on.',
    status_pending: 'Pending payment',
    status_processing: 'Processing',
    status_confirmed: 'Credited',
    status_approved: 'Approved',
    status_completed: 'Credited',
    status_failed: 'Failed',
    status_rejected: 'Rejected',
    status_cancelled: 'Cancelled',
    status_canceled: 'Cancelled',
    status_expired: 'Expired',
    status_refunded: 'Refunded',
});

/**
 * Reduce the backend method declaration to a state per UI lane.
 *
 * The canonical /deposit/methods answer lists the enum values. Newer gateways
 * add `state` / `available` per method and an explicit `lanes` map; older
 * answers simply list the method ids, which is itself the backend saying the
 * lane is offered. A method that is explicitly NOT_CONFIGURED is never shown
 * as a working form.
 *
 * @param {any} payload
 * @returns {Record<string, string>}
 */
export function laneStates(payload) {
    const data = payload && typeof payload === 'object' ? payload : {};
    const declared = data.lanes && typeof data.lanes === 'object' ? data.lanes : {};
    const methods = Array.isArray(data.methods) ? data.methods : [];
    const states = {};

    LANES.forEach((lane) => {
        let state = 'AVAILABLE';

        if (typeof declared[lane] === 'string') {
            state = declared[lane] === 'NOT_CONFIGURED' ? 'NOT_CONFIGURED' : 'AVAILABLE';
        }

        const method = methods.find((candidate) => candidate && typeof candidate === 'object' && candidate.id === lane);
        if (method && (method.state === 'NOT_CONFIGURED' || method.available === false || method.enabled === false)) {
            state = 'NOT_CONFIGURED';
        }

        states[lane] = state;
    });

    // TrueMoney and slip verification are separate merged backends. When the
    // method list explicitly omits their backing method, do not invent a lane:
    // a bank_transfer method is required for the slip lane, and promptpay for
    // the QR lane. TrueMoney has no PaymentMethod case, so it is gated by its
    // own endpoint's honest 503 answer instead.
    if (methods.length > 0) {
        const ids = methods.map((candidate) => (candidate && candidate.id) || '').filter(Boolean);
        if (!ids.includes('promptpay') && !declared.promptpay) {
            states.promptpay = 'NOT_CONFIGURED';
        }
        if (!ids.includes('bank_transfer') && !declared.bank_transfer) {
            states.bank_transfer = 'NOT_CONFIGURED';
        }
    }

    return states;
}

function element(root, selector) {
    return root ? root.querySelector(selector) : null;
}

function showNotice(panel, l10n, message) {
    const notice = element(panel, '[data-lane-notice]');
    const form = element(panel, '[data-lane-form]');

    if (form) {
        form.hidden = true;
    }

    if (notice) {
        announce(notice, message || text(l10n, 'notConfigured'));
    }
}

function enableForm(panel) {
    const notice = element(panel, '[data-lane-notice]');
    const form = element(panel, '[data-lane-form]');

    if (form) {
        form.hidden = false;
    }

    if (notice) {
        notice.hidden = true;
        setText(notice, '');
    }
}

function setTabState(root, lane, state) {
    const tab = root.querySelector('[data-lane-tab="' + lane + '"]');
    if (!tab) {
        return;
    }

    const available = state === 'AVAILABLE';
    tab.setAttribute('aria-disabled', available ? 'false' : 'true');
    tab.tabIndex = available ? 0 : -1;
    tab.dataset.laneState = state;
}

function selectLane(root, lane) {
    root.querySelectorAll('[data-lane-tab]').forEach((tab) => {
        const active = tab.getAttribute('data-lane-tab') === lane;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
    });

    root.querySelectorAll('[data-lane-panel]').forEach((panel) => {
        const active = panel.getAttribute('data-lane-panel') === lane;
        panel.hidden = !active;
        if (active) {
            panel.removeAttribute('inert');
        } else {
            panel.setAttribute('inert', '');
        }
    });
}

function renderQr(canvas, payload) {
    return QRCode.toCanvas(canvas, payload, {
        errorCorrectionLevel: 'M',
        margin: 1,
        width: 232,
        color: { dark: '#0f172a', light: '#ffffff' },
    });
}

function clearQr(canvas) {
    if (!canvas) {
        return;
    }

    const context = canvas.getContext('2d');
    if (context) {
        context.clearRect(0, 0, canvas.width, canvas.height);
    }
}

function referenceFrom(result) {
    const data = (result && result.data && result.data.data) || {};
    const deposit = data.deposit || {};
    const checkout = data.checkout || {};
    const payment = data.payment || {};

    return String(deposit.reference_number || data.reference || data.reference_number || payment.reference_number || '');
}

function statusFrom(result) {
    const data = (result && result.data && result.data.data) || {};
    const deposit = data.deposit || {};

    return String(data.status || deposit.status || result.data.status || '');
}

function init() {
    const root = document.getElementById('deposit-lanes');
    if (!root) {
        return;
    }

    const l10n = readL10n('deposit-l10n', FALLBACK);
    const globalNotice = element(root, '[data-lanes-global-notice]');
    const methodsUrl = root.dataset.methodsUrl || '';
    const depositUrl = root.dataset.depositUrl || '';
    const statusTemplate = root.dataset.statusUrl || '';
    const pollInterval = Math.max(3000, parseInt(root.dataset.pollIntervalMs || '6000', 10) || 6000);
    const currency = root.dataset.currency || 'THB';

    const panels = {};
    LANES.forEach((lane) => {
        panels[lane] = root.querySelector('[data-lane-panel="' + lane + '"]');
    });

    const timers = [];
    let currentDeposit = null;
    let expiryTimer = null;
    let pollTimer = null;
    let disposed = false;

    function resetTimers() {
        if (expiryTimer !== null) {
            clearInterval(expiryTimer);
            expiryTimer = null;
        }
        if (pollTimer !== null) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        clearTimers(timers);
    }

    function qrPanel() {
        return panels.promptpay;
    }

    function setStatus(value, hint) {
        const panel = qrPanel();
        const output = element(panel, '[data-promptpay-status]');
        const label = element(panel, '[data-promptpay-status-label]');
        const result = element(panel, '[data-promptpay-result]');

        if (result) {
            result.hidden = false;
        }

        if (label) {
            setText(label, statusLabel(l10n, value));
            label.dataset.status = String(value || '').toLowerCase();
        }

        if (hint && output) {
            announce(output, hint);
        }
    }

    function expire() {
        resetTimers();
        currentDeposit = null;
        const panel = qrPanel();
        const qr = element(panel, '[data-promptpay-qr]');
        clearQr(qr);
        if (qr) {
            qr.hidden = true;
        }
        setStatus('expired', text(l10n, 'expired'));
    }

    function startCountdown(expiresAt) {
        if (expiryTimer !== null) {
            clearInterval(expiryTimer);
        }

        const target = Date.parse(expiresAt);
        const output = element(qrPanel(), '[data-promptpay-countdown]');
        if (!output || !Number.isFinite(target)) {
            return;
        }

        function tick() {
            const seconds = Math.max(0, Math.floor((target - Date.now()) / 1000));
            const minutes = Math.floor(seconds / 60);
            const rest = seconds % 60;
            setText(output, String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0'));

            if (seconds <= 0) {
                expire();
            }
        }

        tick();
        expiryTimer = setInterval(tick, 1000);
    }

    function stopOnTerminal(status) {
        if (!TERMINAL_STATUSES.includes(String(status).toLowerCase())) {
            return false;
        }

        resetTimers();
        const panel = qrPanel();
        const qr = element(panel, '[data-promptpay-qr]');
        if (qr) {
            qr.hidden = true;
            clearQr(qr);
        }
        setStatus(status, statusLabel(l10n, status));

        return true;
    }

    async function pollStatus() {
        if (!currentDeposit || disposed || statusTemplate === '') {
            return;
        }

        const url = fillUrl(statusTemplate, '__DEPOSIT_ID__', currentDeposit.reference)
            .replace(':depositId', encodeURIComponent(currentDeposit.reference))
            .replace('{depositId}', encodeURIComponent(currentDeposit.reference));
        const result = await apiRequest(url, { method: 'GET' });

        if (disposed || !currentDeposit) {
            return;
        }

        if (!result.ok || !result.data || result.data.success === false) {
            // A failed poll is not a failed deposit. The server-rendered state
            // stays on screen and the next poll retries; nothing is invented.
            return;
        }

        applyStatus(statusFrom(result), text(l10n, 'pollingHint'));
    }

    function applyStatus(status, hint) {
        if (!status) {
            return;
        }

        if (stopOnTerminal(status)) {
            return;
        }

        setStatus(status, hint);
    }

    function onRealtimeDeposit(event) {
        const detail = (event && event.detail) || {};
        if (!currentDeposit) {
            return;
        }

        if (detail.reference_number && String(detail.reference_number) !== currentDeposit.reference) {
            return;
        }

        applyStatus(detail.status, text(l10n, 'realtimeHint'));
    }

    function onRealtimeWallet(event) {
        const detail = (event && event.detail) || {};
        const nodes = document.querySelectorAll('[data-live-balance]');
        const balance = detail.balance !== undefined ? detail.balance : detail.available_balance;

        if (balance === undefined || balance === null || !/^\d+(?:\.\d{1,2})?$/.test(String(balance))) {
            return;
        }

        nodes.forEach((node) => {
            setText(node, formatAmount(String(balance), currency));
        });
    }

    document.addEventListener(DEPOSIT_EVENT, onRealtimeDeposit);
    document.addEventListener(WALLET_EVENT, onRealtimeWallet);
    window.addEventListener('pagehide', () => {
        disposed = true;
        resetTimers();
    }, { once: true });

    async function submitPromptPay(event) {
        event.preventDefault();
        resetTimers();
        currentDeposit = null;

        const panel = qrPanel();
        const form = event.currentTarget;
        const amountInput = element(panel, '[data-promptpay-amount]');
        const submit = element(panel, '[data-promptpay-submit]');
        const result = element(panel, '[data-promptpay-result]');
        const notice = element(panel, '[data-lane-notice]');
        const amount = amountInput ? String(amountInput.value || '').trim() : '';

        if (!/^\d+(?:\.\d{1,2})?$/.test(amount) || Number(amount) <= 0) {
            announce(notice, text(l10n, 'amountInvalid'));
            return;
        }

        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
        }

        const response = await apiRequest(depositUrl, {
            method: 'POST',
            body: {
                amount,
                method: 'promptpay',
                currency,
                idempotency_key: idempotencyKey(),
            },
        });

        if (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
        }

        if (isNotConfigured(response) || (response.data && response.data.state === 'NOT_CONFIGURED')) {
            showNotice(panel, l10n, text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
            setTabState(root, 'promptpay', 'NOT_CONFIGURED');
            const current = window.depositLaneStates || {};
            current.promptpay = 'NOT_CONFIGURED';
            window.depositLaneStates = current;
            root.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states: current } }));
            document.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states: current } }));
            return;
        }

        if (response.status === 401) {
            announce(notice, text(l10n, 'authenticationRequired'));
            return;
        }

        if (response.status === 422) {
            announce(notice, serverMessage(response) || text(l10n, 'validationError'));
            return;
        }

        if (!response.ok || !response.data || response.data.success !== true) {
            announce(notice, text(l10n, response.status === 0 ? 'networkError' : 'serviceUnavailable'));
            return;
        }

        const data = response.data.data || {};
        const checkout = data.checkout || {};
        const payload = typeof checkout.qr_payload === 'string' ? checkout.qr_payload : '';
        const reference = referenceFrom(response);
        const shownAmount = formatAmount(data.deposit && data.deposit.amount ? data.deposit.amount : amount, currency);

        if (payload === '' || reference === '') {
            announce(notice, text(l10n, 'noQrPayload'));
            return;
        }

        if (notice) {
            notice.hidden = true;
            setText(notice, '');
        }

        const canvas = element(panel, '[data-promptpay-qr]');
        if (!canvas) {
            announce(notice, text(l10n, 'qrRenderFailed'));
            return;
        }

        try {
            await renderQr(canvas, payload);
            canvas.hidden = false;
        } catch (_) {
            announce(notice, text(l10n, 'qrRenderFailed'));
            return;
        }

        setText(element(panel, '[data-promptpay-amount-display]'), shownAmount);
        setText(element(panel, '[data-promptpay-reference]'), reference);
        setText(element(panel, '[data-promptpay-expires-at]'), String(checkout.expires_at || ''));
        if (result) {
            result.hidden = false;
        }

        currentDeposit = { reference, amount: shownAmount };
        setStatus(statusFrom(response) || 'pending', text(l10n, 'pollingHint'));

        if (checkout.expires_at) {
            startCountdown(checkout.expires_at);
        }

        pollTimer = setInterval(pollStatus, pollInterval);
        const copyButton = element(panel, '[data-promptpay-copy]');
        if (copyButton) {
            copyButton.dataset.copyValue = reference;
        }
    }

    async function copyReference(event) {
        const button = event.currentTarget;
        const value = button.dataset.copyValue || '';
        const output = element(qrPanel(), '[data-promptpay-status]');

        if (value === '') {
            announce(output, text(l10n, 'copyFailed'));
            return;
        }

        try {
            await navigator.clipboard.writeText(value);
            announce(output, text(l10n, 'copied'));
        } catch (_) {
            announce(output, text(l10n, 'copyFailed'));
        }
    }

    root.querySelectorAll('[data-lane-tab]').forEach((tab) => {
        tab.addEventListener('click', () => {
            if (tab.dataset.laneState === 'NOT_CONFIGURED' || tab.getAttribute('aria-disabled') === 'true') {
                return;
            }
            selectLane(root, tab.getAttribute('data-lane-tab'));
        });

        tab.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') {
                return;
            }
            event.preventDefault();
            const available = Array.from(root.querySelectorAll('[data-lane-tab]'))
                .filter((candidate) => candidate.dataset.laneState !== 'NOT_CONFIGURED');
            const index = available.indexOf(tab);
            if (index === -1 || available.length === 0) {
                return;
            }
            const next = available[(index + (event.key === 'ArrowRight' ? 1 : available.length - 1)) % available.length];
            next.focus();
            selectLane(root, next.getAttribute('data-lane-tab'));
        });
    });

    const promptPayForm = element(panels.promptpay, '[data-lane-form]');
    if (promptPayForm) {
        promptPayForm.addEventListener('submit', submitPromptPay);
    }

    const copyButton = element(panels.promptpay, '[data-promptpay-copy]');
    if (copyButton) {
        copyButton.addEventListener('click', copyReference);
    }

    async function loadMethods() {
        if (methodsUrl === '') {
            announce(globalNotice, text(l10n, 'methodsFailed'));
            return;
        }

        const response = await apiRequest(methodsUrl, { method: 'GET' });

        if (isNotConfigured(response) || (response.data && response.data.state === 'NOT_CONFIGURED')) {
            announce(globalNotice, text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
            LANES.forEach((lane) => {
                setTabState(root, lane, 'NOT_CONFIGURED');
                showNotice(panels[lane], l10n, text(l10n, 'notConfigured'));
            });
            const allOff = { promptpay: 'NOT_CONFIGURED', truemoney: 'NOT_CONFIGURED', bank_transfer: 'NOT_CONFIGURED' };
            window.depositLaneStates = allOff;
            root.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states: allOff } }));
            document.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states: allOff } }));
            return;
        }

        if (response.status === 401) {
            announce(globalNotice, text(l10n, 'authenticationRequired'));
            return;
        }

        if (!response.ok || !response.data || response.data.success !== true) {
            announce(globalNotice, text(l10n, response.status === 0 ? 'networkError' : 'methodsFailed'));
            return;
        }

        const states = laneStates(response.data.data || response.data);
        let firstAvailable = null;

        LANES.forEach((lane) => {
            setTabState(root, lane, states[lane]);
            const panel = panels[lane];
            if (panel) {
                panel.dataset.laneState = states[lane];
            }

            if (states[lane] === 'AVAILABLE') {
                enableForm(panel);
                if (firstAvailable === null) {
                    firstAvailable = lane;
                }
            } else {
                showNotice(panel, l10n, text(l10n, 'notConfigured') + ' ' + text(l10n, 'notConfiguredHelp'));
            }
        });

        if (globalNotice) {
            globalNotice.hidden = true;
            setText(globalNotice, '');
        }

        if (firstAvailable !== null) {
            selectLane(root, firstAvailable);
        } else {
            announce(globalNotice, text(l10n, 'methodsEmpty'));
        }

        root.dataset.lanesLoaded = '1';
        // Retain the answer as well as broadcasting it: the lane modules are
        // code-split and may finish loading after this request resolved.
        window.depositLaneStates = states;
        root.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states } }));
        document.dispatchEvent(new CustomEvent(LANES_READY_EVENT, { detail: { states } }));
    }

    loadMethods();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export default { init, laneStates };
