/**
 * Behavioural security & cache-policy test harness for public/sw.js.
 *
 * Executes the real production public/sw.js inside a Node VM sandbox with
 * spec-accurate Cache / CacheStorage / Fetch / Response / URL primitives
 * and verifies:
 *  - atomic precaching via Cache.prototype.addAll (including failure rollback)
 *  - non-GET and cross-origin ignore before respondWith()
 *  - strict private/financial/auth/API/admin/agent/KYC/storage route bypass
 *    including URL-encoded and dot-segment path normalization
 *  - immutable hashed asset caching + Set-Cookie / private / no-store rejection
 *  - public HTML navigation network-first with zero HTML caching + /offline.html fallback
 *  - app-scoped activate cleanup preserving unrelated origin caches
 *
 * Run with: node tests-pwa-sw.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SW_PATH = path.join(__dirname, 'public', 'sw.js');
const ORIGIN = 'https://example.test';

function normalizeCacheKey(req) {
    const raw = typeof req === 'string' ? req : req.url;
    const parsed = new URL(raw, ORIGIN);
    return parsed.origin === ORIGIN ? parsed.pathname + parsed.search : parsed.href;
}

class FakeHeaders {
    constructor(init = {}) {
        this._map = new Map();
        if (init instanceof FakeHeaders) {
            for (const [k, v] of init._map.entries()) {
                this._map.set(k.toLowerCase(), String(v));
            }
        } else if (init && typeof init === 'object') {
            for (const [k, v] of Object.entries(init)) {
                this._map.set(k.toLowerCase(), String(v));
            }
        }
    }
    get(name) {
        const val = this._map.get(String(name).toLowerCase());
        return val === undefined ? null : val;
    }
    has(name) {
        return this._map.has(String(name).toLowerCase());
    }
}

class FakeResponse {
    constructor(body, init = {}) {
        this.body = body;
        this.status = init.status === undefined ? 200 : init.status;
        this.ok = this.status >= 200 && this.status < 300;
        this.type = init.type || 'basic';
        this.headers = new FakeHeaders(init.headers || {});
    }
    clone() {
        return new FakeResponse(this.body, {
            status: this.status,
            type: this.type,
            headers: this.headers,
        });
    }
}

function createHarness() {
    let networkOnline = true;
    let customResponder = null;
    const fetchLog = [];
    const cacheStore = new Map();
    const listeners = {};
    let respondWithCalls = 0;
    let skipWaitingCalled = false;

    function fakeFetch(request) {
        const rawUrl = typeof request === 'string' ? request : request.url;
        const resolved = new URL(rawUrl, ORIGIN).href;
        fetchLog.push(resolved);

        if (!networkOnline) {
            return Promise.reject(new TypeError('Failed to fetch'));
        }
        if (typeof customResponder === 'function') {
            const custom = customResponder(resolved, request);
            if (custom !== undefined) {
                return custom;
            }
        }
        if (resolved.endsWith('/offline.html')) {
            return Promise.resolve(new FakeResponse(fs.readFileSync(path.join(__dirname, 'public', 'offline.html'), 'utf8'), {
                status: 200,
                headers: { 'content-type': 'text/html; charset=utf-8' },
            }));
        }
        return Promise.resolve(new FakeResponse(`body:${resolved}`, { status: 200 }));
    }

    class FakeCache {
        constructor(name) {
            this.name = name;
            this.map = new Map();
        }
        async put(req, res) {
            this.map.set(normalizeCacheKey(req), res);
        }
        async add(req) {
            const res = await fakeFetch(req);
            if (!res || !res.ok) {
                throw new TypeError(`Bad response status (${res ? res.status : 'none'}) for ${req}`);
            }
            await this.put(req, res);
        }
        async addAll(urls) {
            const staged = [];
            for (const u of urls) {
                const res = await fakeFetch(u);
                if (!res || !res.ok) {
                    throw new TypeError(`Failed to precache ${u}`);
                }
                staged.push([normalizeCacheKey(u), res]);
            }
            for (const [k, res] of staged) {
                this.map.set(k, res);
            }
        }
        async match(req) {
            return this.map.get(normalizeCacheKey(req)) || undefined;
        }
        async keys() {
            return Array.from(this.map.keys());
        }
        async delete(req) {
            return this.map.delete(normalizeCacheKey(req));
        }
    }

    const caches = {
        async open(name) {
            if (!cacheStore.has(name)) {
                cacheStore.set(name, new FakeCache(name));
            }
            return cacheStore.get(name);
        },
        async keys() {
            return Array.from(cacheStore.keys());
        },
        async delete(name) {
            return cacheStore.delete(name);
        },
        async match(req) {
            for (const c of cacheStore.values()) {
                const hit = await c.match(req);
                if (hit) {
                    return hit;
                }
            }
            return undefined;
        },
    };

    const selfMock = {
        location: { origin: ORIGIN },
        addEventListener: (type, fn) => {
            (listeners[type] = listeners[type] || []).push(fn);
        },
        skipWaiting: () => {
            skipWaitingCalled = true;
        },
        clients: { claim: () => Promise.resolve() },
        registration: {},
    };

    const sandbox = {
        self: selfMock,
        caches,
        fetch: fakeFetch,
        Response: FakeResponse,
        Headers: FakeHeaders,
        Request: class {
            constructor(url, init = {}) {
                this.url = url;
                this.method = init.method || 'GET';
                this.mode = init.mode || 'cors';
                this.headers = new FakeHeaders(init.headers || {});
            }
        },
        console,
        URL,
        Set,
        Map,
        Promise,
        TypeError,
        decodeURIComponent,
        setTimeout,
    };
    sandbox.globalThis = sandbox;
    vm.createContext(sandbox);
    vm.runInContext(fs.readFileSync(SW_PATH, 'utf8'), sandbox, { filename: 'sw.js' });

    function fireFetch(url, { method = 'GET', mode = 'cors', accept = '*/*' } = {}) {
        const fullUrl = url.startsWith('http://') || url.startsWith('https://')
            ? url
            : new URL(url, ORIGIN).href;
        const request = {
            url: fullUrl,
            method,
            mode,
            headers: new FakeHeaders({ accept }),
        };
        let responded = false;
        const event = {
            request,
            respondWith(promise) {
                responded = true;
                respondWithCalls++;
                this._p = promise;
            },
        };
        (listeners.fetch || []).forEach((fn) => fn(event));
        return { handled: responded, event };
    }

    return {
        caches,
        cacheStore,
        listeners,
        fetchLog,
        fireFetch,
        setOnline(v) { networkOnline = v; },
        setCustomResponder(fn) { customResponder = fn; },
        getRespondWithCalls() { return respondWithCalls; },
        wasSkipWaitingCalled() { return skipWaitingCalled; },
    };
}

let passed = 0;
let failed = 0;
function assert(label, cond, extra = '') {
    const mark = cond ? 'PASS' : 'FAIL';
    if (cond) {
        passed++;
    } else {
        failed++;
        process.exitCode = 1;
    }
    console.log(`  [${mark}] ${label}${extra ? '  — ' + extra : ''}`);
}

(async () => {
    console.log('  Service worker security & offline policy — public/sw.js\n');

    // --- 0. Atomic precache failure leaves shell cache empty ---------------
    const brokenHarness = createHarness();
    brokenHarness.setCustomResponder((url) => {
        if (url.endsWith('/icons/icon-512.png')) {
            return Promise.resolve(new FakeResponse('missing', { status: 500 }));
        }
        return undefined;
    });
    let installRejected = false;
    try {
        await Promise.all(brokenHarness.listeners.install.map((fn) => {
            let p;
            fn({ waitUntil: (pr) => { p = pr; } });
            return p;
        }));
    } catch (_) {
        installRejected = true;
    }
    const brokenShell = await brokenHarness.caches.open('tl-pwa-shell-v2');
    assert(
        'atomic install rejects and avoids partial shell cache when an asset fails',
        installRejected && (await brokenShell.keys()).length === 0
    );

    // --- 1. Clean install precaches public shell ---------------------------
    const h = createHarness();
    await Promise.all(h.listeners.install.map((fn) => {
        let p;
        fn({ waitUntil: (pr) => { p = pr; } });
        return p;
    }));
    const shell = await h.caches.open('tl-pwa-shell-v2');
    const shellKeys = await shell.keys();
    assert('offline page is precached at install', shellKeys.includes('/offline.html'), `${shellKeys.length} shell entries`);
    assert('manifest is precached at install', shellKeys.includes('/manifest.webmanifest'));
    assert('PNG icons are precached at install', shellKeys.some((k) => k.startsWith('/icons/')));

    // --- 2. Non-GET and cross-origin ignored before respondWith ------------
    assert('POST request is ignored before respondWith', !h.fireFetch('/results', { method: 'POST' }).handled);
    assert('PUT request is ignored before respondWith', !h.fireFetch('/results', { method: 'PUT' }).handled);
    assert('DELETE request is ignored before respondWith', !h.fireFetch('/results', { method: 'DELETE' }).handled);
    assert('cross-origin asset is ignored before respondWith', !h.fireFetch('https://cdn.other.test/x.js').handled);

    // --- 3. Money, auth, identity, admin, agent, storage & encoded bypass --
    const sensitiveRoutes = [
        '/api/v1/wallet',
        '/api/v1/deposit/crypto/address',
        '/api/v1/player/settings/general',
        '/sanctum/csrf-cookie',
        '/broadcasting/auth',
        '/livewire/update',
        '/filament/exports/1',
        '/login',
        '/register',
        '/logout',
        '/forgot-password',
        '/reset-password/abc123',
        '/two-factor-challenge',
        '/dashboard',
        '/wallet',
        '/wallet/convert',
        '/wallet/convert/preview',
        '/wallet/convert/quote',
        '/wallet/convert/CNV-2026-0001',
        '/api/v1/wallet/conversions',
        '/api/v1/wallet/conversions/quote',
        '/player/wallet',
        '/deposit',
        '/deposits/99',
        '/withdraw',
        '/withdrawals/99',
        '/payment/callback',
        '/transfer',
        '/bets',
        '/bet',
        '/bet/purchase',
        '/lotto/betting',
        '/national-lottery/buy',
        '/weekly-lottery/buy',
        '/bingo-lottery/buy',
        '/pcso-lottery/buy',
        '/glo-l6/buy',
        '/results/search?q=000123',
        '/check?ticket=000123',
        '/prize-verification',
        '/member/profile',
        '/member/rewards',
        '/profile',
        '/profile/limits',
        '/account/verification',
        '/kyc',
        '/notifications',
        '/support/tickets',
        '/support/portal',
        '/chat/operator',
        '/admin',
        '/admin/dashboard',
        '/admin/ip-access-rules',
        '/agent/dashboard',
        '/agent/portal',
        '/agent/commissions',
        '/agent/settlements',
        '/app/thai-apk.apk',
        '/storage/kyc/doc.pdf',
        '/webhooks/stripe',
        '/results/../wallet',
        '/%77allet',
        '/results?token=secret123',
    ];
    const leakedOnline = sensitiveRoutes.filter((u) => h.fireFetch(u, { mode: 'navigate', accept: 'text/html' }).handled);
    assert(
        'all money/auth/identity/admin/agent/normalized routes bypass worker online',
        leakedOnline.length === 0,
        leakedOnline.length ? `leaked: ${leakedOnline.join(', ')}` : `${sensitiveRoutes.length} routes verified`
    );

    h.setOnline(false);
    const leakedOffline = sensitiveRoutes.filter((u) => h.fireFetch(u, { mode: 'navigate', accept: 'text/html' }).handled);
    assert(
        'all money/auth/identity/admin/agent routes still bypass worker while offline',
        leakedOffline.length === 0
    );
    h.setOnline(true);

    // --- 4. Immutable hashed Vite assets: cache-first + header guards ------
    const buildCss = `${ORIGIN}/build/assets/app-DNWI17dr.css`;
    h.fetchLog.length = 0;
    const firstAsset = h.fireFetch(buildCss);
    await firstAsset.event._p;
    const assetCache = await h.caches.open('tl-pwa-assets-v2');
    assert('immutable hashed build asset is fetched and cached', h.fetchLog.length === 1 && !!(await assetCache.match(buildCss)));

    h.fetchLog.length = 0;
    const secondAsset = h.fireFetch(buildCss);
    await secondAsset.event._p;
    assert('subsequent read of hashed build asset is served from cache without network', h.fetchLog.length === 0);

    // Response with Set-Cookie or Cache-Control: no-store must NEVER be cached
    const cookieAsset = `${ORIGIN}/build/assets/private-AbCdEf12.js`;
    h.setCustomResponder((url) => {
        if (url === cookieAsset) {
            return Promise.resolve(new FakeResponse('console.log(1)', {
                status: 200,
                headers: { 'set-cookie': 'session=secret', 'cache-control': 'private, no-store' },
            }));
        }
        return undefined;
    });
    const cookieEvt = h.fireFetch(cookieAsset);
    await cookieEvt.event._p;
    assert('response carrying Set-Cookie / private / no-store is never written to cache', !(await assetCache.match(cookieAsset)));
    h.setCustomResponder(null);

    // --- 5. Public navigation: network-first, zero HTML cache, offline page -
    const publicNavUrl = `${ORIGIN}/results`;
    const navOnline = h.fireFetch(publicNavUrl, { mode: 'navigate', accept: 'text/html' });
    const navOnlineRes = await navOnline.event._p;
    assert('public navigation succeeds from live network when online', navOnlineRes && navOnlineRes.body === `body:${publicNavUrl}`);
    assert('public HTML navigation response is NOT stored in Cache Storage', !(await h.caches.match(publicNavUrl)));

    h.setOnline(false);
    const navOffline = h.fireFetch(publicNavUrl, { mode: 'navigate', accept: 'text/html' });
    const navOfflineRes = await navOffline.event._p;
    assert(
        'offline public navigation serves precached /offline.html with live-connection disclosure',
        !!navOfflineRes && typeof navOfflineRes.body === 'string' && navOfflineRes.body.includes('never cached offline')
    );
    h.setOnline(true);

    // --- 6. Activate deletes obsolete app caches ONLY, keeping other apps --
    await h.caches.open('tl-pwa-shell-v1');
    await h.caches.open('tl-pwa-assets-v1');
    await h.caches.open('unrelated-origin-app-v1');

    await Promise.all(h.listeners.activate.map((fn) => {
        let p;
        fn({ waitUntil: (pr) => { p = pr; } });
        return p;
    }));
    const remainingCaches = await h.caches.keys();
    assert(
        'activate deletes obsolete tl-pwa-* caches',
        !remainingCaches.includes('tl-pwa-shell-v1') && !remainingCaches.includes('tl-pwa-assets-v1'),
        remainingCaches.join(', ')
    );
    assert(
        'activate preserves unrelated origin caches',
        remainingCaches.includes('unrelated-origin-app-v1')
    );

    // --- 7. Opt-in SKIP_WAITING message support ----------------------------
    (h.listeners.message || []).forEach((fn) => fn({ data: { type: 'SKIP_WAITING' } }));
    assert('SKIP_WAITING message triggers controlled skipWaiting()', h.wasSkipWaitingCalled());

    console.log(`\n  Summary: ${passed} passed, ${failed} failed (respondWith calls: ${h.getRespondWithCalls()})`);
})();
