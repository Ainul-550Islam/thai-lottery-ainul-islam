/**
 * Public Home live-draw honesty helper.
 *
 * Reads data attributes produced by the server-rendered <x-home.live-draw>
 * component. When the provider is not configured it keeps the honest
 * "LIVE DRAW NOT_CONFIGURED" label and never injects a fake player or URL.
 * If an authorized embed URL is present the operator can open it; this script
 * only manages the status badge and optional lazy frame host — no invented media.
 */
(function () {
    'use strict';

    function apply(root) {
        var live = (root.getAttribute('data-live-status') || 'not_configured').toLowerCase();
        var replay = (root.getAttribute('data-replay-status') || 'not_configured').toLowerCase();
        var host = root.querySelector('[data-live-frame-host]');
        var badge = root.querySelector('.home-badge');

        root.setAttribute('data-live-resolved', 'true');

        if (live !== 'configured') {
            if (host) {
                // Remove any accidental media children; show honest empty state only.
                var kids = host.querySelectorAll('iframe,video,embed,object,source');
                for (var i = 0; i < kids.length; i++) {
                    kids[i].remove();
                }
            }
            if (badge && badge.textContent.indexOf('NOT_CONFIGURED') === -1) {
                badge.textContent = 'NOT_CONFIGURED';
            }
            root.setAttribute('data-live-state', 'not_configured');
        } else {
            root.setAttribute('data-live-state', 'configured');
        }

        root.setAttribute('data-replay-state', replay === 'configured' ? 'configured' : 'not_configured');
    }

    function boot() {
        var nodes = document.querySelectorAll('[data-home-live-draw]');
        for (var i = 0; i < nodes.length; i++) {
            apply(nodes[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
