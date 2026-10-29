<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Services\Payment\PaymentGatewayManager;
use Laravel\Sanctum\Sanctum;

/** Market-specific capabilities must be truthful without blocking global access. */
final class GlobalMarketCapabilityTest extends PaymentTestCase
{
    public function test_bangladesh_market_lists_bdt_not_thb_and_never_exposes_crypto(): void
    {
        config([
            'payment.currency.default' => 'BDT',
            'payment.country' => 'BD',
            'payment.markets.bkash' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bkash' => [
                'enabled' => true,
                'supports_deposit' => true,
                'supports_withdrawal' => true,
                'supported_currencies' => ['BDT'],
                'app_key' => 'test-key',
                'app_secret' => 'test-secret',
                'username' => 'test-username',
                'password' => 'test-password',
                'base_url' => 'https://bkash.example.test',
                'webhook_secret' => 'test-webhook-secret',
            ],
            // A mistaken operator allowlist must not expose an unsupported rail.
            'payment.withdrawal.allowed_methods' => ['bkash', 'crypto'],
            'payment.gateways.crypto.enabled' => true,
        ]);
        $player = $this->createPlayer('0.00', Currency::BDT);
        Sanctum::actingAs($player['user']);

        $deposit = $this->getJson('/api/v1/deposit/methods')->assertOk()
            ->assertJsonPath('data.default_currency', 'BDT');
        $depositRails = collect($deposit->json('data.methods'))->keyBy('id');
        $this->assertFalse($depositRails['bkash']['enabled'], 'A configured key cannot replace verified callback authentication.');
        $this->assertSame('BDT', $depositRails['bkash']['currency']);
        $this->assertFalse($depositRails['promptpay']['enabled']);
        $this->assertFalse($depositRails->has('crypto'));

        $withdrawal = $this->getJson('/api/v1/withdrawal/methods')->assertOk()
            ->assertJsonPath('data.default_currency', 'BDT');
        $withdrawalRails = collect($withdrawal->json('data.methods'))->keyBy('id');
        $this->assertFalse($withdrawalRails['bkash']['enabled']);
        $this->assertSame('BDT', $withdrawalRails['bkash']['currency']);
        $this->assertFalse($withdrawalRails->has('crypto'));

        config(['payment.currency.default' => 'bdt', 'payment.country' => 'bd']);
        $this->assertFalse(app(PaymentGatewayManager::class)->isDepositCapable(PaymentMethod::Bkash));
        $this->getJson('/api/v1/deposit/methods')->assertOk()->assertJsonPath('data.default_currency', 'BDT');
    }

    public function test_non_thai_bank_transfer_uses_the_canonical_currency_aware_intent(): void
    {
        config([
            'payment.currency.default' => 'BDT', 'payment.country' => 'BD',
            'payment.markets.bank_transfer' => ['currencies' => [' bdt '], 'countries' => [' bd ']],
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.supports_deposit' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Configured Bangladesh bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Configured merchant',
            'slip_verification.receiving_accounts' => [],
        ]);
        $player = $this->createAdmittedPlayer('0.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $rails = collect($this->getJson('/api/v1/deposit/methods')->assertOk()->json('data.methods'))->keyBy('id');
        $this->assertTrue($rails['bank_transfer']['enabled']);
        $this->assertSame('BDT', $rails['bank_transfer']['currency']);
        $this->assertSame(route('api.v1.deposits.store'), $rails['bank_transfer']['initiate_route']);

        $this->postJson('/api/v1/deposit/bank-transfer/intent', [
            'amount' => '250.00', 'idempotency_key' => 'bdt-thai-intent-refused',
        ])->assertStatus(503);
        $this->assertSame(0, Deposit::query()->count());

        $this->postJson('/api/v1/deposits', [
            'amount' => '250.00', 'currency' => 'BDT', 'method' => 'bank_transfer',
            'idempotency_key' => 'bdt-bank-canonical-intent',
        ])->assertCreated()->assertJsonPath('data.deposit.currency', 'BDT')
            ->assertJsonPath('data.checkout.instructions.bank_name', 'Configured Bangladesh bank');
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, Deposit::query()->count());
    }

    public function test_actual_transaction_currency_must_also_match_the_merchant_market(): void
    {
        config([
            'payment.currency.default' => 'BDT', 'payment.country' => 'BD',
            'payment.markets.bank_transfer' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Configured bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Configured merchant',
        ]);
        $manager = app(PaymentGatewayManager::class);
        $this->assertTrue($manager->isDepositCapable(PaymentMethod::BankTransfer));
        foreach (['depositDriver', 'withdrawalDriver'] as $operation) {
            try {
                $manager->{$operation}(PaymentMethod::BankTransfer, Currency::THB);
                $this->fail('A different wallet currency cannot escape the market guard.');
            } catch (FinancialException $exception) {
                $this->assertSame('payment_gateway_not_ready', $exception->errorCode());
            }
        }
        $player = $this->createAdmittedPlayer('0.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/deposits', [
            'amount' => '250.00', 'currency' => 'THB', 'method' => 'bank_transfer',
            'idempotency_key' => 'wrong-currency-bank-intent',
        ])->assertStatus(422);
        $this->assertSame(0, Deposit::query()->count());
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
    }

    public function test_automated_thai_routing_is_reported_only_when_usable_in_the_current_market(): void
    {
        config([
            'payment.currency.default' => 'THB', 'payment.country' => 'TH',
            'payment.markets.thai_bank' => ['currencies' => ['THB'], 'countries' => ['TH']],
            'payment.withdrawal_routing.prefer_automated_payout' => true,
            'thai_payout.enabled' => true, 'thai_payout.default_bank' => 'scb',
            'thai_payout.banks.scb' => [
                'enabled' => true, 'contract_confirmed' => true,
                'base_url' => 'https://scb.example.test', 'token_path' => '/oauth/token',
                'transfer_path' => '/transfers', 'status_path' => '/transfers/{reference}',
                'status_method' => 'GET', 'client_id' => 'test-client', 'client_secret' => 'test-secret',
                'resource_owner_id' => 'test-owner', 'webhook_secret' => 'test-webhook-secret',
                'source_account' => '1234567890', 'timeout_seconds' => 10,
            ],
        ]);
        $player = $this->createPlayer('0.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $this->getJson('/api/v1/withdrawal/methods')->assertOk()->assertJsonPath('data.routing.automated_thai_bank', true);

        config(['payment.withdrawal_routing.prefer_automated_payout' => false]);
        $this->getJson('/api/v1/withdrawal/methods')->assertOk()->assertJsonPath('data.routing.automated_thai_bank', false);
        config(['payment.withdrawal_routing.prefer_automated_payout' => true, 'payment.currency.default' => 'BDT', 'payment.country' => 'BD']);
        $this->getJson('/api/v1/withdrawal/methods')->assertOk()->assertJsonPath('data.routing.automated_thai_bank', false);
    }

    public function test_unknown_site_currency_refuses_both_method_listings(): void
    {
        config(['payment.currency.default' => 'XYZ']);
        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $this->getJson('/api/v1/deposit/methods')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->getJson('/api/v1/withdrawal/methods')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
    }

    public function test_withdrawal_capability_also_requires_the_driver_to_support_the_currency(): void
    {
        config([
            'payment.currency.default' => 'USD',
            'payment.country' => 'US',
            'payment.markets.stripe' => ['currencies' => ['USD'], 'countries' => ['US']],
            'payment.gateways.stripe.enabled' => true,
            'payment.gateways.stripe.supports_withdrawal' => true,
            'payment.gateways.stripe.supported_currencies' => ['THB'],
        ]);
        $manager = app(PaymentGatewayManager::class);
        $this->assertFalse($manager->isWithdrawalCapable(PaymentMethod::Stripe));

        config(['payment.gateways.stripe.supported_currencies' => ['USD']]);
        $this->assertTrue($manager->isWithdrawalCapable(PaymentMethod::Stripe));
    }
}
