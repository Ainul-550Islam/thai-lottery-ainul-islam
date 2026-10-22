/**
 * Theme switcher (C5.2).
 *
 * Works together with a tiny inline script in resources/views/layouts/app.blade.php
 * that sets <html data-theme> BEFORE first paint. That ordering matters: without
 * it the page renders in the default dark palette for a frame and then jumps,
 * which is very visible on a dark-gold design.
 *
 * The inline bootstrap is the only part that must be early; this module only
 * wires the control and keeps the choice in sync afterwards.
 *
 * Rules
 * -----
 * 1. An explicit choice (localStorage) always wins over the OS setting.
 * 2. With no stored choice the module follows prefers-color-scheme live, so a
 *    visitor who changes their system theme sees the site follow.
 * 3. The control reports its state to assistive tech via aria-pressed.
 * 4. Every storage access is guarded: private mode and blocked cookies must
 *    not break the page.
 */

const STORAGE_KEY = 'tl-theme';
const DARK = 'dark';
const LIGHT = 'light';

function readStored() {
    try {
        const v = window.localStorage.getItem(STORAGE_KEY);
        return v === LIGHT || v === DARK ? v : null;
    } catch (_) {
        return null; // storage blocked or unavailable
    }
}

function writeStored(value) {
    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch (_) {
        // Non-fatal: the theme still applies for this page view.
    }
}

function systemPrefers() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches
        ? LIGHT
        : DARK;
}

function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.setAttribute('aria-pressed', theme === LIGHT ? 'true' : 'false');
        const label = btn.querySelector('[data-theme-label]');
        if (label) {
            label.textContent = theme === LIGHT ? 'Dark' : 'Light';
        }
    });
}

function init() {
    // The inline bootstrap already applied a theme; adopt it if present,
    // otherwise compute one. Either way keep the DOM and storage consistent.
    const applied = document.documentElement.getAttribute('data-theme');
    const stored = readStored();
    const theme = applied || stored || systemPrefers();

    if (!applied) {
        apply(theme);
    }

    document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-theme') || DARK;
            const next = current === LIGHT ? DARK : LIGHT;
            apply(next);
            writeStored(next); // storing makes the choice explicit from here on
        });
    });

    // Follow the OS only while the visitor has not made an explicit choice.
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: light)');
        const onChange = (e) => {
            if (readStored() === null) {
                apply(e.matches ? LIGHT : DARK);
            }
        };
        if (typeof mq.addEventListener === 'function') {
            mq.addEventListener('change', onChange);
        } else if (typeof mq.addListener === 'function') {
            mq.addListener(onChange); // Safari < 14
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

export { STORAGE_KEY, DARK, LIGHT };
