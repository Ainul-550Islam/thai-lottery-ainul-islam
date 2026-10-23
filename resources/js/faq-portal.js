'use strict';

/**
 * Progressive enhancement adapter (faq-portal).
 *
 * FAQ filtering operates progressively over server-rendered entries without synthetic client endpoints.
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
        document.querySelectorAll('[data-faq-portal]').forEach(bindStatusNotice);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
