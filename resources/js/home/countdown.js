/**
 * Public Home countdown — display only.
 *
 * The target ISO instant and timezone come from the server (GloNextDrawService).
 * This module NEVER builds draw dates, NEVER trusts browser timezone for business
 * decisions, and NEVER replaces the absolute <time datetime> text as the sole
 * timing information (that element remains for screen readers / no-JS).
 */
(function () {
    'use strict';

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function formatRemaining(ms) {
        if (ms <= 0) {
            return '00:00:00';
        }
        var totalSec = Math.floor(ms / 1000);
        var days = Math.floor(totalSec / 86400);
        var hours = Math.floor((totalSec % 86400) / 3600);
        var mins = Math.floor((totalSec % 3600) / 60);
        var secs = totalSec % 60;
        var hms = pad(hours) + ':' + pad(mins) + ':' + pad(secs);
        return days > 0 ? days + 'd ' + hms : hms;
    }

    function initCountdown(root) {
        var targetAttr = root.getAttribute('data-target');
        if (!targetAttr) {
            return;
        }
        var targetMs = Date.parse(targetAttr);
        if (Number.isNaN(targetMs)) {
            return;
        }

        var output =
            root.querySelector('[data-countdown-output]') ||
            root.querySelector('.home-countdown__display') ||
            root;

        var prefersReduced =
            window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function tick() {
            var remaining = targetMs - Date.now();
            if (remaining <= 0) {
                output.textContent = 'Draw time reached';
                root.setAttribute('data-countdown-state', 'reached');
                if (timerId) {
                    window.clearInterval(timerId);
                    timerId = null;
                }
                return;
            }
            // Reduced motion: update once per minute instead of every second.
            if (prefersReduced && timerId && Math.floor(remaining / 1000) % 60 !== 0) {
                return;
            }
            output.textContent = formatRemaining(remaining);
            root.setAttribute('data-countdown-state', 'active');
        }

        var timerId = window.setInterval(tick, 1000);
        tick();
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-home-countdown]');
        for (var i = 0; i < nodes.length; i++) {
            initCountdown(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
