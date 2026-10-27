/**
 * Public Weekly Lottery enhancement layer.
 *
 * Weekly result values, source state, draw dates, prices and purchase outcomes
 * are server-authoritative. This module only keeps the numeric search control
 * convenient; it never invents a market, schedule, number, price or payout.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const search = document.querySelector('[data-wl-search]');

        if (!search) {
            return;
        }

        const term = search.querySelector('input[name="term"]');

        if (term) {
            term.addEventListener('input', function () {
                term.value = term.value.replace(/[^0-9]/g, '');
            });
        }
    });
})();
