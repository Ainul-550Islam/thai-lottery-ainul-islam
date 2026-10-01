/**
 * Public National Lottery enhancement layer.
 *
 * Result, provenance, archive and price values are server projections. This
 * module never creates, edits, calculates or substitutes lottery data. The
 * server-rendered links and GET forms remain fully usable without JavaScript.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const search = document.querySelector('[data-nl-search]');

        if (!search) {
            return;
        }

        const number = search.querySelector('input[name="number"]');

        if (number) {
            number.addEventListener('input', function () {
                number.value = number.value.replace(/[^0-9]/g, '');
            });
        }
    });
})();
