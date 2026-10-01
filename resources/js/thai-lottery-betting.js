/**
 * Thai Lottery Betting Browser Runtime (JavaScript)
 * Reference: user_betting_page.png (LOTTO THAI)
 */

(function (window, document) {
    'use strict';

    var gameConfigs = {
        '3d_direct': { key: '3d_direct', title: '3D Direct', digits: 3, odds: '1:900', multiplier: 900 },
        '3d_tod': { key: '3d_tod', title: '3D Tod', digits: 3, odds: '1:120', multiplier: 120 },
        '2d_top': { key: '2d_top', title: '2D Top', digits: 2, odds: '1:90', multiplier: 90 },
        '2d_bottom': { key: '2d_bottom', title: '2D Bottom', digits: 2, odds: '1:90', multiplier: 90 },
        'run_top': { key: 'run_top', title: 'Run Top', digits: 1, odds: '1:3.2', multiplier: 3.2 }
    };

    var selectedGameType = '3d_direct';
    var currentNumber = '571';
    var currentStake = 100;
    var userBalance = 12500.00;

    var betSlip = [
        {
            id: 'bet-1',
            gameType: '3d_direct',
            gameTitle: '3D Direct',
            numbers: '571',
            stake: 100,
            odds: '1:900',
            oddsMultiplier: 900,
            potentialWin: 90000
        },
        {
            id: 'bet-2',
            gameType: '2d_top',
            gameTitle: '2D Top',
            numbers: '45',
            stake: 75,
            odds: '1:90',
            oddsMultiplier: 90,
            potentialWin: 6750
        },
        {
            id: 'bet-3',
            gameType: '2d_bottom',
            gameTitle: '2D Bottom',
            numbers: '12',
            stake: 75,
            odds: '1:90',
            oddsMultiplier: 90,
            potentialWin: 6750
        }
    ];

    function renderLcd() {
        var lcd = document.getElementById('lcdDisplay');
        if (lcd) {
            lcd.textContent = currentNumber || '---';
            if (!currentNumber) {
                lcd.classList.add('placeholder');
            } else {
                lcd.classList.remove('placeholder');
            }
        }
    }

    function renderKeyHighlights() {
        var keys = document.querySelectorAll('[data-key-val]');
        var digits = currentNumber.split('');
        keys.forEach(function (k) {
            var val = k.getAttribute('data-key-val');
            if (val && val !== 'DEL' && digits.indexOf(val) !== -1) {
                k.classList.add('highlighted');
            } else {
                k.classList.remove('highlighted');
            }
        });
    }

    function renderGameTypeButtons() {
        var btns = document.querySelectorAll('[data-game-type]');
        btns.forEach(function (b) {
            if (b.getAttribute('data-game-type') === selectedGameType) {
                b.classList.add('active');
            } else {
                b.classList.remove('active');
            }
        });
    }

    function renderStakeButtons() {
        var btns = document.querySelectorAll('[data-stake-val]');
        btns.forEach(function (b) {
            if (parseInt(b.getAttribute('data-stake-val'), 10) === currentStake) {
                b.classList.add('active');
            } else {
                b.classList.remove('active');
            }
        });
    }

    function renderSlip() {
        var container = document.getElementById('betSlipList');
        var countDisplay = document.getElementById('slipItemCount');
        var subtotalDisplay = document.getElementById('subtotalStakeDisplay');
        var totalDisplay = document.getElementById('totalStakeDisplay');
        var estWinDisplay = document.getElementById('estPotentialWinDisplay');

        if (countDisplay) {
            countDisplay.textContent = 'My Bets (' + betSlip.length + ' items)';
        }

        var total = 0;
        var estWin = 0;
        for (var i = 0; i < betSlip.length; i++) {
            total += betSlip[i].stake;
            estWin += betSlip[i].potentialWin;
        }

        if (subtotalDisplay) subtotalDisplay.textContent = total.toFixed(2) + ' THB';
        if (totalDisplay) totalDisplay.textContent = total.toFixed(2) + ' THB';
        if (estWinDisplay) estWinDisplay.textContent = estWin.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' THB';

        if (!container) return;

        if (betSlip.length === 0) {
            container.innerHTML = '<div style="text-align:center; padding: 2rem 0; color: #64748b; font-size: 0.75rem;">Your bet slip is empty.</div>';
            return;
        }

        var html = '';
        for (var j = 0; j < betSlip.length; j++) {
            var item = betSlip[j];
            html += '<div class="tlb-bet-item-card">' +
                '<div class="tlb-bet-item-head">' +
                '<span class="tlb-bet-type-name">' + item.gameTitle + '</span>' +
                '<button class="tlb-bet-remove-btn" onclick="window.tlbApp.removeItem(' + j + ')">✕</button>' +
                '</div>' +
                '<div class="tlb-bet-item-details">' +
                '<div class="tlb-bet-detail-col"><span class="tlb-bet-label">Numbers</span><span class="tlb-bet-val highlight">' + item.numbers + '</span></div>' +
                '<div class="tlb-bet-detail-col"><span class="tlb-bet-label">Stake</span><span class="tlb-bet-val">' + item.stake + ' THB</span></div>' +
                '<div class="tlb-bet-detail-col"><span class="tlb-bet-label">Odds</span><span class="tlb-bet-val">' + item.odds + '</span></div>' +
                '<div class="tlb-bet-detail-col"><span class="tlb-bet-label">Win</span><span class="tlb-bet-val win">' + item.potentialWin.toLocaleString() + ' THB</span></div>' +
                '</div>' +
                '</div>';
        }
        container.innerHTML = html;
    }

    function removeItem(idx) {
        betSlip.splice(idx, 1);
        renderSlip();
    }

    function addCurrentToSlip() {
        var cfg = gameConfigs[selectedGameType];
        if (currentNumber.length !== cfg.digits) {
            alert('Please enter ' + cfg.digits + ' digit(s) for ' + cfg.title);
            return;
        }
        var win = Math.round(currentStake * cfg.multiplier);
        betSlip.push({
            id: 'bet-' + Date.now(),
            gameType: cfg.key,
            gameTitle: cfg.title,
            numbers: currentNumber,
            stake: currentStake,
            odds: cfg.odds,
            oddsMultiplier: cfg.multiplier,
            potentialWin: win
        });
        renderSlip();
    }

    function placeWagers() {
        if (betSlip.length === 0) {
            alert('Bet Slip is empty.');
            return;
        }
        var totalStake = 0;
        for (var i = 0; i < betSlip.length; i++) totalStake += betSlip[i].stake;

        if (totalStake > userBalance) {
            alert('Insufficient balance.');
            return;
        }

        userBalance -= totalStake;
        var balEl = document.getElementById('userBalanceDisplay');
        if (balEl) balEl.textContent = 'Balance: ' + userBalance.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' THB';

        alert('Wagers Placed Successfully!\nReference: THB-BET-' + Date.now() + '\nTotal Stake: ' + totalStake.toLocaleString() + ' THB');
        betSlip = [];
        renderSlip();
    }

    function init() {
        // Game buttons
        document.querySelectorAll('[data-game-type]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var type = btn.getAttribute('data-game-type');
                if (type && gameConfigs[type]) {
                    selectedGameType = type;
                    var maxDigits = gameConfigs[type].digits;
                    if (currentNumber.length > maxDigits) {
                        currentNumber = currentNumber.substring(0, maxDigits);
                    }
                    renderGameTypeButtons();
                    renderLcd();
                    renderKeyHighlights();
                }
            });
        });

        // Keypad
        document.querySelectorAll('[data-key-val]').forEach(function (k) {
            k.addEventListener('click', function () {
                var val = k.getAttribute('data-key-val');
                if (val === 'DEL') {
                    currentNumber = currentNumber.slice(0, -1);
                } else {
                    var maxDigits = gameConfigs[selectedGameType].digits;
                    if (currentNumber.length < maxDigits) {
                        currentNumber += val;
                    }
                }
                renderLcd();
                renderKeyHighlights();
            });
        });

        // Stake buttons
        document.querySelectorAll('[data-stake-val]').forEach(function (b) {
            b.addEventListener('click', function () {
                currentStake = parseInt(b.getAttribute('data-stake-val'), 10);
                renderStakeButtons();
            });
        });

        // Confirm
        var confirmBtn = document.getElementById('confirmWagersBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', placeWagers);
        }

        renderGameTypeButtons();
        renderLcd();
        renderKeyHighlights();
        renderStakeButtons();
        renderSlip();
    }

    window.tlbApp = {
        removeItem: removeItem,
        addCurrentToSlip: addCurrentToSlip,
        placeWagers: placeWagers
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);
