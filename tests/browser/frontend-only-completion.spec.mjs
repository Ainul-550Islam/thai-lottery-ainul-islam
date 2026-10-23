// Actual built Blade pages; public POST responses mocked ONLY in this test.
// No financial mutation, fake production endpoints, or backend route mounting.
import { test, expect } from '@playwright/test';

test('Terms UI and interactive search stay localized in Thai and Bangla', async ({ page }) => {
    for (const [locale, label] of [['th', 'ค้นหาในข้อกำหนดนี้'], ['bn', 'এই শর্তাবলির মধ্যে অনুসন্ধান করুন']]) {
        await page.goto(`/terms?lang=${locale}`);
        const input = page.locator('[data-terms-search]');
        await expect(input).toHaveAccessibleName(label);
        const status = page.locator('[data-terms-search-status]');
        const initial = await status.textContent();
        await input.fill('no-such-section-ai2');
        await expect(status).not.toContainText(/No sections|matching sections/);
        await page.locator('[data-clear-search]').click();
        await expect(status).toHaveText(initial);
        // The shortcut must not prevent entering a slash in an editable field.
        await input.focus();
        await page.keyboard.type('a/b');
        await expect(input).toHaveValue('a/b');
        await expect(page.locator('main')).not.toContainText('Search within these Terms');
        await expect(page.locator('main')).not.toContainText('QUESTIONS ABOUT THESE TERMS?');
    }
});

test('fee preview distinguishes HTTP failures, malformed responses and confirmed server data', async ({ page }) => {
    await page.goto('/fees?lang=th');
    await page.locator('#fee-preview-amount').fill('100.00');
    const form = page.locator('[data-fee-preview-form]');
    const box = page.locator('[data-fee-preview-result]');
    for (const status of [401, 403, 419, 422, 429, 500]) {
        await page.route('**/api/v1/fees/preview', route => route.fulfill({
            status, contentType: 'application/json',
            body: JSON.stringify({ message: 'PRIVATE_EXCEPTION_DO_NOT_ECHO', errors: status === 422 ? { base_amount: ['CHECK_VALUE'] } : undefined }),
        }));
        await form.locator('button[type="submit"]').click();
        await expect(box).toHaveAttribute('role', 'alert');
        await expect(box).not.toContainText('PRIVATE_EXCEPTION_DO_NOT_ECHO');
        await expect(box).not.toContainText('NOT_CONFIGURED · NOT_CONFIGURED');
        if (status === 422) await expect(box).toContainText('CHECK_VALUE');
        await page.unroute('**/api/v1/fees/preview');
    }
    await page.route('**/api/v1/fees/preview', route => route.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }),
    }));
    await form.locator('button[type="submit"]').click();
    await expect(box).toHaveAttribute('role', 'alert');
    await page.unroute('**/api/v1/fees/preview');
    await page.route('**/api/v1/fees/preview', route => route.fulfill({
        status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {
            fee_display: '3.00 THB', base_amount: '100.00', currency: 'THB', rule_version: 'test-only', state: 'CONFIGURED',
        } }),
    }));
    await form.locator('button[type="submit"]').click();
    await expect(box).toHaveAttribute('role', 'status');
    await expect(box).toContainText('3.00 THB');
    await expect(box).not.toContainText('Rule version:');
});

test('public checker preserves leading zeros, sends documented draw_ref field, and locks duplicate submissions', async ({ page }) => {
    await page.goto('/prize-verification?lang=bn');
    await page.locator('#prize-value').fill('000123');
    await page.locator('#prize-draw').fill('DRAW-001');
    let requests = 0;
    let body = '';
    let release;
    const gate = new Promise(resolve => { release = resolve; });
    await page.route('**/prize-verification', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        requests++;
        body = route.request().postData() || '';
        await gate;
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
            success: true, data: { status: 'UNAVAILABLE', reason: '<img src=x onerror=alert(1)>' },
        }) });
    });
    const form = page.locator('[data-prize-form]');
    await form.locator('button[type="submit"]').click();
    await expect(form).toHaveAttribute('aria-busy', 'true');
    await expect(form.locator('button[type="submit"]')).toBeDisabled();
    await form.evaluate(el => { el.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })); });
    await expect.poll(() => requests).toBe(1);
    release();
    const box = page.locator('[data-prize-result]');
    await expect(box).toContainText('UNAVAILABLE');
    await expect(box.locator('img')).toHaveCount(0);
    await expect(form.locator('button[type="submit"]')).toBeEnabled();
    expect(body).toContain('000123');
    expect(body).toContain('name="draw_ref"');
    expect(body).toContain('DRAW-001');
    expect(body).not.toContain('name="draw"');
});

test('purchase boundary is noindex and fee/checker errors cannot expose server exceptions', async ({ page }) => {
    await page.goto('/glo-l6/buy');
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    await page.goto('/prize-verification');
    await page.locator('#prize-value').fill('000123');
    await page.route('**/prize-verification', route => route.fulfill({ status: 500, contentType: 'text/html', body: '<h1>PRIVATE_STACK_TRACE</h1>' }));
    await page.locator('[data-prize-form] button[type="submit"]').click();
    const box = page.locator('[data-prize-result]');
    await expect(box).toHaveAttribute('role', 'alert');
    await expect(box).not.toContainText('PRIVATE_STACK_TRACE');
});
