/**
 * Contact page progressive enhancement (PROMPT 10, file 19).
 *
 * WHAT THIS FILE IS NOT. It is not a form framework, it does not validate,
 * and it does not submit. Remove it entirely and the page still works: the
 * form is a plain POST to a named route and every rule that matters is
 * enforced on the server.
 *
 * WHAT IT ADDS
 *   1. a live character counter, announced politely so a screen reader is not
 *      interrupted on every keystroke;
 *   2. a submit button that disables itself once, which stops the ordinary
 *      double-click before it becomes a second request. The server-side
 *      duplicate window is still the real defence, because a button cannot
 *      stop a retry from a reconnecting phone.
 *
 * NO innerHTML ANYWHERE. Every value written here goes through textContent, so
 * nothing this file touches can introduce markup.
 */
(function contactEnhancements() {
    'use strict';

    function init() {
        var form = document.querySelector('[data-ct-form]');

        if (form === null) {
            return;
        }

        setUpCounter();
        setUpSubmitState(form);
    }

    function setUpCounter() {
        var counter = document.querySelector('[data-ct-counter]');
        var textarea = document.querySelector('[data-ct-counter-target]');

        if (counter === null || textarea === null) {
            return;
        }

        var max = window.parseInt(counter.getAttribute('data-ct-max') || '0', 10);

        if (window.isNaN(max) || max < 1) {
            return;
        }

        // polite, not assertive: a counter must not talk over the person
        // typing.
        counter.setAttribute('aria-live', 'polite');

        var template = counter.getAttribute('data-ct-template');

        function render() {
            var used = textarea.value.length;
            var remaining = max - used;

            // textContent, never innerHTML.
            counter.textContent = template !== null
                ? template.replace(':remaining', String(remaining)).replace(':max', String(max))
                : String(remaining) + ' / ' + String(max);
        }

        textarea.addEventListener('input', render);
        render();
    }

    function setUpSubmitState(form) {
        var button = form.querySelector('[data-ct-submit]');

        if (button === null) {
            return;
        }

        form.addEventListener('submit', function onSubmit() {
            var sending = button.getAttribute('data-ct-sending');

            // Disabled AFTER the submit event, so the button's value still
            // reaches the server on browsers that include it.
            window.setTimeout(function disable() {
                button.setAttribute('disabled', 'disabled');
                button.setAttribute('aria-busy', 'true');

                if (sending !== null && sending !== '') {
                    button.textContent = sending;
                }
            }, 0);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
