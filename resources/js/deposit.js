/**
 * Deposit Client Module.
 *
 * Implements client-side deposit submission safety, idempotency token handling,
 * and duplicate-submit prevention.
 */
(function () {
    'use strict';

    function initDepositForm() {
        var form = document.querySelector('form[action*="deposit"]');
        if (!form) return;

        var submitButton = form.querySelector('button[type="submit"]');
        var amountInput = form.querySelector('input[name="amount"]');
        var idempotencyInput = form.querySelector('input[name="idempotency_key"]');

        if (!idempotencyInput && window.crypto && typeof window.crypto.randomUUID === 'function') {
            idempotencyInput = document.createElement('input');
            idempotencyInput.type = 'hidden';
            idempotencyInput.name = 'idempotency_key';
            idempotencyInput.value = window.crypto.randomUUID();
            form.appendChild(idempotencyInput);
        }

        form.addEventListener('submit', function (event) {
            if (form.getAttribute('data-submitting') === 'true') {
                event.preventDefault();
                return false;
            }

            var amount = amountInput ? amountInput.value.trim() : '';
            if (!/^\d+(\.\d{1,2})?$/.test(amount) || !/[1-9]/.test(amount)) {
                event.preventDefault();
                alert('Please enter a valid deposit amount.');
                return false;
            }

            form.setAttribute('data-submitting', 'true');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                var originalText = submitButton.textContent;
                submitButton.textContent = 'Processing Deposit…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDepositForm);
    } else {
        initDepositForm();
    }
})();
