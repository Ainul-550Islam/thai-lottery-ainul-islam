/**
 * Retired wallet-console compatibility guard.
 *
 * The canonical deposit and withdrawal pages own their validated server-side
 * workflows. This asset never calculates balances, invents references, or
 * reports a financial success.
 */
(function (window, document) {
    'use strict';

    function init() {
        var activeTab = 'deposit';

        document.querySelectorAll('[data-tab-name]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                activeTab = tab.getAttribute('data-tab-name') === 'withdraw' ? 'withdraw' : 'deposit';
            });
        });

        var proceed = document.getElementById('proceedPaymentBtn');
        if (proceed) {
            proceed.addEventListener('click', function () {
                window.location.assign(activeTab === 'withdraw' ? '/withdraw' : '/deposit');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
