/**
 * Public informational/legal pages progressive enhancement.
 *
 * - Smooth-scroll-free TOC focus management for Terms anchors.
 * - Marks JS availability on the page root for optional styling.
 * - No innerHTML, no inline script injection, no third-party calls.
 */

const ready = (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

ready(() => {
    const page = document.querySelector('[data-pp-page]');
    if (!page) {
        return;
    }

    page.setAttribute('data-pp-js', 'on');

    // Terms TOC: move focus to the target section for keyboard users.
    const toc = page.querySelector('[data-pp-terms-toc]');
    if (toc) {
        toc.addEventListener('click', (event) => {
            const anchor = event.target.closest('a[href^="#terms-"]');
            if (!anchor) {
                return;
            }
            const id = anchor.getAttribute('href').slice(1);
            const target = document.getElementById(id);
            if (!target) {
                return;
            }
            event.preventDefault();
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: false });
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState(null, '', '#' + id);
            }
        });
    }

    // Skip link: focus main content region.
    const skip = page.querySelector('.pp-skip-link');
    const main = page.querySelector('#pp-main');
    if (skip && main) {
        skip.addEventListener('click', (event) => {
            event.preventDefault();
            main.focus({ preventScroll: false });
        });
    }
});
