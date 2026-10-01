/**
 * Public Mega Lottery enhancement layer.
 *
 * Result numbers, draw dates, source state and all purchase capability remain
 * server-authoritative. This file deliberately has no countdown, random pick,
 * odds, payout, price, ticket, wallet, balance or settlement behavior.
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
                term.value = term.value.replace(/[^0-9-]/g, '');
            });
        }
    });
})();
