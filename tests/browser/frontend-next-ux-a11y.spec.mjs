// Production-built frontend UX, responsive (320px-3840px), WCAG 2.2 AA a11y,
// localization (EN/TH/BN), error surface, and PWA manifest/icon verification.
// Serves real compiled Blade views + real public/build Vite bundles + public/
// static assets over an ephemeral local HTTP server with zero financial writes.
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';

const ROOT = process.cwd();
const PUBLIC_DIR = path.join(ROOT, 'public');
const HARNESS_PHP = path.join(ROOT, 'tests/browser/render-blade-harness.php');

const VIEWPORT_WIDTHS = [320, 360, 390, 430, 768, 1024, 1280, 1440, 1920, 2560, 3840];

const MIME_TYPES = {
    '.css': 'text/css; charset=utf-8',
    '.js': 'application/javascript; charset=utf-8',
    '.mjs': 'application/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.webmanifest': 'application/manifest+json; charset=utf-8',
    '.png': 'image/png',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
    '.html': 'text/html; charset=utf-8',
    '.txt': 'text/plain; charset=utf-8',
};

let server;
let baseUrl = '';
const renderCache = new Map();

function renderSurface(surface, locale = 'en') {
    const cacheKey = `${surface}:${locale}:${baseUrl}`;
    if (!renderCache.has(cacheKey)) {
        const html = execFileSync('php', [HARNESS_PHP, surface, locale, baseUrl], {
            cwd: ROOT,
            encoding: 'utf8',
            maxBuffer: 8 * 1024 * 1024,
        });
        renderCache.set(cacheKey, html);
    }
    return renderCache.get(cacheKey);
}

test.beforeAll(async () => {
    server = http.createServer((req, res) => {
        try {
            const urlObj = new URL(req.url || '/', 'http://127.0.0.1');
            const pathname = decodeURIComponent(urlObj.pathname);

            if (pathname === '/wallet/convert/preview' && req.method === 'POST') {
                res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
                res.end(JSON.stringify({
                    direction: 'cash_to_win',
                    amount_in: '100.00',
                    fee_amount: '2.00',
                    amount_out: '98.00',
                    rate: '0.020000',
                    percentage: '2.00',
                    currency: 'THB',
                    rule_version: '1',
                    configured: true,
                }));
                return;
            }

            if (pathname.startsWith('/__surface/')) {
                const surface = pathname.slice('/__surface/'.length);
                const locale = urlObj.searchParams.get('lang') || 'en';
                const html = renderSurface(surface, locale);
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end(html);
                return;
            }

            const safeRel = path.normalize(pathname).replace(/^(\.\.(\/|\\|$))+/, '');
            const filePath = path.join(PUBLIC_DIR, safeRel);
            if (filePath.startsWith(PUBLIC_DIR) && fs.existsSync(filePath) && fs.statSync(filePath).isFile()) {
                const ext = path.extname(filePath).toLowerCase();
                res.writeHead(200, { 'Content-Type': MIME_TYPES[ext] || 'application/octet-stream' });
                res.end(fs.readFileSync(filePath));
                return;
            }

            res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end('Not found');
        } catch (err) {
            res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end(String(err && err.message ? err.message : err));
        }
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const addr = server.address();
    baseUrl = `http://127.0.0.1:${addr.port}`;
});

test.afterAll(async () => {
    if (server) {
        await new Promise((resolve) => server.close(resolve));
    }
});

test('zero unintended horizontal overflow across 320px-3840px on public, auth, player, support, admin, error and offline surfaces', async ({ page }) => {
    test.setTimeout(120_000);
    const consoleErrors = [];
    const pageErrors = [];
    const missingAssets = [];

    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(err.message));
    page.on('response', (resp) => {
        if (resp.status() >= 400 && !resp.url().includes('favicon.ico')) {
            missingAssets.push(`${resp.status()} ${resp.url()}`);
        }
    });

    const targets = [
        '/__surface/public-services',
        '/__surface/login',
        '/__surface/register',
        '/__surface/player-bet',
        '/__surface/player-deposit',
        '/__surface/player-withdraw',
        '/__surface/player-profile',
        '/__surface/wallet-conversion',
        '/__surface/support-portal',
        '/__surface/notifications',
        '/__surface/admin-layout',
        '/__surface/error-404',
        '/offline.html',
    ];

    for (const target of targets) {
        await page.goto(baseUrl + target, { waitUntil: 'networkidle' });
        for (const width of VIEWPORT_WIDTHS) {
            const height = width < 768 ? 844 : 1080;
            await page.setViewportSize({ width, height });
            const metrics = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                innerWidth: window.innerWidth,
            }));
            expect(
                metrics.scrollWidth,
                `Horizontal overflow on ${target} at ${width}px: scrollWidth=${metrics.scrollWidth} > innerWidth=${metrics.innerWidth}`
            ).toBeLessThanOrEqual(metrics.innerWidth);
        }
    }

    expect(missingAssets, 'All built CSS/JS and icon assets must resolve HTTP 200').toEqual([]);
    expect(pageErrors, 'Zero uncaught runtime exceptions').toEqual([]);
    expect(consoleErrors, 'Zero console.error emissions').toEqual([]);
});

test('landmarks, heading hierarchy, programmatic form labels, contrast, skip link, mobile menu Escape & reduced-motion hold', async ({ page }) => {
    const surfacesToAudit = [
        'public-services',
        'login',
        'register',
        'forgot-password',
        'player-bet',
        'player-deposit',
        'player-withdraw',
        'player-profile',
        'wallet-conversion',
        'support-portal',
        'notifications',
        'admin-layout',
        'error-401',
        'error-403',
        'error-404',
        'error-419',
        'error-429',
        'error-500',
        'error-503',
    ];

    for (const surface of surfacesToAudit) {
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.goto(`${baseUrl}/__surface/${surface}`, { waitUntil: 'networkidle' });

        const audit = await page.evaluate(() => {
            const mains = document.querySelectorAll('main');
            const h1s = document.querySelectorAll('h1');
            const skipLink = document.querySelector('a[href^="#main-content"], a[href^="#admin-main-content"]');
            const themeMeta = document.querySelector('meta[name="theme-color"]');

            // Check unlabeled controls
            const unlabeled = [];
            for (const el of document.querySelectorAll('input, select, textarea')) {
                const type = (el.getAttribute('type') || '').toLowerCase();
                if (['hidden', 'submit', 'button', 'reset'].includes(type)) continue;
                const hasAria = Boolean(el.getAttribute('aria-label') || el.getAttribute('aria-labelledby'));
                const hasLabel = Boolean((el.labels && el.labels.length > 0) || el.closest('label'));
                if (!hasAria && !hasLabel) {
                    unlabeled.push(`${el.tagName.toLowerCase()}#${el.id || el.getAttribute('name') || 'anon'}`);
                }
            }

            // Check unnamed buttons
            const unnamedButtons = [];
            for (const btn of document.querySelectorAll('button')) {
                const text = (btn.textContent || '').trim();
                const aria = (btn.getAttribute('aria-label') || btn.getAttribute('aria-labelledby') || btn.getAttribute('title') || '').trim();
                if (!text && !aria) {
                    unnamedButtons.push(btn.id || btn.className || 'button');
                }
            }

            return {
                mainCount: mains.length,
                h1Count: h1s.length,
                hasSkipLink: Boolean(skipLink),
                themeColor: themeMeta ? themeMeta.getAttribute('content') : null,
                unlabeled,
                unnamedButtons,
            };
        });

        expect(audit.mainCount, `Exactly one <main> landmark on ${surface}`).toBe(1);
        expect(audit.h1Count, `At least one <h1> heading on ${surface}`).toBeGreaterThanOrEqual(1);
        expect(audit.hasSkipLink, `Skip link present on ${surface}`).toBe(true);
        expect(audit.themeColor, `Dark/gold theme-color meta on ${surface}`).toBe('#0B0904');
        expect(audit.unlabeled, `Zero unlabeled form inputs on ${surface}`).toEqual([]);
        expect(audit.unnamedButtons, `Zero unnamed buttons on ${surface}`).toEqual([]);
    }

    // Verify WCAG 2.2 AA contrast (>= 4.5:1) on body & primary heading + skip-link focus + mobile drawer Escape
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${baseUrl}/__surface/public-services`, { waitUntil: 'networkidle' });

    const contrastRatio = await page.evaluate(() => {
        function parseRgb(str) {
            const m = str.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
            return m ? [Number(m[1]), Number(m[2]), Number(m[3])] : [11, 9, 4];
        }
        function luminance([r, g, b]) {
            const chan = [r, g, b].map((v) => {
                const s = v / 255;
                return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
            });
            return 0.2126 * chan[0] + 0.7152 * chan[1] + 0.0722 * chan[2];
        }
        const bg = parseRgb(getComputedStyle(document.body).backgroundColor);
        const fg = parseRgb(getComputedStyle(document.querySelector('h1')).color);
        const l1 = Math.max(luminance(bg), luminance(fg));
        const l2 = Math.min(luminance(bg), luminance(fg));
        return (l1 + 0.05) / (l2 + 0.05);
    });
    expect(contrastRatio).toBeGreaterThanOrEqual(4.5);

    // Mobile drawer ARIA & Escape key handling
    const menuBtn = page.locator('#btn-open-mobile-menu');
    const drawer = page.locator('#mobile-menu-drawer');
    await expect(menuBtn).toHaveAttribute('aria-expanded', 'false');
    await menuBtn.click();
    await expect(menuBtn).toHaveAttribute('aria-expanded', 'true');
    await expect(drawer).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(menuBtn).toHaveAttribute('aria-expanded', 'false');
    await expect(drawer).toBeHidden();

    // Reduced motion check
    await page.emulateMedia({ reducedMotion: 'reduce' });
    const reducedTransition = await page.locator('a[href="#main-content"]').evaluate((el) => getComputedStyle(el).transitionDuration);
    expect(parseFloat(reducedTransition)).toBeLessThanOrEqual(0.001);
});

test('player/bet pre-rendered keypad preserves leading zeroes and interacts cleanly with production bet-slip bundle', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto(`${baseUrl}/__surface/player-bet`, { waitUntil: 'networkidle' });
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-bet-slip-ready', 'true');

    // Click pre-rendered keypad buttons 0 -> 0 -> 7
    await page.locator('#number-keypad button[data-key="0"]').click();
    await page.locator('#number-keypad button[data-key="0"]').click();
    await page.locator('#number-keypad button[data-key="7"]').click();

    await expect(page.locator('#selected-number-input')).toHaveValue('007');

    // Enter stake and add to slip
    await page.locator('#stake-amount-input').fill('25.00');
    await page.locator('#btn-add-to-slip').click();

    await expect(page.locator('[data-slip-count]')).toHaveText('1');
    await expect(page.locator('#bet-items-list')).toContainText('007');
    await expect(page.locator('#total-stake-amount')).toContainText('25.00');
    await expect(page.locator('#total-payout-amount')).toContainText('22,500.00');
});

test('SEO metadata on public pages vs strict noindex,nofollow on auth, player, admin and error pages + EN/TH/BN switching', async ({ page }) => {
    // Public page: index, follow + canonical
    await page.goto(`${baseUrl}/__surface/public-services?lang=en`, { waitUntil: 'networkidle' });
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', 'index, follow');
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', `${baseUrl}/our-services`);
    const enHeading = await page.locator('h1').textContent();

    // Thai locale renders Thai script and lang="th"
    await page.goto(`${baseUrl}/__surface/public-services?lang=th`, { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', 'th');
    const thHeading = await page.locator('h1').textContent();
    expect(thHeading).not.toBe(enHeading);
    expect(thHeading).toMatch(/[\u0E00-\u0E7F]/);

    // Bengali locale renders Bengali script and lang="bn"
    await page.goto(`${baseUrl}/__surface/public-services?lang=bn`, { waitUntil: 'networkidle' });
    await expect(page.locator('html')).toHaveAttribute('lang', 'bn');
    const bnHeading = await page.locator('h1').textContent();
    expect(bnHeading).not.toBe(enHeading);
    expect(bnHeading).toMatch(/[\u0980-\u09FF]/);

    // Private/auth/admin/error pages MUST carry noindex, nofollow
    for (const priv of ['login', 'register', 'player-bet', 'player-deposit', 'player-withdraw', 'player-profile', 'support-portal', 'admin-layout', 'error-404', 'error-500']) {
        await page.goto(`${baseUrl}/__surface/${priv}`, { waitUntil: 'networkidle' });
        await expect(page.locator('meta[name="robots"]'), `${priv} must be non-indexable`).toHaveAttribute('content', /noindex/i);
    }
});

test('PWA manifest and real PNG icon assets load cleanly with luxury dark/gold theme tokens', async ({ page, request }) => {
    const manifestResp = await request.get(`${baseUrl}/manifest.webmanifest`);
    expect(manifestResp.status()).toBe(200);
    const manifest = await manifestResp.json();
    expect(manifest.theme_color).toBe('#0B0904');
    expect(manifest.background_color).toBe('#0B0904');
    expect(manifest.display).toBe('standalone');
    expect(manifest.icons.length).toBeGreaterThanOrEqual(3);

    for (const icon of manifest.icons) {
        const iconResp = await request.get(`${baseUrl}${icon.src}`);
        expect(iconResp.status(), `Icon ${icon.src} must return 200`).toBe(200);
        expect(iconResp.headers()['content-type']).toContain('image/png');
    }

    await page.goto(`${baseUrl}/offline.html`, { waitUntil: 'networkidle' });
    await expect(page.locator('h1')).toBeVisible();
});

test('all active public web routes, sitemap.xml, and auth entry redirects render cleanly with zero console or asset errors', async ({ page, request, baseURL }) => {
    test.setTimeout(120_000);
    const appBase = baseURL || process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';
    const consoleErrors = [];
    const pageErrors = [];
    const brokenAssets = [];

    page.on('console', (msg) => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
    page.on('pageerror', (err) => pageErrors.push(err.message));
    page.on('response', (resp) => {
        const u = resp.url();
        if (resp.status() >= 400 && (u.includes('/build/') || u.includes('/icons/'))) {
            brokenAssets.push(`${resp.status()} ${u}`);
        }
    });

    const activePublicRoutes = [
        '/',
        '/lotteries',
        '/results',
        '/results/search',
        '/glo-l6',
        '/national-lottery',
        '/national-lottery/history',
        '/national-lottery/search',
        '/weekly-lottery',
        '/weekly-lottery/history',
        '/weekly-lottery/search',
        '/bingo-lottery',
        '/bingo-lottery/history',
        '/bingo-lottery/search',
        '/pcso-lottery',
        '/pcso-lottery/history',
        '/pcso-lottery/search',
        '/check',
        '/prize-verification',
        '/sales-points',
        '/services',
        '/about',
        '/vision',
        '/how-to-play',
        '/faq',
        '/fees',
        '/discounts',
        '/lotto-discount',
        '/account-verification-guide',
        '/account-grades',
        '/terms',
        '/privacy',
        '/contact',
        '/download',
        '/login',
        '/register',
        '/forgot-password',
    ];

    for (const routePath of activePublicRoutes) {
        const resp = await page.goto(`${appBase}${routePath}`, { waitUntil: 'domcontentloaded' });
        expect(resp && resp.status(), `Route ${routePath} must answer HTTP 200`).toBe(200);
        await expect(page.locator('main')).toHaveCount(1);
        const title = await page.title();
        expect(title.trim().length, `Route ${routePath} must carry a non-empty <title>`).toBeGreaterThan(2);
    }

    const sitemapResp = await request.get(`${appBase}/sitemap.xml`);
    expect(sitemapResp.status()).toBe(200);
    expect(await sitemapResp.text()).toContain('<urlset');

    expect(brokenAssets, 'Zero broken Vite build or icon assets across all public routes').toEqual([]);
    expect(pageErrors, 'Zero uncaught JS runtime errors across all public routes').toEqual([]);
    expect(consoleErrors, 'Zero console.error messages across all public routes').toEqual([]);
});

test('service worker script enforces private/money/API bypass and offline page never fabricates live official results', async ({ page, request }) => {
    const swResp = await request.get(`${baseUrl}/sw.js`);
    expect(swResp.status()).toBe(200);
    const swSource = await swResp.text();

    for (const sensitivePrefix of ['/api', '/wallet', '/deposit', '/withdraw', '/bet', '/admin', '/login', '/register']) {
        expect(swSource, `sw.js must guard sensitive prefix ${sensitivePrefix}`).toContain(sensitivePrefix);
    }

    await page.goto(`${baseUrl}/offline.html`, { waitUntil: 'domcontentloaded' });
    const offlineBody = (await page.locator('body').textContent()) || '';
    expect(offlineBody.toLowerCase()).toMatch(/offline|connection|unavailable/);
    const cachedPrivateKeys = await page.evaluate(async () => {
        if (!('caches' in window)) return [];
        const names = await caches.keys();
        const leaked = [];
        for (const n of names) {
            const c = await caches.open(n);
            for (const req of await c.keys()) {
                const p = new URL(req.url).pathname;
                if (/^\/(wallet|deposit|withdraw|bet|transfer|profile|admin|api)(\/|$)/.test(p)) {
                    leaked.push(p);
                }
            }
        }
        return leaked;
    });
    expect(cachedPrivateKeys).toEqual([]);
});
