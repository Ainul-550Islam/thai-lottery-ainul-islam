/**
 * Site layout - mobile navigation drawer.
 *
 * WHY THIS IS A MODULE AND NOT AN INLINE SCRIPT
 * This behaviour used to live in a <script> block inside layouts/app.blade.php,
 * which meant every page in the application shipped an inline script. An
 * inline script forces any Content-Security-Policy to include
 * script-src 'unsafe-inline', and that single directive defeats most of what a
 * CSP is for: with it enabled, an injected <script> in any unescaped field
 * executes normally. Moving the handler into a bundled module lets the site
 * adopt a strict policy later without having to rewrite the layout first.
 *
 * WHAT IT OWNS
 * The open button, the close button and the drawer itself. Nothing else on the
 * page is touched, and if any of the three elements is absent the module does
 * nothing at all - pages without a mobile drawer are not an error case.
 */
document.addEventListener('DOMContentLoaded', function () {
    var openButton = document.getElementById('btn-open-mobile-menu');
    var closeButton = document.getElementById('btn-close-mobile-menu');
    var drawer = document.getElementById('mobile-menu-drawer');

    if (!openButton || !closeButton || !drawer) {
        return;
    }

    var HIDDEN_CLASS = 'translate-x-full';

    var open = function () {
        drawer.classList.remove(HIDDEN_CLASS);
        // The page behind the drawer must not scroll while it is open.
        document.body.style.overflow = 'hidden';
    };

    var close = function () {
        drawer.classList.add(HIDDEN_CLASS);
        document.body.style.overflow = '';
    };

    openButton.addEventListener('click', open);
    closeButton.addEventListener('click', close);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !drawer.classList.contains(HIDDEN_CLASS)) {
            close();
        }
    });
});
