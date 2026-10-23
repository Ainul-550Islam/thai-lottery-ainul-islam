/**
 * Deposit Form Guard — Accessible inline validation, CSPRNG idempotency token,
 * and duplicate-submit protection without blocking browser alert() dialogs.
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

    function initDeposit() {
        var form = document.querySelector('form[action*="deposit"]');
        if (!form || form.getAttribute('data-deposit-guard-ready') === 'true') {
            return;
        }
        form.setAttribute('data-deposit-guard-ready', 'true');

        var submitBtn = form.querySelector('button[type="submit"]');
        var amountInput = form.querySelector('input[name="amount"]');
        var idempotencyInput = form.querySelector('input[name="idempotency_key"]');
        var statusBanner = ensureStatusBanner(form);

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
                statusBanner.textContent = 'Please enter a valid positive deposit amount (up to 2 decimal places).';
                statusBanner.classList.remove('hidden');
                return;
            }

            form.setAttribute('data-submitting', 'true');
            form.setAttribute('aria-busy', 'true');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-60', 'cursor-not-allowed');
                submitBtn.textContent = submitBtn.getAttribute('data-loading-label') || 'Processing Deposit…';
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDeposit);
    } else {
        initDeposit();
    }
})();
