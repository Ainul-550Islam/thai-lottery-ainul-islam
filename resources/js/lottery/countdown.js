/**
 * Player-side betting cut-off countdown.
 *
 * WHAT THIS IS FOR
 * Any element carrying data-closes-at, currently the "Betting Closes In"
 * display on resources/views/player/dashboard.blade.php. It is the player-app
 * sibling of resources/js/home/countdown.js (which serves the public home page
 * and its own markup); they are kept separate because they render into
 * different templates with different copy, and a shared module would have to
 * guess which one it is inside.
 *
 * THE INSTANT COMES FROM THE SERVER
 * data-closes-at is an ISO 8601 string with an explicit offset, produced by
 * Carbon on the server from the draw's betting_closes_at. This module subtracts
 * it from the device clock to draw a number of seconds. It never constructs a
 * draw time, never applies the browser's timezone to a business decision, and
 * never decides that betting is open — only the server does that, on every
 * request, through EnsureDrawIsOpen and the betting services.
 *
 * WHAT REACHING ZERO MEANS HERE
 * Exactly one thing: the UI stops inviting a wager it knows the server will
 * refuse. It emits `lottery:betting-closed`, which the ticket selector and bet
 * slip listen for. A device clock that is wrong simply makes the UI pessimistic
 * or optimistic; it cannot let a late bet through, because the server re-checks.
 *
 * ACCESSIBILITY
 * The timer is not a live region. The caller supplies a separate polite
 * closed-status node and localized display labels. Reduced motion refreshes
 * by remaining-minute bucket, including after throttled callbacks.
 */
(function () {
    'use strict';

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    /**
     * Render remaining milliseconds in the same HH : MM : SS shape the blade
     * ships as its static placeholder, so the layout does not jump when this
     * module takes over. Days are prefixed only when there are any.
     */
    function format(ms, dayLabel) {
        if (ms <= 0) {
            return '00 : 00 : 00';
        }

        var totalSeconds = Math.floor(ms / 1000);
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        var hms = pad(hours) + ' : ' + pad(minutes) + ' : ' + pad(seconds);

        return days > 0 ? days + (dayLabel ? ' ' + dayLabel : 'd') + ' ' + hms : hms;
    }

    function init(element) {
        var closesAt = element.getAttribute('data-closes-at');

        if (!closesAt) {
            return;
        }

        var target = Date.parse(closesAt);

        if (Number.isNaN(target)) {
            // An unparseable instant is a server-side problem. Leave the
            // server-rendered text exactly as it is rather than replace it
            // with a wrong or empty value.
            element.setAttribute('data-countdown-state', 'unreadable');

            return;
        }

        var motionQuery = typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
        var prefersReduced = motionQuery && motionQuery.matches;
        var lastMinute = null;
        var statusId = element.getAttribute('data-status-target');
        var status = statusId ? document.getElementById(statusId) : null;
        function motionChanged(event) {
            prefersReduced = event.matches;
            if (!closed) tick();
        }
        function observeMotion(add) {
            if (!motionQuery) return;
            var method = add ? 'addEventListener' : 'removeEventListener';
            var legacy = add ? 'addListener' : 'removeListener';
            if (typeof motionQuery[method] === 'function') motionQuery[method]('change', motionChanged);
            else if (typeof motionQuery[legacy] === 'function') motionQuery[legacy](motionChanged);
        }

        var timerId = null;
        var closed = false;

        element.setAttribute('role', 'timer');
        element.setAttribute('aria-live', 'off');
        element.setAttribute('aria-atomic', 'true');

        function stop() {
            if (timerId !== null) {
                window.clearInterval(timerId);
                timerId = null;
            }
        }

        function close() {
            if (closed) {
                return;
            }

            closed = true;
            stop();

            observeMotion(false);
            var label = element.getAttribute('data-closed-label') || 'CLOSED';
            element.textContent = label;
            if (status) status.textContent = label;
            element.setAttribute('data-countdown-state', 'closed');

            document.dispatchEvent(new CustomEvent('lottery:betting-closed', {
                detail: { closesAt: closesAt },
            }));
        }

        function tick() {
            var remaining = target - Date.now();

            if (remaining <= 0) {
                close();

                return;
            }

            // Throttled callbacks need not land on the exact second boundary.
            var minute = Math.ceil(remaining / 60000);
            if (prefersReduced && timerId !== null && minute === lastMinute) {
                return;
            }

            element.textContent = format(remaining, element.getAttribute('data-day-label'));
            lastMinute = minute;
            element.setAttribute('data-countdown-state', 'running');
        }

        function start() {
            stop();
            observeMotion(false);
            if (closed) return;
            prefersReduced = motionQuery && motionQuery.matches;
            tick();
            if (!closed) {
                observeMotion(true);
                timerId = window.setInterval(tick, 1000);
            }
        }
        start();
        if (typeof window.addEventListener === 'function') {
            window.addEventListener('pagehide', function () { stop(); observeMotion(false); });
            window.addEventListener('pageshow', function (event) { if (event.persisted) start(); });
        }

        // A backgrounded tab throttles timers; re-sync the moment it returns
        // so the player never sees a stale number.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && !closed) {
                tick();
            }
        });
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-closes-at]');

        for (var i = 0; i < nodes.length; i++) {
            init(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
