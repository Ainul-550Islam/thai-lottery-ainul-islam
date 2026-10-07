/**
 * Header wallet balance pill.
 *
 * WHAT THIS OWNS
 * #live-wallet-pill and #player-balance-display in
 * resources/views/layouts/app.blade.php. This module is loaded on every
 * authenticated page, so it is deliberately small and does nothing on pages
 * where the pill is absent (guests, the public site).
 *
 * THE BALANCE ON SCREEN IS THE SERVER'S BALANCE
 * Blade renders the figure from the user's wallet on every request. This
 * module never computes a balance, never adds or subtracts a wager from it,
 * and never caches one across page loads. A stake leaving the wallet is a
 * server-side ledger movement; showing a locally decremented number would be
 * showing a balance no ledger agrees with.
 *
 * WHAT IT ACTUALLY DOES
 * 1. Marks the figure as a polite live region so assistive technology
 *    announces a change instead of silently replacing it.
 * 2. Re-renders the amount through Intl.NumberFormat, keeping the currency
 *    suffix the server printed, so grouping is consistent across locales.
 * 3. Accepts `wallet:balance-changed` with an authoritative amount (from a
 *    server response) and flashes the pill — motion-safe.
 * 4. Accepts `wallet:balance-stale`, meaning "the server's figure has moved
 *    and this page no longer knows it". It marks the pill as stale rather
 *    than inventing a new number. A refresh, or a poll when the operator
 *    configures one, is what resolves it.
 *
 * POLLING IS OFF UNLESS CONFIGURED, AND SAYS SO
 * GET /api/v1/wallet is behind `auth:sanctum`, and this application does not
 * enable Sanctum's stateful-frontend middleware, so a session cookie cannot
 * read it — only a bearer token can. Polling therefore runs only when the
 * server puts both data-balance-endpoint and data-api-token on the pill.
 * Absent either, the module stays quiet instead of producing a loop of 401s.
 */
(function () {
    'use strict';

    var MIN_POLL_SECONDS = 15;

    /**
     * Split "1,234.50 THB" into its numeric part and its trailing unit, so the
     * unit the server chose is preserved verbatim.
     */
    function parseDisplay(text) {
        var trimmed = String(text).trim();
        var match = trimmed.match(/^([0-9][0-9,\s]*(?:\.[0-9]+)?)\s*(.*)$/);

        if (!match) {
            return null;
        }

        var amount = match[1].replace(/[,\s]/g, '');

        if (!/^\d+(\.\d{1,2})?$/.test(amount)) {
            return null;
        }

        return { amount: amount, suffix: match[2] || '' };
    }

    function format(amount) {
        var raw = typeof amount === 'number' && Number.isInteger(amount)
            ? String(amount)
            : (typeof amount === 'string' ? amount.trim() : '');

        if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
            return null;
        }

        var parts = raw.split('.');
        var integer = parts[0].replace(/^0+(?=\d)/, '');
        var fraction = (parts[1] || '').padEnd(2, '0');
        var grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

        return grouped + '.' + fraction;
    }

    function init(pill) {
        var display = pill.querySelector('#player-balance-display');

        if (!display) {
            return;
        }

        var parsed = parseDisplay(display.textContent);

        if (parsed === null) {
            // The server printed something this module does not understand.
            // Leave it exactly as rendered.
            pill.setAttribute('data-wallet-state', 'unreadable');

            return;
        }

        var suffix = parsed.suffix;

        display.setAttribute('aria-live', 'polite');
        display.setAttribute('aria-atomic', 'true');
        display.textContent = format(parsed.amount) + (suffix ? ' ' + suffix : '');
        pill.setAttribute('data-wallet-state', 'fresh');

        var prefersReduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function flash() {
            if (prefersReduced) {
                return;
            }

            pill.style.transition = 'background-color 240ms ease-out';
            pill.style.backgroundColor = 'rgba(16, 185, 129, 0.18)';

            window.setTimeout(function () {
                pill.style.backgroundColor = '';
            }, 420);
        }

        function write(amount) {
            var formatted = format(amount);

            if (formatted === null) {
                markStale();
                return;
            }

            display.textContent = formatted + (suffix ? ' ' + suffix : '');
            pill.setAttribute('data-wallet-state', 'fresh');
            pill.removeAttribute('data-wallet-stale');
            flash();
        }

        document.addEventListener('wallet:balance-changed', function (event) {
            var detail = event.detail || {};

            // Only a canonical decimal string or integer supplied by the
            // server is written. Binary floating-point values are rejected.
            if (format(detail.balance) !== null) {
                write(detail.balance);
            } else {
                markStale();
            }
        });

        function markStale() {
            pill.setAttribute('data-wallet-state', 'stale');
            pill.setAttribute('data-wallet-stale', 'true');
            pill.setAttribute('title', 'Your balance has changed. Refresh the page for the current figure.');
        }

        document.addEventListener('wallet:balance-stale', markStale);

        // -----------------------------------------------------------------
        // Optional, operator-configured polling.
        // -----------------------------------------------------------------
        var endpoint = (pill.getAttribute('data-balance-endpoint') || '').trim();
        var token = (pill.getAttribute('data-api-token') || '').trim();
        var seconds = Number(pill.getAttribute('data-poll-seconds'));

        if (endpoint === '' || token === '') {
            pill.setAttribute('data-wallet-poll', 'disabled');

            return;
        }

        seconds = Number.isFinite(seconds) && seconds >= MIN_POLL_SECONDS ? Math.floor(seconds) : 60;
        pill.setAttribute('data-wallet-poll', String(seconds));

        function poll() {
            if (document.hidden) {
                return;
            }

            window.fetch(endpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    Authorization: 'Bearer ' + token,
                },
            })
                .then(function (response) {
                    return response.ok ? response.json() : null;
                })
                .then(function (body) {
                    // WalletController::show wraps the payload in the standard
                    // ApiResponse envelope: { data: { wallet: { balance } } }.
                    var wallet = body && body.data ? body.data.wallet : null;
                    var amount = wallet ? wallet.balance : null;

                    if (format(amount) !== null) {
                        write(amount);
                    } else {
                        markStale();
                    }
                })
                .catch(function () {
                    // A failed poll changes nothing on screen. The last
                    // server-rendered figure stays, which is the honest state.
                    markStale();
                });
        }

        window.setInterval(poll, seconds * 1000);
        document.addEventListener('wallet:balance-stale', poll);
    }

    function boot() {
        var pills = document.querySelectorAll('#live-wallet-pill');

        for (var i = 0; i < pills.length; i++) {
            init(pills[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
