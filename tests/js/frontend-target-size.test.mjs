import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
// Unrendered Tailwind source minimum-size contract, NOT compiled CSS, a pixel
// measurement, browser target spacing, overflow or device acceptance.
for (const locale of ['en', 'th', 'bn']) {
    test(`mobile navigation ${locale} locale target declares at least44px in both dimensions`, () => {
        const source = fs.readFileSync('resources/views/components/navigation/mobile-nav.blade.php', 'utf8');
        const anchors = [...source.matchAll(/<a\b(?=[^>]*route\('locale\.switch', '(en|th|bn)'\))[^>]*class="([^"]*)"[^>]*>/g)];
        assert.equal(anchors.length, 3, 'Actual navigation must retain all3 existing locale actions');
        const entry = anchors.find(match => match[1] === locale); assert.ok(entry);
        for (const dimension of ['h', 'w']) {
            const token = entry[2].split(/\s+/).find(value => new RegExp(`^min-${dimension}-\\[\\d+px\\]$`).test(value));
            assert.ok(token, `${locale}: missing minimum ${dimension} source declaration`);
            assert.ok(Number(token.match(/\[(\d+)px\]/)[1]) >= 44, `${locale}: ${dimension} below required44px`);
        }
    });
}
