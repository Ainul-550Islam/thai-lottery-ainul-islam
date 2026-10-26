// TYPE: Playwright configuration
// PURPOSE: Run public and authentication-entry browser smoke tests against a real Laravel server without embedding credentials.

import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    timeout: 30_000,
    expect: { timeout: 10_000 },
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['line'], ['json', { outputFile: 'runtime/playwright-results.json' }]] : 'list',
    use: {
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        serviceWorkers: 'block',
    },
    projects: [
        { name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'chromium-mobile', use: { ...devices['Pixel 5'] } },
    ],
});
