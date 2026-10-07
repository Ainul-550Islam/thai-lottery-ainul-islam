/**
 * SVGDeposit — Premium 3D Glass Interactive Deposit Module.
 *
 * Implements client-side deposit submission safety, idempotency key generation,
 * submit locking, and real-time validation feedback.
 */
(function () {
    'use strict';

    function initDeposit() {
        var form = document.querySelector('form[action*="deposit"]');
        if (!form) return;

        var submitBtn = form.querySelector('button[type="submit"]');
        var amountInput = form.querySelector('input[name="amount"]');
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

            var amount = amountInput ? amountInput.value.trim() : '';
            if (!/^\d+(\.\d{1,2})?$/.test(amount) || !/[1-9]/.test(amount)) {
                e.preventDefault();
                alert('Please enter a valid deposit amount.');
                return false;
            }

            form.setAttribute('data-submitting', 'true');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                submitBtn.textContent = 'Processing Deposit…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDeposit);
    } else {
        initDeposit();
    }
})();
