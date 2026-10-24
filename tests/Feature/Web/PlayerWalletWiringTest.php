<?php

namespace Tests\Feature\Web;

use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WEB WALLET WIRING (audit findings W1-W9, S2, S4).
 *
 * The web deposit and withdrawal forms must reach the REAL finance engine:
 * a persisted order with a reference number, exact decimal limits from the
 * canonical config, the closed payment-method vocabulary, an idempotency
 * key per form render, and a funds hold for withdrawals — never a success
 * message backed by nothing.
 */
class PlayerWalletWiringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{user: User, wallet: Wallet}
     */
    private function playerWithPassword(string $password, string $balance = '1000.00'): array
    {
        $user = User::factory()->create(['password' => Hash::make($password)]);

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create(['currency' => 'THB']);

        return ['user' => $user, 'wallet' => $wallet];
    }

    /**
     * @return array{user: User, wallet: Wallet}
     */
    private function player(string $balance = '1000.00'): array
    {
        $user = User::factory()->create();

        $wallet = Wallet::factory()
            ->for($user)
            ->withBalance($balance)
            ->create(['currency' => 'THB']);

        return ['user' => $user, 'wallet' => $wallet];
    }

    // ------------------------------------------------------------------
    // W3/W7/W8: the pages render the canonical config, nothing else.
    // ------------------------------------------------------------------

    public function test_deposit_page_renders_configured_limits_and_methods(): void
    {
        // Pin the runtime config so the assertion is about the WIRING
        // (page follows config), not about any particular environment's
        // env overrides. The gateways the page may advertise must also be
        // ENABLED — the page never advertises a disabled rail, which is the
        // same capability gate the initiation path applies.
        config([
            'payment.deposit.min' => '50.00',
            'payment.deposit.max' => '500000.00',
            'payment.deposit.allowed_methods' => ['stripe', 'bkash', 'nagad', 'crypto'],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.nagad.enabled' => true,
            'payment.gateways.crypto.enabled' => true,
        ]);

        ['user' => $user] = $this->player();

        $page = (string) $this->actingAs($user)->get(route('player.deposit'))->getContent();

        // The configured floor/ceiling, exactly as the engine reads them.
        $this->assertStringContainsString('฿50.00', $page);
        $this->assertStringContainsString('฿500,000.00', $page);

        // The closed deposit method list from config — the enum vocabulary.
        foreach (['Stripe', 'bKash', 'Nagad', 'Crypto'] as $label) {
            $this->assertStringContainsString($label, $page);
        }

        // The legacy marketing gateways that exist nowhere in the payment
        // model must not be advertised.
        $this->assertStringNotContainsString('TrueMoney', $page);
        $this->assertStringNotContainsString('PromptPay', $page);
    }

    public function test_withdraw_page_renders_configured_limits_and_methods(): void
    {
        config([
            'payment.withdrawal.min' => '100.00',
            'payment.withdrawal.max' => '500000.00',
            'payment.withdrawal.processing_hours' => 24,
            'payment.withdrawal.allowed_methods' => ['bkash', 'nagad', 'bank_transfer', 'crypto'],
            // A payout rail is only advertised when it is enabled (and the
            // bank rail carries its full settlement identity).
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.nagad.enabled' => true,
            'payment.gateways.crypto.enabled' => true,
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'Operator Bank PLC',
                'account_number' => '888-999-000',
                'account_name' => 'Operator Holdings Ltd',
                'instructions' => 'Transfer within 24 hours',
            ],
        ]);

        ['user' => $user] = $this->player();

        $page = (string) $this->actingAs($user)->get(route('player.withdraw'))->getContent();

        // The configured floor/ceiling and processing window, exactly as
        // the engine reads them.
        $this->assertStringContainsString('฿100.00', $page);
        $this->assertStringContainsString('฿500,000.00', $page);
        $this->assertStringContainsString('within 24 hours', $page);

        // Configured payout methods.
        $this->assertStringContainsString('Bank transfer', $page);
        $this->assertStringContainsString('bKash', $page);

        // The invented "5-15 mins" promise must be gone.
        $this->assertStringNotContainsString('5-15', $page);
    }

    // ------------------------------------------------------------------
    // W1: storeDeposit reaches the real DepositService.
    // ------------------------------------------------------------------

    public function test_store_deposit_creates_a_real_pending_deposit(): void
    {
        ['user' => $user] = $this->player();

        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_example',
        ]);

        // FINAL AUDIT #1: the form drives the orchestrated initiation —
        // capability gate, deposit intent, REAL gateway session, Payment
        // aggregate — and the browser is sent to the provider's hosted
        // checkout. Nothing is credited at this point. (Stripe is the THB
        // capable automated rail; bKash/Nagad are BDT and are honestly
        // refused for THB wallets by the currency guard.)
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_WIRING1',
                'url' => 'https://checkout.stripe.com/pay/cs_test_WIRING1',
            ], 200),
        ]);

        $response = $this->actingAs($user)->post(route('player.deposit.store'), [
            'amount' => '500',
            'method' => 'stripe',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $response->assertRedirect('https://checkout.stripe.com/pay/cs_test_WIRING1');

        $this->assertDatabaseHas('deposits', [
            'user_id' => $user->id,
            'method' => 'stripe',
            'amount' => '500.00',
            'currency' => 'THB',
            'status' => 'pending',
        ]);

        $deposit = $user->deposits()->first();
        $this->assertNotNull($deposit);
        $this->assertStringStartsWith('DP-', (string) $deposit->reference_number);
        $metadata = is_array($deposit->metadata) ? $deposit->metadata : [];
        $this->assertSame('web', $metadata['source'] ?? null);

        // The Payment aggregate recorded the provider session reference.
        $payment = Payment::query()
            ->where('payable_type', Deposit::class)
            ->where('payable_id', $deposit->getKey())
            ->first();
        $this->assertNotNull($payment);
        $this->assertSame('cs_test_WIRING1', (string) $payment->gateway_reference);
    }

    public function test_store_deposit_is_idempotent_on_form_replay(): void
    {
        ['user' => $user] = $this->player();

        config([
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.secret' => 'sk_test_example',
        ]);

        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_WIRING2',
                'url' => 'https://checkout.stripe.com/pay/cs_test_WIRING2',
            ], 200),
        ]);

        $key = (string) Str::uuid();
        $payload = ['amount' => '250', 'method' => 'stripe', 'idempotency_key' => $key];

        $this->actingAs($user)->post(route('player.deposit.store'), $payload);
        $this->actingAs($user)->post(route('player.deposit.store'), $payload);

        $this->assertDatabaseCount('deposits', 1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_store_deposit_shows_operator_bank_transfer_instructions_when_configured(): void
    {
        ['user' => $user] = $this->player();

        // Operator-configured settlement only (FINAL AUDIT #4): the page
        // renders exactly what the operator supplied, never a placeholder.
        config([
            'payment.deposit.allowed_methods' => ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer'],
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.supported_currencies' => ['THB'],
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Operator Bank PLC',
            'payment.gateways.bank_transfer.settlement.account_number' => '888-999-000',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Operator Holdings Ltd',
            'payment.gateways.bank_transfer.settlement.instructions' => 'Use the reference as the transfer remark.',
        ]);

        $this->actingAs($user)->post(route('player.deposit.store'), [
            'amount' => '500',
            'method' => 'bank_transfer',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect(route('player.deposit'))->assertSessionHas('instructions');

        $this->assertDatabaseHas('deposits', [
            'user_id' => $user->id,
            'method' => 'bank_transfer',
            'status' => 'pending',
        ]);

        $instructions = session('instructions');
        $this->assertSame('Operator Bank PLC', $instructions['bank_name'] ?? null);
        $this->assertSame('888-999-000', $instructions['account_number'] ?? null);
        $this->assertSame('Operator Holdings Ltd', $instructions['account_name'] ?? null);
        $this->assertArrayHasKey('reference', $instructions);
    }

    public function test_store_deposit_fails_closed_when_bank_settlement_is_not_configured(): void
    {
        ['user' => $user] = $this->player();

        // Gateway enabled but NO settlement details. The bank rail's own
        // capability gate fails closed here: supportsDeposit() is false
        // without a full settlement identity, so the method never enters
        // the validation vocabulary and can never reach the initiation
        // path. The refusal is therefore the form's method gate — no
        // deposit record, and no fake account can ever be advertised.
        config([
            'payment.deposit.allowed_methods' => ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer'],
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => null,
            'payment.gateways.bank_transfer.settlement.account_number' => null,
            'payment.gateways.bank_transfer.settlement.account_name' => null,
        ]);

        $this->actingAs($user)->post(route('player.deposit.store'), [
            'amount' => '500',
            'method' => 'bank_transfer',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('method');

        $this->assertDatabaseCount('deposits', 0);

        // And the page the player came from never offered the rail: the
        // capability gate that refused the request also keeps it off the
        // advertised list, so the player is never sent to a bank account
        // that does not exist.
        $page = (string) $this->actingAs($user)->get(route('player.deposit'))->getContent();
        $this->assertStringNotContainsString('Thai Bank Transfer', $page);
    }

    public function test_store_deposit_rejects_a_method_outside_the_configured_list(): void
    {
        ['user' => $user] = $this->player();

        // bank_transfer is a real enum value but NOT in the deposit
        // allowed_methods list — the UI must not be able to push it through.
        $this->actingAs($user)->post(route('player.deposit.store'), [
            'amount' => '500',
            'method' => 'bank_transfer',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('method');

        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_store_deposit_rejects_amount_below_the_configured_minimum(): void
    {
        ['user' => $user] = $this->player();

        config(['payment.deposit.min' => '50.00']);

        // 49 is under the configured floor: the request gate follows the
        // config, not a hard-coded page number.
        $this->actingAs($user)->post(route('player.deposit.store'), [
            'amount' => '49',
            'method' => 'crypto',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('deposits', 0);
    }

    // ------------------------------------------------------------------
    // W2/W4: storeWithdraw reaches the real WithdrawalService. The request
    // lane takes NO hold by design ("read-only sufficiency check. No hold is
    // taken here" - WithdrawalService::request): a pending request must not
    // lock money that approval may never confirm. The hold arrives with the
    // approval act, exactly as the finance engine documents.
    // ------------------------------------------------------------------

    public function test_store_withdraw_creates_a_real_request_without_touching_the_balance(): void
    {
        // The bank-transfer payout rail must be enabled, with its full
        // settlement identity, before a withdrawal can travel it.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'SCB',
                'account_number' => '1234567890',
                'account_name' => 'Thai Lottery Co.',
            ],
        ]);

        ['user' => $user, 'wallet' => $wallet] = $this->player('1000.00');

        $this->actingAs($user)->post(route('player.withdraw.store'), [
            'amount' => '300',
            'method' => 'bank_transfer',
            'bank_name' => 'Bangkok Bank',
            'account_number' => '0987654321',
            'account_name' => 'Niran Suwan',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('withdrawals', [
            'user_id' => $user->id,
            'method' => 'bank_transfer',
            'amount' => '300.00',
            'currency' => 'THB',
            'status' => 'pending',
        ]);

        $withdrawal = $user->withdrawals()->first();
        $this->assertNotNull($withdrawal);
        $this->assertStringStartsWith('WD-', (string) $withdrawal->reference_number);
        $this->assertNotNull($withdrawal->payout_details);

        // The request lane is deliberately hold-free: the money is still
        // fully available until the approval act takes the hold. A request
        // that locked funds would strand money on a withdrawal that may
        // never be approved.
        $wallet->refresh();
        $this->assertSame('1000.00', (string) $wallet->balance);
        $this->assertSame('0.00', (string) $wallet->locked_balance);
        $this->assertSame('1000.00', (string) $wallet->getAvailableBalance());
    }

    public function test_store_withdraw_rejects_insufficient_balance(): void
    {
        // The bank-transfer payout rail must be enabled, with its full
        // settlement identity, before a withdrawal can travel it.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'SCB',
                'account_number' => '1234567890',
                'account_name' => 'Thai Lottery Co.',
            ],
        ]);

        ['user' => $user] = $this->player('50.00');

        $this->actingAs($user)->post(route('player.withdraw.store'), [
            'amount' => '300',
            'method' => 'bank_transfer',
            'bank_name' => 'Bangkok Bank',
            'account_number' => '0987654321',
            'account_name' => 'Niran Suwan',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHas('error');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    public function test_store_withdraw_rejects_amount_below_the_configured_minimum(): void
    {
        // The bank-transfer payout rail must be enabled, with its full
        // settlement identity, before a withdrawal can travel it.
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement' => [
                'bank_name' => 'SCB',
                'account_number' => '1234567890',
                'account_name' => 'Thai Lottery Co.',
            ],
        ]);

        config(['payment.withdrawal.min' => '100.00']);

        ['user' => $user] = $this->player('1000.00');

        $this->actingAs($user)->post(route('player.withdraw.store'), [
            'amount' => '50',
            'method' => 'bank_transfer',
            'bank_name' => 'Bangkok Bank',
            'account_number' => '0987654321',
            'account_name' => 'Niran Suwan',
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('withdrawals', 0);
    }

    // ------------------------------------------------------------------
    // S2: the profile password surface uses the centralised strong rule.
    // ------------------------------------------------------------------

    public function test_web_profile_password_uses_the_strong_password_rule(): void
    {
        ['user' => $user] = $this->playerWithPassword('Password123');

        // Digits only: passes the old min:8, must fail the centralised rule.
        $this->actingAs($user)->put(route('player.profile.password'), [
            'current_password' => 'Password123',
            'new_password' => '12345678',
        ])->assertSessionHasErrors('new_password');

        $this->assertTrue(Hash::check('Password123', (string) $user->fresh()->password));

        // A compliant password still works.
        $this->actingAs($user)->put(route('player.profile.password'), [
            'current_password' => 'Password123',
            'new_password' => 'FreshPass123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('FreshPass123', (string) $user->fresh()->password));
    }

    // ------------------------------------------------------------------
    // S4: the dashboard never shows fabricated draw/result values.
    // ------------------------------------------------------------------

    public function test_dashboard_shows_an_explicit_unavailable_state_without_draws(): void
    {
        ['user' => $user] = $this->player();

        $page = (string) $this->actingAs($user)->get(route('player.dashboard'))->getContent();

        $this->assertStringContainsString('Not scheduled', $page);
        $this->assertStringContainsString('Not recorded', $page);
        $this->assertStringContainsString('—', $page);

        // The fabricated fallback strings must be gone.
        $this->assertStringNotContainsString('DRAW-20260916', $page);
        $this->assertStringNotContainsString('DRAW-20260901', $page);
        $this->assertStringNotContainsString('945812', $page);
        $this->assertStringNotContainsString('Sep 16, 2026', $page);
        $this->assertStringNotContainsString('04 : 18 : 32', $page);
    }
}
