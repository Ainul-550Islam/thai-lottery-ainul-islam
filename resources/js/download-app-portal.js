/**
 * TYPE: page module (fail-closed)
 * PURPOSE: Surface the VERIFIED Android release on the download portal.
 *
 * WHAT REPLACED THE OLD PROTOTYPE
 * This file previously hard-coded a fabricated release: a package name the
 * platform does not own, versions and SHA-256 digests nobody had built, and
 * download URLs that could not exist. Every one of those numbers was invented,
 * and an invented checksum is worse than none — a member who compares against
 * it concludes a real file is corrupt. The module now reads exactly one source
 * of truth: the served /app/thai-apk.manifest.json, which the backend only
 * returns while the deployed binary matches its manifest (see
 * AndroidApkReleaseService). A 404, a parse failure, or any status other than
 * VERIFIED renders the honest unavailable state and no download button.
 *
 * It never fabricates platform rows, never generates QR codes for addresses it
 * cannot verify, and never computes money or hashes in the browser.
 */
(function () {
    'use strict';

    var MANIFEST_URL = '/app/thai-apk.manifest.json';
    var APK_URL = '/app/thai-apk.apk';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function formatBytes(bytes) {
        if (typeof bytes !== 'number' || !isFinite(bytes) || bytes <= 0) {
            return '';
        }
        if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function isVerifiedManifest(data) {
        return !!data
            && typeof data === 'object'
            && data.status === 'VERIFIED'
            && typeof data.sha256 === 'string'
            && /^[a-f0-9]{64}$/.test(data.sha256)
            && typeof data.version === 'string'
            && data.version.length > 0
            && data.package_name === 'com.ainul550.thailottery'
            && data.file === 'app/thai-apk.apk';
    }

    function clear(node) {
        while (node && node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    function renderVerified(portal, manifest) {
        clear(portal);

        var card = el('div', 'dp-release dp-release--verified');
        card.appendChild(el('p', 'dp-release__status', 'Status: VERIFIED'));
        card.appendChild(el('p', 'dp-release__version', 'Version ' + manifest.version));
        card.appendChild(el('p', 'dp-release__package', manifest.package_name));

        var size = formatBytes(Number(manifest.size_bytes));
        if (size) {
            card.appendChild(el('p', 'dp-release__size', size));
        }

        var hash = el('code', 'dp-release__sha256', manifest.sha256);
        card.appendChild(el('p', 'dp-release__label', 'SHA-256'));
        card.appendChild(hash);

        var button = el('a', 'dp-release__button');
        button.href = APK_URL;
        button.setAttribute('download', 'thai-apk.apk');
        button.setAttribute('rel', 'noopener noreferrer');
        button.textContent = 'Download Android APK';
        card.appendChild(button);

        portal.appendChild(card);
    }

    function renderUnavailable(portal) {
        clear(portal);

        var card = el('div', 'dp-release dp-release--empty');
        card.setAttribute('role', 'status');
        card.appendChild(el('p', 'dp-release__status', 'Status: NOT_CONFIGURED'));
        card.appendChild(
            el(
                'p',
                'dp-release__note',
                'No verified Android release is available. The download stays closed until a signed build matches its manifest.'
            )
        );
        portal.appendChild(card);
    }

    function mountIntoSlots(manifest) {
        var slots = document.querySelectorAll('[data-apk-integrity-slot]');
        for (var i = 0; i < slots.length; i += 1) {
            if (isVerifiedManifest(manifest)) {
                renderVerified(slots[i], manifest);
            } else {
                renderUnavailable(slots[i]);
            }
        }
    }

    function load() {
        var portals = document.querySelectorAll('[data-download-portal]');
        var slots = document.querySelectorAll('[data-apk-integrity-slot]');
        if (portals.length === 0 && slots.length === 0) {
            return;
        }

        fetch(MANIFEST_URL, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (response) {
                if (!response.ok) {
                    return null;
                }
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (manifest) {
                var verified = isVerifiedManifest(manifest) ? manifest : null;

                for (var i = 0; i < portals.length; i += 1) {
                    if (verified) {
                        renderVerified(portals[i], verified);
                    } else {
                        renderUnavailable(portals[i]);
                    }
                }

                mountIntoSlots(verified);
            })
            .catch(function () {
                for (var i = 0; i < portals.length; i += 1) {
                    renderUnavailable(portals[i]);
                }
                mountIntoSlots(null);
            });
    }

    ready(load);
})();
