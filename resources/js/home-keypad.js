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
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var buf = new Uint8Array(6);
            window.crypto.getRandomValues(buf);
            for (var i = 0; i < 6; i++) {
                currentDigits[i] = String(buf[i] % 10);
            }
        } else {
            showKeypadNotice('Secure random generator unavailable in this browser. Enter digits manually.');
            return;
        }
        activeDigitIndex = 5;
        renderLuckyDigits();
    }

    function showKeypadNotice(message) {
        var statusNode = document.getElementById('keypadStatusNotice');
        if (statusNode) {
            statusNode.textContent = message;
            statusNode.classList.remove('hidden');
        }
    }

    window.thaiLotteryKeypad = {
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
            showKeypadNotice('Selected 6-digit number ' + number + '. Sign in to place a verified wager.');
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        renderLuckyDigits();

        for (var i = 0; i < 6; i++) {
            (function (idx) {
                var el = document.getElementById('digitBox-' + idx);
                if (el) {
                    el.addEventListener('click', function () {
                        window.thaiLotteryKeypad.selectBox(idx);
                    });
                }
            })(i);
        }
    });
})();
