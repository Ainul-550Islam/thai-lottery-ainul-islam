/**
 * Retired dashboard compatibility guard.
 *
 * Dashboard money and ticket mutations belong to authenticated server routes.
 * This asset redirects controls and never fabricates a deposit, cancellation,
 * refund, countdown, balance, or success response.
 */
(function (window, document) {
    'use strict';

    function init() {
        var deposit = document.getElementById('walletDepositBtn');
        if (deposit) {
            deposit.addEventListener('click', function () {
                window.location.assign('/deposit');
            });
        }

        document.querySelectorAll('[data-cancel-ticket]').forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                window.location.assign('/bets');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
