<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\AgeVerification;
use App\Models\Deposit;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Legal\ConsentService;
use App\Services\Payment\Drivers\PromptPayGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\ProductionPaymentExecutionHubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

/** PromptPay is a THB-only inbound rail, never an alias for bank transfer. */
final class PromptPayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_promptpay_is_a_selectable_thb_inbound_payment_method(): void
    {
        $method = PaymentMethod::tryFrom('promptpay');

        $this->assertSame(PaymentMethod::PromptPay, $method);
        $this->assertSame('PromptPay', $method->label());
        $this->assertTrue($method->isAutomatic());
        $this->assertTrue($method->supportsWebhook());
        $this->assertSame('qr-code', $method->icon());
        $this->assertSame(['THB'], array_map(fn ($currency) => $currency->value, $method->supportedCurrencies()));
    }

    public function test_promptpay_has_a_bound_driver(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);
        $manager = $this->app->make(PaymentGatewayManager::class);

        $this->assertTrue($manager->hasDriver('promptpay'));
        $this->assertInstanceOf(PromptPayGateway::class, $manager->driver('promptpay'));
        $this->assertTrue($manager->isDepositCapable('promptpay'));
        $this->assertFalse($manager->isWithdrawalCapable('promptpay'));
    }

    public function test_promptpay_is_deposit_only_in_the_allowed_lists(): void
    {
        $this->assertContains('promptpay', (array) config('payment.deposit.allowed_methods'));
        $this->assertNotContains('promptpay', (array) config('payment.withdrawal.allowed_methods'));
    }

    public function test_the_web_deposit_form_rejects_promptpay_while_it_is_disabled(): void
    {
        config(['payment.gateways.promptpay.enabled' => false]);
        $user = User::factory()->create();
        $this->onboard($user);
        Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => 'THB']);

        $this->actingAs($user)
            ->post(route('player.deposit.store'), [
                'amount' => '500',
                'method' => 'promptpay',
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertSessionHasErrors('method');

        $this->assertDatabaseCount('deposits', 0);
    }

    public function test_the_deposit_only_operator_contract_is_documented_in_config(): void
    {
        $block = config('payment.gateways.promptpay');

        $this->assertIsArray($block);
        $this->assertSame('promptpay', $block['driver']);
        $this->assertFalse((bool) $block['enabled']);
        $this->assertTrue((bool) $block['supports_deposit']);
        $this->assertFalse((bool) $block['supports_withdrawal']);
        $this->assertSame(['THB'], $block['supported_currencies']);
    }

    public function test_the_execution_hub_executes_promptpay_on_its_own_rail(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);
        $user = User::factory()->create();
        Wallet::factory()->for($user)->withBalance('1000.00')->create(['currency' => 'THB']);

        $result = $this->app->make(ProductionPaymentExecutionHubService::class)->initiateDeposit($user, [
            'amount' => '500.00',
            'channel' => 'promptpay',
            'currency' => 'THB',
        ], 'IDEM-PROMPTPAY-REAL-RAIL');

        $deposit = Deposit::query()->where('reference_number', $result['reference_id'])->sole();
        $this->assertSame(PaymentMethod::PromptPay, $deposit->method);
        $this->assertSame('promptpay', $deposit->provider);
        $this->assertSame($deposit->reference_number, $deposit->provider_reference);
        $this->assertSame('500.00', (string) $deposit->amount);
    }

    public function test_unknown_gateway_names_remain_distinct_from_the_bound_promptpay_driver(): void
    {
        $manager = $this->app->make(PaymentGatewayManager::class);
        $this->assertInstanceOf(PromptPayGateway::class, $manager->driver('promptpay'));
        $this->assertTrue($manager->hasDriver('promptpay'));

        try {
            $manager->driver('definitely-not-a-gateway');
            $this->fail('driver(unknown) resolved something.');
        } catch (FinancialException $exception) {
            $this->assertSame('unsupported_payment_gateway', $exception->errorCode());
        }
    }

    private function onboard(User $user): void
    {
        $user->forceFill(['date_of_birth' => now()->subYears(30)->toDateString()])->save();
        AgeVerification::query()->create([
            'user_id' => $user->id,
            'status' => 'eligible',
            'minimum_age' => (int) config('legal.minimum_age', 18),
            'method' => 'self_declaration',
            'attested_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'source' => 'test',
        ]);
        $documents = collect(app(ConsentService::class)->currentDocuments())->keyBy('document_type');
        app(ConsentService::class)->acceptCurrentDocuments($user, Request::create('/test', 'POST', [
            'terms' => true,
            'privacy_notice' => true,
            'terms_version' => (string) ($documents->get('terms')['version'] ?? ''),
            'terms_hash' => (string) ($documents->get('terms')['content_hash'] ?? ''),
            'privacy_version' => (string) ($documents->get('privacy')['version'] ?? ''),
            'privacy_hash' => (string) ($documents->get('privacy')['content_hash'] ?? ''),
        ], server: ['REMOTE_ADDR' => '127.0.0.1']), 'api');
    }
}
