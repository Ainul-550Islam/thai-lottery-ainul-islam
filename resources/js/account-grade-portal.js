/**
 * ThaiLotto VIP Account Grade & Real-Time Rebate Simulator Controller
 */

(function () {
    'use strict';

    function initGradePortal() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Simulator Inputs
        var spendSlider = document.getElementById('vipSpendSlider');
        var spendInput = document.getElementById('vipSpendInput');
        var quickBtns = document.querySelectorAll('.vip-quick-btn');

        // Output Elements
        var tierBadge = document.getElementById('simTierBadge');
        var tierName = document.getElementById('simTierName');
        var rebateValue = document.getElementById('simRebateValue');
        var rebateRate = document.getElementById('simRebateRate');
        var withdrawalBenefit = document.getElementById('simWithdrawalBenefit');
        var progressBox = document.getElementById('simProgressBox');
        var progressBar = document.getElementById('simProgressBar');
        var progressText = document.getElementById('simProgressText');

        function triggerCalculation(spend) {
            spend = parseFloat(spend);
            if (isNaN(spend) || spend < 0) spend = 0;

            if (spendInput && document.activeElement !== spendInput) {
                spendInput.value = spend;
            }
            if (spendSlider && document.activeElement !== spendSlider) {
                spendSlider.value = spend;
            }

            fetch('/api/v1/public/grades/calculate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({ monthly_spend: spend })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    renderSimulationResult(data);
                }
            })
            .catch(function (err) {
                console.error('VIP Grade simulation error:', err);
            });
        }

        function renderSimulationResult(data) {
            if (tierName) tierName.textContent = data.tier.name;
            if (tierBadge) tierBadge.textContent = 'SL ' + data.tier.sl + ' • ' + data.tier.discount_display + ' CASHBACK';
            if (rebateValue) rebateValue.textContent = data.rebate.rebate_display;
            if (rebateRate) rebateRate.textContent = data.tier.discount_display;
            if (withdrawalBenefit) withdrawalBenefit.textContent = data.tier.free_withdrawals;

            if (data.next_tier) {
                if (progressBox) progressBox.classList.remove('hidden');
                if (progressBar) progressBar.style.width = data.next_tier.progress_percent + '%';
                if (progressText) {
                    progressText.textContent = data.next_tier.spend_remaining_display + ' more to reach ' + data.next_tier.name + ' (' + data.next_tier.progress_percent + '%)';
                }
            } else {
                if (progressBox) progressBox.classList.remove('hidden');
                if (progressBar) progressBar.style.width = '100%';
                if (progressText) progressText.textContent = '🎉 Congratulations! You have unlocked Maximum Diamond VIP Tier.';
            }

            // Highlight corresponding pedestal card
            var cards = document.querySelectorAll('.vip-pedestal-card');
            cards.forEach(function (card) {
                if (card.getAttribute('data-tier-key') === data.tier.key) {
                    card.classList.add('border-amber-400', 'shadow-2xl', 'scale-105');
                } else {
                    card.classList.remove('border-amber-400', 'shadow-2xl', 'scale-105');
                }
            });
        }

        if (spendSlider) {
            spendSlider.addEventListener('input', function () {
                triggerCalculation(this.value);
            });
        }

        if (spendInput) {
            var debounce;
            spendInput.addEventListener('input', function () {
                clearTimeout(debounce);
                var val = this.value;
                debounce = setTimeout(function () {
                    triggerCalculation(val);
                }, 200);
            });
        }

        quickBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var amt = this.getAttribute('data-amount');
                triggerCalculation(amt);
            });
        });

        // Initial calculation trigger
        triggerCalculation(50000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGradePortal);
    } else {
        initGradePortal();
    }
})();
