/*
|--------------------------------------------------------------------------
| Prize verification form helper (PROMPT 4)
|--------------------------------------------------------------------------
|
| PROGRESSIVE ENHANCEMENT ONLY. The page is a plain HTML form that POSTs and
| re-renders server-side; this file only swaps the visible hint and applies a
| per-mode maxlength so a user is not silently truncated by the server.
|
| IT DECIDES NOTHING. There is no verification logic here, no prize logic, no
| authenticity logic, and no fetch() to a verification endpoint. A verdict is
| produced exclusively by App\Services\Lottery\PrizeVerificationService on the
| server; anything this file did would be untrusted input either way.
|
| LEADING ZEROS. The six-digit input is filtered to digits as a STRING. It is
| never parsed with parseInt or Number, because 007123 must stay "007123".
*/

(function () {
    'use strict';

    function setup(form) {
        const valueInput = form.querySelector('[data-pd-value]');
        const modeInputs = Array.from(form.querySelectorAll('[data-pd-mode-option]'));

        if (!valueInput || modeInputs.length === 0) {
            return;
        }

        const limits = {
            number: parseInt(form.dataset.pdNumberMax || '6', 10),
            reference: parseInt(form.dataset.pdReferenceMax || '64', 10),
            barcode: parseInt(form.dataset.pdBarcodeMax || '512', 10),
        };

        function currentMode() {
            const checked = modeInputs.find((input) => input.checked);

            return checked ? checked.value : 'number';
        }

        function applyMode() {
            const mode = currentMode();

            // Hints: show only the one belonging to the selected mode.
            form.querySelectorAll('[data-pd-hint]').forEach((hint) => {
                hint.hidden = hint.dataset.pdHint !== mode;
            });

            const max = limits[mode] || limits.barcode;
            valueInput.setAttribute('maxlength', String(max));

            if (mode === 'number') {
                valueInput.setAttribute('inputmode', 'numeric');
                valueInput.setAttribute('pattern', '[0-9]*');
            } else {
                valueInput.setAttribute('inputmode', 'text');
                valueInput.removeAttribute('pattern');
            }
        }

        function filterValue() {
            if (currentMode() !== 'number') {
                return;
            }

            // String operations only. No numeric coercion anywhere.
            const digitsOnly = valueInput.value.replace(/\D+/g, '');

            if (digitsOnly !== valueInput.value) {
                valueInput.value = digitsOnly;
            }
        }

        modeInputs.forEach((input) => {
            input.addEventListener('change', function () {
                applyMode();
                filterValue();
            });
        });

        valueInput.addEventListener('input', filterValue);

        form.addEventListener('reset', function () {
            window.setTimeout(applyMode, 0);
        });

        applyMode();
    }

    function boot() {
        document.querySelectorAll('[data-pd-form="verification"]').forEach(setup);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
