<?php

declare(strict_types=1);

namespace Tests\Feature\Payout;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WithdrawalStatus;
use App\Models\Payment;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payout\ThaiBank\ThaiBankPayoutWebhookService;
use Tests\Feature\Payment\PaymentTestCase;

final class ThaiBankPayoutWebhookTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'thai_payout.enabled' => true,
            'thai_payout.callback_tolerance_seconds' => 300,
            'thai_payout.banks.scb.webhook_secret' => 'scb-webhook-test-secret',
            'thai_payout.banks.scb.callback_signature_header' => 'X-Signature',
            'thai_payout.banks.scb.callback_timestamp_header' => 'X-Timestamp',
        ]);
    }

    public function test_signed_settled_callback_completes_once_and_identical_replay_has_no_second_effect(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = $this->body($withdrawal->reference_number, 'SETTLED', '100.00', 'THB', (string) $attempt->provider_reference);
        $headers = $this->signedHeaders($body);
        $service = app(ThaiBankPayoutWebhookService::class);

        $first = $service->processCallback('scb', $body, $headers);
        $replay = $service->processCallback('scb', $body, $headers);

        self::assertTrue($first['accepted']);
        self::assertTrue($first['signature_verified']);
        self::assertFalse($first['duplicate'] ?? false);
        self::assertTrue($replay['accepted']);
        self::assertTrue($replay['duplicate'] ?? false);
        self::assertSame(ThaiBankPayoutState::Settled, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Completed, $withdrawal->fresh()->status);
        self::assertSame('400.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
    }

    public function test_signed_rejected_callback_releases_hold_and_terminal_rejection_cannot_regress(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $service = app(ThaiBankPayoutWebhookService::class);
        $rejectBody = $this->body($withdrawal->reference_number, 'REJECTED', null, null, (string) $attempt->provider_reference);
        $rejected = $service->processCallback('scb', $rejectBody, $this->signedHeaders($rejectBody));

        self::assertTrue($rejected['accepted']);
        self::assertSame(ThaiBankPayoutState::Rejected, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);

        $settleBody = $this->body($withdrawal->reference_number, 'SETTLED', '100.00', 'THB', 'SCB-LATE-SETTLE-001');
        $service->processCallback('scb', $settleBody, $this->signedHeaders($settleBody));
        self::assertSame(ThaiBankPayoutState::Rejected, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_settlement_updates_the_linked_payment_atomically_with_the_withdrawal(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $payment = Payment::factory()->create([
            'user_id' => (int) $withdrawal->user_id,
            'payable_type' => Withdrawal::class,
            'payable_id' => (int) $withdrawal->getKey(),
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '100.00',
            'fee' => '0.00',
            'gateway' => PaymentMethod::BankTransfer->value,
        ]);
        $body = $this->body($withdrawal->reference_number, 'SETTLED', '100.00', 'THB', (string) $attempt->provider_reference);

        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertTrue($result['accepted']);
        self::assertSame(PaymentStatus::Captured, $payment->fresh()->status);
        self::assertSame((string) $attempt->provider_reference, $payment->fresh()->gateway_reference);
        self::assertSame('completed', $payment->fresh()->gateway_response['outcome']);
        self::assertSame(WithdrawalStatus::Completed, $withdrawal->fresh()->status);
        self::assertSame('400.00', $wallet->fresh()->balance);
    }

    public function test_rejection_updates_the_linked_payment_and_releases_the_hold(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $payment = Payment::factory()->create([
            'user_id' => (int) $withdrawal->user_id,
            'payable_type' => Withdrawal::class,
            'payable_id' => (int) $withdrawal->getKey(),
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Pending,
            'currency' => Currency::THB,
            'amount' => '100.00',
            'fee' => '0.00',
            'gateway' => PaymentMethod::BankTransfer->value,
        ]);
        $body = $this->body($withdrawal->reference_number, 'REJECTED', null, null, (string) $attempt->provider_reference);

        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertTrue($result['accepted']);
        self::assertSame(PaymentStatus::Failed, $payment->fresh()->status);
        self::assertSame('failed', $payment->fresh()->gateway_response['outcome']);
        self::assertSame(WithdrawalStatus::Failed, $withdrawal->fresh()->status);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);
    }

    public function test_unknown_reference_is_acknowledged_without_local_state_change(): void
    {
        $body = $this->body('WD-UNKNOWN-REFERENCE', 'SETTLED', '1.00', 'THB', 'SCB-UNKNOWN');
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertTrue($result['accepted']);
        self::assertTrue($result['signature_verified']);
        self::assertDatabaseCount('thai_bank_payout_attempts', 0);
    }

    public function test_signed_malformed_callback_is_audited_without_changing_the_reserved_withdrawal(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = json_encode([
            'reference' => str_repeat('W', 65),
            'state' => 'SETTLED',
            'providerReference' => 'SCB-OVERSIZED-REF',
            'amount' => '100.00',
            'currency' => 'THB',
        ], JSON_THROW_ON_ERROR);
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertFalse($result['accepted']);
        self::assertTrue($result['signature_verified']);
        self::assertSame(ThaiBankPayoutState::Accepted, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertSame('100.00', $wallet->fresh()->locked_balance);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseHas('audit_logs', ['description' => 'Signed Thai bank payout callback contained invalid or incomplete facts.']);
    }

    public function test_amount_mismatch_preserves_hold_in_uncertain_state_and_is_audited(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = $this->body($withdrawal->reference_number, 'SETTLED', '99.99', 'THB', (string) $attempt->provider_reference);
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertTrue($result['accepted']);
        self::assertSame(ThaiBankPayoutState::Uncertain, $attempt->fresh()->state);
        self::assertTrue($attempt->fresh()->outcome_unknown);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertSame('100.00', $wallet->fresh()->locked_balance);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertGreaterThan(0, \App\Models\AuditLog::query()->count());
    }

    public function test_duplicate_signed_status_fields_cannot_release_or_settle_the_hold(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = '{"reference":"'.$withdrawal->reference_number.'","state":"SETTLED","st\\u0061te":"REJECTED",'
            .'"providerReference":"'.$attempt->provider_reference.'","amount":"100.00","currency":"THB"}';
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertFalse($result['accepted']);
        self::assertTrue($result['signature_verified']);
        self::assertSame(ThaiBankPayoutState::Accepted, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertSame('100.00', (string) $wallet->fresh()->locked_balance);
        self::assertSame('500.00', (string) $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        self::assertDatabaseHas('audit_logs', ['description' => 'Signed Thai bank payout callback contained invalid or incomplete facts.']);
    }

    public function test_a_decoy_amount_cannot_make_a_mismatched_signed_callback_settle(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = json_encode([
            'reference' => $withdrawal->reference_number,
            'state' => 'SETTLED',
            'providerReference' => (string) $attempt->provider_reference,
            'other' => ['amount' => 100.00],
            'data' => ['amount' => 1.23, 'currency' => 'THB'],
        ], JSON_THROW_ON_ERROR);
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, $this->signedHeaders($body));

        self::assertTrue($result['accepted']);
        self::assertTrue($result['signature_verified']);
        self::assertSame(ThaiBankPayoutState::Uncertain, $attempt->fresh()->state);
        self::assertTrue((bool) $attempt->fresh()->outcome_unknown);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertSame('100.00', (string) $wallet->fresh()->locked_balance);
        self::assertSame('500.00', (string) $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
    }

    public function test_callback_from_another_bank_cannot_mutate_the_matched_attempt(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        config([
            'thai_payout.banks.kbank.webhook_secret' => 'kbank-webhook-test-secret',
            'thai_payout.banks.kbank.callback_signature_header' => 'X-Signature',
            'thai_payout.banks.kbank.callback_timestamp_header' => 'X-Timestamp',
        ]);
        $body = $this->body($withdrawal->reference_number, 'SETTLED', '100.00', 'THB', 'KBANK-CROSS-BANK-001');
        $timestamp = (string) time();
        $headers = [
            'X-Timestamp' => $timestamp,
            'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'kbank-webhook-test-secret'),
        ];

        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('kbank', $body, $headers);

        self::assertTrue($result['accepted']);
        self::assertTrue($result['signature_verified']);
        self::assertSame(ThaiBankPayoutState::Accepted, $attempt->fresh()->state);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertSame('100.00', $wallet->fresh()->locked_balance);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_bad_signature_cannot_change_attempt_or_wallet_state(): void
    {
        [$withdrawal, $attempt, $wallet] = $this->fixture();
        $body = $this->body($withdrawal->reference_number, 'SETTLED', '100.00', 'THB', 'SCB-BAD-SIGNATURE');
        $timestamp = (string) time();
        $result = app(ThaiBankPayoutWebhookService::class)->processCallback('scb', $body, [
            'X-Timestamp' => $timestamp,
            'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'wrong-secret'),
        ]);

        self::assertFalse($result['accepted']);
        self::assertFalse($result['signature_verified']);
        self::assertSame(ThaiBankPayoutState::Accepted, $attempt->fresh()->state);
        self::assertSame('100.00', $wallet->fresh()->locked_balance);
        self::assertSame('500.00', $wallet->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    /** @return array{\App\Models\Withdrawal, ThaiBankPayoutAttempt, \App\Models\Wallet} */
    private function fixture(): array
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
            'state' => ThaiBankPayoutState::Accepted,
            'provider_reference' => 'SCB-ACCEPTED-'.strtoupper(bin2hex(random_bytes(4))),
            'request_fingerprint' => hash('sha256', $withdrawal->reference_number),
            'outcome_unknown' => false,
            'raw_response' => [],
        ]);

        return [$withdrawal->fresh(), $attempt->fresh(), $player['wallet']->fresh()];
    }

    private function body(string $reference, string $state, ?string $amount, ?string $currency, ?string $providerReference): string
    {
        return json_encode(array_filter([
            'reference' => $reference,
            'state' => $state,
            'providerReference' => $providerReference,
            'amount' => $amount,
            'currency' => $currency,
        ], static fn (mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function signedHeaders(string $body): array
    {
        $timestamp = (string) time();

        return [
            'X-Timestamp' => $timestamp,
            'X-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, 'scb-webhook-test-secret'),
        ];
    }
}
