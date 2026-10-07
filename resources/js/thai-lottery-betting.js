/**
 * Retired generic betting-terminal compatibility guard.
 *
 * The authoritative betting surface is the authenticated /bet route. This
 * asset intentionally performs no balance arithmetic, wager creation, or
 * client-side success simulation.
 */
(function (window, document) {
    'use strict';

    function redirectToCanonicalBetting() {
        window.location.assign('/bet');
    }

    function init() {
        document.querySelectorAll('[data-place-wagers], [data-confirm-bet], #placeWagersBtn, #confirmBetBtn')
            .forEach(function (button) {
                button.addEventListener('click', redirectToCanonicalBetting);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
