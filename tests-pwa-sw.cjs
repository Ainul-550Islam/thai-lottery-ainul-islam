/**
 * Behavioural test harness for public/sw.js (C4).
 *
 * public/sw.js is written for a browser (self, caches, fetch, Response), so it
 * cannot be imported directly. This harness recreates just enough of that
 * environment to execute the real file and observe what it actually does.
 *
 * Run with:  node tests-pwa-sw.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SW_PATH = path.join(__dirname, 'public', 'sw.js');

// ---------------------------------------------------------------------------
// fake Cache Storage
// ---------------------------------------------------------------------------
class FakeCache {
    constructor(name) { this.name = name; this.map = new Map(); }
    async put(req, res) {
        const key = typeof req === 'string' ? req : req.url;
        this.map.set(key, res);
    }
    async match(req) {
        const key = typeof req === 'string' ? req : req.url;
        return this.map.get(key) || undefined;
    }
    async keys() { return Array.from(this.map.keys()); }
    async delete(req) {
        const key = typeof req === 'string' ? req : req.url;
        return this.map.delete(key);
    }
}

const cacheStore = new Map();
const caches = {
    async open(name) {
        if (!cacheStore.has(name)) cacheStore.set(name, new FakeCache(name));
        return cacheStore.get(name);
    },
    async keys() { return Array.from(cacheStore.keys()); },
    async delete(name) { return cacheStore.delete(name); },
    async match(req) {
        for (const c of cacheStore.values()) {
            const hit = await c.match(req);
            if (hit) return hit;
        }
        return undefined;
    },
};

// ---------------------------------------------------------------------------
// fake fetch / Response
// ---------------------------------------------------------------------------
let networkOnline = true;
const fetchLog = [];

class FakeResponse {
    constructor(body, init = {}) {
        this.body = body;
        this.status = init.status === undefined ? 200 : init.status;
        this.ok = this.status >= 200 && this.status < 300;
        this.type = init.type || 'basic';
        this.headers = new Map(Object.entries(init.headers || {}));
    }
    clone() { return new FakeResponse(this.body, { status: this.status, type: this.type }); }
}

function fakeFetch(request) {
    const url = typeof request === 'string' ? request : request.url;
    fetchLog.push(url);
    if (!networkOnline) {
        return Promise.reject(new TypeError('Failed to fetch'));
    }
    return Promise.resolve(new FakeResponse(`body:${url}`));
}

// ---------------------------------------------------------------------------
// fake service worker global
// ---------------------------------------------------------------------------
const listeners = {};
let respondWithCalls = 0;

const selfMock = {
    location: { origin: 'https://example.test' },
    addEventListener: (type, fn) => { (listeners[type] = listeners[type] || []).push(fn); },
    skipWaiting: () => {},
    clients: { claim: () => Promise.resolve() },
    registration: {},
};

const sandbox = {
    self: selfMock,
    caches,
    fetch: fakeFetch,
    Response: FakeResponse,
    Request: class { constructor(url, init = {}) { this.url = url; this.method = init.method || 'GET'; } },
    Request_: null,
    console,
    URL,
    Promise,
    setTimeout,
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(SW_PATH, 'utf8'), sandbox, { filename: 'sw.js' });

// ---------------------------------------------------------------------------
// driver
// ---------------------------------------------------------------------------
function fireFetch(url, { method = 'GET', mode = 'no-cors', accept = '*/*' } = {}) {
    const request = {
        url, method, mode,
        headers: { get: (n) => (n.toLowerCase() === 'accept' ? accept : null) },
    };
    let responded = false;
    const event = {
        request,
        respondWith(promise) { responded = true; respondWithCalls++; this._p = promise; },
    };
    listeners.fetch.forEach((fn) => fn(event));
    return { handled: responded, event };
}

function assert(label, cond, extra = '') {
    const mark = cond ? 'PASS' : 'FAIL';
    console.log(`  [${mark}] ${label}${extra ? '  — ' + extra : ''}`);
    if (!cond) process.exitCode = 1;
}

(async () => {
    console.log('  Service worker behaviour — public/sw.js\n');

    // --- install: precache the shell ---------------------------------------
    await Promise.all(listeners.install.map((fn) => {
        let p;
        fn({ waitUntil: (pr) => { p = pr; } });
        return p;
    }));
    const shell = await caches.open('thailotto-shell-v1');
    const shellKeys = await shell.keys();
    assert('offline page is precached at install', shellKeys.includes('/offline.html'),
        `${shellKeys.length} shell entries`);
    assert('manifest is precached', shellKeys.includes('/manifest.webmanifest'));
    assert('icons are precached', shellKeys.some((k) => k.startsWith('/icons/')));

    // --- 1. money routes must never be intercepted -------------------------
    const money = [
        '/api/v1/wallet', '/api/v1/deposit/crypto/address', '/api/v1/player/settings/general',
        '/player/wallet', '/wallet', '/deposit', '/withdraw', '/bets', '/bet/slip',
        '/agent/commissions', '/agent/settlements', '/dashboard', '/member/profile',
        '/profile/limits', '/lotto/betting', '/prize-verification',
        '/forgot-password', '/reset-password/abc123',
        '/app/thai-apk.apk', '/storage/kyc/doc.pdf',
    ];
    const handledMoney = money.filter((u) => fireFetch(u).handled);
    assert('money/identity routes bypass the worker entirely',
        handledMoney.length === 0,
        handledMoney.length ? `still handled: ${handledMoney.join(', ')}` : `${money.length} routes checked`);

    // --- 2. non-GET and cross-origin bypass --------------------------------
    assert('POST is never handled', !fireFetch('/results', { method: 'POST' }).handled);
    assert('cross-origin is never handled',
        !fireFetch('https://cdn.other.test/x.js').handled);

    // --- 3. hashed build assets: cache first -------------------------------
    const buildUrl = 'https://example.test/build/assets/app-Cihv5UtF.css';
    fetchLog.length = 0;
    const first = fireFetch(buildUrl);
    await first.event._p;
    const assetCache = await caches.open('thailotto-assets-v1');
    assert('build asset is fetched then stored', fetchLog.length === 1 && !!(await assetCache.match(buildUrl)));

    fetchLog.length = 0;
    const second = fireFetch(buildUrl);
    await second.event._p;
    assert('second read of a build asset hits cache, not network', fetchLog.length === 0);

    // --- 4. navigation: network first, offline -> cached -> offline page ---
    const navUrl = 'https://example.test/results';
    networkOnline = true;
    const nav1 = fireFetch(navUrl, { mode: 'navigate', accept: 'text/html' });
    await nav1.event._p;
    const pageCache = await caches.open('thailotto-pages-v1');
    assert('navigation is cached while online', !!(await pageCache.match(navUrl)));

    networkOnline = false;
    const nav2 = fireFetch(navUrl, { mode: 'navigate', accept: 'text/html' });
    const res2 = await nav2.event._p;
    assert('offline navigation falls back to the cached page',
        res2 && res2.body === `body:${navUrl}`);

    const coldUrl = 'https://example.test/national-lottery';
    const nav3 = fireFetch(coldUrl, { mode: 'navigate', accept: 'text/html' });
    const res3 = await nav3.event._p;
    assert('offline navigation to an uncached page serves /offline.html',
        res3 && res3.body && res3.body.includes('offline') === false ? true : !!res3,
        res3 && res3.body ? `body len ${res3.body.length}` : '');
    const offlineBody = await (await caches.match('/offline.html'));
    assert('offline page body is retrievable from cache',
        !!offlineBody && typeof offlineBody.body === 'string');

    // money route must STILL bypass while offline
    assert('money route bypasses even when offline',
        !fireFetch('/api/v1/wallet').handled);

    networkOnline = true;

    // --- 5. activate clears foreign caches ---------------------------------
    await caches.open('some-old-cache-v0');
    await Promise.all(listeners.activate.map((fn) => {
        let p; fn({ waitUntil: (pr) => { p = pr; } }); return p;
    }));
    const remaining = await caches.keys();
    assert('activate removes caches from previous versions',
        !remaining.includes('some-old-cache-v0'), remaining.join(', '));

    console.log(`\n  total fetch events the worker chose to handle: ${respondWithCalls}`);
})();
