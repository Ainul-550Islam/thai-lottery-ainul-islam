<?php

declare(strict_types=1);

namespace Tests\Unit\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\ThaiBankPayoutState;
use App\Events\WalletBalanceUpdated;
use App\Events\WithdrawalStatusUpdated;
use App\Exceptions\FinancialException;
use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\AuditLog;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankDestinationValidator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;

final class ThaiBankPayoutGatewayTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate payout scheduling from unrelated realtime and analytics subscribers.
        Event::fake([WalletBalanceUpdated::class, WithdrawalStatusUpdated::class]);
        Queue::fake();
        config(['queue.default' => 'database']);
        Cache::flush();
        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.default_bank', 'scb');
        Config::set('thai_payout.min_amount', '1.00');
        Config::set('thai_payout.max_amount', '500000.00');
        Config::set('thai_payout.daily_limit', '1000000.00');
        Config::set('thai_payout.banks.scb', [
            'enabled' => true,
            'contract_confirmed' => true,
            'status_method' => 'GET',
            'base_url' => 'https://api.scb.test',
            'token_path' => '/v1/oauth/token',
            'transfer_path' => '/v1/transfer',
            'status_path' => '/v1/transfer/{reference}',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'resource_owner_id' => 'owner-id',
            'webhook_secret' => 'callback-secret',
            'source_account' => 'source-account-id',
            'timeout_seconds' => 5,
            'resource_owner_header' => 'resourceOwnerId',
            'request_id_header' => 'requestUId',
            'idempotency_header' => 'Idempotency-Key',
            'channel_header' => 'Channel',
            'channel_value' => 'partner',
            'callback_signature_header' => 'X-Signature',
            'callback_timestamp_header' => 'X-Timestamp',
        ]);
        Cache::put(ScbPayoutAdapter::tokenCacheKey('client-id'), 'access-token', now()->addHour());
    }

    public function test_gateway_is_disabled_when_the_lane_or_required_partner_configuration_is_missing(): void
    {
        Config::set('thai_payout.enabled', false);
        $gateway = $this->gateway();
        self::assertFalse($gateway->isEnabled());
        self::assertSame('configured_only', $gateway->status()->value);

        Config::set('thai_payout.enabled', true);
        Config::set('thai_payout.banks.scb.contract_confirmed', false);
        self::assertFalse($this->gateway()->isEnabled());
    }

    #[DataProvider('outcomes')]
    public function test_maps_bank_outcomes_and_never_dispatches_twice_for_one_withdrawal_reference(
        int $status,
        array $payload,
        bool $successful,
        bool $pending,
        bool $uncertain,
        string $attemptState,
    ): void {
        $withdrawal = $this->approvedWithdrawal();
        Http::fake(['*' => Http::response($payload, $status)]);
        $gateway = $this->gateway();

        $first = $gateway->initiateWithdrawal($withdrawal);
        $second = $gateway->initiateWithdrawal($withdrawal->fresh());

        self::assertSame($successful, $first->successful);
        self::assertSame($pending, $first->isPending);
        self::assertSame($uncertain, $first->outcomeUnknown);
        self::assertSame($first->successful, $second->successful);
        self::assertSame($first->isPending, $second->isPending);
        self::assertSame($first->outcomeUnknown, $second->outcomeUnknown);
        $attempt = ThaiBankPayoutAttempt::query()->sole();
        self::assertSame($attemptState, $attempt->state->value);
        self::assertSame(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/v1/transfer'))->count());
        Http::assertSentCount(1);
        if (in_array($attemptState, [ThaiBankPayoutState::Accepted->value, ThaiBankPayoutState::Uncertain->value, ThaiBankPayoutState::Rejected->value], true)) {
            Queue::assertPushed(PollThaiBankPayoutStatusJob::class, fn (PollThaiBankPayoutStatusJob $job): bool => $job->attemptId === (int) $attempt->getKey());
        } else {
            Queue::assertNothingPushed();
        }
    }

    public static function outcomes(): array
    {
        return [
            'accepted' => [200, ['data' => ['status' => 'ACCEPTED', 'transactionId' => 'SCB-TXN-A']], true, true, false, ThaiBankPayoutState::Accepted->value],
            'settled with evidence' => [200, ['data' => ['status' => 'SETTLED', 'transactionId' => 'SCB-TXN-S', 'amount' => '100.00', 'currency' => 'THB']], true, false, false, ThaiBankPayoutState::Settled->value],
            'rejected' => [422, ['error' => 'declined'], false, false, false, ThaiBankPayoutState::Rejected->value],
            'uncertain server failure' => [503, ['error' => 'temporary'], false, true, true, ThaiBankPayoutState::Uncertain->value],
        ];
    }

    public static function ambiguousSettlementResponses(): array
    {
        return [
            'unrelated amount' => ['{"other":{"amount":100.00},"data":{"status":"SETTLED","transactionId":"SCB-JSON-1","amount":1.23,"currency":"THB"}}'],
            'exponential amount' => ['{"data":{"status":"SETTLED","transactionId":"SCB-JSON-1","amount":100.00e-2,"currency":"THB"}}'],
            'duplicate status' => ['{"data":{"status":"SETTLED","st\\u0061tus":"REJECTED","transactionId":"SCB-JSON-1","amount":100.00,"currency":"THB"}}'],
        ];
    }

    #[DataProvider('ambiguousSettlementResponses')]
    public function test_ambiguous_initial_bank_response_preserves_the_hold(string $body): void
    {
        $withdrawal = $this->approvedWithdrawal();
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);

        $response = $this->gateway()->initiateWithdrawal($withdrawal);

        self::assertFalse($response->successful);
        self::assertTrue($response->isPending);
        self::assertTrue($response->outcomeUnknown);
        self::assertSame(ThaiBankPayoutState::Uncertain, ThaiBankPayoutAttempt::query()->sole()->state);
        self::assertSame('approved', $withdrawal->fresh()->status->value);
        self::assertSame('100.00', (string) $withdrawal->wallet->fresh()->locked_balance);
        self::assertSame('500.00', (string) $withdrawal->wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        Http::assertSentCount(1);
    }

    public function test_preexisting_prepared_attempt_is_not_dispatched_again(): void
    {
        $withdrawal = $this->approvedWithdrawal();
        $details = is_array($withdrawal->payout_details) ? $withdrawal->payout_details : [];
        $instruction = (new ThaiBankDestinationValidator)->instruction(
            reference: (string) $withdrawal->reference_number,
            amount: '100.00',
            currency: 'THB',
            details: $details,
        );
        $attempt = ThaiBankPayoutAttempt::factory()->create([
            'withdrawal_id' => (int) $withdrawal->getKey(),
            'withdrawal_reference' => (string) $withdrawal->reference_number,
            'bank' => 'scb',
            'amount' => '100.00',
            'destination_type' => $instruction->destinationType,
            'destination_masked' => ThaiBankDestinationValidator::maskAccount($instruction->accountNumber),
            'state' => ThaiBankPayoutState::Prepared,
            'request_fingerprint' => ThaiBankPayoutGateway::instructionFingerprint($instruction, 'scb'),
            'outcome_unknown' => false,
        ]);
        Http::fake();

        $response = $this->gateway()->initiateWithdrawal($withdrawal);

        self::assertFalse($response->successful);
        self::assertTrue($response->isPending);
        self::assertTrue($response->outcomeUnknown);
        self::assertSame(ThaiBankPayoutState::Uncertain, $attempt->fresh()->state);
        self::assertTrue((bool) $attempt->fresh()->outcome_unknown);
        Http::assertNothingSent();
        Queue::assertPushed(PollThaiBankPayoutStatusJob::class, fn (PollThaiBankPayoutStatusJob $job): bool => $job->attemptId === (int) $attempt->getKey());
    }

    public function test_changed_instruction_for_an_existing_reference_stays_uncertain_and_never_resubmits(): void
    {
        $withdrawal = $this->approvedWithdrawal();
        Http::fake(['*' => Http::response(['data' => ['status' => 'ACCEPTED', 'transactionId' => 'SCB-TXN-CONFLICT']], 200)]);
        $gateway = $this->gateway();

        $first = $gateway->initiateWithdrawal($withdrawal);
        self::assertTrue($first->successful);
        $withdrawal->forceFill(['payout_details' => [
            'destination_type' => 'bank_account',
            'bank_code' => 'SCB',
            'account_number' => '9876543210',
            'account_name' => 'Example Person',
        ]])->save();

        $second = $gateway->initiateWithdrawal($withdrawal->fresh());

        self::assertFalse($second->successful);
        self::assertTrue($second->isPending);
        self::assertTrue($second->outcomeUnknown);
        self::assertSame('thai_payout_reference_conflict', $second->metadata['reason']);
        self::assertSame(ThaiBankPayoutState::Uncertain, ThaiBankPayoutAttempt::query()->sole()->state);
        self::assertTrue(AuditLog::query()->where('description', 'like', '%conflicting Thai bank instruction%')->exists());
        Http::assertSentCount(1);
    }

    public function test_invalid_destination_is_refused_before_any_http_call(): void
    {
        $withdrawal = $this->approvedWithdrawal([
            'destination_type' => 'bank_account',
            'bank_code' => 'SCB',
            'account_number' => '1234',
            'account_name' => 'Example Person',
        ]);
        Http::fake();

        $response = $this->gateway()->initiateWithdrawal($withdrawal);

        self::assertFalse($response->successful);
        self::assertStringContainsString('destination', strtolower((string) $response->errorMessage));
        self::assertSame(0, ThaiBankPayoutAttempt::query()->count());
        Http::assertNothingSent();
    }

    public function test_validates_destination_against_provider_before_creating_attempt(): void
    {
        $validator = new ThaiBankDestinationValidator;
        $this->expectException(FinancialException::class);
        $validator->instruction('WD-TEST-900', '1.00', 'THB', [
            'destination_type' => 'bank_account',
            'bank_code' => 'SCB',
            'account_number' => '1234',
            'account_name' => 'Example Person',
        ]);
    }

    private function gateway(): ThaiBankPayoutGateway
    {
        // Exercise the driver itself; the provider separately refuses incomplete enabled bindings.
        return app()->makeWith(ThaiBankPayoutGateway::class, ['adapter' => new ScbPayoutAdapter]);
    }

    private function approvedWithdrawal(?array $details = null): Withdrawal
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $withdrawal = app(WithdrawalService::class)->request(
            wallet: $player['wallet'],
            amount: Money::of('100.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
            options: ['payout_details' => $details ?? [
                'destination_type' => 'bank_account',
                'bank_code' => 'SCB',
                'account_number' => '1234567890',
                'account_name' => 'Example Person',
            ]],
        );
        app(WithdrawalApprovalService::class)->approve($withdrawal);

        return $withdrawal->fresh();
    }
}
