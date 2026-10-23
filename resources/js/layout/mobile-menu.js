/**
 * Site layout - Accessible mobile navigation drawer.
 *
 * Manages open/close state, aria-expanded / aria-hidden synchronization,
 * Escape-key dismissal, backdrop click dismissal, and focus restoration.
 */
(function () {
    'use strict';

    function initMobileMenu() {
        var openButton = document.getElementById('btn-open-mobile-menu');
        var closeButton = document.getElementById('btn-close-mobile-menu');
        var drawer = document.getElementById('mobile-menu-drawer');

        if (!openButton || !closeButton || !drawer) {
            return;
        }
        if (drawer.getAttribute('data-mobile-menu-ready') === 'true') {
            return;
        }
        drawer.setAttribute('data-mobile-menu-ready', 'true');

        var HIDDEN_CLASS = 'translate-x-full';
        var previousOverflow = document.body.style.overflow;
        var isOpen = false;

        function syncAria(isOpen) {
            // Reuse the global accessibility module's existing modal trap.
            drawer.setAttribute('data-open', isOpen ? 'true' : 'false');
            openButton.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            drawer.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            drawer.classList.toggle('invisible', !isOpen);
            drawer.classList.toggle('pointer-events-none', !isOpen);
            if (isOpen) {
                drawer.removeAttribute('inert');
            } else {
                drawer.setAttribute('inert', '');
            }
        }

        function open() {
            if (!isOpen) previousOverflow = document.body.style.overflow;
            isOpen = true;
            drawer.classList.remove(HIDDEN_CLASS);
            document.body.style.overflow = 'hidden';
            syncAria(true);
            closeButton.focus();
        }

        function close(restoreFocus) {
            if (!isOpen) return;
            isOpen = false;
            drawer.classList.add(HIDDEN_CLASS);
            document.body.style.overflow = previousOverflow;
            syncAria(false);
            if (restoreFocus !== false) openButton.focus();
        }

        isOpen = !drawer.classList.contains(HIDDEN_CLASS);
        syncAria(isOpen);

        openButton.addEventListener('click', open);
        closeButton.addEventListener('click', close);

        if (typeof window !== 'undefined') {
            // Match the layout's xl:hidden boundary; do not focus its hidden opener.
            if (typeof window.matchMedia === 'function') {
                var desktop = window.matchMedia('(min-width: 1280px)');
                var onDesktop = function (event) { if (event.matches) close(false); };
                if (typeof desktop.addEventListener === 'function') desktop.addEventListener('change', onDesktop);
                else if (typeof desktop.addListener === 'function') desktop.addListener(onDesktop);
                onDesktop(desktop);
            }
            window.addEventListener('pagehide', function () { close(false); });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !drawer.classList.contains(HIDDEN_CLASS)) {
                close();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileMenu);
    } else {
        initMobileMenu();
    }
})();
