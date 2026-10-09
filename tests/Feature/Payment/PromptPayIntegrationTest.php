<?php

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PROMPTPAY ORPHANED CONFIG — EXPLICIT FUTURE-ONLY STATE (FINAL AUDIT #12).
 *
 * config/payment.php carries a promptpay block with no bound driver and no
 * PaymentMethod case. The explicit, tested contract: it can NEVER be
 * selected by a player, advertised as available, or resolved by any
 * payment path, while the operator contract stays on file for the day a
 * real driver is implemented.
 */
class PromptPayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_promptpay_is_not_a_selectable_payment_method(): void
    {
        $this->assertNull(PaymentMethod::tryFrom('promptpay'));
    }

    public function test_promptpay_has_no_bound_driver(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertFalse($manager->hasDriver('promptpay'));

        $this->expectException(FinancialException::class);
        $manager->driver('promptpay');
    }

    public function test_promptpay_never_appears_in_the_deposit_or_withdrawal_allowed_lists(): void
    {
        $depositMethods = (array) config('payment.deposit.allowed_methods');
        $withdrawalMethods = (array) config('payment.withdrawal.allowed_methods');

        $this->assertNotContains('promptpay', $depositMethods);
        $this->assertNotContains('promptpay', $withdrawalMethods);

        // And the closed enum vocabulary cannot produce it either.
        foreach (PaymentMethod::cases() as $method) {
            $this->assertNotSame('promptpay', $method->value);
        }
    }

    public function test_the_web_deposit_form_rejects_promptpay(): void
    {
        $user = User::factory()->create();

        Wallet::factory()
            ->for($user)
            ->withBalance('1000.00')
            ->create(['currency' => 'THB']);

        $this->actingAs($user)
            ->post(route('player.deposit.store'), [
                'amount' => '500',
                'method' => 'promptpay',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('method');

        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_the_orphaned_operator_contract_is_documented_in_config(): void
    {
        // The block exists (explicit state), is disabled by default, and
        // declares itself deposit-only — so the day a driver is bound, the
        // config already says what it may and may not do.
        $block = config('payment.gateways.promptpay');

        $this->assertIsArray($block);
        $this->assertSame('promptpay', $block['driver']);
        $this->assertFalse((bool) $block['enabled']);
        $this->assertTrue((bool) $block['supports_deposit']);
        $this->assertFalse((bool) $block['supports_withdrawal']);
    }

    /**
     * ─────────────────────────────────────────────────────────────────────────
     * AND THE ONE PATH THAT DID NOT REFUSE, WHICH IS WHY THIS TEST EXISTS.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * Every assertion in this file held while the lane was still reachable from
     * one direction. `ProductionPaymentExecutionHubService::methodForChannel()`
     * read:
     *
     *     'bank_transfer', 'promptpay' => PaymentMethod::BankTransfer,
     *
     * so a caller who passed `channel => 'promptpay'` got a BANK TRANSFER. Not a
     * refusal, not an error — a different rail, executed under the name the
     * customer chose. Different settlement, different fees, different
     * reconciliation, and a deposit row naming the wrong method.
     *
     * Nothing above caught it, because every assertion here asks "is promptpay
     * SELECTABLE?" and the answer was correctly no. The alias did not make it
     * selectable; it made it silently SUBSTITUTED, which is a different failure
     * with a worse recovery story: a refusal is a support ticket, a substitution
     * is a discrepancy discovered at reconciliation.
     *
     * The hub is not currently routed, so this was latent rather than live. That
     * is exactly why it is asserted now — the moment somebody wires the hub to a
     * controller, this arm silently becomes a production behaviour.
     */
    public function test_the_execution_hub_refuses_the_promptpay_channel_instead_of_aliasing_it(): void
    {
        $user = User::factory()->create();

        Wallet::factory()
            ->for($user)
            ->withBalance('1000.00')
            ->create(['currency' => 'THB']);

        $hub = $this->app->make(\App\Services\Payment\ProductionPaymentExecutionHubService::class);

        try {
            $hub->initiateDeposit($user, [
                'amount' => '500.00',
                'channel' => 'promptpay',
                'currency' => 'THB',
            ], 'IDEM-PROMPTPAY-ALIAS-'.Str::random(8));

            $this->fail('The hub accepted the promptpay channel: it was aliased onto another rail.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not executable', $e->getMessage());
            $this->assertStringContainsString('no payment driver', $e->getMessage());
        }

        // And nothing was created. A rail that cannot be executed must not leave
        // a pending deposit behind: an unpayable deposit is indistinguishable
        // from an abandoned one, and somebody would eventually try to settle it.
        $this->assertDatabaseCount('deposits', 0);
    }

    /**
     * The refusal is BY NAME, so an operator reading a log can tell the two
     * problems apart: a rail that is configured-but-unbuilt, versus a name that
     * was mistyped.
     */
    public function test_the_manager_names_the_unbuilt_rail_rather_than_calling_it_unsupported(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);

        try {
            $manager->driver('promptpay');

            $this->fail('driver(promptpay) resolved something.');
        } catch (FinancialException $e) {
            $this->assertSame('payment_gateway_not_implemented', $e->errorCode());
        }

        // Whereas an unknown name is still the generic refusal — these must not
        // be collapsed into one code, because they send you to different places.
        try {
            $manager->driver('definitely-not-a-gateway');

            $this->fail('driver(unknown) resolved something.');
        } catch (FinancialException $e) {
            $this->assertSame('unsupported_payment_gateway', $e->errorCode());
        }

        // Still not a driver, either way.
        $this->assertFalse($manager->hasDriver('promptpay'));
    }
}
