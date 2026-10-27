/**
 * Thai Lottery real-time bridge (Laravel Echo over the Pusher protocol → Reverb).
 *
 * This file used to ship a "fallback event bus" that pretended to be a socket when
 * window.Echo was absent - which was always, because nothing ever created window.Echo.
 * Every live balance, bet confirmation and result reveal was therefore wired to a bus
 * nobody published on. It now does three honest things and nothing else:
 *
 *  1. Decide whether the lane is live from facts the page emits (<meta name="realtime-*">
 *     rendered from config/realtime.php; Vite's VITE_REVERB_* as the build-time fallback).
 *     When it is not live, isLive is false, no socket is opened, and the server-rendered
 *     figures and the existing polling stay the source of truth.
 *
 *  2. Subscribe the viewer to exactly the channels the server authorizes for them
 *     (private user.{id}) and the public draw channels, and translate every server event
 *     into a DOM CustomEvent other modules already listen for. No module imports this file
 *     to receive data; the document is the bus.
 *
 *  3. Reconnect with capped exponential backoff and tear the socket down on pagehide, so
 *     a tab in the background does not hold a connection the server has to carry.
 *
 * Nothing here is secret: the app key is a public identifier, the auth endpoint is the
 * framework's, and the user id is the viewer's own (the layout emits it only under @auth).
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const PRIVATE_EVENTS = Object.freeze([
    'wallet.balance.updated',
    'bet.placed',
    'withdrawal.status.updated',
    'deposit.status.updated',
    'payout.completed',
]);

const DOM_EVENT_PREFIX = 'lottery:';

function meta(name) {
    if (typeof document === 'undefined') {
        return null;
    }

    const tag = document.querySelector(`meta[name="${name}"]`);

    if (!tag) {
        return null;
    }

    const value = (tag.getAttribute('content') || '').trim();

    return value === '' ? null : value;
}

function env(name) {
    try {
        const value = import.meta.env ? import.meta.env[name] : undefined;

        return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    } catch (_) {
        return null;
    }
}

function asBool(value) {
    if (value === null || value === undefined) {
        return false;
    }

    return ['1', 'true', 'yes', 'on'].includes(String(value).toLowerCase());
}

function asInt(value, fallback) {
    const parsed = parseInt(String(value), 10);

    return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

/**
 * Resolve the client configuration. Meta tags win because they reflect the running
 * server's config; the Vite variables are what the bundle was built with.
 */
export function resolveConfig() {
    const enabledMeta = meta('realtime-enabled');
    const enabled = enabledMeta !== null ? asBool(enabledMeta) : asBool(env('VITE_REALTIME_ENABLED'));
    const scheme = (meta('realtime-scheme') || env('VITE_REVERB_SCHEME') || 'https').toLowerCase();

    return {
        enabled,
        key: meta('realtime-key') || env('VITE_REVERB_APP_KEY') || '',
        host: meta('realtime-host') || env('VITE_REVERB_HOST') || '',
        port: asInt(meta('realtime-port') || env('VITE_REVERB_PORT'), scheme === 'https' ? 443 : 80),
        scheme,
        authEndpoint: meta('realtime-auth-endpoint') || '/broadcasting/auth',
        reconnectMinMs: asInt(meta('realtime-reconnect-min') || env('VITE_REALTIME_RECONNECT_MIN_MS'), 1000),
        reconnectMaxMs: asInt(meta('realtime-reconnect-max') || env('VITE_REALTIME_RECONNECT_MAX_MS'), 30000),
        csrfToken: meta('csrf-token') || '',
        userId: (() => {
            const id = meta('user-id');

            return id !== null && /^[0-9]+$/.test(id) ? id : null;
        })(),
    };
}

/**
 * Translate a published draw result into the shape lottery/live-results.js consumes.
 * The Thai lottery derives the 3-digit and 2-digit "top" prizes from the first prize's
 * trailing digits; the bottom 2-digit prize is its own draw and travels in the payload.
 */
export function resultsPayloadFrom(detail) {
    if (!detail || typeof detail !== 'object') {
        return null;
    }

    const first = typeof detail.first_prize === 'string' ? detail.first_prize : null;

    if (first === null || !/^[0-9]{6}$/.test(first)) {
        return null;
    }

    const bottom = detail.two_digit_bottom;

    return {
        first_prize: first,
        three_digits_top: first.slice(-3),
        two_digits_top: first.slice(-2),
        two_digits_bottom: typeof bottom === 'string' && /^[0-9]{2}$/.test(bottom) ? bottom : undefined,
    };
}

function dispatch(name, detail) {
    if (typeof document === 'undefined') {
        return;
    }

    document.dispatchEvent(new CustomEvent(name, { detail: detail === undefined ? {} : detail }));
}

export class EchoBridge {
    constructor() {
        this.isInitialized = false;
        this.isLive = false;
        this.client = null;
        this.config = null;
        this.state = 'idle';
        this.reconnectAttempt = 0;
        this.reconnectTimer = null;
        this.stoppedByPage = false;
        this.subscriptions = [];
    }

    /**
     * Start the bridge once. Safe to call repeatedly; safe to call when the lane is dark.
     */
    init() {
        if (this.isInitialized) {
            return this;
        }

        this.isInitialized = true;
        this.config = resolveConfig();

        if (!this.config.enabled || this.config.key === '' || this.config.host === '') {
            this.isLive = false;
            this.setState('disabled');

            return this;
        }

        if (typeof window !== 'undefined' && !window.Pusher) {
            window.Pusher = Pusher;
        }

        this.connect();

        if (typeof window !== 'undefined') {
            window.addEventListener('pagehide', () => this.disconnect('pagehide'), { once: false });
            // A logout form leaves the session behind before the page does: drop the socket first,
            // so no frame addressed to the old session arrives in a tab that no longer owns it.
            document.addEventListener('submit', (event) => {
                const form = event.target;
                const action = form && typeof form.getAttribute === 'function' ? (form.getAttribute('action') || '') : '';

                if (/\/logout(\?|$)/.test(action)) {
                    this.disconnect('logout');
                }
            }, true);
            window.addEventListener('online', () => {
                if (!this.stoppedByPage && this.state !== 'connected') {
                    this.scheduleReconnect(true);
                }
            });
        }

        return this;
    }

    connect() {
        if (this.client) {
            return;
        }

        const cfg = this.config;
        const useTLS = cfg.scheme === 'https';

        this.client = new Echo({
            broadcaster: 'reverb',
            key: cfg.key,
            wsHost: cfg.host,
            wsPort: cfg.port,
            wssPort: cfg.port,
            forceTLS: useTLS,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: cfg.authEndpoint,
            auth: { headers: { 'X-CSRF-TOKEN': cfg.csrfToken, 'X-Requested-With': 'XMLHttpRequest' } },
            // The library's own retry is disabled so a single backoff policy owns reconnects.
            activityTimeout: 30000,
            pongTimeout: 10000,
        });

        if (typeof window !== 'undefined') {
            window.Echo = this.client;
        }

        this.isLive = true;
        this.bindConnectionState();
        this.bindGlobalChannels();
    }

    bindConnectionState() {
        const connection = this.pusherConnection();

        if (!connection || typeof connection.bind !== 'function') {
            return;
        }

        connection.bind('state_change', (change) => {
            const current = change && change.current ? String(change.current) : 'unknown';

            this.setState(current);

            if (current === 'connected') {
                const hadGap = this.reconnectAttempt > 0;
                this.reconnectAttempt = 0;
                this.clearReconnectTimer();

                if (hadGap) {
                    // Anything published during the gap was missed: ask the pollers to refresh.
                    dispatch('wallet:balance-stale');
                    dispatch(`${DOM_EVENT_PREFIX}realtime-resynced`, { reason: 'reconnected' });
                }
            }

            if ((current === 'failed' || current === 'unavailable' || current === 'disconnected') && !this.stoppedByPage) {
                this.scheduleReconnect(false);
            }
        });

        connection.bind('error', () => {
            if (!this.stoppedByPage) {
                this.scheduleReconnect(false);
            }
        });
    }

    pusherConnection() {
        const pusher = this.client && this.client.connector ? this.client.connector.pusher : null;

        return pusher && pusher.connection ? pusher.connection : null;
    }

    scheduleReconnect(immediate) {
        if (this.reconnectTimer !== null || !this.config) {
            return;
        }

        const { reconnectMinMs, reconnectMaxMs } = this.config;
        const exponent = Math.min(this.reconnectAttempt, 10);
        const base = Math.min(reconnectMaxMs, reconnectMinMs * Math.pow(2, exponent));
        const jitter = Math.floor(Math.random() * Math.min(1000, base / 4));
        const delay = immediate ? 0 : base + jitter;

        this.reconnectAttempt += 1;
        this.setState('reconnecting', { attempt: this.reconnectAttempt, delay });

        this.reconnectTimer = setTimeout(() => {
            this.reconnectTimer = null;

            const pusher = this.client && this.client.connector ? this.client.connector.pusher : null;

            if (pusher && typeof pusher.connect === 'function') {
                pusher.connect();
            }
        }, delay);
    }

    clearReconnectTimer() {
        if (this.reconnectTimer !== null) {
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = null;
        }
    }

    /**
     * Subscribe to the viewer's private channel (when the page named a viewer) and to the
     * public draw channels. Event names start with "." because the server declares them
     * via broadcastAs() without a namespace.
     */
    bindGlobalChannels() {
        if (!this.client) {
            return;
        }

        const userId = this.config ? this.config.userId : null;

        if (userId !== null && typeof this.client.private === 'function') {
            const channel = this.client.private(`user.${userId}`);

            channel
                .listen('.wallet.balance.updated', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}wallet-updated`, e);
                    dispatch('wallet:balance-changed', { balance: e && e.balance !== undefined ? e.balance : null });
                })
                .listen('.bet.placed', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}bet-placed`, e);
                })
                .listen('.withdrawal.status.updated', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}withdrawal-updated`, e);
                })
                .listen('.deposit.status.updated', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}deposit-updated`, e);
                })
                .listen('.payout.completed', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}payout-completed`, e);
                    dispatch('wallet:balance-stale');
                });

            this.subscriptions.push(`private-user.${userId}`);
        }

        if (typeof this.client.channel === 'function') {
            this.client.channel('draws')
                .listen('.draw.result.published', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}draw-result-published`, e);

                    const results = resultsPayloadFrom(e);

                    if (results !== null) {
                        dispatch(`${DOM_EVENT_PREFIX}results-updated`, results);
                    }
                })
                .listen('.draw.status.updated', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}draw-status-updated`, e);
                });

            this.client.channel('draws.live')
                .listen('.draw.settlement.completed', (e) => {
                    dispatch(`${DOM_EVENT_PREFIX}draw-settlement-completed`, e);
                });

            this.subscriptions.push('draws', 'draws.live');
        }
    }

    /**
     * Allow a page to react to connection state (e.g. a "live" badge) without touching Echo.
     */
    setState(state, extra) {
        this.state = state;
        dispatch(`${DOM_EVENT_PREFIX}realtime-state`, Object.assign({ state, live: this.isLive }, extra || {}));
    }

    disconnect(reason) {
        this.stoppedByPage = reason === 'pagehide';
        this.clearReconnectTimer();

        if (this.client && typeof this.client.disconnect === 'function') {
            try {
                this.client.disconnect();
            } catch (_) {
                // A socket that is already closed throws in some browsers; nothing to recover.
            }
        }

        this.client = null;
        this.isLive = false;
        this.subscriptions = [];
        this.setState('disconnected', { reason: reason || 'manual' });
    }
}

export const echo = new EchoBridge();

if (typeof window !== 'undefined') {
    window.echoBridge = echo;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => echo.init(), { once: true });
    } else {
        echo.init();
    }
}

export default echo;
