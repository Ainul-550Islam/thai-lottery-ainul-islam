/**
 * Retired withdrawal-portal compatibility guard.
 *
 * Withdrawal requests are accepted only by the authenticated canonical
 * /withdraw page and its validated backend service. No balance, payout,
 * destination, reference, ETA, or success state is synthesized here.
 */
(function (window, document) {
    'use strict';

    function init() {
        var button = document.getElementById('confirmWithdrawalBtn');
        if (button) {
            button.addEventListener('click', function () {
                window.location.assign('/withdraw');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
