/**
 * Thai Lottery Progressive Web App Service Worker
 *
 * Security & financial boundary policy:
 * 1. Ignores all non-GET and cross-origin requests before calling respondWith().
 * 2. Explicitly bypasses all authenticated, financial, wallet, wager, identity,
 *    KYC, notification, support, operator, admin, agent, API, webhook, and
 *    storage routes (after strict URL normalization) — both online and offline.
 * 3. Precaches only an explicit, static public shell atomically via addAll().
 * 4. Caches only immutable content-hashed Vite build assets (/build/assets/*)
 *    with bounded cleanup, rejecting Set-Cookie / private / no-store responses.
 * 5. Never caches HTML documents (which may contain CSRF tokens, auth banners,
 *    or stale draw outcomes); public navigations are network-first with an
 *    offline fallback to precached /offline.html.
 * 6. Activate cleanup removes only obsolete caches owned by this app prefix.
 */
'use strict';

const CACHE_PREFIX = 'tl-pwa-';
const CACHE_VERSION = 'v2';
const SHELL_CACHE = `${CACHE_PREFIX}shell-${CACHE_VERSION}`;
const ASSET_CACHE = `${CACHE_PREFIX}assets-${CACHE_VERSION}`;
const MAX_ASSET_ENTRIES = 160;

const PRECACHE_SHELL_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/favicon.ico',
    '/icons/favicon-16.png',
    '/icons/favicon-32.png',
    '/icons/apple-touch-icon.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-192.png',
    '/icons/icon-maskable-512.png',
];

const SHELL_PATH_SET = new Set(PRECACHE_SHELL_URLS);

const IMMUTABLE_BUILD_ASSET_RE = /^\/build\/assets\/[A-Za-z0-9._-]+-[A-Za-z0-9_-]{6,}\.(?:css|js|mjs|woff2?|ttf|otf|png|jpe?g|webp|avif|svg)$/;

const BYPASS_EXACT_OR_PREFIXES = [
    '/api',
    '/sanctum',
    '/broadcasting',
    '/livewire',
    '/filament',
    '/login',
    '/logout',
    '/register',
    '/forgot-password',
    '/reset-password',
    '/password',
    '/two-factor',
    '/two-factor-challenge',
    '/user',
    '/email/verify',
    '/verify-email',
    '/confirm-password',
    '/auth',
    '/dashboard',
    '/wallet',
    '/deposit',
    '/deposits',
    '/withdraw',
    '/withdrawal',
    '/withdrawals',
    '/payment',
    '/payments',
    '/payout',
    '/payouts',
    '/transfer',
    '/transfers',
    '/transaction',
    '/transactions',
    '/conversion',
    '/bet',
    '/bets',
    '/betting',
    '/lotto/betting',
    '/ticket-check',
    '/check',
    '/prize-verification',
    '/ticket',
    '/tickets',
    '/draws',
    '/player',
    '/member',
    '/account/verification',
    '/account',
    '/profile',
    '/settings',
    '/security',
    '/kyc',
    '/verification',
    '/responsible-gaming',
    '/rewards',
    '/notifications',
    '/support/tickets',
    '/support/portal',
    '/chat',
    '/operator',
    '/admin',
    '/agent',
    '/horizon',
    '/telescope',
    '/pulse',
    '/storage',
    '/uploads',
    '/app',
    '/webhook',
    '/webhooks',
    '/locale',
    '/up',
    '/health',
    '/metrics',
];

const BYPASS_SUFFIX_PATTERNS = [
    /\/buy(?:\/|$)/,
    /\/search(?:\/|$)/,
    /\/purchase(?:\/|$)/,
    /\/checkout(?:\/|$)/,
    /\/callback(?:\/|$)/,
    /\.apk$/i,
    /\.pdf$/i,
];

function normalizePathname(rawPathname) {
    let decoded = rawPathname || '/';
    try {
        decoded = decodeURIComponent(decoded);
    } catch (_) {
        return null;
    }
    const collapsed = decoded.replace(/\/{2,}/g, '/');
    const segments = [];
    for (const part of collapsed.split('/')) {
        if (!part || part === '.') {
            continue;
        }
        if (part === '..') {
            segments.pop();
            continue;
        }
        segments.push(part);
    }
    return '/' + segments.join('/');
}

function isSensitiveOrBypassedPath(pathname, search) {
    if (!pathname) {
        return true;
    }
    const lower = pathname.toLowerCase();

    for (let i = 0; i < BYPASS_EXACT_OR_PREFIXES.length; i++) {
        const prefix = BYPASS_EXACT_OR_PREFIXES[i];
        if (lower === prefix || lower.startsWith(prefix + '/')) {
            return true;
        }
    }

    for (let j = 0; j < BYPASS_SUFFIX_PATTERNS.length; j++) {
        if (BYPASS_SUFFIX_PATTERNS[j].test(lower)) {
            return true;
        }
    }

    if (search) {
        const lowerSearch = search.toLowerCase();
        if (
            lowerSearch.includes('token=') ||
            lowerSearch.includes('signature=') ||
            lowerSearch.includes('code=') ||
            lowerSearch.includes('ticket=') ||
            lowerSearch.includes('number=')
        ) {
            return true;
        }
    }

    return false;
}

function isSafeStaticResponse(response) {
    if (!response || response.status !== 200 || response.type !== 'basic') {
        return false;
    }
    const headers = response.headers;
    if (headers && typeof headers.get === 'function') {
        if (headers.get('set-cookie')) {
            return false;
        }
        const cc = (headers.get('cache-control') || '').toLowerCase();
        if (cc.includes('no-store') || cc.includes('private')) {
            return false;
        }
        const vary = (headers.get('vary') || '').trim();
        if (vary === '*') {
            return false;
        }
    }
    return true;
}

async function trimCache(cache, maxEntries) {
    const keys = await cache.keys();
    if (keys.length <= maxEntries) {
        return;
    }
    const excess = keys.length - maxEntries;
    for (let i = 0; i < excess; i++) {
        await cache.delete(keys[i]);
    }
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL_CACHE).then((cache) => cache.addAll(PRECACHE_SHELL_URLS))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            const deletions = keys
                .filter((key) => key.startsWith(CACHE_PREFIX) && key !== SHELL_CACHE && key !== ASSET_CACHE)
                .map((key) => caches.delete(key));
            return Promise.all(deletions);
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('message', (event) => {
    if (event && event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (!request || request.method !== 'GET') {
        return;
    }

    let parsedUrl;
    try {
        parsedUrl = new URL(request.url, self.location.origin);
    } catch (_) {
        return;
    }

    if (parsedUrl.origin !== self.location.origin) {
        return;
    }

    const normalizedPath = normalizePathname(parsedUrl.pathname);
    if (!normalizedPath || isSensitiveOrBypassedPath(normalizedPath, parsedUrl.search)) {
        return;
    }

    // 1. Precached public shell artifacts (manifest, icons, offline fallback)
    if (SHELL_PATH_SET.has(normalizedPath)) {
        event.respondWith(
            caches.open(SHELL_CACHE).then(async (cache) => {
                const cached = await cache.match(normalizedPath);
                if (cached) {
                    return cached;
                }
                const fresh = await fetch(request);
                if (isSafeStaticResponse(fresh)) {
                    await cache.put(normalizedPath, fresh.clone());
                }
                return fresh;
            })
        );
        return;
    }

    // 2. Immutable content-hashed Vite build assets (/build/assets/*)
    if (IMMUTABLE_BUILD_ASSET_RE.test(normalizedPath)) {
        event.respondWith(
            caches.open(ASSET_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                if (cached) {
                    return cached;
                }
                const fresh = await fetch(request);
                if (isSafeStaticResponse(fresh)) {
                    await cache.put(request, fresh.clone());
                    await trimCache(cache, MAX_ASSET_ENTRIES);
                }
                return fresh;
            })
        );
        return;
    }

    // 3. Public HTML document navigations: network-first, NEVER cached, offline fallback only
    const acceptHeader = request.headers && typeof request.headers.get === 'function'
        ? (request.headers.get('accept') || '')
        : '';
    const isHtmlNavigation = request.mode === 'navigate' || acceptHeader.includes('text/html');

    if (isHtmlNavigation) {
        event.respondWith(
            fetch(request).catch(async () => {
                const shellCache = await caches.open(SHELL_CACHE);
                const offlineFallback = await shellCache.match('/offline.html');
                if (offlineFallback) {
                    return offlineFallback;
                }
                return new Response(
                    '<!doctype html><html lang="en"><meta charset="utf-8"><title>Offline</title><body><h1>You are offline</h1><p>Live lottery results and wallet services require an active internet connection.</p></body></html>',
                    {
                        status: 503,
                        headers: {
                            'content-type': 'text/html; charset=utf-8',
                            'cache-control': 'no-store',
                        },
                    }
                );
            })
        );
    }
});
