/**
 * TYPE: shared page enhancement
 * PURPOSE: Local search, accessible accordions, section navigation, keyboard focus and browser print for public Pages 05–14.
 *
 * No request is made and no legal or financial text is sent to an external
 * service. The server-rendered page remains complete without JavaScript.
 */
(() => {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
            return;
        }
        callback();
    };

    const initSearch = (page) => {
        const input = page.querySelector('[data-local-search]');
        const clear = page.querySelector('[data-clear-search]');
        const status = page.querySelector('[data-search-status]');
        const items = Array.from(page.querySelectorAll('[data-search-item]'));
        if (!input || !status || items.length === 0) return;

        const render = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let visible = 0;
            items.forEach((item) => {
                const text = (item.dataset.searchText || item.textContent || '').toLocaleLowerCase();
                const match = query === '' || text.includes(query);
                item.hidden = !match;
                item.classList.toggle('is-search-match', query !== '' && match);
                if (match) visible += 1;
            });

            status.textContent = query === ''
                ? `Showing all ${items.length} items.`
                : `${visible} matching item${visible === 1 ? '' : 's'} shown.`;
        };

        input.addEventListener('input', render);
        page.addEventListener('keydown', (event) => {
            if (event.key === '/' && event.target !== input && event.target instanceof HTMLElement) {
                event.preventDefault();
                input.focus();
            }
        });
        clear?.addEventListener('click', () => {
            input.value = '';
            render();
            input.focus();
        });
    };

    const initAccordions = (page) => {
        page.querySelectorAll('[data-accordion-button]').forEach((button) => {
            const targetId = button.getAttribute('aria-controls');
            const panel = targetId
                ? Array.from(page.querySelectorAll('[id]')).find((element) => element.id === targetId)
                : null;
            if (!panel) return;

            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', open ? 'false' : 'true');
                panel.hidden = open;
            });

            button.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
                const buttons = Array.from(page.querySelectorAll('[data-accordion-button]'));
                const current = buttons.indexOf(button);
                const offset = event.key === 'ArrowDown' ? 1 : -1;
                event.preventDefault();
                buttons[(current + offset + buttons.length) % buttons.length].focus();
            });
        });
    };

    const initPrint = (page) => {
        page.querySelectorAll('[data-print-page]').forEach((button) => {
            button.addEventListener('click', () => window.print());
        });
    };

    const initGradeToggles = (page) => {
        page.querySelectorAll('[data-grade-games-toggle]').forEach((button) => {
            const targetId = button.getAttribute('aria-controls');
            const panel = targetId
                ? Array.from(page.querySelectorAll('[id]')).find((element) => element.id === targetId)
                : null;
            if (!panel) return;

            button.addEventListener('click', () => {
                const open = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', open ? 'false' : 'true');
                panel.hidden = open;
            });
        });
    };

    const initContents = (page) => {
        const links = Array.from(page.querySelectorAll('[data-content-link]'));
        const sections = Array.from(page.querySelectorAll('[data-content-section]'));
        if (links.length === 0 || sections.length === 0 || !('IntersectionObserver' in window)) return;

        const setActive = (id) => links.forEach((link) => link.classList.toggle('is-active', link.dataset.contentLink === id));
        setActive(sections[0].id);
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) setActive(entry.target.id);
            });
        }, { rootMargin: '-18% 0px -70% 0px' });
        sections.forEach((section) => observer.observe(section));
    };

    ready(() => {
        document.querySelectorAll('[data-next-public-page]').forEach((page) => {
            initSearch(page);
            initAccordions(page);
            initGradeToggles(page);
            initPrint(page);
            initContents(page);
        });
    });
})();
