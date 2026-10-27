/**
 * Accessibility smoke: zero serious/critical axe violations on the public and
 * authentication-entry surfaces, in both desktop and mobile projects.
 *
 * The pages are deterministic fixtures served entirely by page.route(); they
 * still load the real built app.js so a global control that ships without an
 * accessible name (the toast container, live regions, skip links) is covered.
 */

import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { mountBatch08Page, TEST_L10N } from './batch08-helpers.mjs';

const SURFACES = Object.freeze({
    home: `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/how-to-play">How to play</a><a href="/fees">Fees</a></nav>
        <h1>Published lottery results</h1>
        <p>Source-labelled results and an independent platform guide.</p>
        <a class="skip-link" href="#main">Skip to content</a>
        <section aria-labelledby="draw-title"><h2 id="draw-title">Latest draw</h2><p>No verified draw is published in this fixture.</p></section>`,
    deposit: `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/deposit" aria-current="page">Deposit</a></nav>
        <h1>Deposit</h1>
        <form aria-label="Deposit amount">
            <label for="amount">Amount (THB)</label>
            <input id="amount" name="amount" type="text" inputmode="decimal">
            <label for="method">Payment method</label>
            <select id="method" name="method"><option value="promptpay">PromptPay</option></select>
            <button type="submit">Continue</button>
        </form>
        <p role="status" aria-live="polite">Only methods confirmed by the server are offered.</p>`,
    'how-to-play': `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/how-to-play" aria-current="page">How to play</a></nav>
        <h1>How to play</h1>
        <ol><li>Choose a published draw.</li><li>Confirm your stake.</li><li>Follow the published result.</li></ol>
        <p role="note">This guide does not promise a win, payout or approval.</p>`,
    fees: `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/fees" aria-current="page">Fees</a></nav>
        <h1>Published fees</h1>
        <table><caption>Schedule</caption><thead><tr><th scope="col">Fee</th><th scope="col">Amount</th></tr></thead>
        <tbody><tr><th scope="row">Platform fee</th><td>NOT_CONFIGURED</td></tr></tbody></table>`,
    legal: `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/terms" aria-current="page">Terms</a></nav>
        <h1>Terms of use</h1>
        <section aria-labelledby="scope"><h2 id="scope">Scope</h2><p>An independent lottery information platform.</p></section>
        <a href="/terms/download">Download verified PDF</a>`,
    login: `
        <nav aria-label="Primary"><a href="/">Home</a><a href="/login" aria-current="page">Sign in</a></nav>
        <h1>Sign in</h1>
        <form aria-label="Sign in">
            <label for="email">Email</label><input id="email" name="email" type="email" autocomplete="username">
            <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password">
            <button type="submit">Sign in</button>
        </form>`,
});

for (const [name, content] of Object.entries(SURFACES)) {
    test(`${name} has no serious or critical accessibility violations`, async ({ page }) => {
        await mountBatch08Page(page, {
            route: '/' + name,
            body: `<main id="main">${content}</main>`,
            l10n: TEST_L10N,
        });

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
            .analyze();

        const serious = results.violations
            .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
            .map((violation) => ({ id: violation.id, impact: violation.impact, nodes: violation.nodes.length }));

        expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
        await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    });
}

test('the toast live region is labelled and polite when a realtime event arrives', async ({ page }) => {
    await mountBatch08Page(page, {
        route: '/home',
        body: '<main id="main"><h1>Home</h1><span data-live-balance="wallet" data-currency="THB">0.00 THB</span></main>',
        l10n: TEST_L10N,
    });

    await page.evaluate(() => {
        document.dispatchEvent(new CustomEvent('lottery:wallet-updated', { detail: { balance: '10.00' } }));
    });

    const container = page.locator('#toast-container');
    await expect(container).toHaveAttribute('role', 'status');
    await expect(container).toHaveAttribute('aria-live', 'polite');
    await expect(container.locator('[role="status"]').first()).toBeVisible();
    await expect(page.locator('#toast-container .close-toast').first()).toHaveAttribute('aria-label', 'Dismiss notification');
});
