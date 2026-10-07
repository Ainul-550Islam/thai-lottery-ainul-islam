/**
 * Lottery Platform Multi-Game Discount & Payout Multiplier Controller
 */

(function () {
    'use strict';

    function initDiscountPortal() {
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        var csrfToken = tokenMeta ? tokenMeta.getAttribute('content') : '';

        // Calculator elements
        var gameSelect = document.getElementById('calcGameSelect');
        var betInput = document.getElementById('calcBetAmount');
        var vipSelect = document.getElementById('calcVipTier');

        // Results elements
        var resGross = document.getElementById('resGrossBet');
        var resDiscount = document.getElementById('resDiscountSavings');
        var resNet = document.getElementById('resNetStake');
        var resMultiplier = document.getElementById('resMultiplier');
        var resPotentialWin = document.getElementById('resPotentialWin');

        function triggerCalculation() {
            var g = gameSelect ? gameSelect.value : '3d_top';
            var amt = betInput ? parseFloat(betInput.value) : 100;
            var vip = vipSelect ? vipSelect.value : 'bronze';

            if (isNaN(amt) || amt <= 0) amt = 100;

            fetch('/api/v1/public/lotto-discount/calculate', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify({
                    game_key: g,
                    bet_amount: amt,
                    vip_tier: vip
                })
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.calculation) {
                    var c = data.calculation;
                    if (resGross) resGross.textContent = Number(c.gross_bet).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' THB';
                    if (resDiscount) resDiscount.textContent = '-' + Number(c.discount_savings).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' THB (' + c.discount_percent + ')';
                    if (resNet) resNet.textContent = Number(c.net_stake).toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' THB';
                    if (resMultiplier) resMultiplier.textContent = c.payout_multiplier;
                    if (resPotentialWin) resPotentialWin.textContent = c.potential_win_display;
                }
            })
            .catch(function (err) {
                console.error('Lotto discount calculator error:', err);
            });
        }

        if (gameSelect) gameSelect.addEventListener('change', triggerCalculation);
        if (vipSelect) vipSelect.addEventListener('change', triggerCalculation);
        if (betInput) {
            var debounce;
            betInput.addEventListener('input', function () {
                clearTimeout(debounce);
                debounce = setTimeout(triggerCalculation, 200);
            });
        }

        // Quick Preset Bet Amount Buttons
        var presetBtns = document.querySelectorAll('.ld-preset-btn');
        presetBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var amt = this.getAttribute('data-amount');
                if (betInput) {
                    betInput.value = amt;
                    triggerCalculation();
                }
            });
        });

        // Market Filter Tabs
        var filterBtns = document.querySelectorAll('.market-filter-btn');
        var marketSections = document.querySelectorAll('.market-table-section');

        filterBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = this.getAttribute('data-target');

                filterBtns.forEach(function (b) {
                    b.classList.remove('bg-amber-500', 'text-slate-950', 'font-black');
                    b.classList.add('bg-slate-800', 'text-slate-300');
                });
                this.classList.remove('bg-slate-800', 'text-slate-300');
                this.classList.add('bg-amber-500', 'text-slate-950', 'font-black');

                marketSections.forEach(function (sec) {
                    if (target === 'all' || sec.getAttribute('data-family') === target) {
                        sec.style.display = 'block';
                    } else {
                        sec.style.display = 'none';
                    }
                });
            });
        });

        // Initial Calculation
        triggerCalculation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDiscountPortal);
    } else {
        initDiscountPortal();
    }
})();
