/**
 * Deposit lanes — PromptPay QR, TrueMoney voucher, bank slip verification.
 *
 * All network is mocked with page.route(). The page loads the real built
 * app.js entry and therefore the real code-split lane modules, so a broken
 * import, a missing root or a faked success state fails this suite.
 */

import { expect, test } from '@playwright/test';
import { depositMarkup, mountBatch08Page, TEST_L10N } from './batch08-helpers.mjs';

const METHODS_PATH = '/api/v1/deposit/methods';
const DEPOSIT_PATH = '/api/v1/wallet/deposit';

function methods(lanes) {
    return {
        status: 200,
        body: {
            success: true,
            data: {
                methods: [
                    { id: 'promptpay', name: 'PromptPay', label: 'PromptPay', state: lanes.promptpay },
                    { id: 'bank_transfer', name: 'BankTransfer', label: 'Bank Transfer', state: lanes.bank_transfer },
                ],
                lanes,
                default_currency: 'THB',
                currencies: ['THB'],
            },
        },
    };
}

async function mountLanes(page, handlers, options = {}) {
    return mountBatch08Page(page, {
        body: depositMarkup(options.markup),
        l10n: TEST_L10N,
        handlers: Object.assign({
            ['GET ' + METHODS_PATH]: methods({
                promptpay: 'AVAILABLE',
                truemoney: 'AVAILABLE',
                bank_transfer: 'AVAILABLE',
            }),
            'POST /api/v1/deposit/bank-transfer/intent': {
                status: 200,
                body: { success: true, data: { instructions: {} } },
            },
        }, handlers),
        ...options.mount,
    });
}

test.describe('Batch 08 deposit lanes', () => {
    test('renders only the lanes the backend reports as AVAILABLE', async ({ page }) => {
        await mountLanes(page, {
            ['GET ' + METHODS_PATH]: methods({
                promptpay: 'AVAILABLE',
                truemoney: 'NOT_CONFIGURED',
                bank_transfer: 'NOT_CONFIGURED',
            }),
        });

        const promptPayTab = page.locator('[data-lane-tab="promptpay"]');
        const truemoneyTab = page.locator('[data-lane-tab="truemoney"]');
        const slipTab = page.locator('[data-lane-tab="bank_transfer"]');

        await expect(promptPayTab).toHaveAttribute('aria-disabled', 'false');
        await expect(promptPayTab).toHaveAttribute('aria-selected', 'true');
        await expect(page.locator('[data-lane-panel="promptpay"] [data-lane-form]')).toBeVisible();

        await expect(truemoneyTab).toHaveAttribute('aria-disabled', 'true');
        await expect(slipTab).toHaveAttribute('aria-disabled', 'true');
        await expect(page.locator('[data-lane-panel="truemoney"] [data-lane-notice]')).toContainText(/not available/i);
        await expect(page.locator('[data-lane-panel="truemoney"] [data-lane-form]')).toBeHidden();
        await expect(page.locator('[data-lane-panel="bank_transfer"] [data-lane-form]')).toBeHidden();
    });

    test('shows the localized NOT_CONFIGURED notice instead of any form on a 503 methods answer', async ({ page }) => {
        await mountLanes(page, {
            ['GET ' + METHODS_PATH]: {
                status: 503,
                body: { status: 'NOT_CONFIGURED', message: 'Deposit methods are disabled.' },
            },
        });

        await expect(page.locator('[data-lanes-global-notice]')).toContainText(/not available/i);
        await expect(page.locator('[data-lanes-global-notice]')).toContainText('Nothing was charged.');
        await expect(page.locator('[data-lane-form]')).toHaveCount(3);
        await expect(page.locator('[data-lane-panel="promptpay"] [data-lane-form]')).toBeHidden();
        await expect(page.locator('[data-lane-tab="promptpay"]')).toHaveAttribute('aria-disabled', 'true');
        await expect(page.locator('[data-lane-tab="truemoney"]')).toHaveAttribute('aria-disabled', 'true');
        await expect(page.locator('[data-lane-tab="bank_transfer"]')).toHaveAttribute('aria-disabled', 'true');
    });

    test('PromptPay draws the QR, counts down and follows a realtime status transition', async ({ page }) => {
        const expiresAt = new Date(Date.now() + 180_000).toISOString();
        await mountLanes(page, {
            ['POST ' + DEPOSIT_PATH]: {
                status: 201,
                body: {
                    success: true,
                    data: {
                        deposit: { id: 41, reference_number: 'TL-REF-1', status: 'pending', amount: '100.00', currency: 'THB' },
                        payment: { reference_number: 'TL-REF-1', status: 'pending' },
                        checkout: { qr_payload: '00020101021129370016A000000677010111', expires_at: expiresAt },
                    },
                },
            },
        });

        await page.locator('[data-promptpay-amount]').fill('100.00');
        await page.locator('[data-promptpay-submit]').click();

        await expect(page.locator('[data-promptpay-result]')).toBeVisible();
        await expect(page.locator('[data-promptpay-qr]')).toBeVisible();
        await expect(page.locator('[data-promptpay-amount-display]')).toHaveText('100.00 THB');
        await expect(page.locator('[data-promptpay-reference]')).toHaveText('TL-REF-1');
        await expect(page.locator('[data-promptpay-countdown]')).toHaveText(/^\d{2}:\d{2}$/);
        await expect(page.locator('[data-promptpay-status-label]')).toHaveText('Pending payment');

        // The EMV payload itself must never be printed into the DOM.
        await expect(page.locator('body')).not.toContainText('A000000677010111');

        await page.evaluate(() => {
            document.dispatchEvent(new CustomEvent('lottery:deposit-updated', {
                detail: { reference_number: 'TL-REF-1', status: 'confirmed', amount: '100.00' },
            }));
            document.dispatchEvent(new CustomEvent('lottery:wallet-updated', {
                detail: { balance: '1250.50', currency: 'THB' },
            }));
        });

        await expect(page.locator('[data-promptpay-status-label]')).toHaveText('Credited');
        await expect(page.locator('[data-live-balance]')).toHaveText('1,250.50 THB');
    });

    test('TrueMoney maps documented error codes and never echoes the voucher code', async ({ page }) => {
        const token = 'A'.repeat(40);
        await mountLanes(page, {
            'POST /api/v1/deposit/truemoney/redeem': {
                status: 409,
                body: {
                    success: false,
                    error: { code: 'truemoney_voucher_already_processed', message: 'Already processed.', details: {} },
                },
            },
        });

        await page.locator('[data-lane-tab="truemoney"]').click();
        await page.locator('[data-truemoney-url]').fill('https://gift.truemoney.com/campaign/?v=' + token);
        await page.locator('[data-truemoney-submit]').click();

        await expect(page.locator('[data-truemoney-status]')).toContainText('already been submitted');
        await expect(page.locator('[data-truemoney-status]')).toContainText('Do not submit again');
        await expect(page.locator('[data-truemoney-url]')).toHaveValue('');
        const bodyText = await page.locator('body').innerText();
        expect(bodyText).not.toContain(token);
        expect(bodyText).not.toContain('Secret');
    });

    test('TrueMoney refuses a malformed voucher host without contacting the server', async ({ page }) => {
        const fixture = await mountLanes(page, {});
        const before = fixture.requests.filter((entry) => entry.path.includes('truemoney')).length;

        await page.locator('[data-lane-tab="truemoney"]').click();
        await page.locator('[data-truemoney-url]').fill('https://evil.example/campaign/?v=' + 'B'.repeat(40));
        await page.locator('[data-truemoney-submit]').click();

        await expect(page.locator('[data-truemoney-status]')).toContainText('Invalid voucher URL');
        expect(fixture.requests.filter((entry) => entry.path.includes('truemoney')).length).toBe(before);
    });

    test('slip verification shows Needs review with a translated reason and masks the bank account', async ({ page }) => {
        await mountLanes(page, {
            'POST /api/v1/deposit/bank-transfer/intent': {
                status: 200,
                body: {
                    success: true,
                    data: {
                        instructions: {
                            bank_name: 'Test Bank',
                            account_name: 'Test Merchant',
                            account_number: '1234567890',
                            reference: 'SLIP-REF-77',
                            instructions: 'Transfer the exact amount.',
                        },
                    },
                },
            },
            'POST /api/v1/deposit/slip-verification': {
                status: 201,
                body: {
                    success: true,
                    data: {
                        verification_id: 9,
                        status: 'needs_review',
                        reason_code: 'manual_review_required',
                        amount: '250.00',
                        currency: 'THB',
                    },
                },
            },
        });

        await page.locator('[data-lane-tab="bank_transfer"]').click();
        await expect(page.locator('[data-slip-bank-name]')).toHaveText('Test Bank');
        await expect(page.locator('[data-slip-account-number]')).toContainText('7890');
        await expect(page.locator('[data-slip-account-number]')).not.toContainText('1234567890');

        await page.locator('[data-slip-amount]').fill('250.00');
        await page.locator('[data-slip-mode="qr"]').check();
        await page.locator('[data-slip-qr-payload]').fill('00330006000000');
        await page.locator('[data-slip-submit]').click();

        await expect(page.locator('[data-slip-result]')).toBeVisible();
        await expect(page.locator('[data-slip-status-label]')).toHaveText('Needs review');
        await expect(page.locator('[data-slip-reason]')).toContainText('Reason');
        await expect(page.locator('[data-slip-amount-display]')).toHaveText('250.00 THB');
    });

    test('a duplicate slip is reported as Duplicate, never credited', async ({ page }) => {
        await mountLanes(page, {
            'POST /api/v1/deposit/slip-verification': {
                status: 201,
                body: {
                    success: true,
                    data: {
                        verification_id: 10,
                        status: 'duplicate',
                        reason_code: 'duplicate',
                        amount: '250.00',
                        currency: 'THB',
                    },
                },
            },
        });

        await page.locator('[data-lane-tab="bank_transfer"]').click();
        await page.locator('[data-slip-amount]').fill('250.00');
        await page.locator('[data-slip-mode="qr"]').check();
        await page.locator('[data-slip-qr-payload]').fill('00330006000001');
        await page.locator('[data-slip-submit]').click();

        await expect(page.locator('[data-slip-status-label]')).toHaveText('Duplicate slip');
        await expect(page.locator('[data-slip-reason]')).toContainText('already been used');
        await expect(page.locator('[data-slip-status-label]')).not.toHaveText('Credited');
    });

    test('a NOT_CONFIGURED slip answer disables the lane and keeps the notice visible', async ({ page }) => {
        await mountLanes(page, {
            'POST /api/v1/deposit/slip-verification': {
                status: 503,
                body: {
                    success: false,
                    error: { code: 'slip_verification_not_configured', message: 'Not configured', details: {} },
                },
            },
        });

        await page.locator('[data-lane-tab="bank_transfer"]').click();
        await page.locator('[data-slip-amount]').fill('100.00');
        await page.locator('[data-slip-mode="qr"]').check();
        await page.locator('[data-slip-qr-payload]').fill('00330006000002');
        await page.locator('[data-slip-submit]').click();

        await expect(page.locator('#slip-upload [data-lane-notice]')).toContainText('Slip lane not available');
        await expect(page.locator('#slip-upload [data-lane-form]')).toBeHidden();
    });
});
