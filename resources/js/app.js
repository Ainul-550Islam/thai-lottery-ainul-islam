import './bootstrap';
import './echo';
import './accessibility';
import './auth-portal';
import './svgbet-slip';
import './svgdeposit';
import './svgwithdraw';
import './notifications.js';
import '../css/frontend-wiring.css';

/**
 * Page modules are code-split on purpose. A module is fetched only when the
 * page actually contains its root element, so the public pages do not carry
 * the deposit, slip, illustration or settings bundles. Vite turns each
 * dynamic import into its own chunk; the static imports above stay in the
 * shared entry because they own global behaviour (CSRF, realtime, a11y,
 * toasts, design tokens).
 *
 * The realtime bridge (echo.js) keeps its `echo.init()` contract. This entry
 * only imports it; nothing here opens a second socket or starts a poller.
 */
const PAGE_MODULES = Object.freeze([
    ['#deposit-lanes', () => import('./deposit-portal.js')],
    ['#truemoney-redeem', () => import('./truemoney-redeem.js')],
    ['#slip-upload', () => import('./slip-upload.js')],
    ['#payout-illustration', () => import('./how-to-play-portal.js')],
    ['#notification-preferences', () => import('./player-settings.js')],
    ['[data-legal-download]', () => import('./legal-downloads.js')],
]);

let pageModulesLoaded = false;

function loadPageModules() {
    if (pageModulesLoaded) {
        return;
    }
    pageModulesLoaded = true;

    PAGE_MODULES.forEach(([selector, loader]) => {
        if (!document.querySelector(selector)) {
            return;
        }

        // A failed chunk fetch must not take the rest of the page down; the
        // server-rendered content and the noscript fallbacks remain usable.
        Promise.resolve()
            .then(loader)
            .catch(() => undefined);
    });
}

// Global application bootstrap and live notification dispatchers
window.addEventListener('DOMContentLoaded', () => {
    // Accessibility announcement on page load for screen readers
    if (window.TLA11y) {
        window.TLA11y.setupLiveRegion();
    }

    loadPageModules();
});

if (document.readyState !== 'loading') {
    loadPageModules();
}
