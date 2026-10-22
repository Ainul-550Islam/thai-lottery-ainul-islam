import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../resources/js/home/live-draw.js', import.meta.url), 'utf8');

function runWidget(liveStatus, replayStatus = 'not_configured') {
    const media = { removed: false, remove() { this.removed = true; } };
    const host = { querySelectorAll: () => [media] };
    const attributes = {
        'data-live-status': liveStatus,
        'data-replay-status': replayStatus,
    };
    const root = {
        querySelector: (selector) => selector === '[data-live-frame-host]' ? host : null,
        getAttribute: (name) => attributes[name] ?? null,
        setAttribute: (name, value) => { attributes[name] = value; },
    };
    const document = {
        readyState: 'complete',
        querySelectorAll: (selector) => selector === '[data-home-live-draw]' ? [root] : [],
        addEventListener() {},
    };

    vm.runInNewContext(source, { document });
    return { attributes, media };
}

test('configured stream is retained and reported as configured', () => {
    const { attributes, media } = runWidget('configured');
    assert.equal(media.removed, false);
    assert.equal(attributes['data-live-state'], 'configured');
});

test('unconfigured stream media is removed and state stays distinct', () => {
    const { attributes, media } = runWidget('not_configured');
    assert.equal(media.removed, true);
    assert.equal(attributes['data-live-state'], 'not_configured');
});

test('provider outage is not mislabeled as missing configuration', () => {
    const { attributes, media } = runWidget('unavailable');
    assert.equal(media.removed, true);
    assert.equal(attributes['data-live-state'], 'unavailable');
});

test('unknown server status fails closed to unavailable', () => {
    const { attributes, media } = runWidget('unexpected-status');
    assert.equal(media.removed, true);
    assert.equal(attributes['data-live-state'], 'unavailable');
});
