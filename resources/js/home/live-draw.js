/**
 * Progressive enhancement for the server-rendered public live-draw card.
 *
 * The backend is authoritative for provider selection, URL validation and
 * status. This script only removes unsafe/stale media when the server did not
 * report a configured stream; it never upgrades a status or invents a URL.
 */
(function () {
    'use strict';

    var VALID_STATES = ['configured', 'not_configured', 'unavailable'];

    function removeMedia(host) {
        if (!host) {
            return;
        }

        var media = host.querySelectorAll('iframe,video,embed,object,source');
        for (var i = 0; i < media.length; i++) {
            media[i].remove();
        }
    }

    function apply(root) {
        var reportedLive = (root.getAttribute('data-live-status') || 'unavailable').toLowerCase();
        var live = VALID_STATES.indexOf(reportedLive) === -1 ? 'unavailable' : reportedLive;
        var reportedReplay = (root.getAttribute('data-replay-status') || 'not_configured').toLowerCase();
        var replay = reportedReplay === 'configured' ? 'configured' : 'not_configured';
        var host = root.querySelector('[data-live-frame-host]');

        // Configured is the only state allowed to retain a player. An outage is
        // not the same thing as an absent configuration, so preserve that
        // distinction in the DOM and leave the server-rendered badge untouched.
        if (live !== 'configured') {
            removeMedia(host);
        }

        root.setAttribute('data-live-resolved', 'true');
        root.setAttribute('data-live-state', live);
        root.setAttribute('data-replay-state', replay);
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
