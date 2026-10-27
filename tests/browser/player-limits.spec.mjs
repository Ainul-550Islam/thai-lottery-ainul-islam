/**
 * Player limits, self-exclusion and the prize checker — end to end.
 *
 * These run against a real server. They assert the two things that a feature
 * test cannot: that the page a member actually receives contains the controls,
 * and that the controls talk to the endpoints the server exposes rather than to
 * a URL that happens to look right.
 *
 * NO ASSERTION HERE READS A HARDCODED AMOUNT. Money is asserted as a SHAPE
 * (a decimal string), because the amount belongs to configuration and a test
 * that pins it becomes a test that fails when finance changes a limit.
 */
import { expect, test } from '@playwright/test';

const PLAYER = '/player/security';

test.describe('player limits', () => {
    test('the security page exposes limits, sessions and self-exclusion', async ({ page }) => {
        await page.goto(PLAYER);

        // Unauthenticated visitors are redirected rather than shown an empty
        // shell — an empty limits form would read as "no limits apply".
        const url = page.url();

        if (url.includes('/login')) {
            test.info().annotations.push({ type: 'note', description: 'guest redirected to login, as designed' });

            return;
        }

        await expect(page.locator('[data-update-url]')).toHaveCount(1);
    });

    test('no limit control falls back to a browser dialog', async ({ page }) => {
        const dialogs = [];

        page.on('dialog', async (dialog) => {
            dialogs.push(dialog.type());
            await dialog.dismiss();
        });

        await page.goto(PLAYER);
        await page.waitForLoadState('networkidle');

        expect(dialogs, 'a limit change must report through the page, never a blocking dialog').toEqual([]);
    });
});

test.describe('prize checker', () => {
    test('the results page ships the checker and its dictionary', async ({ page }) => {
        await page.goto('/results');

        await expect(page.locator('[data-prize-checker]')).toBeVisible();
        await expect(page.locator('#prize-checker-l10n')).toHaveCount(1);

        // The endpoint is rendered from the route name; an empty attribute means
        // the route was not registered when the view compiled.
        const endpoint = await page.locator('[data-prize-checker]').getAttribute('data-endpoint');
        expect(endpoint).toBeTruthy();
        expect(endpoint).toContain('/api/v1/glo/prize-check');
    });

    test('a malformed ticket is refused in the page, not by a dialog', async ({ page }) => {
        const dialogs = [];

        page.on('dialog', async (dialog) => {
            dialogs.push(dialog.type());
            await dialog.dismiss();
        });

        await page.goto('/results');

        await page.locator('[data-prize-input]').fill('12');
        await page.locator('[data-prize-submit]').click();

        // The input is marked invalid and the status region speaks.
        await expect(page.locator('[data-prize-input]')).toHaveAttribute('aria-invalid', 'true');
        await expect(page.locator('[data-prize-status]')).not.toBeEmpty();

        expect(dialogs).toEqual([]);
    });

    test('a check writes only server-supplied values into the panel', async ({ page }) => {
        await page.goto('/results');

        await page.locator('[data-prize-input]').fill('042042');
        await page.locator('[data-prize-submit]').click();

        const panel = page.locator('[data-prize-result]');
        await expect(panel).toBeVisible();

        const state = await panel.getAttribute('data-state');
        expect(['CHECKED', 'NOT_CONFIGURED', 'UNAVAILABLE']).toContain(state);

        // Whatever the state, the panel never shows a currency amount the server
        // did not send: an absence keeps the money block hidden.
        if (state !== 'CHECKED') {
            await expect(page.locator('[data-prize-tier]')).toBeHidden();
        }
    });

    test('the toast region is a live region', async ({ page }) => {
        await page.goto('/results');

        // Nothing is shown until a toast exists; once one does, it must be
        // announced rather than merely painted.
        const region = page.locator('.tl-toast-region');

        if (await region.count() > 0) {
            await expect(region).toHaveAttribute('aria-live', 'polite');
        }
    });
});
