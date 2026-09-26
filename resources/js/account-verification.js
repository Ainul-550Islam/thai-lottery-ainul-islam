/**
 * Account verification page — client enhancement only.
 * Server remains authoritative for every validation and status decision.
 * No DOM HTML injection APIs, no inline handlers, no trust of client status fields.
 */

const ready = (fn) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', fn, { once: true });
    } else {
        fn();
    }
};

ready(() => {
    const page = document.querySelector('[data-page="account-verification"]');
    if (!page) {
        return;
    }

    page.setAttribute('data-js', 'on');

    const input = page.querySelector('[data-document-input]');
    if (input) {
        const hintId = 'document-client-hint';
        let hint = document.getElementById(hintId);
        if (!hint) {
            hint = document.createElement('p');
            hint.id = hintId;
            hint.className = 'acct-hint';
            hint.setAttribute('role', 'status');
            input.insertAdjacentElement('afterend', hint);
        }

        const allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
        const maxKb = 10240;

        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (!file) {
                hint.textContent = '';
                return;
            }

            const ext = (file.name.split('.').pop() || '').toLowerCase();
            const forbidden = ['php', 'phtml', 'phar', 'exe', 'svg', 'sh', 'bat'];
            if (forbidden.includes(ext)) {
                hint.textContent = 'File extension not allowed.';
                input.value = '';
                return;
            }
            if (file.type && !allowed.includes(file.type)) {
                hint.textContent = 'File type not allowed (PDF, JPG, PNG, WEBP).';
                input.value = '';
                return;
            }
            if (file.size > maxKb * 1024) {
                hint.textContent = 'File exceeds size limit.';
                input.value = '';
                return;
            }
            // Client checks are UX only — the server re-validates everything.
            hint.textContent = file.name + ' (' + Math.ceil(file.size / 1024) + ' KB) — will be verified on the server.';
        });
    }

    // Ensure no client-side status fields sneak into the form.
    const form = page.querySelector('[data-account-verification-form]');
    if (form) {
        form.addEventListener('submit', (event) => {
            const banned = ['status', 'approved', 'phone_verified', 'verification_status', 'grade', 'discount', 'fee'];
            for (const name of banned) {
                if (form.elements[name]) {
                    event.preventDefault();
                    return;
                }
            }
        });
    }
});
