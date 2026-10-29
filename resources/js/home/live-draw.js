/**
 * Progressive enhancement for the server-rendered public live-draw card.
 *
 * The backend is authoritative for provider selection, URL validation, status
 * AND playback. This script does exactly one thing:
 *
 *   removes unsafe/stale media when the server did not report a configured
 *   stream. It never upgrades a status, invents a URL, creates a player, or
 *   touches one the server rendered.
 *
 * WHY THE RENDERED URL IS NEVER REWRITTEN
 * This module used to force `autoplay=1&mute=1` onto a server-rendered YouTube
 * iframe and to add `allow="autoplay"` itself. Both were playback decisions the
 * server had already made: home.blade.php renders the embed URL and its own
 * `allow` list, and a stored `autoplay=0` is an operator saying "do not start
 * this on load". Overriding it started a video on a public page nobody asked to
 * start one on, and it stayed invisible to any check that only read the state
 * attributes. So the src and the permission attributes are left byte for byte
 * exactly as rendered.
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
        // A configured player survives exactly as the server rendered it. This
        // branch is deliberately empty of DOM writes: nothing here may create an
        // iframe, rewrite its src or widen its permissions.

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
