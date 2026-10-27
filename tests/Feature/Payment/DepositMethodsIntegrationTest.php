<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\AgeVerification;
use App\Models\Deposit;
use App\Models\DepositSlipVerification;
use App\Models\User;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

/**
 * The compatibility deposit and withdrawal surfaces must tell the truth about
 * their rails.
 *
 * WHY THIS SUITE EXISTS
 * The retired implementation advertised rails it could not collect on. A
 * deposit page is a promise to a player that money can arrive, and a withdrawal
 * page is a promise that money can leave, so both listings have to be derived
 * from the same gateway manager the money path uses. A rail with nothing
 * configured must say so instead of rendering a button that only ever returns
 * an error.
 *
 * What is asserted here is the honest version: a rail that is disabled or
 * unconfigured is reported NOT_CONFIGURED, a bank-transfer intent is only
 * created when a receiving account actually exists, and the deposit it creates
 * is PENDING — the money is recognised by the slip lane or an operator, never
 * by this route. The automated Thai bank payout rail is reported as ROUTING and
 * never as a method a player may choose.
 */
final class DepositMethodsIntegrationTest extends PaymentTestCase
{
    public function test_the_listing_reports_a_disabled_rail_as_not_configured(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => false,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
            'payment.gateways.bank_transfer.enabled' => false,
            'slip_verification.receiving_accounts' => [],
        ]);

        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/deposit/methods');
        $response->assertOk();

        $rails = collect($response->json('data.methods'))->keyBy('id');

        $this->assertSame(['stripe', 'bkash', 'nagad', 'promptpay', 'bank_transfer'], $rails->keys()->all());

        $promptpay = $rails->get('promptpay');
        $this->assertFalse($promptpay['enabled']);
        $this->assertSame('NOT_CONFIGURED', $promptpay['state']);
        $this->assertNotEmpty($promptpay['reason'], 'A disabled rail must say why.');

        $bankTransfer = $rails->get('bank_transfer');
        $this->assertFalse($bankTransfer['enabled']);
        $this->assertSame('NOT_CONFIGURED', $bankTransfer['state']);
        $this->assertSame('THB', $bankTransfer['currency']);
        // A rail without bounds of its own reports the global deposit bounds,
        // never a number invented in the controller.
        $this->assertSame((string) config('payment.deposit.min'), $bankTransfer['min_amount']);
        $this->assertSame((string) config('payment.deposit.max'), $bankTransfer['max_amount']);
    }

    public function test_a_configured_rail_is_reported_available(): void
    {
        config([
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Siam Commercial Bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Operator Account',
            'slip_verification.receiving_accounts' => [
                ['bank' => 'SCB', 'account_last_digits' => '7890', 'name' => 'Operator Account'],
            ],
        ]);

        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $rails = collect($this->getJson('/api/v1/deposit/methods')->assertOk()->json('data.methods'))->keyBy('id');

        foreach (['promptpay', 'bank_transfer'] as $rail) {
            $this->assertTrue($rails->get($rail)['enabled'], $rail.' must be reported available.');
            $this->assertSame('AVAILABLE', $rails->get($rail)['state']);
            $this->assertNull($rails->get($rail)['reason']);
        }
    }

    public function test_the_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/deposit/methods')->assertUnauthorized();
    }

    public function test_a_bank_transfer_intent_without_a_receiving_account_is_refused(): void
    {
        config(['slip_verification.receiving_accounts' => []]);

        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/deposit/bank-transfer/intent', [
            'amount' => '250.00',
            'idempotency_key' => 'bank-transfer-no-account',
        ])->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');

        $this->assertSame(0, Deposit::query()->count(), 'A refused intent must not create a deposit.');
    }

    public function test_a_bank_transfer_intent_returns_the_configured_accounts_and_a_pending_deposit(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Siam Commercial Bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Operator Account',
            'slip_verification.receiving_accounts' => [
                ['bank' => 'SCB', 'account_last_digits' => '7890', 'name' => 'Operator Account'],
            ],
        ]);

        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposit/bank-transfer/intent', [
            'amount' => '250.00',
            'idempotency_key' => 'bank-transfer-with-account',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount', '250.00')
            ->assertJsonPath('data.currency', 'THB')
            ->assertJsonPath('data.status', DepositStatus::Pending->value);

        $accounts = $response->json('data.receiving_accounts');
        $this->assertSame([['bank' => 'SCB', 'account_last_digits' => '7890', 'name' => 'Operator Account']], $accounts);

        $deposit = Deposit::query()->findOrFail($response->json('data.deposit_id'));
        $this->assertSame(PaymentMethod::BankTransfer, $deposit->method);
        $this->assertSame(DepositStatus::Pending, $deposit->status, 'A bank-transfer intent must never credit a wallet.');
        $this->assertSame('250.00', (string) $deposit->amount);
        $this->assertSame($player['user']->id, $deposit->user_id);
        $this->assertSame($deposit->reference_number, $response->json('data.transfer_note_reference'));

        // The wallet is untouched: money is recognised by the slip lane or an
        // operator review, never by the intent.
        $this->assertSame('0.00', (string) $player['wallet']->refresh()->balance);
    }

    public function test_a_bank_transfer_intent_requires_authentication(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Siam Commercial Bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Operator Account',
            'slip_verification.receiving_accounts' => [
                ['bank' => 'SCB', 'account_last_digits' => '7890', 'name' => 'Operator Account'],
            ],
        ]);

        $this->postJson('/api/v1/deposit/bank-transfer/intent', [
            'amount' => '250.00',
            'idempotency_key' => 'bank-transfer-anonymous',
        ])->assertUnauthorized();

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_a_client_submitted_internal_payout_key_is_refused(): void
    {
        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $this->postJson('/api/v1/deposits', [
            'amount' => '250.00',
            'method' => 'thai_bank',
            'idempotency_key' => 'thai-bank-not-a-method',
        ])->assertStatus(422);

        $this->assertSame(0, Deposit::query()->count());
    }

    public function test_slip_verification_delegates_to_the_canonical_lane(): void
    {
        // Slip verification is disabled, so the canonical controller answers
        // with its own named refusal. Proving the compat route reaches that
        // lane — and does not answer with a made-up success of its own — is
        // the point: there is one implementation of slip verification.
        config([
            'slip_verification.enabled' => false,
            'slip_verification.provider' => 'easyslip',
        ]);

        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);

        $response = $this->postJson('/api/v1/deposit/verify-slip', [
            'amount' => '250.00',
            'qr_payload' => '0046000000000000000000TEST',
        ]);

        $this->assertContains($response->status(), [422, 503]);
        $this->assertSame(0, DepositSlipVerification::query()->count());
    }

    public function test_slip_verification_requires_authentication(): void
    {
        config(['slip_verification.enabled' => false]);

        $this->postJson('/api/v1/deposit/verify-slip', [
            'amount' => '250.00',
            'qr_payload' => '0046000000000000000000TEST',
        ])->assertUnauthorized();
    }

    public function test_the_withdrawal_listing_reports_a_disabled_rail_as_not_configured(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => false,
            'payment.gateways.bkash.enabled' => false,
            'payment.gateways.nagad.enabled' => false,
            'payment.gateways.crypto.enabled' => false,
            'thai_payout.enabled' => false,
        ]);

        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/withdrawal/methods');
        $response->assertOk();

        $rails = collect($response->json('data.methods'))->keyBy('id');

        $this->assertSame(
            array_values(array_intersect(
                (array) config('payment.withdrawal.allowed_methods', []),
                ['bkash', 'nagad', 'bank_transfer', 'crypto'],
            )),
            $rails->keys()->all(),
            'The listing is the configured requestable set, nothing invented.',
        );

        foreach ($rails as $id => $rail) {
            $this->assertFalse($rail['enabled'], $id.' must not be offered while its gateway is off.');
            $this->assertSame('NOT_CONFIGURED', $rail['state']);
            $this->assertNotEmpty($rail['reason']);
            $this->assertSame((string) config('payment.withdrawal.min'), $rail['min_amount']);
            $this->assertSame((string) config('payment.withdrawal.max'), $rail['max_amount']);
            $this->assertSame((string) config('finance.withdrawal.kyc_gate_threshold'), $response->json('data.requires_kyc_above'));
        }

        $this->assertSame('THB', $response->json('data.default_currency'));
    }

    public function test_a_configured_withdrawal_rail_is_reported_available(): void
    {
        config([
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.gateways.bank_transfer.settlement.bank_name' => 'Siam Commercial Bank',
            'payment.gateways.bank_transfer.settlement.account_number' => '1234567890',
            'payment.gateways.bank_transfer.settlement.account_name' => 'Operator Account',
        ]);

        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $rails = collect($this->getJson('/api/v1/withdrawal/methods')->assertOk()->json('data.methods'))->keyBy('id');

        $this->assertTrue($rails->get('bank_transfer')['enabled']);
        $this->assertSame('AVAILABLE', $rails->get('bank_transfer')['state']);
        $this->assertNull($rails->get('bank_transfer')['reason']);
    }

    public function test_the_withdrawal_listing_never_offers_the_internal_payout_rail(): void
    {
        config(['thai_payout.enabled' => true]);

        $player = $this->createPlayer('0.00');
        Sanctum::actingAs($player['user']);

        $response = $this->getJson('/api/v1/withdrawal/methods');
        $response->assertOk();

        $ids = collect($response->json('data.methods'))->pluck('id');

        $this->assertNotContains('thai_bank', $ids->all(), 'The automated payout rail is routed, never chosen.');
        $this->assertTrue($response->json('data.routing.automated_thai_bank'));
    }

    public function test_the_withdrawal_listing_requires_authentication(): void
    {
        $this->getJson('/api/v1/withdrawal/methods')->assertUnauthorized();
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
