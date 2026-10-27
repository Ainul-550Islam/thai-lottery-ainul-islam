/**
 * Terms page progressive enhancement.
 *
 * The server renders the legal source. This file provides local search,
 * section navigation, mobile contents controls, and print support only.
 */
(() => {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    };

    const initContents = (page) => {
        const toggle = page.querySelector('[data-contents-toggle]');
        const list = page.querySelector('#terms-contents-list');
        if (!toggle || !list) return;

        toggle.addEventListener('click', () => {
            const open = list.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        page.querySelectorAll('[data-section-link]').forEach((link) => {
            link.addEventListener('click', () => {
                if (window.innerWidth <= 959) {
                    list.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && list.classList.contains('is-open')) {
                list.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
                toggle.focus();
            }
        });
    };

    const initActiveSection = (page) => {
        const sections = Array.from(page.querySelectorAll('[data-term-section]'));
        const links = Array.from(page.querySelectorAll('[data-section-link]'));
        if (!sections.length || !links.length || !('IntersectionObserver' in window)) return;

        const setActive = (id) => {
            links.forEach((link) => link.classList.toggle('is-active', link.dataset.sectionLink === id));
        };

        setActive(sections[0].id);
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) setActive(entry.target.id);
            });
        }, { rootMargin: '-18% 0px -70% 0px', threshold: 0 });

        sections.forEach((section) => observer.observe(section));
    };

    const initKeyboardContents = (page) => {
        const links = Array.from(page.querySelectorAll('[data-section-link]'));
        links.forEach((link) => {
            link.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                event.preventDefault();
                const current = links.indexOf(link);
                const delta = event.key === 'ArrowDown' ? 1 : -1;
                links[(current + delta + links.length) % links.length].focus();
            });
        });
    };

    const initSearch = (page) => {
        const input = page.querySelector('[data-terms-search]');
        const clear = page.querySelector('[data-clear-search]');
        const status = page.querySelector('[data-terms-search-status]');
        const sections = Array.from(page.querySelectorAll('[data-term-section]'));
        if (!input || !status || !sections.length) return;

        const render = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visible = 0;

            sections.forEach((section) => {
                const searchable = (section.dataset.searchableText || '').toLocaleLowerCase();
                const match = query === '' || searchable.includes(query);
                section.classList.toggle('is-search-hidden', !match);
                section.classList.toggle('is-search-match', query !== '' && match);
                if (match) visible += 1;
            });

            if (query === '') {
                status.textContent = `Showing all ${sections.length} sections.`;
            } else if (visible === 0) {
                status.textContent = `No sections match “${input.value.trim()}”.`;
            } else {
                status.textContent = `${visible} matching section${visible === 1 ? '' : 's'} shown.`;
            }
        };

        input.addEventListener('input', render);
        page.addEventListener('keydown', (event) => {
            if (event.key === '/' && event.target !== input && event.target instanceof HTMLElement) {
                event.preventDefault();
                input.focus();
            }
        });
        if (clear) {
            clear.addEventListener('click', () => {
                input.value = '';
                render();
                input.focus();
            });
        }
    };

    const initPrint = (page) => {
        page.querySelectorAll('[data-print-terms]').forEach((button) => {
            button.addEventListener('click', () => window.print());
        });
    };

    ready(() => {
        const page = document.querySelector('[data-terms-page]');
        if (!page) return;
        initContents(page);
        initActiveSection(page);
        initKeyboardContents(page);
        initSearch(page);
        initPrint(page);
    });
})();
