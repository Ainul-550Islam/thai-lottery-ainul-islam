/**
 * Withdrawal Form Guard — Accessible inline validation, dynamic payout method
 * field toggling, CSPRNG idempotency token, and duplicate-submit protection.
 */
(function () {
    'use strict';

    function generateUuidV4Fallback() {
        if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') {
            return '';
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        var hex = '';
        for (var i = 0; i < bytes.length; i++) {
            hex += bytes[i].toString(16).padStart(2, '0');
        }
        return (
            hex.slice(0, 8) + '-' +
            hex.slice(8, 12) + '-' +
            hex.slice(12, 16) + '-' +
            hex.slice(16, 20) + '-' +
            hex.slice(20, 32)
        );
    }

    function ensureStatusBanner(form) {
        var existing = form.querySelector('[data-form-status]');
        if (existing) {
            return existing;
        }
        var banner = document.createElement('div');
        banner.setAttribute('data-form-status', '');
        banner.setAttribute('role', 'alert');
        banner.setAttribute('aria-live', 'polite');
        banner.className = 'hidden rounded-xl border border-rose-500/40 bg-rose-950/60 px-4 py-3 text-xs font-medium text-rose-200';
        form.insertBefore(banner, form.firstChild);
        return banner;
    }

    function initWithdraw() {
        var form = document.querySelector('form[action*="withdraw"]');
        if (!form || form.getAttribute('data-withdraw-guard-ready') === 'true') {
            return;
        }
        form.setAttribute('data-withdraw-guard-ready', 'true');

        var submitBtn = form.querySelector('button[type="submit"]');
        var amountInput = form.querySelector('input[name="amount"]');
        var methodRadios = form.querySelectorAll('input[name="method"]');
        var bankFields = form.querySelector('[data-bank-fields]');
        var statusBanner = ensureStatusBanner(form);

        function updateMethodDisplay() {
            var selected = form.querySelector('input[name="method"]:checked');
            if (bankFields && selected) {
                bankFields.style.display = selected.value === 'bank_transfer' ? 'block' : 'none';
            }
        }

        for (var i = 0; i < methodRadios.length; i++) {
            methodRadios[i].addEventListener('change', updateMethodDisplay);
        }
        updateMethodDisplay();

        var idempotencyInput = form.querySelector('input[name="idempotency_key"]');
        if (!idempotencyInput) {
            var token = (window.crypto && typeof window.crypto.randomUUID === 'function')
                ? window.crypto.randomUUID()
                : generateUuidV4Fallback();
            if (token) {
                idempotencyInput = document.createElement('input');
                idempotencyInput.type = 'hidden';
                idempotencyInput.name = 'idempotency_key';
                idempotencyInput.value = token;
                form.appendChild(idempotencyInput);
            }
        }

        if (amountInput) {
            amountInput.addEventListener('input', function () {
                amountInput.removeAttribute('aria-invalid');
                statusBanner.textContent = '';
                statusBanner.classList.add('hidden');
            });
        }

        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-submitting') === 'true') {
                e.preventDefault();
                return;
            }

            var amount = amountInput ? amountInput.value.trim() : '';
            if (!/^\d+(\.\d{1,2})?$/.test(amount) || !/[1-9]/.test(amount)) {
                e.preventDefault();
                if (amountInput) {
                    amountInput.setAttribute('aria-invalid', 'true');
                    amountInput.focus();
                }
                statusBanner.textContent = 'Please enter a valid positive withdrawal amount (up to 2 decimal places).';
                statusBanner.classList.remove('hidden');
                return;
            }

            form.setAttribute('data-submitting', 'true');
            form.setAttribute('aria-busy', 'true');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
                submitBtn.textContent = submitBtn.getAttribute('data-loading-label') || 'Processing Withdrawal…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWithdraw);
    } else {
        initWithdraw();
    }
})();
