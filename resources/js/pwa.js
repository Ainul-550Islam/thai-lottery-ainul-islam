/**
 * Progressive Web App registration.
 *
 * C4 FIX. `public/sw.js` existed but no page ever registered it, so the
 * service worker was dead code and the site could not be installed or used
 * offline. This module registers it.
 *
 * Design notes
 * ------------
 * 1. We NEVER auto-reload the page when a new worker takes over. On a
 *    wagering platform a visitor may be part-way through a bet, a deposit or
 *    a withdrawal; silently reloading the document would discard that state.
 *    Instead we dispatch a `tl:pwa-update` event and let the UI decide, at a
 *    safe moment, whether to offer a refresh.
 *
 * 2. Every money- and identity-related route is excluded from caching inside
 *    the service worker itself (see NEVER_CACHE in public/sw.js). This module
 *    deliberately does not try to duplicate that list — the worker is the
 *    single place that decision lives, so the two can never drift apart.
 *
 * 3. Registration is best-effort. Any failure (unsupported browser, insecure
 *    context, blocked by policy) is swallowed: the site must work without a
 *    service worker, because it always did.
 */

const UPDATE_EVENT = 'tl:pwa-update';

function dispatchUpdate() {
    window.dispatchEvent(new CustomEvent(UPDATE_EVENT, {
        detail: { at: Date.now() },
    }));
}

function track(worker) {
    if (!worker) {
        return;
    }

    worker.addEventListener('statechange', () => {
        // 'installed' + an active controller means this is an *update*, not the
        // first install. 'activated' on first install has no controller yet.
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            dispatchUpdate();
        }
    });
}

function register() {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    // Service workers require a secure context. On plain HTTP (other than
    // localhost) registration would throw, so skip it rather than log noise.
    if (!window.isSecureContext) {
        return;
    }

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' })
            .then((registration) => {
                track(registration.installing);
                track(registration.waiting);

                registration.addEventListener('updatefound', () => {
                    track(registration.installing);
                });

                // A worker may already have been waiting before this page
                // loaded (e.g. installed by an earlier tab).
                if (registration.waiting && navigator.serviceWorker.controller) {
                    dispatchUpdate();
                }
            })
            .catch(() => {
                // Intentionally silent: offline capability is an enhancement.
            });
    });

    // When a new worker has taken control, the next navigation uses it. We do
    // not reload automatically — see note 1 above.
    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) {
            return;
        }
        reloading = true;
        // Nothing to do here today; kept as the single hook point for a future
        // "new version available" banner so the logic is not scattered.
    });
}

register();

export { UPDATE_EVENT };
