/*
 * PCSO Lottery public progressive enhancement.
 *
 * Server services remain authoritative. This file only improves the search
 * form's input affordance and does not contain results, prices, draw dates,
 * purchase actions or fallback data.
 */
(function () {
    'use strict';

    function bootSearch(form) {
        const type = form.querySelector('[name="type"]');
        const term = form.querySelector('[name="term"]');
        const hint = form.querySelector('.wl-search__hint');
        const configuredMax = Number.parseInt(form.dataset.wlMaxLength || '32', 10);
        const widths = {
            '6d': 6,
            '4d': 4,
            '3d': 3,
            '2d': 2,
            date: configuredMax
        };

        if (!type || !term) {
            return;
        }

        function updateInputMode() {
            const selected = type.value || 'date';
            const max = widths[selected] || configuredMax;
            const isDate = selected === 'date';

            term.maxLength = max;
            term.inputMode = isDate ? 'text' : 'numeric';
            term.pattern = isDate ? '' : '[0-9]{' + max + '}';

            if (hint) {
                hint.dataset.activeSearchType = selected;
            }
        }

        type.addEventListener('change', updateInputMode);
        updateInputMode();

        form.addEventListener('submit', function (event) {
            const selected = type.value;
            const value = term.value.trim();
            const max = widths[selected] || configuredMax;

            if (value === '') {
                return;
            }

            if (selected !== 'date' && !new RegExp('^[0-9]{' + max + '}$').test(value)) {
                event.preventDefault();
                term.focus();
                term.setCustomValidity('Enter the exact digit width for the selected PCSO category.');
                term.reportValidity();
                return;
            }

            term.setCustomValidity('');
        });

        term.addEventListener('input', function () {
            term.setCustomValidity('');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-wl-search]').forEach(bootSearch);
    });
}());
