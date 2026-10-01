/**
 * Withdrawal Client Module.
 *
 * Implements client-side withdrawal submission safety, idempotency token handling,
 * and duplicate-submit prevention.
 */
(function () {
    'use strict';

    function initWithdrawalForm() {
        var form = document.querySelector('form[action*="withdraw"]');
        if (!form) return;

        var submitButton = form.querySelector('button[type="submit"]');
        var amountInput = form.querySelector('input[name="amount"]');
        var methodRadios = form.querySelectorAll('input[name="method"]');
        var bankFields = form.querySelector('[data-bank-fields]');

        function updateMethodDisplay() {
            var selected = form.querySelector('input[name="method"]:checked');
            if (bankFields && selected) {
                if (selected.value === 'bank_transfer') {
                    bankFields.style.display = 'block';
                } else {
                    bankFields.style.display = 'none';
                }
            }
        }

        for (var i = 0; i < methodRadios.length; i++) {
            methodRadios[i].addEventListener('change', updateMethodDisplay);
        }

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

            var amount = parseFloat(amountInput ? amountInput.value : '0');
            if (isNaN(amount) || amount <= 0) {
                event.preventDefault();
                alert('Please enter a valid withdrawal amount.');
                return false;
            }

            form.setAttribute('data-submitting', 'true');
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
                submitButton.textContent = 'Processing Withdrawal…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWithdrawalForm);
    } else {
        initWithdrawalForm();
    }
})();
