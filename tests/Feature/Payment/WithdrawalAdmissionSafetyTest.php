<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/** New customer requests must not reserve money for an unsupported provider. */
final class WithdrawalAdmissionSafetyTest extends PaymentTestCase
{
    /** @return array<string, array{string}> */
    public static function customerRoutes(): array
    {
        return ['canonical' => ['/api/v1/withdrawals'], 'compatibility' => ['/api/v1/withdrawal/request']];
    }

    #[DataProvider('customerRoutes')]
    public function test_crypto_cannot_be_requested_or_hold_money_even_when_operator_allowlists_it(string $route): void
    {
        config([
            'payment.currency.default' => 'USD', 'payment.country' => 'US',
            'payment.withdrawal.enabled' => true, 'payment.withdrawal.allowed_methods' => ['crypto'],
            'payment.gateways.crypto.enabled' => true,
        ]);
        Http::preventStrayRequests();
        $player = $this->createAdmittedPlayer('500.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson($route, ['amount' => '100.00', 'currency' => 'USD', 'method' => 'crypto',
            'payout_details' => ['account_number' => 'NOT-A-PAYABLE-ADDRESS'],
            'idempotency_key' => 'unsupported-crypto-withdrawal-1'])->assertStatus(422);
        self::assertSame(0, Withdrawal::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        Http::assertNothingSent();
    }

    public function test_crypto_request_does_not_lock_a_customers_funds(): void
    {
        config(['payment.currency.default' => 'USD', 'payment.country' => 'US',
            'payment.withdrawal.enabled' => true, 'payment.withdrawal.allowed_methods' => ['crypto'],
            'payment.gateways.crypto.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/withdrawals', ['amount' => '100.00', 'currency' => 'USD', 'method' => 'crypto',
            'idempotency_key' => 'unsupported-crypto-withdrawal-2']);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance,
            'An unavailable crypto payout must not strand a reservation.');
        self::assertSame(0, Withdrawal::query()->count());
    }

    public function test_disabled_withdrawal_switch_rejects_new_request_without_affecting_balance(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH',
            'payment.withdrawal.enabled' => false, 'payment.withdrawal.allowed_methods' => ['bank_transfer'],
            'payment.gateways.bank_transfer.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/withdrawals', ['amount' => '100.00', 'currency' => 'THB', 'method' => 'bank_transfer',
            'idempotency_key' => 'withdrawal-global-switch-test'])->assertStatus(422);
        self::assertSame(0, Withdrawal::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    /** @return array<string, array{string}> */
    public static function unverifiedBdtMethods(): array
    {
        return ['bkash' => ['bkash'], 'nagad' => ['nagad']];
    }

    #[DataProvider('unverifiedBdtMethods')]
    public function test_unverified_bdt_provider_does_not_strand_a_new_hold(string $method): void
    {
        config(['payment.currency.default' => 'BDT', 'payment.country' => 'BD',
            'payment.withdrawal.enabled' => true, 'payment.withdrawal.allowed_methods' => [$method],
            'payment.gateways.'.$method.'.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/withdrawals', ['amount' => '100.00', 'currency' => 'BDT', 'method' => $method,
            'idempotency_key' => 'unverified-bdt-withdrawal-'.$method])->assertStatus(422);
        self::assertSame(0, Withdrawal::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_disabling_new_requests_does_not_prevent_cancelling_an_existing_hold(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH', 'payment.withdrawal.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $response = $this->postJson('/api/v1/withdrawals', ['amount' => '100.00', 'currency' => 'THB', 'method' => 'manual',
            'idempotency_key' => 'withdrawal-existing-hold-01'])->assertCreated();
        self::assertSame('100.00', (string) $player['wallet']->fresh()->locked_balance);
        config(['payment.withdrawal.enabled' => false]);
        $this->postJson('/api/v1/withdrawals/'.$response->json('data.withdrawal.id').'/cancel', [
            'reason' => 'Customer requested cancellation.',
        ])->assertOk();
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    public function test_disabled_switch_allows_only_an_exact_existing_request_replay_without_another_hold(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH', 'payment.withdrawal.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $payload = ['amount' => '100.00', 'currency' => 'THB', 'method' => 'manual',
            'idempotency_key' => 'withdrawal-before-disabled-01'];
        $first = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        config(['payment.withdrawal.enabled' => false]);
        $again = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        self::assertSame($first->json('data.withdrawal.id'), $again->json('data.withdrawal.id'));
        $payload['amount'] = '101.00';
        $this->postJson('/api/v1/withdrawals', $payload)->assertStatus(422);
        self::assertSame(1, Withdrawal::query()->count());
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_closed_launch_switch_allows_only_replay_and_cancellation_of_an_existing_hold(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'TH', 'payment.withdrawal.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::THB);
        Sanctum::actingAs($player['user']);
        $payload = ['amount' => '100.00', 'currency' => 'THB', 'method' => 'manual',
            'idempotency_key' => 'withdrawal-before-launch-close-01'];
        $first = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        config(['real_money.enabled' => false]);
        $again = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        self::assertSame($first->json('data.withdrawal.id'), $again->json('data.withdrawal.id'));
        $changed = $payload;
        $changed['amount'] = '101.00';
        $this->postJson('/api/v1/withdrawals', $changed)->assertStatus(422);
        // This test deliberately exercises a fourth request; clear only this
        // fixture's rate-limit counter so the throttle does not mask admission.
        \Illuminate\Support\Facades\RateLimiter::clear(md5('withdrawaluser:'.$player['user']->id));
        \Illuminate\Support\Facades\RateLimiter::clear('withdrawal:user:'.$player['user']->id);
        $fresh = $payload;
        $fresh['idempotency_key'] = 'withdrawal-after-launch-close-02';
        $this->postJson('/api/v1/withdrawals', $fresh)->assertStatus(503)
            ->assertJsonPath('error.code', 'real_money_disabled');
        self::assertSame(1, Withdrawal::query()->count());
        self::assertSame('100.00', (string) $player['wallet']->fresh()->locked_balance);
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        $this->postJson('/api/v1/withdrawals/'.$first->json('data.withdrawal.id').'/cancel', [
            'reason' => 'Customer requested cancellation.',
        ])->assertOk();
        self::assertSame('0.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    public function test_withdrawal_catalogue_and_request_use_the_same_owned_usd_wallet(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'US',
            'payment.withdrawal.enabled' => true, 'payment.withdrawal.allowed_methods' => ['bank_transfer'],
            'payment.markets.bank_transfer' => ['currencies' => ['USD'], 'countries' => ['US']],
            'payment.gateways.bank_transfer.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $response = $this->getJson('/api/v1/withdrawal/methods?currency=USD')->assertOk()
            ->assertJsonPath('data.default_currency', 'THB')->assertJsonPath('data.selected_currency', 'USD');
        $rails = collect($response->json('data.methods'))->keyBy('id');
        self::assertTrue($rails['bank_transfer']['enabled']);
        self::assertSame('USD', $rails['bank_transfer']['currency']);
        $this->postJson($rails['bank_transfer']['request_route'], ['amount' => '100.00', 'currency' => 'USD',
            'method' => 'bank_transfer', 'idempotency_key' => 'withdrawal-selected-usd-wallet'])->assertCreated()
            ->assertJsonPath('data.withdrawal.currency', 'USD');
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->locked_balance);
    }

    public function test_withdrawal_catalogue_cannot_select_another_customers_wallet(): void
    {
        config(['payment.currency.default' => 'THB', 'payment.country' => 'US']);
        $player = $this->createPlayer('0.00', Currency::THB);
        $other = $this->createPlayer('0.00', Currency::USD);
        Sanctum::actingAs($player['user']);
        $this->getJson('/api/v1/withdrawal/methods?currency=USD')->assertNotFound();
        self::assertSame('0.00', (string) $other['wallet']->fresh()->balance);
    }

    public function test_omitted_currency_uses_configured_bdt_wallet_for_bank_withdrawal(): void
    {
        config(['payment.currency.default' => 'BDT', 'payment.country' => 'BD',
            'payment.withdrawal.enabled' => true, 'payment.withdrawal.allowed_methods' => ['bank_transfer'],
            'payment.markets.bank_transfer' => ['currencies' => ['BDT'], 'countries' => ['BD']],
            'payment.gateways.bank_transfer.enabled' => true]);
        $player = $this->createAdmittedPlayer('500.00', Currency::BDT);
        Sanctum::actingAs($player['user']);
        $this->postJson('/api/v1/withdrawals', ['amount' => '100.00', 'method' => 'bank_transfer',
            'idempotency_key' => 'withdrawal-default-bdt-wallet'])->assertCreated()
            ->assertJsonPath('data.withdrawal.currency', 'BDT');
        self::assertSame((int) $player['wallet']->id, (int) Withdrawal::query()->sole()->wallet_id);
        self::assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('100.00', (string) $player['wallet']->fresh()->locked_balance);
    }
}
