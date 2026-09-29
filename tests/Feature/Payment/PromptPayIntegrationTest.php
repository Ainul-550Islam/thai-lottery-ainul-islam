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
}
