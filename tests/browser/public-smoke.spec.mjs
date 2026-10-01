// TYPE: Playwright browser smoke test
// PURPOSE: Exercise actual public and authentication-entry routes against the running Laravel application without credentials or financial mutation.

import { expect, test } from '@playwright/test';

const publicJourneys = [
    ['home', '/'],
    ['results', '/results'],
    ['lottery hub', '/lotteries'],
    ['GLO L6', '/glo-l6'],
    ['FAQ', '/faq'],
    ['contact', '/contact'],
    ['login entry', '/login'],
    ['register entry', '/register'],
    ['password reset entry', '/forgot-password'],
];

test.describe('public and authentication-entry journeys', () => {
    for (const [name, path] of publicJourneys) {
        test(`${name} renders from the running application`, async ({ page }) => {
            const response = await page.goto(path, { waitUntil: 'domcontentloaded' });
            expect(response).not.toBeNull();
            expect(response.ok()).toBeTruthy();
            await expect(page.locator('body')).not.toBeEmpty();
            await expect(page.locator('body')).not.toContainText('APP_KEY');
            await expect(page.locator('body')).not.toContainText('password_hash');
        });
    }

    test('critical public form surfaces accept keyboard focus', async ({ page }) => {
        await page.goto('/contact', { waitUntil: 'domcontentloaded' });
        await page.keyboard.press('Tab');
        const activeElement = page.locator(':focus');
        await expect(activeElement).toHaveCount(1);
        await expect(activeElement).toBeVisible();
    });
});
