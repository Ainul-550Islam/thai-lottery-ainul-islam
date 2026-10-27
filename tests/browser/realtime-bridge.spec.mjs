/**
 * Realtime bridge: DOM events published by echo.js become accessible toasts
 * and live-balance updates, and polling keeps working with realtime OFF.
 *
 * window.Echo and window.WebSocket are stubbed through addInitScript, and
 * every network request is mocked. No socket is opened and no server is
 * contacted; the events are the same CustomEvents the real bridge dispatches.
 */

import { expect, test } from '@playwright/test';
import { depositMarkup, mountBatch08Page, TEST_L10N } from './batch08-helpers.mjs';

async function stubRealtimeGlobals(page) {
    await page.addInitScript(() => {
        window.__batch08Sockets = 0;
        window.WebSocket = class StubWebSocket {
            constructor() {
                window.__batch08Sockets += 1;
                this.readyState = 0;
            }
            close() { this.readyState = 3; }
            send() { return undefined; }
            addEventListener() { return undefined; }
            removeEventListener() { return undefined; }
        };
        window.Echo = {
            private: () => ({ listen: () => ({ listen: () => ({}) }) }),
            channel: () => ({ listen: () => ({ listen: () => ({}) }) }),
            disconnect: () => undefined,
        };
    });
}

const MONEY_EVENTS = Object.freeze([
    ['lottery:wallet-updated', { balance: '999.25', currency: 'THB' }, 'Wallet balance updated'],
    ['lottery:bet-placed', { status: 'pending', reference_number: 'BET-SECRET-1', total_stake: '10.00' }, 'Bet recorded'],
    ['lottery:withdrawal-updated', { status: 'failed', reference_number: 'WD-SECRET-2' }, 'Withdrawal status updated'],
    ['lottery:deposit-updated', { status: 'confirmed', reference_number: 'DEP-SECRET-3' }, 'Deposit status updated'],
    ['lottery:payout-completed', { status: 'completed', reference_number: 'PAY-SECRET-4' }, 'Prize payout completed'],
]);

test.describe('Batch 08 realtime bridge', () => {
    test('each private money event produces a polite toast with no private payload', async ({ page }) => {
        await stubRealtimeGlobals(page);
        await mountBatch08Page(page, {
            body: '<main id="main"><h1>Home</h1><span data-live-balance="wallet" data-currency="THB">0.00 THB</span></main>',
            l10n: TEST_L10N,
        });

        for (const [name, detail, title] of MONEY_EVENTS) {
            await page.evaluate(({ eventName, eventDetail }) => {
                document.dispatchEvent(new CustomEvent(eventName, { detail: Object.assign({
                    account_number: '1234567890',
                    voucher_code: 'VOUCHER-SECRET',
                    qr_payload: 'EMV-PAYLOAD-SECRET',
                }, eventDetail) }));
            }, { eventName: name, eventDetail: detail });

            await expect(page.locator('#toast-container')).toContainText(title);
            const toastText = await page.locator('#toast-container').innerText();
            expect(toastText).not.toContain('1234567890');
            expect(toastText).not.toContain('VOUCHER-SECRET');
            expect(toastText).not.toContain('EMV-PAYLOAD-SECRET');
            expect(toastText).not.toContain('SECRET-');
            // Bursts are coalesced; wait past the 280 ms window between events.
            await page.waitForTimeout(360);
        }

        await expect(page.locator('[data-live-balance]')).toHaveText('999.25 THB');
        await expect(page.locator('#toast-container')).toHaveAttribute('aria-live', 'polite');
    });

    test('the public draw events do not create a money toast', async ({ page }) => {
        await stubRealtimeGlobals(page);
        await mountBatch08Page(page, {
            body: '<main id="main"><h1>Results</h1><span data-live-balance="wallet" data-currency="THB">1.00 THB</span></main>',
            l10n: TEST_L10N,
        });

        await page.evaluate(() => {
            document.dispatchEvent(new CustomEvent('lottery:draw-result-published', { detail: { first_prize: '123456' } }));
            document.dispatchEvent(new CustomEvent('lottery:draw-status-updated', { detail: { status: 'open' } }));
            document.dispatchEvent(new CustomEvent('lottery:draw-settlement-completed', { detail: { draw_id: 7 } }));
            document.dispatchEvent(new CustomEvent('lottery:realtime-state', { detail: { state: 'disabled', live: false } }));
        });
        await page.waitForTimeout(400);

        await expect(page.locator('#toast-container')).toBeEmpty();
        await expect(page.locator('[data-live-balance]')).toHaveText('1.00 THB');
    });

    test('a reconnect burst is coalesced into one announcement', async ({ page }) => {
        await stubRealtimeGlobals(page);
        await mountBatch08Page(page, {
            body: '<main id="main"><h1>Home</h1></main>',
            l10n: TEST_L10N,
        });

        await page.evaluate(() => {
            for (let index = 0; index < 6; index += 1) {
                document.dispatchEvent(new CustomEvent('lottery:realtime-resynced', { detail: { reason: 'reconnected' } }));
            }
        });

        await expect(page.locator('#toast-container')).toContainText('Live updates restored');
        await expect(page.locator('#toast-container')).toContainText('more updates');
        await expect(page.locator('#toast-container > div')).toHaveCount(1);
    });

    test('realtime disabled leaves polling active and opens no socket', async ({ page }) => {
        await stubRealtimeGlobals(page);
        const polls = [];
        const fixture = await mountBatch08Page(page, {
            body: depositMarkup({ includeTruemoney: false, includeSlip: false }),
            l10n: TEST_L10N,
            handlers: {
                'GET /api/v1/deposit/methods': {
                    status: 200,
                    body: {
                        success: true,
                        data: {
                            methods: [{ id: 'promptpay', name: 'PromptPay', label: 'PromptPay', state: 'AVAILABLE' }],
                            lanes: { promptpay: 'AVAILABLE', truemoney: 'NOT_CONFIGURED', bank_transfer: 'NOT_CONFIGURED' },
                        },
                    },
                },
                'POST /api/v1/wallet/deposit': {
                    status: 201,
                    body: {
                        success: true,
                        data: {
                            deposit: { id: 3, reference_number: 'POLL-REF-1', status: 'pending', amount: '10.00', currency: 'THB' },
                            payment: { reference_number: 'POLL-REF-1', status: 'pending' },
                            checkout: { qr_payload: '000201010211', expires_at: new Date(Date.now() + 300_000).toISOString() },
                        },
                    },
                },
                'GET /api/v1/deposit/status/POLL-REF-1': ({ pathname }) => {
                    polls.push(pathname);
                    return {
                        status: 200,
                        body: {
                            success: true,
                            data: { id: 3, reference_number: 'POLL-REF-1', status: 'pending', amount: '10.00', currency: 'THB' },
                        },
                    };
                },
            },
        });

        await expect(page.locator('meta[name="realtime-enabled"]')).toHaveAttribute('content', '0');
        await page.locator('[data-promptpay-amount]').fill('10.00');
        await page.locator('[data-promptpay-submit]').click();
        await expect(page.locator('[data-promptpay-qr]')).toBeVisible();

        await expect.poll(() => polls.length, { timeout: 12_000 }).toBeGreaterThan(0);
        expect(fixture.requests.some((entry) => entry.path === '/api/v1/deposit/status/POLL-REF-1')).toBe(true);
        expect(await page.evaluate(() => window.__batch08Sockets || 0)).toBe(0);
    });
});
