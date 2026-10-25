// TYPE: Playwright configuration
// PURPOSE: deterministic browser coverage for the public pages, deposit lanes,
// i18n, accessibility, the realtime bridge and verified legal downloads.

import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8000';
const webServerUrl = process.env.E2E_WEB_SERVER_URL || baseURL;
const webServerCommand = process.env.E2E_WEB_SERVER_COMMAND
    || 'php artisan serve --host=127.0.0.1 --port=8000';

export default defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['line'], ['json', { outputFile: 'runtime/playwright-results.json' }]] : 'list',
    use: {
        baseURL,
        // Fixed locale, timezone, motion and colour scheme: an illustration or
        // countdown must not depend on the machine that happens to run CI.
        locale: process.env.E2E_LOCALE || 'en-US',
        timezoneId: process.env.E2E_TIMEZONE || 'Asia/Bangkok',
        colorScheme: 'dark',
        reducedMotion: 'reduce',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        serviceWorkers: 'block',
    },
    webServer: {
        command: webServerCommand,
        url: webServerUrl,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        // No application secret is embedded here. A deployment supplies its own
        // env file; local runs use the repository's testing defaults.
        env: {
            APP_ENV: process.env.APP_ENV || 'testing',
            APP_DEBUG: 'false',
        },
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1365, height: 900 } },
        },
        {
            name: 'chromium-mobile',
            use: { ...devices['Pixel 5'] },
        },
    ],
});
