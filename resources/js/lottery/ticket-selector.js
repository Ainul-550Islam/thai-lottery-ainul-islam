/**
 * Lottery Ticket Selector Module
 *
 * Preserves leading zeros on all lottery numbers as exact strings, validates
 * exact digit counts and decimal stake strings with BigInt satang bounds,
 * renders an accessible numeric keypad, and enforces sticky betting closure.
 */
(function () {
    'use strict';

    var MAX_STAKE_SATANG = 99999999999999n;

    function normalizeStake(raw) {
        var trimmed = String(raw || '').trim();
        var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(trimmed);
        if (!match) {
            return null;
        }
        var whole = BigInt(match[1]);
        var fracStr = (match[2] || '').padEnd(2, '0');
        var frac = BigInt(fracStr);
        var satang = whole * 100n + frac;
        if (satang <= 0n || satang > MAX_STAKE_SATANG) {
            return null;
        }
        return whole.toString() + '.' + fracStr;
    }

    function initTicketSelector() {
        var root = document.querySelector('[data-ticket-selector]');
        if (!root || root.getAttribute('data-ticket-selector-ready') === 'true') {
            return;
        }
        root.setAttribute('data-ticket-selector-ready', 'true');

        var marketSelect = root.querySelector('#market-select');
        var numberInput = root.querySelector('#selected-number-input');
        var stakeInput = root.querySelector('#stake-amount-input');
        var addButton = root.querySelector('#btn-add-to-slip');
        var keypad = root.querySelector('#number-keypad');
        var statusEl = root.querySelector('[data-selection-status]');

        if (!marketSelect || !numberInput || !stakeInput || !addButton) {
            return;
        }

        var bettingClosed = false;
        var keypadButtons = [];

        function setStatus(message, tone) {
            if (!statusEl) {
                return;
            }
            statusEl.textContent = message || '';
            statusEl.setAttribute('data-tone', tone || 'info');
            statusEl.setAttribute('role', tone === 'error' ? 'alert' : 'status');
            statusEl.setAttribute('aria-live', 'polite');
        }

        function selectedOption() {
            if (marketSelect.selectedIndex >= 0) {
                return marketSelect.options[marketSelect.selectedIndex];
            }
            return marketSelect.querySelector('option');
        }

        function expectedDigits() {
            var opt = selectedOption();
            if (!opt) {
                return 0;
            }
            var parsed = parseInt(opt.getAttribute('data-digits') || '0', 10);
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        }

        function syncInputConstraints() {
            var digits = expectedDigits();
            if (digits > 0) {
                numberInput.setAttribute('maxlength', String(digits));
                numberInput.setAttribute('inputmode', 'numeric');
                numberInput.setAttribute('autocomplete', 'off');
                var current = String(numberInput.value || '').replace(/\D+/g, '');
                if (current.length > digits) {
                    numberInput.value = current.slice(0, digits);
                }
            }
            if (bettingClosed) {
                applyClosedLock();
            }
        }

        function applyClosedLock() {
            bettingClosed = true;
            marketSelect.disabled = true;
            numberInput.disabled = true;
            stakeInput.disabled = true;
            addButton.disabled = true;
            for (var i = 0; i < keypadButtons.length; i++) {
                keypadButtons[i].disabled = true;
            }
        }

        function bindKeypadAction(btn, action, digitValue) {
            btn.addEventListener('click', function () {
                if (bettingClosed || numberInput.disabled) {
                    return;
                }
                var digits = expectedDigits();
                var val = String(numberInput.value || '').replace(/\D+/g, '');
                if (action === 'clear') {
                    numberInput.value = '';
                } else if (action === 'backspace') {
                    numberInput.value = val.slice(0, -1);
                } else if (digits === 0 || val.length < digits) {
                    numberInput.value = val + digitValue;
                }
                numberInput.focus();
            });
            keypadButtons.push(btn);
        }

        function buildKeypad() {
            if (!keypad) {
                return;
            }
            var existingButtons = keypad.querySelectorAll('button');
            if (existingButtons.length > 0) {
                for (var eIdx = 0; eIdx < existingButtons.length; eIdx++) {
                    var existingBtn = existingButtons[eIdx];
                    var rawKey = String(existingBtn.getAttribute('data-key') || existingBtn.textContent || '').trim().toLowerCase();
                    if (rawKey === 'clear' || rawKey === 'clr') {
                        bindKeypadAction(existingBtn, 'clear', '');
                    } else if (rawKey === 'backspace' || rawKey === 'del' || rawKey === '⌫') {
                        bindKeypadAction(existingBtn, 'backspace', '');
                    } else if (/^\d$/.test(rawKey)) {
                        bindKeypadAction(existingBtn, 'digit', rawKey);
                    }
                }
                return;
            }
            var keys = [
                { label: '1', action: 'digit', aria: 'Digit 1' },
                { label: '2', action: 'digit', aria: 'Digit 2' },
                { label: '3', action: 'digit', aria: 'Digit 3' },
                { label: '4', action: 'digit', aria: 'Digit 4' },
                { label: '5', action: 'digit', aria: 'Digit 5' },
                { label: '6', action: 'digit', aria: 'Digit 6' },
                { label: '7', action: 'digit', aria: 'Digit 7' },
                { label: '8', action: 'digit', aria: 'Digit 8' },
                { label: '9', action: 'digit', aria: 'Digit 9' },
                { label: 'CLR', action: 'clear', aria: 'Clear selected number' },
                { label: '0', action: 'digit', aria: 'Digit 0' },
                { label: 'DEL', action: 'backspace', aria: 'Delete last digit' },
            ];

            for (var i = 0; i < keys.length; i++) {
                var keySpec = keys[i];
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = keySpec.label;
                btn.setAttribute('aria-label', keySpec.aria);
                btn.setAttribute('data-keypad-key', keySpec.label);
                btn.className = 'min-h-[44px] min-w-[44px] rounded-xl border border-slate-700/80 bg-slate-900/90 px-3 py-2.5 text-sm font-bold text-amber-100 transition hover:border-amber-400/60 hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40';
                bindKeypadAction(btn, keySpec.action, keySpec.label);
                keypad.appendChild(btn);
            }
        }

        function handleAddSelection() {
            if (bettingClosed) {
                setStatus('Betting is closed for this draw.', 'error');
                return;
            }

            var opt = selectedOption();
            if (!opt || !opt.value) {
                setStatus('Select a valid market before adding a number.', 'error');
                return;
            }

            var digits = expectedDigits();
            var rawNumber = String(numberInput.value || '').trim();
            if (!/^\d+$/.test(rawNumber) || (digits > 0 && rawNumber.length !== digits)) {
                setStatus('Enter an exact ' + (digits || 1) + '-digit number (including leading zeros).', 'error');
                numberInput.setAttribute('aria-invalid', 'true');
                return;
            }

            var normalizedStake = normalizeStake(stakeInput.value);
            if (!normalizedStake) {
                setStatus('Enter a valid stake between 0.01 and 999,999,999,999.99 THB.', 'error');
                stakeInput.setAttribute('aria-invalid', 'true');
                return;
            }

            numberInput.removeAttribute('aria-invalid');
            stakeInput.removeAttribute('aria-invalid');

            var detail = {
                market: String(opt.value),
                label: String(opt.getAttribute('data-label') || opt.textContent || opt.value).trim(),
                number: rawNumber,
                stake: normalizedStake,
                multiplier: String(opt.getAttribute('data-multiplier') || '0').trim(),
                accepted: false,
                rejectionReason: '',
            };

            document.dispatchEvent(new CustomEvent('lottery:selection-added', { detail: detail }));

            if (detail.accepted === true) {
                numberInput.value = '';
                setStatus('Added ' + rawNumber + ' (' + normalizedStake + ' THB) to bet slip.', 'success');
            } else {
                setStatus(
                    detail.rejectionReason || 'Selection could not be added while bet slip is locked or full.',
                    'error'
                );
            }
        }

        marketSelect.addEventListener('change', function () {
            syncInputConstraints();
            if (!bettingClosed) {
                setStatus('', 'info');
            }
        });

        numberInput.addEventListener('input', function () {
            var digits = expectedDigits();
            var cleaned = String(numberInput.value || '').replace(/\D+/g, '');
            if (digits > 0 && cleaned.length > digits) {
                cleaned = cleaned.slice(0, digits);
            }
            if (numberInput.value !== cleaned) {
                numberInput.value = cleaned;
            }
            numberInput.removeAttribute('aria-invalid');
        });

        numberInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleAddSelection();
            }
        });

        stakeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleAddSelection();
            }
        });

        addButton.addEventListener('click', handleAddSelection);

        document.addEventListener('lottery:betting-closed', function () {
            applyClosedLock();
            setStatus('Betting is closed for this draw.', 'error');
        });

        buildKeypad();
        syncInputConstraints();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTicketSelector);
    } else {
        initTicketSelector();
    }
})();
