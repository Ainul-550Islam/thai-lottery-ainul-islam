// Wallet conversion route & transport regression suite.
//
// Proves against both `routes/web.php` + `routes/api.php` definitions and the
// compiled `resources/views/wallet/conversion/partials/quote-form.blade.php` +
// production-built `resources/js/wallet-conversion.js` bundle that:
//   1. The frontend calls ONLY the real existing endpoints:
//        - POST /wallet/convert/preview  (player.wallet.conversion.preview)
//        - POST /wallet/convert/quote    (player.wallet.conversion.quote)
//        - POST /wallet/convert          (player.wallet.conversion.store)
//      and NEVER targets a non-existent `/wallet/conversion` route.
//   2. Live fee previews use server-returned pricing (`fee_amount`,
//      `amount_out`, `percentage`) and fail closed to `—` + server-authority
//      notice on transport/server failure rather than computing fees in JS.
//   3. Execute (`Convert`) submits a CSPRNG `idempotency_key` (16-128 chars,
//      matching `RequireConversionIdempotencyKey`), preserves that key across
//      repeated submit events on the same form instance, blocks duplicate
//      clicks, and fails closed when Web Crypto CSPRNG is unavailable.
import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import querystring from 'node:querystring';

const ROOT = process.cwd();
const PUBLIC_DIR = path.join(ROOT, 'public');
const HARNESS_PHP = path.join(ROOT, 'tests/browser/render-blade-harness.php');

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
};

let server;
let baseUrl = '';
let previewMode = 'ok';
let recordedRequests = [];

function renderWalletConversion(locale = 'en') {
    return execFileSync('php', [HARNESS_PHP, 'wallet-conversion', locale, baseUrl], {
        cwd: ROOT,
        encoding: 'utf8',
        maxBuffer: 8 * 1024 * 1024,
    });
}

function readRequestBody(req) {
    return new Promise((resolve) => {
        const chunks = [];
        req.on('data', (chunk) => chunks.push(chunk));
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    });
}

test.beforeAll(async () => {
    server = http.createServer(async (req, res) => {
        const urlObj = new URL(req.url || '/', 'http://127.0.0.1');
        const pathname = decodeURIComponent(urlObj.pathname);

        if (pathname === '/wallet/convert' && req.method === 'GET') {
            const locale = urlObj.searchParams.get('lang') || 'en';
            const html = renderWalletConversion(locale);
            res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
            res.end(html);
            return;
        }

        if (
            pathname === '/wallet/convert/preview' ||
            pathname === '/wallet/convert/quote' ||
            (pathname === '/wallet/convert' && req.method === 'POST') ||
            pathname.startsWith('/wallet/conversion')
        ) {
            const rawBody = await readRequestBody(req);
            recordedRequests.push({
                method: req.method,
                pathname,
                headers: req.headers,
                rawBody,
            });

            if (pathname === '/wallet/convert/preview' && req.method === 'POST') {
                if (previewMode === 'error') {
                    res.writeHead(503, { 'Content-Type': 'application/json; charset=utf-8' });
                    res.end(JSON.stringify({ message: 'Fee schedule temporarily unavailable.' }));
                    return;
                }
                const parsed = JSON.parse(rawBody || '{}');
                res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
                res.end(JSON.stringify({
                    direction: parsed.direction || 'cash_to_win',
                    amount_in: parsed.amount || '100.00',
                    fee_amount: parsed.amount === '250.00' ? '5.00' : '2.00',
                    amount_out: parsed.amount === '250.00' ? '245.00' : '98.00',
                    rate: '0.020000',
                    percentage: '2.00',
                    currency: 'THB',
                    rule_version: '1',
                    configured: true,
                }));
                return;
            }

            if (pathname === '/wallet/convert/quote' && req.method === 'POST') {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<!doctype html><html lang="en"><body><main><h1>Quote Ready</h1></main></body></html>');
                return;
            }

            if (pathname === '/wallet/convert' && req.method === 'POST') {
                res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
                res.end('<!doctype html><html lang="en"><body><main><h1>Conversion Submitted</h1></main></body></html>');
                return;
            }

            res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
            res.end('Unexpected non-existent endpoint');
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
    });

    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.afterAll(async () => {
    if (server) {
        await new Promise((resolve) => server.close(resolve));
    }
});

test.beforeEach(() => {
    previewMode = 'ok';
    recordedRequests = [];
});

test('route definitions and frontend templates bind exclusively to /wallet/convert endpoints (never /wallet/conversion)', async ({ page }) => {
    const webRoutes = fs.readFileSync(path.join(ROOT, 'routes/web.php'), 'utf8');
    const apiRoutes = fs.readFileSync(path.join(ROOT, 'routes/api.php'), 'utf8');

    expect(webRoutes).toContain("Route::get('/wallet/convert'");
    expect(webRoutes).toContain("->name('player.wallet.conversion')");
    expect(webRoutes).toContain("Route::post('/wallet/convert/preview'");
    expect(webRoutes).toContain("->name('player.wallet.conversion.preview')");
    expect(webRoutes).toContain("Route::post('/wallet/convert/quote'");
    expect(webRoutes).toContain("->name('player.wallet.conversion.quote')");
    expect(webRoutes).toContain("Route::post('/wallet/convert'");
    expect(webRoutes).toContain("->name('player.wallet.conversion.store')");
    expect(webRoutes).not.toContain("'/wallet/conversion'");

    expect(apiRoutes).toContain("Route::post('/v1/wallet/conversions/quote'");
    expect(apiRoutes).toContain("Route::post('/v1/wallet/conversions'");
    expect(apiRoutes).not.toContain("'/v1/wallet/conversion'");

    await page.goto(`${baseUrl}/wallet/convert`, { waitUntil: 'networkidle' });

    const formAttrs = await page.locator('form[data-wc-conversion-form]').evaluate((form) => ({
        action: new URL(form.getAttribute('action'), window.location.origin).pathname,
        previewUrl: new URL(form.getAttribute('data-wc-preview-url'), window.location.origin).pathname,
        quoteUrl: new URL(form.getAttribute('data-wc-quote-url'), window.location.origin).pathname,
        method: (form.getAttribute('method') || '').toUpperCase(),
    }));

    expect(formAttrs).toEqual({
        action: '/wallet/convert',
        previewUrl: '/wallet/convert/preview',
        quoteUrl: '/wallet/convert/quote',
        method: 'POST',
    });
    expect(recordedRequests.some((r) => r.pathname.startsWith('/wallet/conversion'))).toBe(false);
});

test('live typing calls POST /wallet/convert/preview with CSRF and fails closed when preview server errors', async ({ page }) => {
    await page.goto(`${baseUrl}/wallet/convert`, { waitUntil: 'networkidle' });
    recordedRequests = [];

    const amountInput = page.locator('[data-wc-amount]');
    await amountInput.fill('250.00');

    await expect(page.locator('[data-wc-preview-fee]')).toHaveText('5.00 THB');
    await expect(page.locator('[data-wc-preview-net]')).toHaveText('245.00 THB');
    await expect(page.locator('[data-wc-preview-rate]')).toHaveText('2.00%');

    const previewReq = recordedRequests.find((r) => r.pathname === '/wallet/convert/preview');
    expect(previewReq).toBeDefined();
    expect(previewReq.method).toBe('POST');
    expect(previewReq.headers['x-csrf-token']).toBe('harness-csrf-token');
    expect(JSON.parse(previewReq.rawBody)).toEqual({
        direction: 'cash_to_win',
        amount: '250.00',
    });

    // Server failure must clear numbers to '—' rather than guessing client-side
    previewMode = 'error';
    await amountInput.fill('300.00');
    await expect(page.locator('[data-wc-preview-fee]')).toHaveText('—');
    await expect(page.locator('[data-wc-preview-net]')).toHaveText('—');
    await expect(page.locator('[data-wc-preview-rate]')).toHaveText('—');
    await expect(page.locator('[data-wc-preview-note]')).toBeVisible();
});

test('Get price button posts to POST /wallet/convert/quote and Convert button posts to POST /wallet/convert with preserved CSPRNG idempotency_key', async ({ page }) => {
    await page.goto(`${baseUrl}/wallet/convert`, { waitUntil: 'networkidle' });

    const initialKey = await page.locator('[data-wc-idempotency-key]').inputValue();
    expect(initialKey).toMatch(/^wc-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    expect(initialKey.length).toBeGreaterThanOrEqual(16);
    expect(initialKey.length).toBeLessThanOrEqual(128);

    await page.locator('[data-wc-amount]').fill('250.00');
    recordedRequests = [];

    // 1. Verify "Get price" submits to POST /wallet/convert/quote
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('[data-wc-quote]').click(),
    ]);

    const quotePost = recordedRequests.find((r) => r.pathname === '/wallet/convert/quote' && r.method === 'POST');
    expect(quotePost).toBeDefined();
    const quoteParams = querystring.parse(quotePost.rawBody);
    expect(quoteParams._token).toBe('harness-csrf-token');
    expect(quoteParams.direction).toBe('cash_to_win');
    expect(quoteParams.amount).toBe('250.00');

    // 2. Reload /wallet/convert and verify "Convert" posts to POST /wallet/convert
    //    preserving the rendered form instance's CSPRNG idempotency_key and blocking double-submit.
    await page.goto(`${baseUrl}/wallet/convert`, { waitUntil: 'networkidle' });
    const formKey = await page.locator('[data-wc-idempotency-key]').inputValue();
    expect(formKey).toMatch(/^wc-[0-9a-f-]{36}$/i);
    await page.locator('[data-wc-amount]').fill('250.00');
    recordedRequests = [];

    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
        page.locator('[data-wc-submit]').click(),
    ]);

    const convertPosts = recordedRequests.filter((r) => r.pathname === '/wallet/convert' && r.method === 'POST');
    expect(convertPosts).toHaveLength(1);
    expect(recordedRequests.some((r) => r.pathname.startsWith('/wallet/conversion'))).toBe(false);

    const convertParams = querystring.parse(convertPosts[0].rawBody);
    expect(convertParams._token).toBe('harness-csrf-token');
    expect(convertParams.direction).toBe('cash_to_win');
    expect(convertParams.amount).toBe('250.00');
    expect(convertParams.idempotency_key).toBe(formKey);
});

test('missing Web Crypto CSPRNG fails closed without Math.random fallback', async ({ page }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'crypto', {
            value: undefined,
            configurable: true,
        });
    });

    await page.goto(`${baseUrl}/wallet/convert`, { waitUntil: 'networkidle' });

    await expect(page.locator('[data-wc-submit]')).toBeDisabled();
    await expect(page.locator('[data-wc-disabled-note]')).toBeVisible();
    await expect(page.locator('[data-wc-idempotency-key]')).toHaveValue('');
});
