/**
 * Thailand Government Lottery (GLO) Interactive Ticket Checker (JavaScript)
 * Reference: draw_results_checker.png
 */

(function (window, document) {
    'use strict';

    function initGloChecker() {
        let currentInput = '';
        const displayScreen = document.getElementById('gloKeypadScreen');
        const actionBtn = document.getElementById('gloInstantCheckBtn');
        const modal = document.getElementById('gloResultModal');
        const closeModalBtn = document.getElementById('gloCloseModalBtn');

        const publishedResults = {
            drawNumber: '128',
            publishDate: 'Oct 16, 2023',
            firstPrize: '724605',
            twoDigitBottom: '14',
            threeDigitTop: '605',
        };

        function renderScreen() {
            if (!displayScreen) return;
            if (currentInput.length === 0) {
                displayScreen.textContent = '- - - - - -';
                displayScreen.style.color = '#d4af37';
            } else {
                const padded = currentInput.padEnd(6, '-').split('').join(' ');
                displayScreen.textContent = padded;
                displayScreen.style.color = '#fbbf24';
            }
        }

        // Keypad digit clicks
        const keyButtons = document.querySelectorAll('[data-key-val]');
        keyButtons.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const val = btn.getAttribute('data-key-val');
                if (!val) return;

                if (val === 'clear') {
                    currentInput = '';
                    renderScreen();
                } else if (val === 'check') {
                    performCheck();
                } else {
                    if (currentInput.length < 6) {
                        currentInput += val;
                        renderScreen();
                    }
                }
            });
        });

        if (actionBtn) {
            actionBtn.addEventListener('click', function (e) {
                e.preventDefault();
                performCheck();
            });
        }

        if (closeModalBtn && modal) {
            closeModalBtn.addEventListener('click', function () {
                modal.classList.add('hidden');
            });
        }

        function performCheck() {
            const num = currentInput.trim();
            if (num.length < 2) {
                alert('Please enter at least 2 to 6 digits to verify.');
                return;
            }

            let won = false;
            let tier = '';
            let amount = '';
            let msg = '';

            if (num.length === 6 && num === publishedResults.firstPrize) {
                won = true;
                tier = 'First Prize (รางวัลที่ 1)';
                amount = 'THB 6,000,000';
                msg = '🎉 CONGRATULATIONS! You won the First Prize jackpot!';
            } else if (num.endsWith(publishedResults.threeDigitTop) || (num.length === 3 && num === publishedResults.threeDigitTop)) {
                won = true;
                tier = '3-Digit Top (3 ตัวบน)';
                amount = 'THB 4,000';
                msg = '🎉 Congratulations! You matched the 3-Digit Top prize!';
            } else if (num.endsWith(publishedResults.twoDigitBottom) || (num.length === 2 && num === publishedResults.twoDigitBottom)) {
                won = true;
                tier = '2-Digit Bottom (2 ตัวท้าย)';
                amount = 'THB 2,000';
                msg = '🎉 Congratulations! You matched the 2-Digit Bottom prize!';
            } else {
                won = false;
                msg = 'No winning matches found for this draw.';
            }

            const modalTitle = document.getElementById('gloModalTitle');
            const modalBody = document.getElementById('gloModalBody');

            if (modal && modalTitle && modalBody) {
                modalTitle.textContent = won ? '🏆 Official Prize Winning!' : 'Ticket Verification Result';
                modalBody.innerHTML = `
                    <div class="text-center space-y-3 py-2">
                        <div class="font-mono text-2xl font-black text-amber-400 tracking-widest">${num}</div>
                        <p class="text-sm ${won ? 'text-emerald-400 font-bold' : 'text-slate-300'}">${msg}</p>
                        ${won ? `
                            <div class="bg-slate-950/80 p-3 rounded-xl border border-amber-500/30">
                                <span class="text-xs text-slate-400 block">${tier}</span>
                                <span class="text-xl font-black text-emerald-400">${amount}</span>
                            </div>
                        ` : ''}
                    </div>
                `;
                modal.classList.remove('hidden');
            } else {
                alert(won ? 'WINNER: ' + tier + ' (' + amount + ')' : msg);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initGloChecker);
    } else {
        initGloChecker();
    }
})(window, document);
