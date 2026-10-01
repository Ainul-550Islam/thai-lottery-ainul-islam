/**
 * Vision & Mission progressive enhancement.
 *
 * Content is rendered by the server. This script adds accessible active
 * states and light reveal behavior only; it does not poll or calculate data.
 */
(() => {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    };

    const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const activateGroup = (items, item) => {
        items.forEach((candidate) => candidate.classList.toggle('is-active', candidate === item));
    };

    const initKeyboardGroups = (page, selector) => {
        const items = Array.from(page.querySelectorAll(selector));
        if (!items.length) return;

        items.forEach((item) => {
            item.addEventListener('focus', () => activateGroup(items, item));
            item.addEventListener('click', () => activateGroup(items, item));
            item.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
                event.preventDefault();
                const current = items.indexOf(item);
                const delta = event.key === 'ArrowRight' ? 1 : -1;
                const next = items[(current + delta + items.length) % items.length];
                next.focus();
                activateGroup(items, next);
            });
        });
    };

    const initReveal = (page) => {
        const targets = page.querySelectorAll('.vision-clear-card, .vision-value-card, .vision-journey-card');
        if (reducedMotion() || !('IntersectionObserver' in window)) {
            targets.forEach((target) => target.classList.add('is-visible'));
            return;
        }

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
        const page = document.querySelector('[data-vision-page]');
        if (!page) return;
        initKeyboardGroups(page, '[data-clear-value]');
        initKeyboardGroups(page, '[data-journey-stage]');
        initReveal(page);
    });
})();
