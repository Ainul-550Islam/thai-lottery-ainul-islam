/**
 * About page progressive enhancement.
 *
 * The server owns all content. This script only enhances focus, active
 * timeline state, and reveal styling; it does not fetch or calculate content.
 */
(() => {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    };

    const prefersReducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const initTimeline = (page) => {
        const items = Array.from(page.querySelectorAll('[data-timeline-item]'));
        if (!items.length) return;

        const activate = (item) => {
            items.forEach((candidate) => candidate.classList.toggle('is-active', candidate === item));
        };

        items.forEach((item) => {
            item.addEventListener('focus', () => activate(item));
            item.addEventListener('mouseenter', () => activate(item));
            item.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                event.preventDefault();
                const current = items.indexOf(item);
                const delta = event.key === 'ArrowDown' ? 1 : -1;
                const next = items[(current + delta + items.length) % items.length];
                next.focus();
                activate(next);
            });
        });

        if (!prefersReducedMotion() && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) entry.target.classList.add('is-visible');
                });
            }, { threshold: 0.12 });
            items.forEach((item) => observer.observe(item));
        } else {
            items.forEach((item) => item.classList.add('is-visible'));
        }
    };

    const initReveal = (page) => {
        if (prefersReducedMotion() || !('IntersectionObserver' in window)) return;
        const targets = page.querySelectorAll('.about-step-card, .about-glass-card, .about-link-card');
        const observer = new IntersectionObserver((entries, currentObserver) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                currentObserver.unobserve(entry.target);
            });
        }, { threshold: 0.1 });
        targets.forEach((target) => observer.observe(target));
    };

    ready(() => {
        const page = document.querySelector('[data-about-page]');
        if (!page) return;
        initTimeline(page);
        initReveal(page);
    });
})();
