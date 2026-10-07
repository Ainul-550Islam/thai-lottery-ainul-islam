/**
 * Retired deposit-portal compatibility guard.
 *
 * Deposit initiation is available only through the authenticated canonical
 * /deposit page and its validated backend contract. No provider response,
 * reference, address, bonus, balance, or success state is synthesized here.
 */
(function (document) {
    'use strict';

    function init() {
        var button = document.getElementById('initiateDepositBtn');
        if (button) {
            button.addEventListener('click', function () {
                window.location.assign('/deposit');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(document);
