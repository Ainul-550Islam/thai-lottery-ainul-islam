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

    function formatRemaining(ms, dayLabel) {
        if (ms <= 0) {
            return '00:00:00';
        }
        var totalSec = Math.floor(ms / 1000);
        var days = Math.floor(totalSec / 86400);
        var hours = Math.floor((totalSec % 86400) / 3600);
        var mins = Math.floor((totalSec % 3600) / 60);
        var secs = totalSec % 60;
        var hms = pad(hours) + ':' + pad(mins) + ':' + pad(secs);
        return days > 0 ? days + (dayLabel ? ' ' + dayLabel : 'd') + ' ' + hms : hms;
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
            root.querySelector('.home-countdown__display');
        var status = root.querySelector('[data-countdown-status]');
        var cells = ['days', 'hours', 'minutes', 'seconds'].map(function (unit) {
            return root.querySelector('#cd-' + unit);
        });
        var hasGrid = cells.every(function (cell) { return cell !== null; });
        if (!output && !hasGrid) return;

        function render(ms) {
            if (!hasGrid) {
                output.textContent = formatRemaining(ms, root.getAttribute('data-day-label'));
                return;
            }
            var seconds = Math.max(0, Math.floor(ms / 1000));
            var values = [Math.floor(seconds / 86400), Math.floor(seconds % 86400 / 3600), Math.floor(seconds % 3600 / 60), seconds % 60];
            cells.forEach(function (cell, index) { cell.textContent = pad(values[index]); });
        }

        var motionQuery = typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
        var prefersReduced = motionQuery && motionQuery.matches;
        var lastMinute = null;
        var didReachTarget = false;
        function onMotionChange(event) {
            prefersReduced = event.matches;
            tick();
        }
        function observeMotion(add) {
            if (!motionQuery) return;
            var method = add ? 'addEventListener' : 'removeEventListener';
            var legacy = add ? 'addListener' : 'removeListener';
            if (typeof motionQuery[method] === 'function') motionQuery[method]('change', onMotionChange);
            else if (typeof motionQuery[legacy] === 'function') motionQuery[legacy](onMotionChange);
        }

        function tick() {
            if (didReachTarget) return;
            var remaining = targetMs - Date.now();
            if (remaining <= 0) {
                didReachTarget = true;
                var reached = root.getAttribute('data-reached-label') || 'The scheduled draw time has arrived.';
                if (hasGrid) render(0);
                else output.textContent = reached;
                if (status) status.textContent = reached;
                root.setAttribute('data-countdown-state', 'reached');
                if (timerId) {
                    window.clearInterval(timerId);
                    timerId = null;
                }
                observeMotion(false);
                return;
            }
            // Compare minute buckets: throttled callbacks may miss the exact boundary.
            var minute = Math.ceil(remaining / 60000);
            if (prefersReduced && timerId && minute === lastMinute) {
                return;
            }
            render(remaining);
            lastMinute = minute;
            root.setAttribute('data-countdown-state', 'active');
        }

        var timerId = null;
        function start() {
            if (didReachTarget) return;
            if (timerId) window.clearInterval(timerId);
            timerId = null;
            prefersReduced = motionQuery && motionQuery.matches;
            tick(); // Always paint immediately, including after BFCache restoration.
            if (targetMs > Date.now()) {
                observeMotion(true);
                timerId = window.setInterval(tick, 1000);
            }
        }
        start();
        if (typeof window.addEventListener === 'function') {
            window.addEventListener('pagehide', function () {
                if (timerId) window.clearInterval(timerId);
                timerId = null;
                observeMotion(false);
            });
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) start();
            });
        }
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
