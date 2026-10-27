// Plain `node --test tests/js/frontend-api.test.mjs`.
//
// The shared helpers are the reason no lane has to re-implement CSRF, the
// NOT_CONFIGURED distinction, or money formatting. That also means a bug here is
// a bug in every lane at once, so the rules are pinned here instead of being
// trusted.
import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clearTimers,
    errorCode,
    fillUrl,
    formatAmount,
    isNotConfigured,
    serverMessage,
    statusLabel,
    text,
} from '../../resources/js/frontend-api.js';

test('formatAmount groups thousands and always shows two decimals', () => {
    assert.equal(formatAmount('1234.5', 'THB'), '1,234.50 THB');
    assert.equal(formatAmount('1000000', 'THB'), '1,000,000.00 THB');
    assert.equal(formatAmount('0.05', 'THB'), '0.05 THB');
    assert.equal(formatAmount('999', ''), '999.00');
});

test('formatAmount refuses anything that is not a decimal money string', () => {
    // Returning "" rather than a guess is the whole point: a caller that renders
    // the result shows nothing instead of a wrong figure.
    for (const bad of ['', ' ', 'abc', '-5.00', '1.234', '1e5', '12,34', null, undefined, '12.3.4']) {
        assert.equal(formatAmount(bad, 'THB'), '', `accepted ${String(bad)}`);
    }
});

test('formatAmount never rounds: three decimals are refused, not trimmed', () => {
    assert.equal(formatAmount('10.005', 'THB'), '');
});

test('text substitutes :placeholders and leaves unknown keys empty', () => {
    const l10n = { greeting: 'Hello :name, you have :count new items', plain: 'Nothing to substitute' };

    assert.equal(text(l10n, 'greeting', { name: 'Somchai', count: 3 }), 'Hello Somchai, you have 3 new items');
    assert.equal(text(l10n, 'plain'), 'Nothing to substitute');
    assert.equal(text(l10n, 'missing'), '');
});

test('text does not treat a substitution value as a format string', () => {
    const l10n = { line: 'Value: :value' };

    assert.equal(text(l10n, 'line', { value: '$&$1' }), 'Value: $&$1');
});

test('isNotConfigured recognises the honest not-configured answers', () => {
    assert.equal(isNotConfigured({ ok: false, status: 501, data: { state: 'NOT_CONFIGURED' } }), true);
    assert.equal(isNotConfigured({ ok: false, status: 503, data: { state: 'NOT_AVAILABLE' } }), true);
    assert.equal(isNotConfigured({ ok: false, status: 404, data: null }), true);
});

test('isNotConfigured is false for a real failure and for success', () => {
    assert.equal(isNotConfigured({ ok: false, status: 500, data: { state: 'NOT_CONFIGURED' } }), false);
    assert.equal(isNotConfigured({ ok: true, status: 200, data: { state: 'NOT_CONFIGURED' } }), false);
    assert.equal(isNotConfigured({ ok: false, status: 422, data: { code: 'INVALID' } }), false);
    assert.equal(isNotConfigured(null), false);
});

test('errorCode reads both envelope shapes the application uses', () => {
    assert.equal(errorCode({ data: { error: { code: 'INSUFFICIENT_FUNDS' } } }), 'INSUFFICIENT_FUNDS');
    assert.equal(errorCode({ data: { code: 'INVALID_INPUT' } }), 'INVALID_INPUT');
    assert.equal(errorCode({ data: {} }), '');
    assert.equal(errorCode(null), '');
});

test('serverMessage reads both envelope shapes and never invents a message', () => {
    assert.equal(serverMessage({ data: { error: { message: 'Not enough balance' } } }), 'Not enough balance');
    assert.equal(serverMessage({ data: { message: 'Accepted' } }), 'Accepted');
    assert.equal(serverMessage({ data: {} }), '');
    assert.equal(serverMessage(null), '');
});

test('statusLabel translates known statuses and shows unknown ones verbatim', () => {
    const l10n = { status_pending: 'Waiting for payment', status_completed: 'Complete' };

    assert.equal(statusLabel(l10n, 'pending'), 'Waiting for payment');
    assert.equal(statusLabel(l10n, 'COMPLETED'), 'Complete');
    assert.equal(statusLabel(l10n, 'awaiting_review'), 'awaiting_review');
    assert.equal(statusLabel(l10n, ''), '—');
});

test('fillUrl substitutes an encoded token in both supported shapes', () => {
    assert.equal(
        fillUrl('/api/v1/deposit/slip/__VERIFICATION_ID__', '__VERIFICATION_ID__', 'a b/c'),
        '/api/v1/deposit/slip/a%20b%2Fc',
    );

    assert.equal(fillUrl('/api/v1/x/:id', ':id', 42), '/api/v1/x/42');
});

test('fillUrl escapes a value that contains URL syntax', () => {
    const filled = fillUrl('/api/v1/x/:id', ':id', 'p/../admin?x=1');

    assert.equal(filled, '/api/v1/x/p%2F..%2Fadmin%3Fx%3D1');
    assert.equal(filled.includes('?'), false, 'a value must not introduce a query string');
});

test('clearTimers cancels everything and empties the array in place', () => {
    const cancelled = [];
    const originalClearTimeout = globalThis.clearTimeout;
    const originalClearInterval = globalThis.clearInterval;

    globalThis.clearTimeout = (id) => cancelled.push(id);
    globalThis.clearInterval = (id) => cancelled.push(id);

    try {
        const timers = [1, 2, 3];
        clearTimers(timers);

        assert.deepEqual(timers, [], 'the array must be emptied so a caller cannot reuse a cancelled id');

        // The helper does not know whether an id came from setTimeout or
        // setInterval, so it clears each id through both — and clearing an
        // unrelated id is a no-op in every browser. What matters is coverage:
        // every id is cleared at least once.
        assert.deepEqual(
            [...new Set(cancelled)].sort(),
            [1, 2, 3],
            'every id must be cancelled',
        );
    } finally {
        globalThis.clearTimeout = originalClearTimeout;
        globalThis.clearInterval = originalClearInterval;
    }
});
