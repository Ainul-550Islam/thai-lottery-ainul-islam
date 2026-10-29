<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentMethodData;
use App\DTOs\Payment\PaymentProviderData;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\PaymentIntent;
use App\Models\Wallet;
use App\Services\Payment\PaymentProviderRegistry;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

/** Per-wallet currency is evaluated within the configured merchant market, not invented location proof. */
final class MultiCurrencyMarketSafetyTest extends PaymentTestCase
{
    private function configureStripe(string $default = 'THB'): void
    {
        config([
            'payment.currency.default' => $default, 'payment.country' => 'US',
            'payment.markets.stripe' => ['currencies' => ['USD'], 'countries' => ['US']],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_deposit' => true,
            'payment.gateways.stripe.supported_currencies' => ['USD'],
            'payment.gateways.stripe.key' => 'pk_test_fixture',
            'payment.gateways.stripe.secret' => 'sk_test_fixture',
            'payment.gateways.stripe.webhook_secret' => 'whsec_test_fixture',
        ]);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_multicurrency',
            'url' => 'https://example.invalid/test-checkout'], 200)]);
    }

    public function test_requested_usd_wallet_can_create_an_eligible_usd_checkout_even_if_default_is_thb(): void
    {
        $this->configureStripe();
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/deposits', [
            'amount' => '100.25', 'currency' => 'USD', 'method' => 'stripe',
            'idempotency_key' => 'multicurrency-usd-explicit',
        ])->assertCreated()->assertJsonPath('data.deposit.currency', 'USD')
            ->assertJsonPath('data.deposit.amount', '100.25')->assertJsonPath('data.deposit.status', 'pending');
        $deposit = Deposit::query()->sole();
        self::assertSame((int) $player['wallet']->id, (int) $deposit->wallet_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && data_get($request->data(), 'line_items.0.price_data.currency') === 'usd'
            && (string) data_get($request->data(), 'line_items.0.price_data.unit_amount') === '10025');
    }

    public function test_omitted_currency_uses_the_configured_usd_default_not_hardcoded_thb(): void
    {
        $this->configureStripe('USD');
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/deposits', [
            'amount' => '100.00', 'method' => 'stripe', 'idempotency_key' => 'multicurrency-usd-default',
        ])->assertCreated()->assertJsonPath('data.deposit.currency', 'USD');
        self::assertSame((int) $player['wallet']->id, (int) Deposit::query()->sole()->wallet_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_catalogue_uses_the_selected_owned_wallet_currency_and_same_manager(): void
    {
        $this->configureStripe();
        $player = $this->createPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $response = $this->getJson('/api/v1/deposit/methods?currency=USD')->assertOk()
            ->assertJsonPath('data.default_currency', 'THB')->assertJsonPath('data.selected_currency', 'USD');
        $rails = collect($response->json('data.methods'))->keyBy('id');
        self::assertTrue($rails['stripe']['enabled']);
        self::assertSame('USD', $rails['stripe']['currency']);
        self::assertFalse($rails['promptpay']['enabled']);
        self::assertFalse($rails['bkash']['enabled']);
        self::assertFalse($rails['nagad']['enabled']);
        self::assertFalse($rails->has('crypto'));
    }

    public function test_advertised_stripe_route_creates_the_same_manager_backed_checkout_without_registry_rows(): void
    {
        $this->configureStripe();
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods?currency=USD')->assertOk()->json('data.methods'))->keyBy('id');
        self::assertTrue($rails['stripe']['enabled']);
        $this->postJson($rails['stripe']['initiate_route'], [
            'amount' => '100.00', 'currency' => 'USD', 'method' => 'stripe',
            'idempotency_key' => 'multicurrency-listed-route',
        ])->assertCreated()->assertJsonPath('data.deposit.currency', 'USD');
        self::assertSame((int) $player['wallet']->id, (int) Deposit::query()->sole()->wallet_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        Http::assertSentCount(1);
    }

    public function test_disabled_product_method_is_not_advertised_even_if_credentials_are_present(): void
    {
        $this->configureStripe('USD');
        config(['payment.deposit.allowed_methods' => ['bank_transfer']]);
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods?currency=USD')->assertOk()->json('data.methods'))->keyBy('id');
        self::assertFalse($rails['stripe']['enabled']);
        $this->postJson('/api/v1/deposits', [
            'amount' => '100.00', 'currency' => 'USD', 'method' => 'stripe',
            'idempotency_key' => 'multicurrency-disabled-method',
        ])->assertStatus(422);
        self::assertSame(0, Deposit::query()->count());
        Http::assertNothingSent();
    }

    public function test_global_deposit_switch_stops_checkout_before_any_record_or_provider_call(): void
    {
        $this->configureStripe('USD');
        config(['payment.deposit.enabled' => false]);
        $player = $this->createAdmittedPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/deposits', [
            'amount' => '100.00', 'currency' => 'USD', 'method' => 'stripe',
            'idempotency_key' => 'multicurrency-global-switch',
        ])->assertStatus(422);
        self::assertSame(0, Deposit::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_global_deposit_switch_is_reflected_in_the_catalogue(): void
    {
        $this->configureStripe('USD');
        config(['payment.deposit.enabled' => false]);
        $player = $this->createPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods?currency=USD')->assertOk()->json('data.methods'))->keyBy('id');
        self::assertFalse($rails['stripe']['enabled']);
    }

    public function test_currency_selection_cannot_access_another_users_wallet(): void
    {
        $this->configureStripe();
        $player = $this->createAdmittedPlayer('0.00', Currency::THB);
        $other = $this->createPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->getJson('/api/v1/deposit/methods?currency=USD')->assertNotFound();
        $this->postJson('/api/v1/deposits', [
            'amount' => '100.00', 'currency' => 'USD', 'method' => 'stripe',
            'idempotency_key' => 'multicurrency-not-my-wallet',
        ])->assertNotFound();
        self::assertSame(0, Deposit::query()->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $other['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public function test_client_supplied_country_never_overrides_the_configured_merchant_market(): void
    {
        $this->configureStripe();
        config(['payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678']);
        $player = $this->createPlayer('0.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods?currency=THB&country=TH')->assertOk()->json('data.methods'))->keyBy('id');
        self::assertFalse($rails['promptpay']['enabled']);
        self::assertFalse(app(\App\Services\Payment\PaymentGatewayManager::class)->isDepositCapable(PaymentMethod::PromptPay, Currency::THB));
        Http::assertNothingSent();
    }

    public function test_thai_merchant_can_offer_its_own_thb_rails_with_a_usd_site_default(): void
    {
        config([
            'payment.currency.default' => 'USD', 'payment.country' => 'TH',
            'payment.markets.promptpay' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.markets.bank_transfer' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Test-only Thai bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Test-only merchant',
            'slip_verification.receiving_accounts' => [
                ['bank' => 'SCB', 'account_last_digits' => '7890', 'name' => 'Test-only merchant'],
            ],
        ]);
        Http::preventStrayRequests();
        $player = $this->createAdmittedPlayer('0.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods?currency=THB')->assertOk()
            ->assertJsonPath('data.selected_currency', 'THB')->json('data.methods'))->keyBy('id');
        self::assertTrue($rails['promptpay']['enabled']);
        self::assertTrue($rails['bank_transfer']['enabled']);
        $this->postJson('/api/v1/deposit/promptpay/generate', [
            'amount' => '100.00', 'idempotency_key' => 'multicurrency-thai-promptpay',
        ])->assertCreated()->assertJsonPath('data.currency', 'THB');
        $this->postJson('/api/v1/deposit/bank-transfer/intent', [
            'amount' => '200.00', 'idempotency_key' => 'multicurrency-thai-bank',
        ])->assertCreated()->assertJsonPath('data.currency', 'THB');
        self::assertSame(2, Deposit::query()->where('currency', 'THB')->count());
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        Http::assertNothingSent();
    }

    public function test_unsupported_or_malformed_selection_fails_closed(): void
    {
        $this->configureStripe();
        $player = $this->createPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        foreach (['XYZ', 'USD%2CTHB', ''] as $bad) {
            $this->getJson('/api/v1/deposit/methods?currency='.$bad)->assertStatus(422);
        }
        $this->getJson('/api/v1/deposit/methods?currency%5B%5D=USD')->assertStatus(422);
        self::assertSame(0, Deposit::query()->count());
    }

    public function test_legacy_intent_endpoint_selects_the_callers_own_requested_currency_wallet(): void
    {
        $this->configureStripe();
        $registry = app(PaymentProviderRegistry::class);
        $registry->register(PaymentProviderData::fromInput('stripe', 'Synthetic Stripe', 'stripe', 'active', ['USD']));
        $registry->registerMethod(PaymentMethodData::fromInput('stripe', 'stripe', 'USD', '1.00', '1000.00', status: 'enabled'));
        $player = $this->createAdmittedPlayer('0.00', Currency::THB);
        $usd = Wallet::factory()->for($player['user'])->withBalance('0.00')->create(['currency' => Currency::USD]);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/payments/intents', [
            'direction' => 'deposit', 'amount' => '100.00', 'currency' => 'USD',
            'method' => 'stripe', 'idempotency_key' => 'multicurrency-registry-usd',
        ])->assertCreated()->assertJsonPath('data.currency', 'USD');
        self::assertSame((int) $usd->id, (int) PaymentIntent::query()->sole()->wallet_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $usd->fresh()->balance);
    }
}
