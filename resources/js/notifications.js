/**
 * Thai Lottery Toast Notification Manager
 * Handles responsive, stacked floating alerts for async actions, websocket events, and validation feedback.
 *
 * Batch 08: this is also the visible half of the realtime bridge. echo.js
 * publishes DOM CustomEvents; this module turns the money-chain events into
 * polite, accessible toasts and keeps every [data-live-balance] element in
 * step with the authoritative figure the server broadcast. When realtime is
 * off nothing is dispatched, so this module stays completely quiet and the
 * existing server-rendered figures and pollers keep working untouched.
 */

import { formatAmount, readL10n, setText, text } from './frontend-api.js';

class NotificationManager {
    constructor() {
        this.container = null;
        this.ensureContainer();
    }

    ensureContainer() {
        let el = document.getElementById('toast-container');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-container';
            el.className = 'fixed top-5 right-5 z-50 flex flex-col space-y-3 max-w-sm w-full pointer-events-none px-4 sm:px-0';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            el.setAttribute('aria-atomic', 'false');
            document.body.appendChild(el);
        }
        this.container = el;
    }

    show(type, title, message = '', duration = 4000) {
        this.ensureContainer();

        const toast = document.createElement('div');
        toast.className = `pointer-events-auto transform transition-all duration-300 ease-out translate-y-2 opacity-0 flex items-start p-4 rounded-xl shadow-xl border backdrop-blur-md ${this.getStyle(type)}`;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.setAttribute('aria-atomic', 'true');
        toast.dataset.toastType = type;

        const iconSvg = this.getIcon(type);

        toast.innerHTML = `
            <div class="flex-shrink-0 mr-3 mt-0.5">
                ${iconSvg}
            </div>
            <div class="flex-1">
                <h4 class="text-sm font-bold text-white tracking-wide">${this.escapeHtml(title)}</h4>
                ${message ? `<p class="text-xs text-slate-300 mt-1 leading-relaxed">${this.escapeHtml(message)}</p>` : ''}
            </div>
            <button type="button" class="flex-shrink-0 ml-3 text-slate-400 hover:text-white focus:outline-none close-toast" aria-label="${this.escapeHtml(this.dismissLabel || 'Dismiss notification')}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        `;

        this.container.appendChild(toast);

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.remove('translate-y-2', 'opacity-0');
            toast.classList.add('translate-y-0', 'opacity-100');
        });

        // Close on click
        toast.querySelector('.close-toast').addEventListener('click', () => {
            this.dismiss(toast);
        });

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => {
                this.dismiss(toast);
            }, duration);
        }
    }

    dismiss(toast) {
        toast.classList.add('opacity-0', 'translate-x-full');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }

    success(title, message = '', duration = 4000) {
        this.show('success', title, message, duration);
    }

    error(title, message = '', duration = 5000) {
        this.show('error', title, message, duration);
    }

    warning(title, message = '', duration = 4500) {
        this.show('warning', title, message, duration);
    }

    info(title, message = '', duration = 4000) {
        this.show('info', title, message, duration);
    }

    getStyle(type) {
        switch (type) {
            case 'success':
                return 'bg-emerald-900/90 border-emerald-500/50 text-emerald-200';
            case 'error':
                return 'bg-rose-900/90 border-rose-500/50 text-rose-200';
            case 'warning':
                return 'bg-amber-900/90 border-amber-500/50 text-amber-200';
            case 'info':
            default:
                return 'bg-slate-900/90 border-amber-500/30 text-amber-200';
        }
    }

    getIcon(type) {
        switch (type) {
            case 'success':
                return '<svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            case 'error':
                return '<svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            case 'warning':
                return '<svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
            case 'info':
            default:
                return '<svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        }
    }

    escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

export const notifications = new NotificationManager();

/**
 * Realtime bridge — DOM CustomEvents published by resources/js/echo.js.
 *
 * Only event type and status words reach a toast. No reference number, voucher
 * code, provider payload, account number or raw event object is ever rendered
 * or logged: those are private even on the owner's own screen. Bursts of the
 * same event within 280 ms are coalesced into one toast with a count, so a
 * reconnect cannot flood a screen reader.
 */
const REALTIME_FALLBACK = Object.freeze({
    dismiss: 'Dismiss notification',
    walletTitle: 'Wallet balance updated',
    walletMessage: 'Your available balance was refreshed from the server.',
    depositTitle: 'Deposit status updated',
    withdrawalTitle: 'Withdrawal status updated',
    payoutTitle: 'Prize payout completed',
    betTitle: 'Bet recorded',
    resyncedTitle: 'Live updates restored',
    resyncedMessage: 'The page is refreshing its balances and statuses.',
    statusPrefix: 'Status',
    pending: 'Pending',
    processing: 'Processing',
    confirmed: 'Credited',
    approved: 'Approved',
    completed: 'Completed',
    failed: 'Failed',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
    cancelledAlt: 'Canceled',
    expired: 'Expired',
    paid: 'Paid',
    burstSuffix: 'more updates',
});

const realtimeL10n = readL10n('realtime-l10n', REALTIME_FALLBACK);
notifications.dismissLabel = text(realtimeL10n, 'dismiss');

const BURST_WINDOW_MS = 280;
const bursts = new Map();
let burstTimer = null;

function safeStatus(detail) {
    const raw = detail && detail.status !== undefined ? String(detail.status).toLowerCase() : '';
    if (raw === '') {
        return '';
    }

    const key = 'status_' + raw.replace(/[^a-z0-9_]/g, '_');
    if (Object.prototype.hasOwnProperty.call(realtimeL10n, key)) {
        return realtimeL10n[key];
    }
    if (Object.prototype.hasOwnProperty.call(realtimeL10n, raw)) {
        return realtimeL10n[raw];
    }

    const known = ['pending', 'processing', 'confirmed', 'approved', 'completed', 'failed', 'rejected', 'cancelled', 'canceled', 'expired', 'paid'];
    return known.includes(raw) ? raw.replace(/^./, (character) => character.toUpperCase()) : text(realtimeL10n, 'pending');
}

function messageFor(kind, detail) {
    const status = safeStatus(detail);
    if (status === '') {
        return kind === 'wallet' ? text(realtimeL10n, 'walletMessage') : '';
    }
    return text(realtimeL10n, 'statusPrefix') + ': ' + status;
}

function flushBursts() {
    burstTimer = null;
    bursts.forEach((burst, kind) => {
        const extra = burst.count > 1
            ? ' (+' + (burst.count - 1) + ' ' + text(realtimeL10n, 'burstSuffix') + ')'
            : '';
        notifications.show(
            burst.type,
            burst.title,
            (burst.message || '') + extra,
            kind === 'wallet' ? 3500 : 4500,
        );
    });
    bursts.clear();
}

function queueToast(kind, type, title, message) {
    const existing = bursts.get(kind);
    if (existing) {
        existing.count += 1;
        existing.message = message || existing.message;
    } else {
        bursts.set(kind, { type, title, message, count: 1 });
    }

    if (burstTimer === null) {
        burstTimer = setTimeout(flushBursts, BURST_WINDOW_MS);
    }
}

/** Write the authoritative balance into every opt-in live-balance element. */
function updateLiveBalances(detail) {
    const value = detail && (detail.balance !== undefined && detail.balance !== null
        ? detail.balance
        : detail.available_balance);

    if (value === undefined || value === null || !/^\d+(?:\.\d{1,2})?$/.test(String(value))) {
        return;
    }

    const nodes = document.querySelectorAll('[data-live-balance]');
    nodes.forEach((node) => {
        const currency = node.getAttribute('data-currency') || 'THB';
        setText(node, formatAmount(String(value), currency));
        node.setAttribute('data-balance-state', 'fresh');
        node.setAttribute('aria-live', 'polite');
    });
}

function onWalletUpdated(event) {
    updateLiveBalances((event && event.detail) || {});
    queueToast('wallet', 'success', text(realtimeL10n, 'walletTitle'), text(realtimeL10n, 'walletMessage'));
}

function onBalanceChanged(event) {
    // echo.js also emits this narrower event. Updating here must not create a
    // second toast: only the balance elements are touched.
    updateLiveBalances({ balance: event && event.detail ? event.detail.balance : null });
}

function onDepositUpdated(event) {
    queueToast('deposit', 'info', text(realtimeL10n, 'depositTitle'), messageFor('deposit', event && event.detail));
}

function onWithdrawalUpdated(event) {
    queueToast('withdrawal', 'info', text(realtimeL10n, 'withdrawalTitle'), messageFor('withdrawal', event && event.detail));
}

function onPayoutCompleted(event) {
    queueToast('payout', 'success', text(realtimeL10n, 'payoutTitle'), messageFor('payout', event && event.detail));
}

function onBetPlaced(event) {
    queueToast('bet', 'info', text(realtimeL10n, 'betTitle'), messageFor('bet', event && event.detail));
}

function onResynced() {
    queueToast('resynced', 'info', text(realtimeL10n, 'resyncedTitle'), text(realtimeL10n, 'resyncedMessage'));
}

document.addEventListener('lottery:wallet-updated', onWalletUpdated);
document.addEventListener('wallet:balance-changed', onBalanceChanged);
document.addEventListener('lottery:deposit-updated', onDepositUpdated);
document.addEventListener('lottery:withdrawal-updated', onWithdrawalUpdated);
document.addEventListener('lottery:payout-completed', onPayoutCompleted);
document.addEventListener('lottery:bet-placed', onBetPlaced);
document.addEventListener('lottery:realtime-resynced', onResynced);

window.notifications = notifications;
window.notificationBridge = {
    updateLiveBalances,
    queueToast,
    l10n: realtimeL10n,
};

export default notifications;
