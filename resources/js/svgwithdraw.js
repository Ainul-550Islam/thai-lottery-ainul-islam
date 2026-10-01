/**
 * SVGWithdraw — Premium 3D Glass Interactive Withdrawal Module.
 *
 * Implements client-side withdrawal submission safety, dynamic method field display,
 * idempotency token handling, and duplicate submit locking.
 */
(function () {
    'use strict';

    function initWithdraw() {
        var form = document.querySelector('form[action*="withdraw"]');
        if (!form) return;

        var submitBtn = form.querySelector('button[type="submit"]');
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

        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-submitting') === 'true') {
                e.preventDefault();
                return false;
            }

            var amount = parseFloat(amountInput ? amountInput.value : '0');
            if (isNaN(amount) || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid withdrawal amount.');
                return false;
            }

            form.setAttribute('data-submitting', 'true');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                submitBtn.textContent = 'Processing Withdrawal…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWithdraw);
    } else {
        initWithdraw();
    }
})();
