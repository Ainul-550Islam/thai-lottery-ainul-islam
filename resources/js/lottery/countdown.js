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
 * The display is marked aria-live="polite" and, under
 * prefers-reduced-motion: reduce, is refreshed once a minute instead of once a
 * second so a screen reader is not flooded with per-second updates.
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
    function format(ms) {
        if (ms <= 0) {
            return '00 : 00 : 00';
        }

        var totalSeconds = Math.floor(ms / 1000);
        var days = Math.floor(totalSeconds / 86400);
        var hours = Math.floor((totalSeconds % 86400) / 3600);
        var minutes = Math.floor((totalSeconds % 3600) / 60);
        var seconds = totalSeconds % 60;

        var hms = pad(hours) + ' : ' + pad(minutes) + ' : ' + pad(seconds);

        return days > 0 ? days + 'd ' + hms : hms;
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

        var prefersReduced = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        var timerId = null;
        var closed = false;

        element.setAttribute('aria-live', 'polite');
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

            element.textContent = 'CLOSED';
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

            // Reduced motion: only repaint on a minute boundary.
            if (prefersReduced && timerId !== null && Math.floor(remaining / 1000) % 60 !== 0) {
                return;
            }

            element.textContent = format(remaining);
            element.setAttribute('data-countdown-state', 'running');
        }

        tick();

        if (!closed) {
            timerId = window.setInterval(tick, 1000);
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
