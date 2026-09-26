/**
 * Account grade page — display/filter enhancement only.
 * Grade calculation and discount rates remain server-side.
 */

const ready = (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

ready(() => {
    const page = document.querySelector('[data-page="account-grade"]');
    if (!page) {
        return;
    }

    page.setAttribute('data-js', 'on');

    const filter = page.querySelector('[data-grade-filter]');
    const table = page.querySelector('[data-grade-history]');
    if (filter && table) {
        filter.addEventListener('change', () => {
            const value = filter.value;
            const rows = table.querySelectorAll('tbody tr[data-history-grade]');
            rows.forEach((row) => {
                const grade = row.getAttribute('data-history-grade') || '';
                const show = value === '' || grade === value;
                row.hidden = !show;
            });
        });
    }

    // Recalculate posts to the server route — never mutates grade locally.
    const refresh = page.querySelector('[data-grade-refresh]');
    if (refresh) {
        refresh.addEventListener('click', () => {
            // Allow normal form submit; no local override.
        });
    }
});
