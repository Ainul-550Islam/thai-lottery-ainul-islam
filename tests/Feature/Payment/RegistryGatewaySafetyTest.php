<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentIntentData;
use App\DTOs\Payment\PaymentMethodData;
use App\DTOs\Payment\PaymentProviderData;
use App\Enums\Currency;
use App\Exceptions\PaymentProviderException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\PaymentIntent;
use App\Services\Payment\PaymentIntentService;
use App\Services\Payment\PaymentProviderRegistry;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/** The legacy registry is not a second source of public gateway availability. */
final class RegistryGatewaySafetyTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'payment.currency.default' => 'USD', 'payment.country' => 'US',
            'payment.markets.stripe' => ['currencies' => ['USD'], 'countries' => ['US']],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_deposit' => true,
            'payment.gateways.stripe.supported_currencies' => ['USD'],
            'payment.gateways.stripe.key' => 'pk_test_fixture',
            'payment.gateways.stripe.secret' => 'sk_test_fixture',
            'payment.gateways.stripe.webhook_secret' => 'whsec_test_fixture',
            'payment.gateways.crypto.enabled' => true,
        ]);
    }

    private function offer(string $code, string $driver, string $method, string $currency): void
    {
        $registry = app(PaymentProviderRegistry::class);
        $registry->register(PaymentProviderData::fromInput($code, 'Synthetic '.$code, $driver, 'active', [$currency]));
        $registry->registerMethod(PaymentMethodData::fromInput($code, $method, $currency, '1.00', '1000.00', status: 'enabled'));
    }

    public function test_closed_launch_switch_refuses_a_new_registry_intent(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createPlayer('0.00', Currency::USD);
        $data = PaymentIntentData::fromInput((int) $player['user']->id, (int) $player['wallet']->id,
            'deposit', '100.00', 'USD', 'stripe', 'synthetic-closed-registry');
        config(['real_money.enabled' => false]);
        $caught = null;
        try {
            app(PaymentIntentService::class)->create($data);
        } catch (\App\Exceptions\FinancialException $exception) {
            $caught = $exception;
        }
        self::assertSame(0, PaymentIntent::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertInstanceOf(\App\Exceptions\FinancialException::class, $caught);
        self::assertSame('real_money_disabled', $caught->errorCode());
    }

    public function test_closed_launch_switch_returns_a_safe_registry_http_refusal(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        config(['real_money.enabled' => false]);
        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-closed-registry-http',
        ])->assertStatus(503)->assertJsonPath('error.code', 'real_money_disabled');
        self::assertSame(0, PaymentIntent::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_closed_launch_switch_preserves_exact_registry_replay_and_cancellation(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createPlayer('0.00', Currency::USD);
        $data = PaymentIntentData::fromInput((int) $player['user']->id, (int) $player['wallet']->id,
            'deposit', '100.00', 'USD', 'stripe', 'synthetic-registry-replay');
        $first = app(PaymentIntentService::class)->create($data);
        config(['real_money.enabled' => false]);
        $replay = app(PaymentIntentService::class)->create($data);
        self::assertTrue($replay['replayed']);
        self::assertSame($first['intent']->id, $replay['intent']->id);
        $cancelled = app(PaymentIntentService::class)->cancel($first['intent']->intent_key, (int) $player['user']->id);
        self::assertSame(\App\Enums\PaymentTransactionStatus::Failed, $cancelled->status);
        self::assertSame(1, PaymentIntent::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_provider_and_method_menus_hide_enabled_crypto_but_keep_eligible_stripe(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $this->offer('crypto', 'crypto', 'crypto', 'USD');
        // Hiding the literal name alone must not expose a crypto-backed alias.
        $this->offer('coinwallet', 'crypto', 'coinwallet', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);

        $providers = collect($this->getJson('/api/v1/payments/providers')->assertOk()->json('data'));
        self::assertSame(['stripe'], $providers->pluck('code')->all());
    }

    public function test_method_menu_also_hides_crypto_and_keeps_eligible_stripe(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $this->offer('crypto', 'crypto', 'crypto', 'USD');
        $this->offer('coinwallet', 'crypto', 'coinwallet', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);

        $methods = collect($this->getJson('/api/v1/payments/methods')->assertOk()->json('data'));
        self::assertSame(['stripe'], $methods->pluck('method')->all());
        self::assertSame('USD', $methods->sole()['currency']);
    }

    public function test_service_cannot_create_an_unsupported_crypto_intent_directly(): void
    {
        $this->offer('crypto', 'crypto', 'crypto', 'USD');
        $player = $this->createPlayer('0.00', Currency::USD);
        $data = PaymentIntentData::fromInput((int) $player['user']->id, (int) $player['wallet']->id,
            'deposit', '100.00', 'USD', 'crypto', 'synthetic-direct-crypto');
        try {
            app(PaymentIntentService::class)->create($data);
            self::fail('A direct service call must not bypass the unavailable gateway.');
        } catch (PaymentProviderException $exception) {
            self::assertNotSame('', $exception->errorCode());
        }
        self::assertSame(0, PaymentIntent::query()->count());
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_registry_cannot_disguise_crypto_as_the_stripe_driver(): void
    {
        $this->offer('stripe', 'crypto', 'stripe', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        self::assertSame([], $this->getJson('/api/v1/payments/providers')->assertOk()->json('data'));
        self::assertSame([], $this->getJson('/api/v1/payments/methods')->assertOk()->json('data'));
        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-disguised-crypto',
        ])->assertStatus(422);
        self::assertSame(0, PaymentIntent::query()->count());
    }

    /** @return array<string, array{string, string}> */
    public static function unsupportedCryptoOffers(): array
    {
        return ['literal' => ['crypto', 'crypto'], 'alias' => ['coinwallet', 'coinwallet']];
    }

    #[DataProvider('unsupportedCryptoOffers')]
    public function test_crypto_registry_cannot_create_a_payment_intent(string $code, string $method): void
    {
        $this->offer($code, 'crypto', $method, 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => $method, 'idempotency_key' => 'synthetic-unsupported-'.$method,
        ])->assertStatus(422);
        self::assertSame(0, PaymentIntent::query()->count());
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_bdt_registry_cannot_advertise_unverified_bkash_or_nagad(): void
    {
        config(['payment.currency.default' => 'BDT', 'payment.country' => 'BD']);
        $this->offer('bkash', 'bkash', 'bkash', 'BDT');
        $this->offer('nagad', 'nagad', 'nagad', 'BDT');
        $player = $this->createAdmittedPlayer('0.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        self::assertSame([], $this->getJson('/api/v1/payments/providers')->assertOk()->json('data'));
        self::assertSame([], $this->getJson('/api/v1/payments/methods')->assertOk()->json('data'));
    }

    public function test_disabled_gateway_cannot_be_advertised_or_used_by_an_active_registry_row(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        config(['payment.gateways.stripe.enabled' => false]);
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        self::assertSame([], $this->getJson('/api/v1/payments/providers')->assertOk()->json('data'));
        self::assertSame([], $this->getJson('/api/v1/payments/methods')->assertOk()->json('data'));
        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-disabled-stripe',
        ])->assertStatus(422);
        self::assertSame(0, PaymentIntent::query()->count());
    }

    public function test_a_deposit_only_stripe_offer_cannot_create_a_withdrawal_intent(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'withdrawal', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-stripe-withdrawal',
        ])->assertStatus(422);
        self::assertSame(0, PaymentIntent::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_previously_created_intent_remains_readable_and_cancellable_after_gateway_closes(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $payload = ['direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-stripe-history'];
        $key = $this->postJson('/api/v1/payments/intents', $payload)->assertCreated()->json('data.intent_key');
        config(['payment.gateways.stripe.enabled' => false]);
        $this->postJson('/api/v1/payments/intents', $payload)->assertStatus(422);
        $this->getJson('/api/v1/payments/intents/'.$key)->assertOk();
        $this->postJson('/api/v1/payments/intents/'.$key.'/cancel')->assertOk();
        self::assertSame(1, PaymentIntent::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_eligible_stripe_registry_offer_can_still_create_an_intent(): void
    {
        $this->offer('stripe', 'stripe', 'stripe', 'USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'synthetic-eligible-stripe',
        ])->assertCreated()->assertJsonPath('data.currency', 'USD');
        $intent = PaymentIntent::query()->sole();
        self::assertSame((int) $player['wallet']->id, (int) $intent->wallet_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }
}
