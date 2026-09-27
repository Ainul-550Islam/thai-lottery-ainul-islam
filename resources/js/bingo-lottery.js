/*
 * Mega Lottery result surface — progressive enhancement (PROMPT 8, file 26).
 *
 * WHAT THIS FILE IS ALLOWED TO DO
 * ---------------------------------------------------------------------------
 * Improve a form and a table that already work without it. The page is server
 * rendered and the search form is a plain GET; with JavaScript disabled every
 * feature on the page still functions. Nothing here fetches a result,
 * computes a result, or writes one into the DOM.
 *
 * THREE HARD RULES, ENFORCED BY THE ABSENCE OF THE RELEVANT APIS
 * ---------------------------------------------------------------------------
 * 1. No raw-HTML DOM writes at all - none of the properties or methods that
 *    parse a string as markup are used. The only DOM writes below are to form
 *    control values, to the hidden attribute, and to the disabled flag. A
 *    result page cannot be turned into an HTML injection sink by this file.
 *
 * 2. No numeric conversion of a lottery value, ever - no parseInt, no
 *    Number(), no unary +, no arithmetic. Lottery numbers are strings with
 *    meaningful leading zeros: Number('049') is 49, which is a different
 *    result. The input sanitiser strips non-digits with a regex and TRUNCATES
 *    with slice(), a string operation that cannot renumber anything. The file
 *    contains exactly one numeric conversion, parseLengthBound() at the
 *    bottom, applied only to a maxlength attribute.
 *
 * 3. No date arithmetic. Buddhist-era conversion happens only in the shared
 *    calendar service on the server. The Buddhist-era offset literal appears
 *    nowhere in this file, and no Date object is constructed.
 *
 * NO NETWORK CALLS. No fetch, no XHR, no WebSocket. Nothing on this page is
 * client-authoritative, so there is nothing for the browser to ask.
 */

(function bingoLottery() {
    'use strict';

    /* The exact width each search type accepts. Mirrors
     * config('bingo_lottery.search.type_map'); the server re-checks it, so
     * this is a convenience and never a control. 'date' is absent because a
     * date is not a fixed-width digit string. */
    var TYPE_WIDTH = {
        '6mega': 6,
        '3mega': 3,
        '2mega': 2
    };

    /**
     * Keep an input to digits only, without ever converting it to a number.
     * slice() truncates a STRING; a leading zero cannot be lost.
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
     * Apply the selected type's width to the term field.
     *
     * This narrows what a visitor can type; it never rewrites what they
     * already typed into a different value. Switching from 6 Mega to 2 Mega
     * truncates rather than reformats, and the server still validates the
     * exact width.
     *
     * @param {HTMLFormElement} form
     * @param {number} maxLength
     */
    function applyTypeConstraint(form, maxLength) {
        var typeSelect = form.querySelector('select[name="type"]');
        var termInput = form.querySelector('input[name="term"]');

        if (typeSelect === null || termInput === null) {
            return;
        }

        function sync() {
            var type = typeSelect.value;
            var width = Object.prototype.hasOwnProperty.call(TYPE_WIDTH, type)
                ? TYPE_WIDTH[type]
                : null;

            if (width === null) {
                termInput.setAttribute('maxlength', String(maxLength));
                termInput.removeAttribute('pattern');
                dateCharacters(termInput, maxLength);

                return;
            }

            termInput.setAttribute('maxlength', String(width));
            termInput.setAttribute('pattern', '[0-9]{' + String(width) + '}');
            digitsOnly(termInput, width);
        }

        typeSelect.addEventListener('change', sync);
        termInput.addEventListener('input', sync);
        sync();
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
        var forms = document.querySelectorAll('[data-wl-search]');
        var index;

        for (index = 0; index < forms.length; index += 1) {
            (function bind(form) {
                // A STRING LENGTH bound, never a lottery value.
                var maxLength = parseLengthBound(form.getAttribute('data-wl-max-length'));

                applyTypeConstraint(form, maxLength);
                wireEmptyParameterPruning(form);
            }(forms[index]));
        }
    }

    /**
     * Parse a LENGTH BOUND - not a lottery value - from an attribute.
     *
     * The only numeric conversion in this file. It is a named function with an
     * explicit comment so that a reviewer grepping for parseInt finds one call
     * site whose input is a maxlength attribute, rather than an inline cast
     * that could later be pointed at a draw value.
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
