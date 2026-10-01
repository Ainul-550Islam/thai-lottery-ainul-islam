/**
 * Thai Lottery Accessibility & Assistive Technology Engine
 *
 * Implements WCAG 2.1 AA compliant keyboard navigation, ARIA live region announcements,
 * focus trapping for modal dialogs, and high-contrast / reduced-motion preferences.
 */

(function (window, document) {
    'use strict';

    const A11y = {
        liveRegion: null,

        init() {
            this.setupLiveRegion();
            this.setupKeyboardListeners();
            this.setupReducedMotionObserver();
            this.setupFocusTraps();
        },

        setupLiveRegion() {
            let region = document.getElementById('tl-a11y-live-region');
            if (!region) {
                region = document.createElement('div');
                region.id = 'tl-a11y-live-region';
                region.setAttribute('role', 'status');
                region.setAttribute('aria-live', 'polite');
                region.setAttribute('aria-atomic', 'true');
                region.className = 'sr-only';
                document.body.appendChild(region);
            }
            this.liveRegion = region;
        },

        announce(message, priority = 'polite') {
            if (!this.liveRegion) {
                this.setupLiveRegion();
            }
            this.liveRegion.setAttribute('aria-live', priority);
            this.liveRegion.textContent = '';
            setTimeout(() => {
                if (this.liveRegion) {
                    this.liveRegion.textContent = message;
                }
            }, 50);
        },

        setupKeyboardListeners() {
            document.addEventListener('keydown', (e) => {
                // Escape key to dismiss modals and open drawers
                if (e.key === 'Escape') {
                    const openModals = document.querySelectorAll('[role="dialog"][data-open="true"], .tl-modal.is-active');
                    openModals.forEach((modal) => {
                        const closeBtn = modal.querySelector('[data-modal-close]');
                        if (closeBtn && typeof closeBtn.click === 'function') {
                            closeBtn.click();
                        } else {
                            modal.setAttribute('data-open', 'false');
                            modal.classList.remove('is-active');
                        }
                    });
                }
            });
        },

        setupReducedMotionObserver() {
            const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
            const handleReducedMotion = (e) => {
                if (e.matches) {
                    document.documentElement.classList.add('reduced-motion');
                } else {
                    document.documentElement.classList.remove('reduced-motion');
                }
            };
            mediaQuery.addEventListener('change', handleReducedMotion);
            handleReducedMotion(mediaQuery);
        },

        setupFocusTraps() {
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Tab') return;

                const activeModal = document.querySelector('[role="dialog"][data-open="true"], .tl-modal.is-active');
                if (!activeModal) return;

                const focusableElements = activeModal.querySelectorAll(
                    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
                );
                if (focusableElements.length === 0) return;

                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];

                if (e.shiftKey) {
                    if (document.activeElement === firstElement) {
                        lastElement.focus();
                        e.preventDefault();
                    }
                } else {
                    if (document.activeElement === lastElement) {
                        firstElement.focus();
                        e.preventDefault();
                    }
                }
            });
        }
    };

    window.TLA11y = A11y;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => A11y.init());
    } else {
        A11y.init();
    }
})(window, document);
