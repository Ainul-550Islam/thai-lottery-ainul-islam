/**
 * Analytics consent (D3).
 *
 * WHY CONSENT IS NOT OPTIONAL HERE
 * --------------------------------
 * This product takes real money from residents of Thailand (PDPA) and
 * Bangladesh, and is reachable from the EU and UK. Both regimes treat
 * analytics cookies as requiring consent. Google's own Consent Mode exists
 * precisely so a tag can load and still behave lawfully — without it, shipping
 * gtag to those visitors is a compliance problem, not a marketing one.
 *
 * The tags in components/analytics/head.blade.php initialise with everything
 * DENIED and `wait_for_update: 600`. This file's only job is to resolve that
 * state before the 600ms expires: either by replaying a stored choice, or by
 * asking.
 *
 * CONTRACT WITH THE HEAD SNIPPET
 * ------------------------------
 * This module must be imported EARLY. It only calls gtag()/fbq() to update
 * consent state; it never loads a tag itself, so ordering against the tag
 * snippets is safe either way. But it must run inside the wait_for_update
 * window, or the page view is recorded under the default denied state and the
 * visitor's real choice only applies from the next page.
 *
 * NO TRACKING WITHOUT A DECISION
 * ------------------------------
 * The banner is not dismissible by clicking away. A banner that can be
 * dismissed by accident records consent that was never given, which is the
 * worst outcome: it looks like opt-in and is not.
 */

const STORAGE_KEY = 'tl-analytics-consent';
const GRANTED = 'granted';
const DENIED = 'denied';

/**
 * Every signal is denied or granted together. There is no partial consent UI,
 * because a visitor cannot meaningfully distinguish "analytics storage" from
 * "ad personalisation", and offering a grid of toggles is a dark pattern that
 * regulators have specifically acted against.
 */
const SIGNALS = [
    'ad_storage',
    'ad_user_data',
    'ad_personalization',
    'analytics_storage',
];

function readStored() {
    try {
        const v = localStorage.getItem(STORAGE_KEY);
        return v === GRANTED || v === DENIED ? v : null;
    } catch (e) {
        return null;
    }
}

function writeStored(value) {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch (e) {
        // Storage blocked: the choice simply will not persist and the visitor
        // will be asked again next page. Annoying, not broken.
    }
}

/** Push the decision into whichever tags are present. */
function applyConsent(decision) {
    const state = {};
    for (const signal of SIGNALS) {
        state[signal] = decision;
    }

    if (typeof window.gtag === 'function') {
        window.gtag('consent', 'update', state);
    }

    // Meta Pixel has its own consent vocabulary.
    if (typeof window.fbq === 'function') {
        window.fbq('consent', decision === GRANTED ? 'grant' : 'revoke');
    }

    document.documentElement.setAttribute('data-analytics-consent', decision);
}

function buildBanner(onDecide) {
    const banner = document.createElement('div');
    banner.setAttribute('role', 'dialog');
    banner.setAttribute('aria-modal', 'false');
    banner.setAttribute('aria-labelledby', 'tl-consent-title');
    banner.setAttribute('aria-describedby', 'tl-consent-body');
    banner.dataset.analyticsBanner = '';

    // Inline styles: this element must render correctly even if the stylesheet
    // has not finished loading, and it must follow the theme tokens.
    Object.assign(banner.style, {
        position: 'fixed',
        left: '0',
        right: '0',
        bottom: '0',
        zIndex: '2147483000',
        background: 'rgb(var(--tl-surface-1-rgb) / 0.98)',
        borderTop: '1px solid rgb(var(--tl-line-rgb) / 1)',
        color: 'rgb(var(--tl-content-primary-rgb) / 1)',
        padding: '1rem 1.25rem',
        fontFamily: 'inherit',
        fontSize: '0.875rem',
        lineHeight: '1.5',
        boxShadow: '0 -8px 24px rgb(0 0 0 / 0.35)',
        display: 'flex',
        flexWrap: 'wrap',
        gap: '0.75rem 1rem',
        alignItems: 'center',
        justifyContent: 'space-between',
    });

    const text = document.createElement('div');
    text.style.flex = '1 1 22rem';

    const title = document.createElement('p');
    title.id = 'tl-consent-title';
    title.style.margin = '0 0 0.25rem';
    title.style.fontWeight = '700';
    title.style.color = 'rgb(var(--tl-content-strong-rgb) / 1)';
    title.textContent = 'Cookies & measurement';

    const body = document.createElement('p');
    body.id = 'tl-consent-body';
    body.style.margin = '0';
    body.textContent =
        'We would like to measure how this site is used so we can improve it. ' +
        'Nothing is shared with advertisers. You can change your choice at any time.';

    text.appendChild(title);
    text.appendChild(body);

    const actions = document.createElement('div');
    Object.assign(actions.style, {
        display: 'flex',
        gap: '0.5rem',
        flexWrap: 'wrap',
    });

    const buttonBase = {
        font: 'inherit',
        fontWeight: '600',
        padding: '0.5rem 1rem',
        borderRadius: '0.75rem',
        border: '1px solid rgb(var(--tl-line-rgb) / 1)',
        cursor: 'pointer',
        background: 'transparent',
        color: 'rgb(var(--tl-content-primary-rgb) / 1)',
    };

    const reject = document.createElement('button');
    reject.type = 'button';
    Object.assign(reject.style, buttonBase);
    reject.textContent = 'Decline';
    reject.addEventListener('click', () => onDecide(DENIED));

    const accept = document.createElement('button');
    accept.type = 'button';
    Object.assign(accept.style, buttonBase, {
        background: 'rgb(var(--tl-gold-rgb) / 1)',
        borderColor: 'rgb(var(--tl-gold-rgb) / 1)',
        color: '#0B0904',
        fontWeight: '700',
    });
    accept.textContent = 'Accept';
    accept.addEventListener('click', () => onDecide(GRANTED));

    actions.appendChild(reject);
    actions.appendChild(accept);

    banner.appendChild(text);
    banner.appendChild(actions);

    return banner;
}

function init() {
    const stored = readStored();

    if (stored) {
        applyConsent(stored);
        return;
    }

    // Nothing stored, so ask. The tags are already loaded but denied, which is
    // the correct holding state.
    const banner = buildBanner((decision) => {
        writeStored(decision);
        applyConsent(decision);
        banner.remove();
    });

    document.body.prepend(banner);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
    init();
}

export { applyConsent, GRANTED, DENIED };
