/**
 * Wallet Management Browser Runtime (JavaScript)
 * Reference: wallet_transactions.png (FortuneLotto)
 */

(function (window, document) {
    'use strict';

    function initWallet() {
        var activeTab = 'deposit';
        var selectedGateway = 'stripe';
        var selectedAmount = 500;

        // Tab switcher
        document.querySelectorAll('[data-tab-name]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                activeTab = tab.getAttribute('data-tab-name');
                document.querySelectorAll('[data-tab-name]').forEach(function (t) {
                    t.classList.remove('active');
                });
                tab.classList.add('active');

                var btnText = document.getElementById('proceedBtnText');
                if (btnText) {
                    btnText.textContent = activeTab === 'deposit' ? 'Proceed to Deposit' : 'Proceed to Withdraw';
                }
            });
        });

        // Gateway select
        document.querySelectorAll('[data-gateway]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectedGateway = btn.getAttribute('data-gateway');
                document.querySelectorAll('[data-gateway]').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
            });
        });

        // Amount presets
        document.querySelectorAll('[data-preset-amount]').forEach(function (pill) {
            pill.addEventListener('click', function () {
                selectedAmount = parseInt(pill.getAttribute('data-preset-amount'), 10);
                document.querySelectorAll('[data-preset-amount]').forEach(function (p) {
                    p.classList.remove('active');
                });
                pill.classList.add('active');
            });
        });

        // Proceed button
        var proceedBtn = document.getElementById('proceedPaymentBtn');
        if (proceedBtn) {
            proceedBtn.addEventListener('click', function () {
                var amountPrompt = prompt('Enter ' + activeTab + ' amount in THB:', selectedAmount);
                if (amountPrompt) {
                    var parsed = parseFloat(amountPrompt);
                    if (!isNaN(parsed) && parsed > 0) {
                        alert(activeTab.toUpperCase() + ' request for ' + parsed.toLocaleString() + ' THB via ' + selectedGateway.toUpperCase() + ' processed successfully.\nRef ID: TX-' + Math.floor(10000 + Math.random() * 90000));
                    }
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWallet);
    } else {
        initWallet();
    }
})(window, document);
