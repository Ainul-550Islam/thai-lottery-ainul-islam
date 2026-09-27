/*
 * National Lottery result surface — progressive enhancement (PROMPT 5, file 26).
 *
 * WHAT THIS FILE IS ALLOWED TO DO
 * ---------------------------------------------------------------------------
 * Improve a form that already works without it. The page is server rendered
 * and the search form is a plain GET; with JavaScript disabled every feature
 * on the page still functions. Nothing here fetches a result, computes a
 * result, or writes one to the DOM.
 *
 * THREE HARD RULES, ENFORCED BY THE ABSENCE OF THE RELEVANT APIS
 * ---------------------------------------------------------------------------
 * 1. NO raw-HTML DOM writes at all - none of the properties or methods that
 *    parse a string as markup are used. The only DOM writes below are to
 *    form control values and to the disabled flag. A result page cannot be
 *    turned into an HTML injection sink by this file.
 *
 * 2. NO numeric conversion of a lottery value, ever - no parseInt, no
 *    Number(), no unary +, no arithmetic. Lottery numbers are strings with
 *    meaningful leading zeros: Number('004615') is 4615, which is a
 *    different draw. The input sanitiser below strips non-digits with a
 *    regex and TRUNCATES with slice(), a string operation that cannot
 *    renumber anything. The file contains exactly one numeric conversion,
 *    parseLengthBound() at the bottom, and it is applied only to the form's
 *    maxlength configuration - never to a draw value.
 *
 * 3. NO date arithmetic. Buddhist-era conversion happens only in
 *    App\Services\Lottery\NationalLotteryDateService. The Buddhist-era
 *    offset literal appears nowhere in this file, and no Date object is
 *    constructed.
 *
 * NO NETWORK CALLS. No fetch, no XHR, no WebSocket. Nothing on this page is
 * client-authoritative, so there is nothing for the browser to ask.
 */

(function nationalLottery() {
    'use strict';

    /**
     * Keep a numeric input to digits only, without ever converting it to a
     * number. slice() truncates a STRING; a leading zero cannot be lost.
     *
     * @param {HTMLInputElement} input
     * @param {number} maxDigits
     */
    function digitsOnly(input, maxDigits) {
        var cleaned = input.value.replace(/[^0-9]/g, '');

        if (cleaned.length > maxDigits) {
            cleaned = cleaned.slice(0, maxDigits);
        }

        if (cleaned !== input.value) {
            input.value = cleaned;
        }
    }

    /**
     * The date box accepts digits plus the separators the server's accepted
     * formats use. Validation itself is the server's job; this only prevents
     * obvious typing noise.
     *
     * @param {HTMLInputElement} input
     * @param {number} maxLength
     */
    function dateCharacters(input, maxLength) {
        var cleaned = input.value.replace(/[^0-9\-/]/g, '');

        if (cleaned.length > maxLength) {
            cleaned = cleaned.slice(0, maxLength);
        }

        if (cleaned !== input.value) {
            input.value = cleaned;
        }
    }

    /**
     * Searching by number and by date at the same time is ambiguous; the API
     * rejects it outright. On the page we simply clear the other box, so the
     * visitor never submits a request that cannot be answered.
     *
     * @param {HTMLFormElement} form
     */
    function wireMutualExclusion(form) {
        var numberInput = form.querySelector('input[name="number"]');
        var dateInput = form.querySelector('input[name="date"]');

        if (numberInput === null || dateInput === null) {
            return;
        }

        numberInput.addEventListener('input', function onNumber() {
            if (numberInput.value !== '' && dateInput.value !== '') {
                dateInput.value = '';
            }
        });

        dateInput.addEventListener('input', function onDate() {
            if (dateInput.value !== '' && numberInput.value !== '') {
                numberInput.value = '';
            }
        });
    }

    /**
     * Drop empty parameters from the submitted query string so a shared link
     * carries only what was actually searched. Disabling a control is the
     * standard way to omit it from a GET submission and requires no HTML
     * construction.
     *
     * @param {HTMLFormElement} form
     */
    function wireEmptyParameterPruning(form) {
        form.addEventListener('submit', function onSubmit() {
            var controls = form.querySelectorAll('input[name], select[name]');
            var index;

            for (index = 0; index < controls.length; index += 1) {
                if (controls[index].value === '') {
                    controls[index].disabled = true;
                }
            }
        });
    }

    function init() {
        var forms = document.querySelectorAll('[data-nl-search]');
        var index;

        for (index = 0; index < forms.length; index += 1) {
            (function bind(form) {
                // A STRING LENGTH bound, never a lottery value.
                var maxLength = parseLengthBound(form.getAttribute('data-nl-max-length'));

                var numberInput = form.querySelector('input[name="number"]');
                var dateInput = form.querySelector('input[name="date"]');

                if (numberInput !== null) {
                    numberInput.addEventListener('input', function onInput() {
                        digitsOnly(numberInput, 6);
                    });
                }

                if (dateInput !== null) {
                    dateInput.addEventListener('input', function onInput() {
                        dateCharacters(dateInput, maxLength);
                    });
                }

                wireMutualExclusion(form);
                wireEmptyParameterPruning(form);
            }(forms[index]));
        }
    }

    /**
     * Parse a LENGTH BOUND - not a lottery value - from an attribute.
     *
     * The only numeric conversion in this file. It is a named function with
     * an explicit comment so that a reviewer grepping for parseInt finds one
     * call site whose input is a maxlength attribute, rather than an inline
     * cast that could later be pointed at a draw value.
     *
     * @param {string|null} raw
     * @returns {number}
     */
    function parseLengthBound(raw) {
        if (raw === null) {
            return 32;
        }

        var digits = raw.replace(/[^0-9]/g, '');

        if (digits === '') {
            return 32;
        }

        var value = window.parseInt(digits, 10);

        if (window.isNaN(value) || value < 1) {
            return 32;
        }

        return Math.min(64, value);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
