'use strict';

/**
 * Progressive enhancement adapter (how-to-play-portal).
 *
 * How-to-Play steps reflect published product rules without simulated client-side payout calculators.
 * Never invokes browser alert() dialogs, unsafe innerHTML assignments,
 * non-cryptographic randomness, or unmounted API routes.
 */
(function () {
    function bindStatusNotice(root) {
        if (!root || root.dataset.boundEnhanced === '1') {
            return;
        }
        root.dataset.boundEnhanced = '1';
        var forms = root.querySelectorAll('form[data-prevent-double-submit]');
        forms.forEach(function (form) {
            form.addEventListener('submit', function () {
                var submitter = form.querySelector('button[type="submit"], input[type="submit"]');
                if (submitter && !submitter.disabled) {
                    submitter.disabled = true;
                    submitter.setAttribute('aria-busy', 'true');
                }
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-how-to-play-portal]').forEach(bindStatusNotice);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
