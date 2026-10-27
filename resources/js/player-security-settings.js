/**
 * Player Security & Responsible Gaming Settings Controller (JavaScript)
 * Reference: kyc_responsible_gaming.png
 */

(function (window, document) {
    'use strict';

    function initSecuritySettings() {
        const depositSlider = document.getElementById('depositSlider');
        const depositDisplay = document.getElementById('depositValDisplay');

        if (depositSlider && depositDisplay) {
            depositSlider.addEventListener('input', function () {
                depositDisplay.textContent = parseInt(depositSlider.value, 10).toLocaleString();
            });
        }

        const betSlider = document.getElementById('betSlider');
        const betDisplay = document.getElementById('betValDisplay');

        if (betSlider && betDisplay) {
            betSlider.addEventListener('input', function () {
                betDisplay.textContent = parseInt(betSlider.value, 10).toLocaleString();
            });
        }

        const wagerSlider = document.getElementById('wagerSlider');
        const wagerDisplay = document.getElementById('wagerValDisplay');

        if (wagerSlider && wagerDisplay) {
            wagerSlider.addEventListener('input', function () {
                wagerDisplay.textContent = parseInt(wagerSlider.value, 10).toLocaleString();
            });
        }

        // Edit button prompt handlers
        const editBtns = document.querySelectorAll('[data-edit-limit]');
        editBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const limitKey = btn.getAttribute('data-edit-limit');
                const val = prompt('Enter new ' + limitKey + ' limit (THB):');
                if (val) {
                    const parsed = parseInt(val.replace(/[^0-9]/g, ''), 10);
                    if (!isNaN(parsed)) {
                        if (limitKey === 'deposit' && depositDisplay && depositSlider) {
                            depositDisplay.textContent = parsed.toLocaleString();
                            depositSlider.value = parsed;
                        } else if (limitKey === 'bet' && betDisplay && betSlider) {
                            betDisplay.textContent = parsed.toLocaleString();
                            betSlider.value = parsed;
                        } else if (limitKey === 'wager' && wagerDisplay && wagerSlider) {
                            wagerDisplay.textContent = parsed.toLocaleString();
                            wagerSlider.value = parsed;
                        }
                    }
                }
            });
        });

        // Break duration buttons
        const breakBtns = document.querySelectorAll('[data-break-duration]');
        breakBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const duration = btn.getAttribute('data-break-duration');
                const pin = prompt('Confirm ' + duration + ' break with PIN:');
                if (pin) {
                    const activeBreak = document.getElementById('activeBreakDisplay');
                    if (activeBreak) {
                        activeBreak.textContent = duration;
                        activeBreak.style.color = '#38bdf8';
                    }
                    alert('Cool-off break set for ' + duration + '.');
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSecuritySettings);
    } else {
        initSecuritySettings();
    }
})(window, document);
