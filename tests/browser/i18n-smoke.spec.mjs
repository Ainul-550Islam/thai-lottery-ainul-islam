/**
 * i18n smoke: English, Thai and Bangla on the public/player surfaces.
 *
 * The copy under test is read from the REAL lang/{en,th,bn} PHP catalogues, so
 * a missing key, an untranslated Bangla fallback or a raw translation key that
 * leaked into rendered text fails here. Every request (including the built
 * bundles) is served from disk through page.route(); no network is used.
 */

import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { mountBatch08Page, TEST_L10N } from './batch08-helpers.mjs';

const LOCALES = Object.freeze({ en: 'en', th: 'th', bn: 'bn' });
const HTML_LANG = Object.freeze({ en: 'en', th: 'th', bn: 'bn' });
const KEY_PREFIXES = [
    'public_pages.', 'player.', 'deposit.', 'how_page.', 'fees_page.',
    'payout_illustration.', 'account_services.', 'notifications.',
];
// A key-shaped token is a whole whitespace-delimited word such as
// "player.status_pending" or "deposit.not_configured". Sentence punctuation
// ("...", "Mr.") is not a translation key and must not be flagged.
const LEAK = /^[a-z_]+\.[a-z_.]+$/;

function catalogue(locale, file) {
    const source = fs.readFileSync(path.join(process.cwd(), 'lang', locale, file + '.php'), 'utf8');
    const entries = new Map();
    const pattern = /'([A-Za-z0-9_.]+)'\s*=>\s*'((?:\\'|[^'])*)'/g;
    let match;
    while ((match = pattern.exec(source)) !== null) {
        entries.set(match[1], match[2].replace(/\\'/g, "'"));
    }
    return entries;
}

const PAGES = Object.freeze([
    ['home', ['public_pages', ['about_title', 'about_lead', 'skip_to_content']]],
    ['deposit', ['deposit', ['lanes_title', 'lanes_lead', 'not_configured', 'not_configured_help']]],
    ['how-to-play', ['how_page', ['illustration_title', 'illustration_lead', 'illustration_submit']]],
    ['fees', ['public_pages', ['download_title', 'download_hint', 'download_pdf']]],
    ['legal', ['public_pages', ['download_title', 'download_not_available', 'download_version']]],

]);

for (const [pageName, [catalogueName, keys]] of PAGES) {
    for (const [locale, folder] of Object.entries(LOCALES)) {
        test(`${pageName} renders clean localized copy in ${locale}`, async ({ page }) => {
            const entries = catalogue(folder, catalogueName);
            const values = keys.map((key) => {
                const value = entries.get(key);
                expect(value, `${folder}/${catalogueName}.php is missing ${key}`).toBeTruthy();
                return value;
            });

            const body = `<h1>${values[0]}</h1><p>${values[1]}</p><p>${values[2] ?? ''}</p>
                <span data-live-balance="wallet" data-currency="THB">0.00 THB</span>`;

            await mountBatch08Page(page, {
                route: '/' + pageName,
                lang: HTML_LANG[locale],
                body,
                l10n: TEST_L10N,
                meta: '<meta name="description" content="Localized fixture">',
            });

            await expect(page.locator('html')).toHaveAttribute('lang', HTML_LANG[locale]);
            const text = await page.locator('body').innerText();
            for (const prefix of KEY_PREFIXES) {
                expect(text, `raw ${prefix}* key leaked into ${pageName}/${locale}`).not.toContain(prefix);
            }
            expect(text).not.toContain('undefined');
            expect(text).not.toContain('NaN');
            // Dots in real sentences are fine; a dotted key-shaped token is not.
            for (const token of text.split(/\s+/)) {
                if (token === '' || token.includes('http')) {
                    continue;
                }
                const cleaned = token.replace(/^[^a-z_]+|[^a-z_.]+$/gi, '');
                expect(LEAK.test(cleaned), `translation key leaked as visible text: ${token}`).toBe(false);
            }
        });
    }
}

test('Bangla copy is a real override, not a silent English fallback', async ({ page }) => {
    const english = catalogue('en', 'deposit');
    const bangla = catalogue('bn', 'deposit');
    const thai = catalogue('th', 'deposit');

    for (const key of ['lanes_title', 'lanes_lead', 'not_configured', 'not_configured_help', 'qr_alt']) {
        expect(bangla.get(key), 'bn/deposit.php ' + key).toBeTruthy();
        expect(thai.get(key), 'th/deposit.php ' + key).toBeTruthy();
        expect(bangla.get(key)).not.toBe(english.get(key));
        expect(thai.get(key)).not.toBe(english.get(key));
    }

    await mountBatch08Page(page, {
        route: '/deposit',
        lang: 'bn',
        body: `<h1>${bangla.get('lanes_title')}</h1><p>${bangla.get('lanes_lead')}</p>`,
        l10n: TEST_L10N,
    });
    await expect(page.locator('body')).toContainText(bangla.get('lanes_title'));
});
