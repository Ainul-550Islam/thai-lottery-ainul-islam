/**
 * Legacy standalone bet-slip helper.
 *
 * When a canonical [data-bet-form] surface is present on the page,
 * resources/js/lottery/bet-slip.js owns transport, idempotency journaling,
 * and reconciliation exclusively so listeners never collide.
 */
(function () {
    'use strict';

    function initLegacyBetSlipFallback() {
        var container = document.getElementById('bet-slip-container');
        if (!container) {
            return;
        }
        if (
            container.closest('form[data-bet-form]') ||
            document.querySelector('form[data-bet-form]') ||
            container.getAttribute('data-bet-slip-ready') === 'true'
        ) {
            return;
        }

        var statusAlert = container.querySelector('[data-slip-status]');
        var placeBtn = container.querySelector('#btn-place-bet');
        if (placeBtn) {
            placeBtn.disabled = true;
        }
        if (statusAlert) {
            statusAlert.textContent = 'Wager submission requires an active authenticated draw form.';
            statusAlert.classList.remove('hidden');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLegacyBetSlipFallback);
    } else {
        initLegacyBetSlipFallback();
    }
})();
