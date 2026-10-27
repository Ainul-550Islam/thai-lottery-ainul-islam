/**
 * Progressive enhancement for the server-rendered public live-draw card.
 *
 * The backend is authoritative for provider selection, URL validation and
 * status. This script only:
 *   1. removes unsafe/stale media when the server did not report a configured
 *      stream (it never upgrades a status or invents a URL), and
 *   2. upgrades an already-rendered YouTube iframe to muted autoplay, because
 *      every major browser blocks autoplay with sound. The iframe must exist
 *      in the server-rendered DOM first — this module never creates one.
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

    /**
     * Return true only for the two YouTube host shapes the platform embeds:
     * youtube.com/embed/... and the short youtu.be/... form.
     */
    function isYouTubeUrl(url) {
        if (typeof url !== 'string' || url === '') {
            return false;
        }

        return url.indexOf('youtube.com') !== -1 || url.indexOf('youtu.be') !== -1;
    }

    /**
     * Force muted autoplay on a server-rendered YouTube iframe.
     *
     * Autoplay is only permitted while muted, so both params are applied
     * together. Existing query parameters are preserved; autoplay/mute are
     * normalised to 1 regardless of what the stored URL carried.
     */
    function ensureAutoplay(iframe) {
        if (!iframe || iframe.tagName.toLowerCase() !== 'iframe') {
            return;
        }

        var current = iframe.getAttribute('src') || '';
        if (!isYouTubeUrl(current)) {
            return;
        }

        var hashIndex = current.indexOf('#');
        var hash = hashIndex !== -1 ? current.slice(hashIndex) : '';
        var withoutHash = hashIndex !== -1 ? current.slice(0, hashIndex) : current;
        var parts = withoutHash.split('?');
        var base = parts[0];
        var params = parts[1] ? parts[1].split('&') : [];
        var seen = { autoplay: false, mute: false };
        var next = [];

        for (var i = 0; i < params.length; i++) {
            var pair = params[i];
            if (pair === '') {
                continue;
            }

            var name = pair.split('=')[0];
            if (name === 'autoplay') {
                next.push('autoplay=1');
                seen.autoplay = true;
            } else if (name === 'mute') {
                next.push('mute=1');
                seen.mute = true;
            } else {
                next.push(pair);
            }
        }

        if (!seen.autoplay) {
            next.push('autoplay=1');
        }
        if (!seen.mute) {
            next.push('mute=1');
        }

        var rebuilt = base + '?' + next.join('&') + hash;
        if (rebuilt !== current) {
            iframe.setAttribute('src', rebuilt);
        }

        // Reflect the policy on the element itself so allow="autoplay" is
        // present even when the Blade template is older than this script.
        var allow = (iframe.getAttribute('allow') || '').split(';').map(function (token) {
            return token.trim();
        }).filter(Boolean);

        if (allow.indexOf('autoplay') === -1) {
            allow.push('autoplay');
        }
        iframe.setAttribute('allow', allow.join('; '));
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
        } else if (host) {
            // A player survives only because the server rendered one. Upgrade
            // its playback parameters; never construct a new iframe here.
            var frames = host.querySelectorAll('iframe');
            for (var i = 0; i < frames.length; i++) {
                ensureAutoplay(frames[i]);
            }
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
