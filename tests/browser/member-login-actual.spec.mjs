import { expect, test } from '@playwright/test';

// Additive actual-application GET-only coverage. Uses the existing configured
// baseURL and projects. Never replaces the historical /__harness/login test,
// submits credentials, solves a challenge, changes consent, or mutates money.
// Requires the real test application, approved toolchain, vendor and assets.
test('actual member-login action exposes labelled controls and its configured challenge', async ({ page }) => {
    const response = await page.goto('/login', { waitUntil: 'networkidle' });
    expect(response?.status()).toBe(200);
    await expect(page).toHaveURL(/\/login(?:\?|$)/);
    await expect(page.locator('#login-hint')).toBeVisible(); // Distinguishes auth.member-login from legacy auth.login.
    const form = page.locator('form:has(#login-hint)');
    await expect(form).toHaveAttribute('method', /post/i);
    await expect(form.locator('input[name="_token"]')).toHaveCount(1);
    await expect(page.locator('#login')).toHaveAttribute('autocomplete', 'username');
    await expect(page.locator('#password')).toHaveAttribute('autocomplete', 'current-password');
    for (const id of ['login', 'password', 'remember']) {
        const label = page.locator(`label[for="${id}"]`);
        await expect(label).toHaveCount(1);
        expect((await label.innerText()).trim().length).toBeGreaterThan(0);
        await page.locator(`#${id}`).focus();
        await expect(page.locator(`#${id}`)).toBeFocused();
    }
    // A disabled server feature has no challenge control to certify. The
    // separate protected-source audit remains failed until the owner acts;
    // an enabled test environment is required for challenge acceptance.
    const answer = page.locator('#captcha_answer');
    if (await answer.count()) {
        await expect(answer).toBeVisible();
        await expect(answer).toHaveAttribute('required', '');
        const question = answer.locator('..').locator('span').first();
        await expect(question).toBeVisible();
        await expect(question).not.toHaveAttribute('aria-hidden', 'true');
        const questionId = await question.getAttribute('id');
        expect(questionId, 'Security-approved question description id is required').toBeTruthy();
        expect((await answer.getAttribute('aria-describedby') || '').split(/\s+/)).toContain(questionId);
        await answer.focus();
        await expect(answer).toBeFocused();
    }
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
});

test('actual home to member-login preserves screenshots, vitals and page lifecycle through teardown', async ({ page }, testInfo) => {
    const lifecycle = [], failures = [];
    let crashed = false;
    page.on('crash', () => { crashed = true; });
    page.on('pageerror', error => failures.push({ kind: 'pageerror', name: error.name }));
    page.on('console', message => {
        const text = message.text();
        if (text.startsWith('__FRONTEND_LIFECYCLE__:')) lifecycle.push(text);
        else if (message.type() === 'error') failures.push({ kind: 'console-error' });
    });
    page.on('requestfailed', request => failures.push({ kind: 'request-failed', path: new URL(request.url()).pathname }));
    page.on('response', response => {
        if (response.status() >= 400 && new URL(response.url()).origin === new URL(page.url()).origin) {
            failures.push({ kind: 'http-error', path: new URL(response.url()).pathname, status: response.status() });
        }
    });
    await page.setViewportSize(testInfo.project.name.includes('mobile') ? { width: 390, height: 844 } : { width: 1440, height: 900 });
    await page.addInitScript(() => {
        for (const kind of ['pageshow', 'pagehide']) window.addEventListener(kind, () => console.info(`__FRONTEND_LIFECYCLE__:${kind}:${location.pathname}`));
        window.__actualMemberVitals = { lcp: 0, cls: 0 };
        new PerformanceObserver(list => {
            for (const entry of list.getEntries()) window.__actualMemberVitals.lcp = Math.max(window.__actualMemberVitals.lcp, entry.startTime);
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver(list => {
            for (const entry of list.getEntries()) if (!entry.hadRecentInput) window.__actualMemberVitals.cls += entry.value;
        }).observe({ type: 'layout-shift', buffered: true });
    });
    const metrics = {};
    try {
        for (const [name, url] of [['home', '/'], ['member-login', '/login']]) {
            const response = await page.goto(url, { waitUntil: 'networkidle' });
            expect(response?.status()).toBe(200);
            await page.waitForTimeout(120);
            if (name === 'member-login') await expect(page.locator('#login-hint')).toBeVisible();
            metrics[name] = await page.evaluate(() => {
                const nav = performance.getEntriesByType('navigation')[0];
                return { lcpMs: Math.round(window.__actualMemberVitals.lcp || nav?.domContentLoadedEventEnd || 0), cls: window.__actualMemberVitals.cls };
            });
            expect(metrics[name].lcpMs).toBeGreaterThan(0);
            expect(metrics[name].lcpMs).toBeLessThan(2500);
            expect(metrics[name].cls).toBeLessThan(0.05);
            await page.screenshot({ path: testInfo.outputPath(`${name}-viewport.png`), fullPage: false });
            if (name === 'home') await page.screenshot({ path: testInfo.outputPath('home-fullpage.png'), fullPage: true });
            expect(crashed).toBe(false);
        }
        await page.goto('about:blank'); // Trigger actual pagehide; fixtures retain control of context teardown.
        await expect.poll(() => lifecycle.some(line => line === '__FRONTEND_LIFECYCLE__:pagehide:/login')).toBe(true);
        expect(lifecycle).toContain('__FRONTEND_LIFECYCLE__:pageshow:/login');
        expect(lifecycle).toContain('__FRONTEND_LIFECYCLE__:pagehide:/');
        expect(crashed).toBe(false);
        expect(failures).toEqual([]);
    } finally {
        await testInfo.attach('actual-member-lifecycle-vitals.json', { body: JSON.stringify({ lifecycle, metrics, failures, crashed }, null, 2), contentType: 'application/json' });
    }
});
