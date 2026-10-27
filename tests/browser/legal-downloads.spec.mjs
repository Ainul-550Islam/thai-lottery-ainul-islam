/**
 * Verified legal PDF downloads: progressive enhancement, document version and
 * the honest 501 / 404 NOT_CONFIGURED answers.
 *
 * A real PDF byte sequence is served through page.route(). No CDN, no external
 * asset and no Laravel server is contacted. The plain <a href> remains in the
 * DOM for the noscript / no-JavaScript fallback.
 */

import { expect, test } from '@playwright/test';
import { mountBatch08Page, TEST_L10N } from './batch08-helpers.mjs';

const DOWNLOAD_PATH = '/api/v1/public/terms/download';
const PDF = '%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n';

function legalBody() {
    return `<main id="main">
        <h1>Terms of use</h1>
        <section id="legal-downloads" aria-labelledby="legal-downloads-title">
            <h2 id="legal-downloads-title">Verified document</h2>
            <div class="tl-legal-actions">
                <a href="${DOWNLOAD_PATH}" data-legal-download data-filename="terms-of-use.pdf" data-document="terms">Download verified PDF</a>
            </div>
            <p data-legal-download-version role="status" aria-live="polite" hidden></p>
            <p data-legal-download-status role="status" aria-live="polite"></p>
            <noscript><p>Without JavaScript the download link opens the server answer.</p></noscript>
        </section>
    </main>`;
}

test.describe('Batch 08 legal PDF downloads', () => {
    test('a verified PDF is fetched with Accept: application/pdf and its version is shown', async ({ page }) => {
        const fixture = await mountBatch08Page(page, {
            route: '/terms',
            body: legalBody(),
            l10n: TEST_L10N,
            handlers: {
                ['GET ' + DOWNLOAD_PATH]: {
                    status: 200,
                    contentType: 'application/pdf',
                    headers: {
                        'Content-Disposition': 'attachment; filename="terms-v3.2.1.pdf"',
                        'X-Document-Version': 'v3.2.1',
                        'ETag': '"abc123"',
                    },
                    body: PDF,
                },
            },
        });

        const download = page.waitForEvent('download', { timeout: 10_000 }).catch(() => null);
        await page.locator('[data-legal-download]').click();

        await expect(page.locator('[data-legal-download-status]')).toContainText('Verified PDF ready.');
        await expect(page.locator('[data-legal-download-version]')).toContainText('Document version: v3.2.1');
        await expect(page.locator('[data-legal-download-version]')).toBeVisible();

        const matched = fixture.requests.find((entry) => entry.path === DOWNLOAD_PATH);
        expect(matched, 'the PDF endpoint was never requested').toBeTruthy();
        expect(matched.headers.accept).toContain('application/pdf');
        // A blob download is browser-generated; whether Playwright emits the
        // event depends on the browser's download policy, so only assert that
        // the application did not leave a broken anchor behind.
        await download;
        await expect(page.locator('[data-legal-download]')).toHaveAttribute('href', DOWNLOAD_PATH);
    });

    test('a 501 NOT_CONFIGURED answer shows the localized notice and disables the control', async ({ page }) => {
        await mountBatch08Page(page, {
            route: '/terms',
            body: legalBody(),
            l10n: TEST_L10N,
            handlers: {
                ['GET ' + DOWNLOAD_PATH]: {
                    status: 501,
                    body: {
                        success: false,
                        state: 'NOT_CONFIGURED',
                        message: 'A verified Terms PDF export is not configured for this environment.',
                    },
                },
            },
        });

        await page.locator('[data-legal-download]').click();

        await expect(page.locator('[data-legal-download-status]')).toContainText('not configured');
        await expect(page.locator('[data-legal-download]')).toHaveAttribute('aria-disabled', 'true');
        await expect(page.locator('[data-legal-download-status]')).not.toContainText('undefined');
    });

    test('a 404 NOT_AVAILABLE answer shows the print / save fallback', async ({ page }) => {
        await mountBatch08Page(page, {
            route: '/terms',
            body: legalBody(),
            l10n: TEST_L10N,
            handlers: {
                ['GET ' + DOWNLOAD_PATH]: {
                    status: 404,
                    body: { success: false, state: 'NOT_AVAILABLE', message: 'No published document.' },
                },
            },
        });

        await page.locator('[data-legal-download]').click();
        await expect(page.locator('[data-legal-download-status]')).toContainText('not available yet');
        await expect(page.locator('[data-legal-download]')).toHaveAttribute('aria-disabled', 'true');
    });

    test('an unsupported locale is reported without a broken download', async ({ page }) => {
        await mountBatch08Page(page, {
            route: '/terms',
            body: legalBody(),
            l10n: TEST_L10N,
            handlers: {
                ['GET ' + DOWNLOAD_PATH]: {
                    status: 422,
                    body: { success: false, state: 'UNSUPPORTED_LOCALE', supported_locales: ['en', 'th'] },
                },
            },
        });

        await page.locator('[data-legal-download]').click();
        await expect(page.locator('[data-legal-download-status]')).toContainText('not published for download');
        await expect(page.locator('[data-legal-download]')).not.toHaveAttribute('aria-disabled', 'true');
    });
});
