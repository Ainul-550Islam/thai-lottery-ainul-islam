<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WithdrawalStatus;
use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\ThaiBankPayoutAttempt;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Services\Payout\ThaiBank\ThaiBankPayoutWebhookService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;

final class PollThaiBankPayoutStatusJobTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.poll_after_seconds' => 60,
            'thai_payout.poll_max_age_hours' => 24,
            'thai_payout.callback_tolerance_seconds' => 300,
            'thai_payout.banks.scb' => [
                'enabled' => true,
                'contract_confirmed' => true,
                'status_method' => 'GET',
                'base_url' => 'https://api.scb.test',
                'token_path' => '/v1/oauth/token',
                'transfer_path' => '/v1/transfer',
                'status_path' => '/v1/transfer/{reference}',
                'client_id' => 'poll-client',
                'client_secret' => 'poll-secret',
                'resource_owner_id' => 'poll-owner',
                'webhook_secret' => 'poll-webhook-secret',
                'source_account' => 'poll-source-account',
                'timeout_seconds' => 5,
                'resource_owner_header' => 'resourceOwnerId',
                'request_id_header' => 'requestUId',
                'idempotency_header' => 'Idempotency-Key',
                'channel_header' => 'Channel',
                'channel_value' => 'partner',
                'callback_signature_header' => 'X-Signature',
                'callback_timestamp_header' => 'X-Timestamp',
            ],
        ]);
        Cache::put(ScbPayoutAdapter::tokenCacheKey('poll-client'), 'poll-access-token', now()->addHour());
    }

    #[DataProvider('pollableStates')]
    public function test_accepted_and_uncertain_attempts_are_polled_only_for_status_and_resolved_once(string $initialState): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture(ThaiBankPayoutState::from($initialState));
        Http::fake(['*' => Http::response(['data' => [
            'status' => 'SETTLED',
            'transactionId' => 'SCB-POLL-ACCEPTED-001',
            'amount' => '100.00',
            'currency' => 'THB',
        ]], 200)]);

        $job = new PollThaiBankPayoutStatusJob((int) $attempt->getKey());
        $job->handle(app(DistributedLockService::class), app(ThaiBankPayoutWebhookService::class), app(AuditLogService::class));
        $job->handle(app(DistributedLockService::class), app(ThaiBankPayoutWebhookService::class), app(AuditLogService::class));

        self::assertSame(ThaiBankPayoutState::Settled, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Completed, $withdrawal->fresh()->status);
        self::assertSame('400.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);
        self::assertSame(1, Http::recorded()->count());
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v1/transfer/SCB-POLL-ACCEPTED-001'));
        self::assertDatabaseCount('financial_transactions', 1);
    }

    public static function pollableStates(): array
    {
        return [
            'accepted' => [ThaiBankPayoutState::Accepted->value],
            'uncertain' => [ThaiBankPayoutState::Uncertain->value],
        ];
    }

    public function test_confirmed_rejection_is_finalized_locally_without_a_status_or_transfer_call(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture(ThaiBankPayoutState::Rejected);
        Http::fake();

        (new PollThaiBankPayoutStatusJob((int) $attempt->getKey()))->handle(
            app(DistributedLockService::class),
            app(ThaiBankPayoutWebhookService::class),
            app(AuditLogService::class),
        );

        self::assertSame(ThaiBankPayoutState::Rejected, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);
        Http::assertNothingSent();
        self::assertGreaterThan(0, \App\Models\AuditLog::query()->count());
    }

    public function test_over_age_attempt_is_flagged_for_manual_reconciliation_without_poll_or_hold_release(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture(ThaiBankPayoutState::Uncertain);
        $attempt->forceFill(['created_at' => now()->subHours(25), 'updated_at' => now()->subHours(25)])->save();
        Http::fake();

        (new PollThaiBankPayoutStatusJob((int) $attempt->getKey()))->handle(
            app(DistributedLockService::class),
            app(ThaiBankPayoutWebhookService::class),
            app(AuditLogService::class),
        );

        $attempt->refresh();
        $withdrawal->refresh();
        self::assertSame(ThaiBankPayoutState::Uncertain, $attempt->state);
        self::assertTrue($attempt->outcome_unknown);
        self::assertTrue($attempt->raw_response['manual_reconciliation_required']);
        self::assertTrue($withdrawal->metadata['thai_bank_payout']['reconciliation_required']);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->status);
        self::assertSame('100.00', $wallet->fresh()->locked_balance);
        self::assertSame('500.00', $wallet->fresh()->balance);
        Http::assertNothingSent();
        self::assertGreaterThan(0, \App\Models\AuditLog::query()->count());
    }

    private function fixture(ThaiBankPayoutState $state): array
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $withdrawal = app(WithdrawalService::class)->request(
            wallet: $player['wallet'],
            amount: Money::of('100.00', Currency::THB),
            method: PaymentMethod::BankTransfer,
            options: ['payout_details' => [
                'destination_type' => 'bank_account',
                'bank_code' => 'SCB',
                'account_number' => '1234567890',
                'account_name' => 'Example Person',
            ]],
        );
        app(WithdrawalApprovalService::class)->approve($withdrawal);
        $attempt = ThaiBankPayoutAttempt::query()->create([
            'withdrawal_id' => (int) $withdrawal->getKey(),
            'withdrawal_reference' => $withdrawal->reference_number,
            'bank' => 'scb',
            'amount' => '100.00',
            'destination_type' => 'bank_account',
            'destination_masked' => '••••7890',
            'state' => $state,
            'provider_reference' => 'SCB-POLL-ACCEPTED-001',
            'request_fingerprint' => hash('sha256', $withdrawal->reference_number),
            'outcome_unknown' => $state === ThaiBankPayoutState::Uncertain,
            'raw_response' => [],
            'created_at' => now()->subSeconds(61),
            'updated_at' => now()->subSeconds(61),
        ]);

        return [$withdrawal->fresh(), $attempt->fresh(), $player['wallet']->fresh()];
    }
}
