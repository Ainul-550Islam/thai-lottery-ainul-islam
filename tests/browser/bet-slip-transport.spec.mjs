// Real Chromium DOM tests with MOCKED transport, not Laravel/money E2E.
// Canonical money/CSRF/ledger execution is separately tested in
// BrowserBetFinancialBoundaryTest. No live endpoint or credentials are used.
import { test, expect } from '@playwright/test';
import path from 'node:path';

const origin = 'http://127.0.0.1:43871';
const html = `<!doctype html><html><head><meta charset="UTF-8"></head><body>
<form method="POST" action="${origin}/bet/purchase" data-bet-form data-slip-scope="7:42">
<input type="hidden" name="_token" value="test-csrf-only">
<section data-ticket-selector>
<select id="market-select"><option value="3d_direct" data-digits="3" data-multiplier="900" data-label="3D">3D</option></select>
<input id="selected-number-input"><input id="stake-amount-input"><button type="button" id="btn-add-to-slip">Add</button>
<div id="number-keypad"></div><div data-selection-status></div></section>
<aside id="bet-slip-container" data-max-items="50" data-draw-id="42">
<div data-slip-count>0</div><div id="bet-items-list"></div>
<strong id="total-stake-amount"></strong><strong id="total-payout-amount"></strong>
<div data-slip-status></div><button type="button" id="btn-place-bet" disabled>Submit</button></aside></form>
</body></html>`;

async function boot(page) {
    await page.route(origin + '/bet', route => route.fulfill({ contentType: 'text/html', body: html }));
    await page.goto(origin + '/bet');
    await page.addScriptTag({ path: path.resolve('resources/js/lottery/ticket-selector.js') });
    await page.addScriptTag({ path: path.resolve('resources/js/lottery/bet-slip.js') });
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-bet-slip-ready', 'true');
}

async function add(page, number = '007', stake = '20.00') {
    await page.locator('#selected-number-input').fill(number);
    await page.locator('#stake-amount-input').fill(stake);
    await page.locator('#btn-add-to-slip').click();
}

function report(payload, outcomes) {
    return {
        success: true,
        data: { report: {
            draw_id: 42, requested: payload.items.length,
            purchased: outcomes.filter(x => x === 'purchased').length,
            replayed: outcomes.filter(x => x === 'replayed').length,
            refused: outcomes.filter(x => x === 'refused').length,
            total_charged: outcomes.includes('purchased') ? '20.00' : '0.00', currency: 'THB',
            items: payload.items.map((selection, index) => ({
                index, selection, outcome: outcomes[index],
                bet_id: outcomes[index] === 'refused' ? null : index + 1,
                ticket_number: outcomes[index] === 'refused' ? null : 'TICKET-' + index,
                reason: outcomes[index] === 'refused' ? 'Insufficient available funds' : null,
            })),
        } },
    };
}

// All requests are intercepted: this suite cannot charge a wallet.
test('session JSON transport preserves zeros, decimal strings and CSRF without page navigation', async ({ page }) => {
    let sent;
    await page.route(origin + '/bet/purchase', async route => {
        sent = route.request().postDataJSON();
        expect(route.request().headers()['x-csrf-token']).toBe('test-csrf-only');
        expect(route.request().headers()['authorization']).toBeUndefined();
        await route.fulfill({ status: 201, json: report(sent, ['purchased']) });
    });
    await boot(page);
    await add(page);
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-slip-state', 'resolved');
    expect(sent.items).toEqual([{ market: '3d_direct', number: '007', stake: '20.00' }]);
    expect(sent.client_key).toMatch(/^slip-[a-f0-9]{48}$/);
    expect(page.url()).toBe(origin + '/bet');
    await expect(page.locator('[data-slip-status]')).toContainText('Newly charged: 20.00 THB');
    expect(await page.evaluate(() => sessionStorage.length)).toBe(0);
});

test('partial report is displayed with refusal, not silently labelled fully accepted', async ({ page }) => {
    await page.route(origin + '/bet/purchase', route => {
        const payload = route.request().postDataJSON();
        return route.fulfill({ status: 201, json: report(payload, ['purchased', 'refused']) });
    });
    await boot(page);
    await add(page, '007', '20.00');
    await add(page, '008', '100.00');
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('[data-slip-status]')).toContainText('Purchased: 1, replayed: 0, refused: 1');
    await expect(page.locator('[data-slip-status]')).toContainText('008: refused');
    await expect(page.locator('[data-slip-status]')).toContainText('Insufficient available funds');
});

test('lost response locks editing and same-tab reload retries byte-identical identity', async ({ page }) => {
    const requests = [];
    await page.route(origin + '/bet/purchase', route => {
        requests.push(route.request().postData());
        if (requests.length === 1) return route.abort('failed');
        return route.fulfill({ status: 200, json: report(JSON.parse(requests[1]), ['replayed']) });
    });
    await boot(page);
    await add(page);
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-slip-state', 'unknown');
    await add(page, '008', '50.00');
    await expect(page.locator('[data-slip-item]')).toHaveCount(1);
    await expect(page.locator('[data-remove-index]')).toBeDisabled();
    await page.reload();
    await page.addScriptTag({ path: path.resolve('resources/js/lottery/ticket-selector.js') });
    await page.addScriptTag({ path: path.resolve('resources/js/lottery/bet-slip.js') });
    await expect(page.locator('[data-slip-status]')).toContainText('pending slip was recovered');
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-slip-state', 'resolved');
    expect(requests[1]).toBe(requests[0]);
    await expect(page.locator('[data-slip-status]')).toContainText('replayed: 1');
    await expect(page.locator('[data-slip-status]')).toContainText('Newly charged: 0.00');
});

test('malformed success JSON keeps outcome unknown and preserves pending identity', async ({ page }) => {
    await page.route(origin + '/bet/purchase', route => route.fulfill({ status: 200, json: { success: true } }));
    await boot(page);
    await add(page);
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-slip-state', 'unknown');
    await expect(page.locator('[data-slip-status]')).toContainText('Outcome unknown');
    expect(await page.evaluate(() => sessionStorage.length)).toBe(1);
});

test('editing and double-click are blocked while the request is in flight', async ({ page }) => {
    let release;
    const barrier = new Promise(resolve => { release = resolve; });
    let calls = 0;
    await page.route(origin + '/bet/purchase', async route => {
        calls++;
        const payload = route.request().postDataJSON();
        await barrier;
        await route.fulfill({ status: 201, json: report(payload, ['purchased']) });
    });
    await boot(page);
    await add(page);
    await page.locator('#btn-place-bet').click();
    await expect(page.locator('#btn-place-bet')).toBeDisabled();
    await add(page, '008', '100.00');
    await expect(page.locator('[data-slip-item]')).toHaveCount(1);
    await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { cancelable: true })));
    release();
    await expect(page.locator('#bet-slip-container')).toHaveAttribute('data-slip-state', 'resolved');
    expect(calls).toBe(1);
});

test('closed event is sticky after subsequent selections and removals', async ({ page }) => {
    await boot(page);
    await add(page);
    await page.evaluate(() => document.dispatchEvent(new Event('lottery:betting-closed')));
    await expect(page.locator('#selected-number-input')).toBeDisabled();
    const accepted = await page.evaluate(() => {
        const detail = { market: '3d_direct', number: '008', stake: '20.00', multiplier: '900' };
        document.dispatchEvent(new CustomEvent('lottery:selection-added', { detail }));
        return detail.accepted;
    });
    expect(accepted).toBe(false);
    await expect(page.locator('[data-slip-item]')).toHaveCount(1);
    await page.locator('[data-remove-index]').click();
    await expect(page.locator('#btn-place-bet')).toBeDisabled();
});

test('decimal preview does not use binary float payout math', async ({ page }) => {
    await boot(page);
    await page.locator('option').evaluate(option => option.setAttribute('data-multiplier', '900.12345678'));
    await add(page, '007', '999999999999.99');
    await expect(page.locator('#total-stake-amount')).toHaveText('999,999,999,999.99');
    await expect(page.locator('#total-payout-amount')).toHaveText('900,123,456,779,990.99');
    await add(page, '007', '0.01');
    await expect(page.locator('#total-stake-amount')).toHaveText('999,999,999,999.99');
    await expect(page.locator('[data-selection-status]')).toContainText('merged stake exceeds');
});

test('corrupt recovery refuses transmission rather than generating another purchase identity', async ({ page }) => {
    await boot(page);
    await page.evaluate(() => sessionStorage.setItem('lottery-pending-slip:7:42', '{corrupt'));
    await page.reload();
    await page.addScriptTag({ path: path.resolve('resources/js/lottery/bet-slip.js') });
    await expect(page.locator('[data-slip-status]')).toContainText('storage is unavailable or invalid');
    await expect(page.locator('#btn-place-bet')).toBeDisabled();
});
