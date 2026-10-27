// Plain `node --test tests/js/qr-encoder.test.mjs`. No bundler, no browser:
// the encoder is pure arithmetic, so it can be proven here.
import assert from 'node:assert/strict';
import test from 'node:test';
import { FIXTURES } from './fixtures/qr-reference-matrices.mjs';
import { encodeQr } from '../../resources/js/vendor/qr-encoder.js';

/** djb2 over the concatenated rows — the same function the fixtures were pinned with. */
function digest(rows) {
    let value = 5381;

    for (const row of rows) {
        for (const character of row) {
            value = ((value * 33) + character.codePointAt(0)) & 0xFFFFFFFF;
        }
    }

    return value;
}

function rowsOf(symbol) {
    return symbol.modules.map((row) => row.map((module) => (module ? '1' : '0')).join(''));
}

test('every reference symbol is reproduced module for module', () => {
    for (const fixture of FIXTURES) {
        const symbol = encodeQr(fixture.payload, { ecl: fixture.ecl });

        assert.equal(symbol.version, fixture.version, `${fixture.name}: version`);
        assert.equal(symbol.mask, fixture.mask, `${fixture.name}: mask chosen by penalty score`);
        assert.equal(symbol.size, fixture.version * 4 + 17, `${fixture.name}: symbol size`);

        const rows = rowsOf(symbol);

        assert.equal(
            digest(rows) >>> 0,
            fixture.digest,
            `${fixture.name}: symbol does not match the reference encoder`,
        );

        if (fixture.rows) {
            assert.deepEqual(rows, fixture.rows, `${fixture.name}: full matrix`);
        }
    }
});

test('an explicit mask reproduces the same symbol as an automatic one', () => {
    const fixture = FIXTURES.find((candidate) => candidate.name === 'promptpay_M');
    const explicit = encodeQr(fixture.payload, { ecl: fixture.ecl, mask: fixture.mask });

    assert.deepEqual(rowsOf(explicit), rowsOf(encodeQr(fixture.payload, { ecl: fixture.ecl })));
});

test('the payload is encoded exactly, without trimming or case folding', () => {
    const base = '00020101021229370016A00000067701011101130066812345678';
    const withSpace = `${base} 5802TH`;

    const a = encodeQr(base, { ecl: 'M' });
    const b = encodeQr(withSpace, { ecl: 'M' });

    // One extra byte must produce a different symbol: if the encoder silently
    // normalised the string, these two would collide and a payment code could
    // scan to the wrong account.
    assert.notEqual(digest(rowsOf(a)) >>> 0, digest(rowsOf(b)) >>> 0);
});

test('a payload that cannot fit is refused rather than truncated', () => {
    // 834 bytes is the documented ceiling at ECC level M; beyond the module's
    // supported versions there is no honest answer except a throw.
    assert.throws(() => encodeQr('A'.repeat(900), { ecl: 'M' }), /does not fit/);
});

test('an empty payload and an unknown ECC level are refused', () => {
    assert.throws(() => encodeQr('', { ecl: 'M' }), TypeError);
    assert.throws(() => encodeQr('x', { ecl: 'Z' }), RangeError);
});

test('finder patterns sit in the three corners and the timing row alternates', () => {
    const { modules, size } = encodeQr('test', { ecl: 'M' });

    const expectedFinder = [
        [1, 1, 1, 1, 1, 1, 1],
        [1, 0, 0, 0, 0, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 1, 1, 1, 0, 1],
        [1, 0, 0, 0, 0, 0, 1],
        [1, 1, 1, 1, 1, 1, 1],
    ];

    for (const [originX, originY] of [[0, 0], [size - 7, 0], [0, size - 7]]) {
        for (let y = 0; y < 7; y += 1) {
            for (let x = 0; x < 7; x += 1) {
                assert.equal(
                    modules[originY + y][originX + x] ? 1 : 0,
                    expectedFinder[y][x],
                    `finder at ${originX},${originY} module ${x},${y}`,
                );
            }
        }
    }

    for (let i = 8; i < size - 8; i += 1) {
        assert.equal(modules[6][i] ? 1 : 0, i % 2 === 0 ? 1 : 0, `timing row at ${i}`);
    }
});
