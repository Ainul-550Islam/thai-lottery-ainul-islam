/**
 * Universal Deposit Methods Browser Runtime (JavaScript)
 * Real API connect for PromptPay, Bank Transfer, Crypto/USDT, MFS, Cards
 */

(function (window, document) {
    'use strict';

    function initDepositPortal() {
        var currentAmount = 1000;
        var bonusRate = 0.10;
        var remainingSeconds = 900;

        function recalculate() {
            var bonus = Math.round(currentAmount * bonusRate);
            var total = currentAmount + bonus;

            var baseEl = document.getElementById('summaryBaseAmount');
            var bonusEl = document.getElementById('summaryBonusAmount');
            var totalEl = document.getElementById('summaryTotalCredited');

            if (baseEl) baseEl.textContent = currentAmount.toLocaleString() + ' THB';
            if (bonusEl) bonusEl.textContent = '+' + bonus.toLocaleString() + ' THB';
            if (totalEl) totalEl.textContent = total.toLocaleString() + ' THB';
        }

        // Category clicks
        document.querySelectorAll('[data-deposit-cat]').forEach(function (card) {
            card.addEventListener('click', function () {
                var cat = card.getAttribute('data-deposit-cat');
                document.querySelectorAll('[data-deposit-cat]').forEach(function (c) {
                    c.classList.remove('active');
                });
                card.classList.add('active');

                var map = {
                    'promptpay': 'promptpaySection',
                    'banking': 'bankingSection',
                    'crypto': 'cryptoSection',
                    'mfs': 'mfsSection',
                    'card': 'cardSection'
                };

                ['promptpaySection', 'bankingSection', 'cryptoSection', 'mfsSection', 'cardSection'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.style.display = 'none';
                });

                var activeId = map[cat];
                if (activeId) {
                    var activeEl = document.getElementById(activeId);
                    if (activeEl) activeEl.style.display = 'block';
                }
            });
        });

        // Amount buttons
        document.querySelectorAll('[data-amount-val]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentAmount = parseInt(btn.getAttribute('data-amount-val'), 10);
                document.querySelectorAll('[data-amount-val]').forEach(function (b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');

                var inp = document.getElementById('customAmountInput');
                if (inp) inp.value = currentAmount;

                recalculate();
            });
        });

        // Custom amount input
        var customInp = document.getElementById('customAmountInput');
        if (customInp) {
            customInp.addEventListener('input', function () {
                var val = parseFloat(customInp.value.replace(/[^0-9.]/g, '')) || 0;
                currentAmount = val;
                recalculate();
            });
        }

        // Copy buttons
        document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tid = btn.getAttribute('data-copy-target');
                var el = document.getElementById(tid);
                if (el) {
                    navigator.clipboard.writeText(el.textContent.trim()).then(function () {
                        var orig = btn.textContent;
                        btn.textContent = 'Copied!';
                        setTimeout(function () { btn.textContent = orig; }, 2000);
                    });
                }
            });
        });

        // Countdown
        var timerEl = document.getElementById('qrCountdownTimer');
        if (timerEl) {
            setInterval(function () {
                if (remainingSeconds <= 0) return;
                remainingSeconds--;
                var m = Math.floor(remainingSeconds / 60);
                var s = remainingSeconds % 60;
                var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
                timerEl.textContent = pad(m) + ':' + pad(s);
            }, 1000);
        }

        recalculate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDepositPortal);
    } else {
        initDepositPortal();
    }
})(window, document);
