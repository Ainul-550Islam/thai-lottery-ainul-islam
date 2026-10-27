/**
 * Lottery Platform Interactive 6-Digit Lucky Number Picker & Live Keypad Controller
 */

(function () {
    'use strict';

    var currentDigits = ['1', '2', '3', '4', '5', '6'];
    var activeDigitIndex = 0;

    function renderLuckyDigits() {
        for (var i = 0; i < 6; i++) {
            var box = document.getElementById('digitBox-' + i);
            if (box) {
                box.textContent = currentDigits[i] !== undefined ? currentDigits[i] : '-';
                if (i === activeDigitIndex) {
                    box.classList.add('border-amber-400', 'text-amber-300', 'shadow-[0_0_15px_rgba(245,166,35,0.4)]');
                    box.classList.remove('border-[#1c2b44]', 'text-white');
                } else {
                    box.classList.remove('border-amber-400', 'text-amber-300', 'shadow-[0_0_15px_rgba(245,166,35,0.4)]');
                    box.classList.add('border-[#1c2b44]', 'text-white');
                }
            }
        }
    }

    function inputDigit(num) {
        currentDigits[activeDigitIndex] = String(num);
        if (activeDigitIndex < 5) {
            activeDigitIndex++;
        }
        renderLuckyDigits();
    }

    function backspaceDigit() {
        currentDigits[activeDigitIndex] = '0';
        if (activeDigitIndex > 0) {
            activeDigitIndex--;
        }
        renderLuckyDigits();
    }

    function clearDigits() {
        currentDigits = ['0', '0', '0', '0', '0', '0'];
        activeDigitIndex = 0;
        renderLuckyDigits();
    }

    function quickPickRandom() {
        for (var i = 0; i < 6; i++) {
            currentDigits[i] = String(Math.floor(Math.random() * 10));
        }
        activeDigitIndex = 5;
        renderLuckyDigits();
    }

    // Attach to global window
    window.thaiLottoKeypad = {
        inputDigit: inputDigit,
        backspaceDigit: backspaceDigit,
        clearDigits: clearDigits,
        quickPickRandom: quickPickRandom,
        selectBox: function (index) {
            activeDigitIndex = index;
            renderLuckyDigits();
        },
        addToCart: function () {
            var number = currentDigits.join('');
            alert('Ticket number ' + number + ' added to Bet Cart! Proceed to confirmation.');
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        renderLuckyDigits();

        // Attach digit box click listeners
        for (var i = 0; i < 6; i++) {
            (function (idx) {
                var el = document.getElementById('digitBox-' + idx);
                if (el) {
                    el.addEventListener('click', function () {
                        window.thaiLottoKeypad.selectBox(idx);
                    });
                }
            })(i);
        }

        // Live seconds ticker
        setInterval(function () {
            var secEl = document.getElementById('nextDrawSecs');
            if (secEl) {
                var s = parseInt(secEl.textContent, 10);
                s = s > 0 ? s - 1 : 59;
                secEl.textContent = s < 10 ? '0' + s : String(s);
            }
        }, 1000);
    });
})();
