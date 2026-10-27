/**
 * Player bet page — market and number selection (ticket selector).
 *
 * WHAT THIS OWNS
 * The left-hand card of resources/views/player/bet.blade.php: the market
 * <select>, the digit field, the on-screen keypad and the stake field. It
 * validates a single selection and hands it to the bet slip as a DOM event.
 * It does NOT own the slip itself, the totals or the purchase call — that is
 * resources/js/lottery/bet-slip.js, which listens for the event dispatched
 * here. Keeping the two apart means the keypad cannot silently change money
 * totals and the slip cannot silently change what a selection means.
 *
 * WHERE THE RULES COME FROM
 * Digit count and payout multiplier are NOT hardcoded in this file. The blade
 * renders them onto each <option> as data-digits and data-multiplier straight
 * from config('lottery.markets'), so the browser uses the same rule table the
 * settlement engine uses. If a market is re-priced in configuration, this
 * module follows it with no JavaScript change. A market whose attributes are
 * missing or unreadable is treated as unusable rather than guessed at.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * - It never pads a short number. "7" is not silently turned into "07" for a
 *   2-digit market, because 07 and 70 are different bets and the player, not
 *   this script, decides which one they meant.
 * - It never treats a lottery number as a Number. Numbers stay strings the
 *   whole way through so a leading zero cannot be lost.
 * - It is a convenience layer only. Every rule enforced here is enforced again
 *   server-side by App\Http\Requests\Api\V1\BulkBetRequest and the betting
 *   services; nothing here is a security boundary.
 */
(function () {
    'use strict';

    var STAKE_PATTERN = /^[0-9]{1,12}(\.[0-9]{1,2})?$/;

    /**
     * Read an integer data attribute, returning null when it is absent or not
     * a positive integer. Null means "the server did not tell us", which is
     * always handled as a refusal rather than a default.
     */
    function positiveInt(element, attribute) {
        if (!element) {
            return null;
        }

        var raw = element.getAttribute(attribute);

        if (raw === null || raw.trim() === '') {
            return null;
        }

        var value = Number(raw);

        if (!Number.isFinite(value) || value <= 0 || Math.floor(value) !== value) {
            return null;
        }

        return value;
    }

    /**
     * Read a numeric data attribute that may legitimately be fractional (a
     * payout multiplier). Returns null when absent or not a positive number.
     */
    function positiveNumber(element, attribute) {
        if (!element) {
            return null;
        }

        var raw = element.getAttribute(attribute);

        if (raw === null || raw.trim() === '') {
            return null;
        }

        var value = Number(raw);

        return Number.isFinite(value) && value > 0 ? value : null;
    }

    function selectedOption(select) {
        return select.options[select.selectedIndex] || null;
    }

    function digitsOnly(value) {
        return String(value).replace(/[^0-9]/g, '');
    }

    function init(root) {
        var marketSelect = root.querySelector('#market-select');
        var numberInput = root.querySelector('#selected-number-input');
        var keypad = root.querySelector('#number-keypad');
        var stakeInput = root.querySelector('#stake-amount-input');
        var addButton = root.querySelector('#btn-add-to-slip');
        var status = root.querySelector('[data-selection-status]');

        // Every one of these is required. A partially present card is a template
        // change this module has not been updated for, and doing half the job
        // would be worse than doing none of it.
        if (!marketSelect || !numberInput || !keypad || !stakeInput || !addButton) {
            return;
        }

        function say(message, tone) {
            if (!status) {
                return;
            }

            status.textContent = message || '';
            status.setAttribute('data-tone', tone || 'neutral');
        }

        /**
         * Apply the selected market's rules to the digit field.
         */
        function applyMarket() {
            var option = selectedOption(marketSelect);
            var digits = positiveInt(option, 'data-digits');

            if (digits === null) {
                numberInput.value = '';
                numberInput.setAttribute('disabled', 'disabled');
                addButton.setAttribute('disabled', 'disabled');
                say('This market is not available for selection.', 'error');

                return;
            }

            numberInput.removeAttribute('disabled');
            addButton.removeAttribute('disabled');
            numberInput.setAttribute('maxlength', String(digits));
            numberInput.setAttribute('inputmode', 'numeric');
            numberInput.setAttribute('autocomplete', 'off');
            numberInput.setAttribute('placeholder', new Array(digits + 1).join('-'));
            numberInput.setAttribute('aria-label', 'Enter ' + digits + ' digit' + (digits === 1 ? '' : 's'));

            // Truncating (rather than clearing) keeps the digits the player
            // already typed when they move from a 3-digit to a 2-digit market.
            if (numberInput.value.length > digits) {
                numberInput.value = numberInput.value.slice(0, digits);
            }

            root.setAttribute('data-required-digits', String(digits));
            say('');
        }

        function setNumber(value) {
            var digits = positiveInt(selectedOption(marketSelect), 'data-digits');

            if (digits === null) {
                return;
            }

            numberInput.value = digitsOnly(value).slice(0, digits);
            say('');
        }

        // ---------------------------------------------------------------
        // Keypad
        // ---------------------------------------------------------------
        keypad.addEventListener('click', function (event) {
            var button = event.target.closest('[data-key]');

            if (!button || !keypad.contains(button)) {
                return;
            }

            var key = button.getAttribute('data-key');

            if (key === 'clear') {
                setNumber('');
            } else if (key === 'backspace') {
                setNumber(numberInput.value.slice(0, -1));
            } else if (/^[0-9]$/.test(key)) {
                setNumber(numberInput.value + key);
            }

            // The field keeps focus so a physical keyboard and the on-screen
            // keypad can be used interchangeably.
            if (!numberInput.disabled) {
                numberInput.focus();
            }
        });

        // Typing directly into the field is filtered through the same path.
        numberInput.addEventListener('input', function () {
            setNumber(numberInput.value);
        });

        marketSelect.addEventListener('change', applyMarket);

        // ---------------------------------------------------------------
        // Add to slip
        // ---------------------------------------------------------------
        addButton.addEventListener('click', function () {
            var option = selectedOption(marketSelect);
            var market = option ? option.value : '';
            var digits = positiveInt(option, 'data-digits');
            var multiplier = positiveNumber(option, 'data-multiplier');

            if (!market || digits === null || multiplier === null) {
                say('This market is not available for selection.', 'error');

                return;
            }

            var number = digitsOnly(numberInput.value);

            if (number.length !== digits) {
                // Deliberately not auto-padded: 07 and 70 are different bets.
                say('Enter exactly ' + digits + ' digit' + (digits === 1 ? '' : 's') + ' for this market.', 'error');
                numberInput.focus();

                return;
            }

            var stake = String(stakeInput.value).trim();

            if (!STAKE_PATTERN.test(stake) || Number(stake) <= 0) {
                say('Enter a stake greater than zero, with at most two decimal places.', 'error');
                stakeInput.focus();

                return;
            }

            // The slip is the only component that decides whether this
            // selection is accepted (it enforces the per-slip item ceiling and
            // merges duplicates). It reports the outcome back on the same
            // event object.
            var detail = {
                market: market,
                label: option.getAttribute('data-label') || option.textContent.trim(),
                number: number,
                stake: stake,
                multiplier: multiplier,
                accepted: false,
                reason: '',
            };

            document.dispatchEvent(new CustomEvent('lottery:selection-added', {
                detail: detail,
                bubbles: false,
                cancelable: false,
            }));

            if (detail.accepted) {
                say(detail.label + ' ' + number + ' added to the slip.', 'success');
                setNumber('');
                numberInput.focus();
            } else {
                say(detail.reason || 'This selection was not added to the slip.', 'error');
            }
        });

        // When the countdown reaches the cut-off, selection stops. The
        // countdown module owns the clock; this module only obeys it.
        document.addEventListener('lottery:betting-closed', function () {
            addButton.setAttribute('disabled', 'disabled');
            numberInput.setAttribute('disabled', 'disabled');
            say('Betting for this draw is closed.', 'error');
        });

        applyMarket();
        root.setAttribute('data-ticket-selector-ready', 'true');
    }

    function boot() {
        var roots = document.querySelectorAll('[data-ticket-selector]');

        for (var i = 0; i < roots.length; i++) {
            init(roots[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
