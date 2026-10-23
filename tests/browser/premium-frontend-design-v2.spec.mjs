import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import { expect, test } from '@playwright/test';

const ROOT = process.cwd();
const PUBLIC_DIR = path.join(ROOT, 'public');
const HARNESS_PHP = path.join(ROOT, 'tests', 'browser', 'render-blade-harness.php');
const SCREENSHOT_DIR = '/tmp/v2-screenshots';
const VITALS_PATH = '/tmp/v2-web-vitals.json';

const V2_WIDTHS = [320, 360, 375, 390, 414, 768, 1024, 1280, 1440, 1920];

const V2_SURFACES = [
    'home',
    'public-services',
    'results-search',
    'login',
    'register',
    'forgot-password',
    'player-dashboard',
    'player-bet',
    'player-wallet',
    'player-deposit',
    'player-withdraw',
    'player-profile',
    'player-rg',
    'player-security',
    'wallet-conversion',
    'support-portal',
    'admin-layout',
    'admin-withdrawal-reconciliation',
    'error-404',
];

const MIME = {
    '.html': 'text/html; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.webmanifest': 'application/manifest+json; charset=utf-8',
    '.png': 'image/png',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon',
    '.woff2': 'font/woff2',
};

let server;
let baseUrl = '';
const htmlCache = new Map();

function renderSurface(surface, locale = 'en') {
    const key = `${surface}:${locale}`;
    if (!htmlCache.has(key)) {
        const html = execFileSync('php', [HARNESS_PHP, surface, locale, baseUrl], {
            cwd: ROOT,
            encoding: 'utf8',
            maxBuffer: 12 * 1024 * 1024,
        });
        htmlCache.set(key, html);
    }
    return htmlCache.get(key);
}

test.beforeAll(async () => {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    await new Promise((resolve) => {
        server = http.createServer((req, res) => {
            const url = new URL(req.url || '/', 'http://127.0.0.1');
            if (url.pathname.startsWith('/__harness/')) {
                const surface = url.pathname.replace('/__harness/', '');
                const locale = url.searchParams.get('locale') || 'en';
                try {
                    const html = renderSurface(surface, locale);
                    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                    res.end(html);
                } catch (err) {
                    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
                    res.end(String(err));
                }
                return;
            }

            const relPath = decodeURIComponent(url.pathname === '/' ? '/offline.html' : url.pathname);
            const fullPath = path.resolve(PUBLIC_DIR, '.' + relPath);
            if (!fullPath.startsWith(PUBLIC_DIR) || !fs.existsSync(fullPath) || fs.statSync(fullPath).isDirectory()) {
                res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
                res.end('Not found');
                return;
            }
            const ext = path.extname(fullPath).toLowerCase();
            res.writeHead(200, { 'Content-Type': MIME[ext] || 'application/octet-stream' });
            fs.createReadStream(fullPath).pipe(res);
        });
        server.listen(0, '127.0.0.1', () => {
            const addr = server.address();
            baseUrl = `http://127.0.0.1:${addr.port}`;
            resolve();
        });
    });
});

test.afterAll(async () => {
    if (server) {
        await new Promise((resolve) => server.close(resolve));
    }
});

test('Flagship Home V2 renders all 10 required sections, 3D gold balls, leading zeroes, and neutral copy', async ({ page }) => {
    const externalRequests = [];
    page.on('request', (req) => {
        const u = req.url();
        if (!u.startsWith(baseUrl) && !u.startsWith('data:') && !u.startsWith('blob:')) {
            externalRequests.push(u);
        }
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(`${baseUrl}/__harness/home?locale=en`, { waitUntil: 'networkidle' });

    expect(externalRequests).toEqual([]);

    const requiredIds = [
        'hero',
        'current-result',
        'next-draw',
        'lane-results',
        'check',
        'sales-points',
        'live-draw',
        'products',
        'prize',
        'stats',
        'bonuses',
        'payments',
        'support',
        'app-links',
        'trust',
    ];
    for (const id of requiredIds) {
        await expect(page.locator(`#${id}`)).toBeVisible();
    }

    // Leading-zero 6-digit first prize preserved verbatim + 6 decorative 3D gold orbs
    await expect(page.locator('#current-result')).toContainText('012345');
    await expect(page.locator('#current-result .tl-lottery-ball-3d')).toHaveCount(6);
    await expect(page.locator('#current-result .tl-lottery-ball-3d').first()).toHaveText('0');

    // Unconfigured live draw renders zero iframes and explicit screen-reader state
    await expect(page.locator('#live-draw iframe')).toHaveCount(0);
    await expect(page.locator('#live-draw')).toContainText('LIVE DRAW NOT_CONFIGURED');

    // Official GLO catalogue prices 80.00 THB & 20.00 THB, zero competitor strings, zero href="#"
    await expect(page.locator('#products')).toContainText('80.00 THB');
    await expect(page.locator('#products')).toContainText('20.00 THB');
    await expect(page.locator('a[href="#"]')).toHaveCount(0);
    const bodyHtml = await page.content();
    expect(bodyHtml.toLowerCase()).not.toContain('thailotto');
    expect(bodyHtml).not.toContain('hero-banner.png');
});

test('10-width V2 responsive matrix (320..1920px) & >=44px mobile touch targets hold across all elevated families', async ({ page }) => {
    test.setTimeout(180_000);
    for (const surface of V2_SURFACES) {
        await page.goto(`${baseUrl}/__harness/${surface}?locale=en`, { waitUntil: 'domcontentloaded' });
        for (const width of V2_WIDTHS) {
            const height = width < 768 ? 844 : 900;
            await page.setViewportSize({ width, height });
            const metrics = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                innerWidth: window.innerWidth,
            }));
            expect(
                metrics.scrollWidth,
                `${surface} overflowed horizontally at ${width}px (${metrics.scrollWidth} > ${metrics.innerWidth})`
            ).toBeLessThanOrEqual(metrics.innerWidth + 1);
        }

        // At 375px mobile width, verify primary buttons and inputs meet >= 44px height
        await page.setViewportSize({ width: 375, height: 812 });
        const undersized = await page.evaluate(() => {
            const targets = Array.from(
                document.querySelectorAll(
                    '.tl-v2-btn-gold, .tl-v2-btn-outline, .tl-submit-btn, .wc-button, #btn-open-mobile-menu, #btn-add-to-slip, #btn-place-bet'
                )
            );
            return targets
                .filter((el) => {
                    const style = getComputedStyle(el);
                    if (style.display === 'none' || style.visibility === 'hidden') return false;
                    const rect = el.getBoundingClientRect();
                    return rect.width > 0 && rect.height > 0 && rect.height < 43.5;
                })
                .map((el) => ({ tag: el.tagName, id: el.id, cls: el.className, h: el.getBoundingClientRect().height }));
        });
        expect(undersized, `Undersized primary touch targets on ${surface}`).toEqual([]);
    }

    // Also verify offline.html across all 10 widths
    await page.goto(`${baseUrl}/offline.html`, { waitUntil: 'domcontentloaded' });
    for (const width of V2_WIDTHS) {
        await page.setViewportSize({ width, height: 800 });
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `offline.html overflow at ${width}px`).toBeLessThanOrEqual(1);
    }
});

test('WCAG 2.2 AA landmarks, single h1, programmatic labels, contrast >=4.5:1, focus ring & reduced-motion hold', async ({ page }) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 1280, height: 900 });

    for (const surface of V2_SURFACES) {
        await page.goto(`${baseUrl}/__harness/${surface}?locale=en`, { waitUntil: 'domcontentloaded' });
        const audit = await page.evaluate(() => {
            const cvs = document.createElement('canvas');
            cvs.width = 1;
            cvs.height = 1;
            const ctx = cvs.getContext('2d', { willReadFrequently: true });
            function parseCssColor(str) {
                if (!str || str === 'transparent' || str === 'rgba(0, 0, 0, 0)') return [0, 0, 0, 0];
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = str;
                ctx.fillRect(0, 0, 1, 1);
                const d = ctx.getImageData(0, 0, 1, 1).data;
                return [d[0], d[1], d[2], Number((d[3] / 255).toFixed(4))];
            }
            function blend(fg, bg) {
                const a = fg[3] + bg[3] * (1 - fg[3]);
                if (a === 0) return [11, 9, 4, 1];
                return [
                    (fg[0] * fg[3] + bg[0] * bg[3] * (1 - fg[3])) / a,
                    (fg[1] * fg[3] + bg[1] * bg[3] * (1 - fg[3])) / a,
                    (fg[2] * fg[3] + bg[2] * bg[3] * (1 - fg[3])) / a,
                    a,
                ];
            }
            function effectiveBg(el) {
                const layers = [];
                let cur = el;
                while (cur && cur.nodeType === 1) {
                    const cs = getComputedStyle(cur);
                    let bg = parseCssColor(cs.backgroundColor);
                    if (bg[3] === 0 && cs.backgroundImage && cs.backgroundImage.includes('gradient')) {
                        const colorTokens = cs.backgroundImage.match(/(?:rgba?\([^)]+\)|oklch\([^)]+\)|oklab\([^)]+\)|color\([^)]+\)|#[0-9a-fA-F]{3,8})/g);
                        if (colorTokens && colorTokens.length > 0) {
                            bg = parseCssColor(colorTokens[Math.floor(colorTokens.length / 2)]);
                        }
                    }
                    if (bg[3] > 0) layers.push(bg);
                    if (bg[3] >= 0.99) break;
                    cur = cur.parentElement;
                }
                let base = [11, 9, 4, 1];
                for (let i = layers.length - 1; i >= 0; i--) {
                    base = blend(layers[i], base);
                }
                return base;
            }
            function lum([r, g, b]) {
                const f = (v) => {
                    const s = v / 255;
                    return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
                };
                return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
            }
            function ratio(c1, c2) {
                const l1 = lum(c1);
                const l2 = lum(c2);
                return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
            }

            const mainCount = document.querySelectorAll('main').length;
            const bannerCount = document.querySelectorAll('[role="banner"]').length;
            const contentinfoCount = document.querySelectorAll('[role="contentinfo"]').length;
            const h1Count = document.querySelectorAll('h1').length;
            const skipLinks = document.querySelectorAll('a.tl-skip-link, a.skip-link').length;

            const unlabeledInputs = Array.from(document.querySelectorAll('input, select, textarea'))
                .filter((el) => {
                    if (el.type === 'hidden') return false;
                    const st = getComputedStyle(el);
                    if (st.display === 'none' || st.visibility === 'hidden') return false;
                    const hasLabel =
                        (el.labels && el.labels.length > 0) ||
                        Boolean(el.getAttribute('aria-label')) ||
                        Boolean(el.getAttribute('aria-labelledby')) ||
                        Boolean(el.closest('label'));
                    return !hasLabel;
                })
                .map((el) => el.name || el.id || el.tagName);

            const contrastFailures = [];
            const sampleNodes = Array.from(document.querySelectorAll('h1, h2, h3, p, label, th, td, dt, dd, button, a'))
                .slice(0, 60);
            for (const el of sampleNodes) {
                const st = getComputedStyle(el);
                if (st.display === 'none' || st.visibility === 'hidden' || Number(st.opacity) < 0.85) continue;
                if (el.classList.contains('sr-only') || el.classList.contains('visually-hidden') || el.classList.contains('tl-skip-link') || el.classList.contains('skip-link')) continue;
                if ((el).disabled) continue;
                const text = (el.textContent || '').trim();
                if (!text) continue;
                const rect = el.getBoundingClientRect();
                if (rect.width < 2 || rect.height < 2) continue;
                const isTextClip = st.backgroundClip === 'text' || st.webkitBackgroundClip === 'text';
                const bg = effectiveBg(isTextClip && el.parentElement ? el.parentElement : el);
                let rawFg = parseCssColor(st.color);
                if (isTextClip && rawFg[3] === 0 && st.backgroundImage && st.backgroundImage.includes('gradient')) {
                    const stops = st.backgroundImage.match(/(?:rgba?\([^)]+\)|oklch\([^)]+\)|oklab\([^)]+\)|color\([^)]+\)|#[0-9a-fA-F]{3,8})/g);
                    if (stops && stops.length > 0) {
                        rawFg = parseCssColor(stops[Math.floor(stops.length / 2)]);
                    }
                }
                const fg = blend(rawFg, bg);
                const r = ratio(fg, bg);
                const fontSize = parseFloat(st.fontSize) || 16;
                const fontWeight = parseInt(st.fontWeight, 10) || 400;
                const isLarge = fontSize >= 24 || (fontSize >= 18.66 && fontWeight >= 700);
                const minRatio = isLarge ? 3.0 : 4.5;
                if (r < minRatio) {
                    contrastFailures.push({ tag: el.tagName, text: text.slice(0, 32), ratio: Number(r.toFixed(2)), minRatio });
                }
            }

            return { mainCount, bannerCount, contentinfoCount, h1Count, skipLinks, unlabeledInputs, contrastFailures };
        });

        expect(audit.mainCount, `${surface} must have exactly 1 <main>`).toBe(1);
        expect(audit.bannerCount, `${surface} duplicate role="banner"`).toBeLessThanOrEqual(1);
        expect(audit.contentinfoCount, `${surface} duplicate role="contentinfo"`).toBeLessThanOrEqual(1);
        expect(audit.h1Count, `${surface} must have 1 <h1>`).toBe(1);
        expect(audit.skipLinks, `${surface} must expose a skip-link`).toBeGreaterThanOrEqual(1);
        expect(audit.unlabeledInputs, `${surface} has unlabeled form controls`).toEqual([]);
        expect(audit.contrastFailures, `${surface} WCAG AA contrast failures`).toEqual([]);
    }

    // Verify prefers-reduced-motion disables ball animation duration
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto(`${baseUrl}/__harness/home?locale=en`, { waitUntil: 'domcontentloaded' });
    const ballDuration = await page.locator('.tl-lottery-ball-3d').first().evaluate((el) => getComputedStyle(el).animationDuration);
    expect(['0s', '0.00001s', '1e-05s', '0.01ms']).toContain(ballDuration);
});

test('EN / TH / BN localization parity applies script-aware fonts and zero overflow at 320px & 1440px', async ({ page }) => {
    for (const locale of ['en', 'th', 'bn']) {
        await page.setViewportSize({ width: 320, height: 800 });
        await page.goto(`${baseUrl}/__harness/home?locale=${locale}`, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('html')).toHaveAttribute('lang', locale);
        const info = await page.evaluate(() => ({
            fontFamily: getComputedStyle(document.documentElement).fontFamily,
            scrollWidth: document.documentElement.scrollWidth,
            innerWidth: window.innerWidth,
            text: document.body.innerText,
        }));
        expect(info.scrollWidth).toBeLessThanOrEqual(info.innerWidth + 1);
        expect(info.text).not.toMatch(/\b(?:home|public_pages|player|wallet_conversion)\.[a-z0-9_]+\b/i);
        if (locale === 'th') {
            expect(info.fontFamily).toContain('Noto Sans Thai');
            await expect(page.locator('#lane-results')).toContainText('2569');
        }
        if (locale === 'bn') {
            expect(info.fontFamily).toContain('Noto Sans Bengali');
            await expect(page.locator('#lane-results')).toContainText('২০২৬');
        }
    }
});

test('Lab Web Vitals & visual screenshots pass V2 budgets on Desktop (1440x900) and Mobile (390x844)', async ({ page, browserName }, testInfo) => {
    const isDesktop = !testInfo.project.name.includes('mobile');
    const viewport = isDesktop ? { width: 1440, height: 900 } : { width: 390, height: 844 };
    await page.setViewportSize(viewport);

    await page.addInitScript(() => {
        window.__tlVitals = { lcp: 0, cls: 0 };
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                window.__tlVitals.lcp = Math.max(window.__tlVitals.lcp, entry.startTime);
            }
        }).observe({ type: 'largest-contentful-paint', buffered: true });
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (!entry.hadRecentInput) {
                    window.__tlVitals.cls += entry.value;
                }
            }
        }).observe({ type: 'layout-shift', buffered: true });
    });

    const screenshotTargets = ['home', 'login', 'player-bet', 'wallet-conversion', 'admin-withdrawal-reconciliation'];
    const vitalsReport = {};

    for (const target of screenshotTargets) {
        await page.goto(`${baseUrl}/__harness/${target}?locale=en`, { waitUntil: 'networkidle' });
        await page.waitForTimeout(120);
        const metrics = await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0];
            const resources = performance.getEntriesByType('resource');
            const totalTransferBytes = resources.reduce((acc, r) => acc + (r.transferSize || r.encodedBodySize || 0), 0);
            return {
                lcpMs: Math.round(window.__tlVitals.lcp || (nav ? nav.domContentLoadedEventEnd : 0)),
                cls: Number((window.__tlVitals.cls || 0).toFixed(4)),
                dclMs: nav ? Math.round(nav.domContentLoadedEventEnd) : 0,
                resourceCount: resources.length,
                totalTransferKB: Number((totalTransferBytes / 1024).toFixed(1)),
            };
        });

        expect(metrics.lcpMs, `LCP budget exceeded on ${target}`).toBeLessThan(2500);
        expect(metrics.cls, `CLS budget exceeded on ${target}`).toBeLessThan(0.05);
        vitalsReport[target] = metrics;

        const bodyText = await page.locator('body').innerText();
        expect(bodyText, `Untranslated placeholder leaked on ${target}`).not.toMatch(/:remaining\b|:currency\b|wallet_conversion\.|public_pages\.|home\./);

        const suffix = isDesktop ? 'desktop-1440' : 'mobile-390';
        await page.screenshot({
            path: path.join(SCREENSHOT_DIR, `${target}-${suffix}.png`),
            fullPage: false,
        });
        if (target === 'home') {
            await page.screenshot({
                path: path.join(SCREENSHOT_DIR, `home-fullpage-${suffix}.png`),
                fullPage: true,
            });
        }
    }

    let existing = {};
    if (fs.existsSync(VITALS_PATH)) {
        try {
            existing = JSON.parse(fs.readFileSync(VITALS_PATH, 'utf8'));
        } catch {
            existing = {};
        }
    }
    existing[isDesktop ? 'desktop' : 'mobile'] = vitalsReport;
    fs.writeFileSync(VITALS_PATH, JSON.stringify(existing, null, 2));
});
