<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\AuditAction;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\AgeVerification;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Legal\ConsentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;

final class PromptPayDepositTest extends PaymentTestCase
{
    private const MERCHANT = '0812345678';

    private const KBANK_SECRET = 'deterministic-kbank-test-secret';

    private const SCB_SECRET = 'deterministic-scb-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.gateways.promptpay.enabled', true);
        Config::set('payment.gateways.promptpay.merchant_id', self::MERCHANT);
        Config::set('payment.gateways.promptpay.qr_expiry_minutes', 15);
        Config::set('services.kbank.webhook_secret', self::KBANK_SECRET);
        Config::set('services.scb.webhook_secret', self::SCB_SECRET);
    }

    public function test_promptpay_checkout_and_kbank_notification_credit_exactly_once(): void
    {
        [$player, $deposit] = $this->checkout();
        $response = $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-EXACT-001');

        $response->assertOk()->assertJsonPath('result', 'credited')->assertJsonPath('credited', true);
        $this->assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);
        $this->assertSame('125.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
        $this->assertSame('0.00', bcsub(
            (string) LedgerEntry::query()->where('type', 'debit')->sum('amount'),
            (string) LedgerEntry::query()->where('type', 'credit')->sum('amount'),
            2,
        ));

        $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-EXACT-001')->assertOk();
        $this->assertSame('125.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_scb_notification_uses_the_same_exact_match_lane(): void
    {
        [$player, $deposit] = $this->checkout('175.00', 'promptpay-scb-checkout');
        $payload = [
            'event_type' => 'credit',
            'transactionId' => 'SCB-EXACT-001',
            'billPaymentRef1' => $deposit->provider_reference,
            'amount' => '175.00',
            'currency' => 'THB',
            'timestamp' => (string) time(),
        ];

        $this->postJson('/api/v1/payments/bank-webhook/scb', $payload, [
            'X-SCB-Signature' => $this->signature($payload, self::SCB_SECRET),
            'X-SCB-Timestamp' => $payload['timestamp'],
        ])->assertOk()->assertJsonPath('result', 'credited')->assertJsonPath('credited', true);

        $this->assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);
        $this->assertSame('175.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_wrong_amount_is_review_only_and_writes_an_audit_row(): void
    {
        [$player, $deposit] = $this->checkout();

        $this->notifyKBank($deposit, '124.99', $deposit->provider_reference, 'KBANK-WRONG-AMOUNT')
            ->assertOk()->assertJsonPath('result', 'needs_review')->assertJsonPath('credited', false);

        $fresh = $deposit->fresh();
        $this->assertSame(DepositStatus::Pending, $fresh->status);
        $this->assertTrue($fresh->metadata['needs_review']);
        $this->assertSame('amount_mismatch', $fresh->metadata['promptpay_review']['reason']);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::Deposit)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'amount_mismatch'));
    }

    public function test_unknown_reference_never_credits_and_is_audited(): void
    {
        [$player, $deposit] = $this->checkout();

        $this->notifyKBank($deposit, '125.00', 'DEP-UNKNOWN-REFERENCE', 'KBANK-UNKNOWN-REF')
            ->assertOk()->assertJsonPath('result', 'unmatched')->assertJsonPath('credited', false);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->get()->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'unknown_reference'));
    }

    public function test_two_candidates_for_one_bill_reference_are_review_only(): void
    {
        [$firstPlayer, $first] = $this->checkout('125.00', 'promptpay-ambiguous-first');
        [$secondPlayer, $second] = $this->checkout('125.00', 'promptpay-ambiguous-second');
        $second->provider_reference = $first->provider_reference;
        $second->save();

        $this->notifyKBank($first, '125.00', $first->provider_reference, 'KBANK-AMBIGUOUS-REF')
            ->assertOk()->assertJsonPath('result', 'needs_review')->assertJsonPath('credited', false);

        $this->assertSame('ambiguous_reference', $first->fresh()->metadata['promptpay_review']['reason']);
        $this->assertSame('ambiguous_reference', $second->fresh()->metadata['promptpay_review']['reason']);
        $this->assertSame('0.00', (string) $firstPlayer['wallet']->fresh()->balance);
        $this->assertSame('0.00', (string) $secondPlayer['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::Deposit)->get()
            ->filter(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'ambiguous_reference')->count());
    }

    public function test_a_new_bank_transaction_for_an_already_credited_deposit_is_review_only(): void
    {
        [$player, $deposit] = $this->checkout();
        $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-FIRST-CREDIT')
            ->assertOk()->assertJsonPath('result', 'credited');

        $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-SECOND-CREDIT')
            ->assertOk()->assertJsonPath('result', 'needs_review')->assertJsonPath('credited', false);

        $fresh = $deposit->fresh();
        $this->assertSame(DepositStatus::Confirmed, $fresh->status);
        $this->assertSame('confirmed_credit_conflict', $fresh->metadata['promptpay_review']['reason']);
        $this->assertSame('125.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::Deposit)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'confirmed_credit_conflict'));
    }

    public function test_same_bank_transaction_with_a_conflicting_amount_is_review_only_not_a_second_credit(): void
    {
        [$player, $deposit] = $this->checkout();
        $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-CONFLICTING-REPLAY')
            ->assertOk()->assertJsonPath('result', 'credited');

        $this->notifyKBank($deposit, '124.99', $deposit->provider_reference, 'KBANK-CONFLICTING-REPLAY')
            ->assertOk()->assertJsonPath('result', 'needs_review')->assertJsonPath('credited', false);

        $fresh = $deposit->fresh();
        $this->assertSame(DepositStatus::Confirmed, $fresh->status);
        $this->assertSame('confirmed_credit_conflict', $fresh->metadata['promptpay_review']['reason']);
        $this->assertSame('125.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::Deposit)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'confirmed_credit_conflict'));
    }

    public function test_expired_checkout_is_review_only(): void
    {
        [$player, $deposit] = $this->checkout();
        $metadata = $deposit->metadata;
        $metadata['promptpay_expires_at'] = now()->subSecond()->toIso8601String();
        $deposit->metadata = $metadata;
        $deposit->save();

        $this->notifyKBank($deposit, '125.00', $deposit->provider_reference, 'KBANK-EXPIRED')
            ->assertOk()->assertJsonPath('result', 'needs_review')->assertJsonPath('credited', false);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('expired_or_missing_expiry', $deposit->fresh()->metadata['promptpay_review']['reason']);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::Deposit)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'expired_or_missing_expiry'));
    }

    public function test_bad_signature_is_refused_before_matching_and_writes_an_audit_row(): void
    {
        [$player, $deposit] = $this->checkout();
        $payload = $this->kbankPayload($deposit, '125.00', $deposit->provider_reference, 'KBANK-BAD-SIGNATURE');

        $this->postJson('/api/v1/payments/bank-webhook/kbank', $payload, [
            'X-KBANK-Signature' => str_repeat('0', 64),
            'X-KBANK-Timestamp' => $payload['timestamp'],
        ])->assertBadRequest()->assertExactJson([
            'status' => 'ERROR',
            'message' => 'Notification rejected.',
        ]);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::SecurityAlert)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'invalid_signature'));
    }

    public function test_a_fresh_header_cannot_authenticate_a_stale_body_timestamp(): void
    {
        [$player, $deposit] = $this->checkout();
        $payload = $this->kbankPayload($deposit, '125.00', $deposit->provider_reference, 'KBANK-STALE-BODY');
        $payload['timestamp'] = (string) (time() - 301);

        $this->postJson('/api/v1/payments/bank-webhook/kbank', $payload, [
            'X-KBANK-Signature' => $this->signature($payload, self::KBANK_SECRET),
            'X-KBANK-Timestamp' => (string) time(),
        ])->assertBadRequest()->assertExactJson([
            'status' => 'ERROR',
            'message' => 'Notification rejected.',
        ]);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', AuditAction::SecurityAlert)->get()
            ->contains(fn (AuditLog $log): bool => data_get($log->metadata, 'reason') === 'invalid_or_expired_timestamp'));
    }

    public function test_header_only_timestamp_must_be_included_in_the_signature(): void
    {
        [$player, $deposit] = $this->checkout();
        $payload = $this->kbankPayload($deposit, '125.00', $deposit->provider_reference, 'KBANK-HEADER-TIMESTAMP');
        unset($payload['timestamp']);
        $timestamp = (string) time();

        $this->postJson('/api/v1/payments/bank-webhook/kbank', $payload, [
            'X-KBANK-Signature' => $this->signature($payload, self::KBANK_SECRET),
            'X-KBANK-Timestamp' => $timestamp,
        ])->assertBadRequest()->assertExactJson([
            'status' => 'ERROR',
            'message' => 'Notification rejected.',
        ]);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);

        $this->postJson('/api/v1/payments/bank-webhook/kbank', $payload, [
            'X-KBANK-Signature' => $this->signature($payload, self::KBANK_SECRET, $timestamp.'.'),
            'X-KBANK-Timestamp' => $timestamp,
        ])->assertOk()->assertJsonPath('result', 'credited')->assertJsonPath('credited', true);

        $this->assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);
        $this->assertSame('125.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_missing_and_unknown_bank_event_types_are_ignored(): void
    {
        [$player, $deposit] = $this->checkout();
        $unknown = $this->kbankPayload($deposit, '125.00', $deposit->provider_reference, 'KBANK-UNKNOWN-EVENT');
        $unknown['event_type'] = 'debit';

        $this->postJson('/api/v1/payments/bank-webhook/kbank', $unknown, [
            'X-KBANK-Signature' => $this->signature($unknown, self::KBANK_SECRET),
            'X-KBANK-Timestamp' => $unknown['timestamp'],
        ])->assertOk()->assertJsonPath('result', 'ignored')->assertJsonPath('credited', false);

        $missing = $this->kbankPayload($deposit, '125.00', $deposit->provider_reference, 'KBANK-MISSING-EVENT');
        unset($missing['event_type']);
        $this->postJson('/api/v1/payments/bank-webhook/kbank', $missing, [
            'X-KBANK-Signature' => $this->signature($missing, self::KBANK_SECRET),
            'X-KBANK-Timestamp' => $missing['timestamp'],
        ])->assertOk()->assertJsonPath('result', 'ignored')->assertJsonPath('credited', false);

        $this->assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    /** @return array{0: array{user: User, wallet: Wallet}, 1: Deposit} */
    private function checkout(string $amount = '125.00', string $key = 'promptpay-test-checkout'): array
    {
        $player = $this->createPlayer('0.00');
        $this->onboard($player['user']);
        Sanctum::actingAs($player['user']);
        $response = $this->postJson('/api/v1/wallet/deposit', [
            'amount' => $amount,
            'method' => PaymentMethod::PromptPay->value,
            'currency' => 'THB',
            'idempotency_key' => $key,
            'merchant_id' => '9999999999',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.deposit.status', 'pending')
            ->assertJsonPath('data.deposit.method', 'promptpay')
            ->assertJsonPath('data.checkout.bill_reference', $response->json('data.deposit.reference_number'));
        $qr = $response->json('data.checkout.qr_payload');
        $this->assertIsString($qr);
        $this->assertStringContainsString('5406'.$amount, $qr);
        $this->assertStringContainsString($response->json('data.checkout.bill_reference'), $qr);
        $this->assertStringNotContainsString('9999999999', $qr);
        $this->assertNotEmpty($response->json('data.checkout.expires_at'));

        return [$player, Deposit::query()->findOrFail($response->json('data.deposit.id'))];
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

    private function notifyKBank(Deposit $deposit, string $amount, ?string $reference, string $transactionId)
    {
        $payload = $this->kbankPayload($deposit, $amount, $reference, $transactionId);

        return $this->postJson('/api/v1/payments/bank-webhook/kbank', $payload, [
            'X-KBANK-Signature' => $this->signature($payload, self::KBANK_SECRET),
            'X-KBANK-Timestamp' => $payload['timestamp'],
        ]);
    }

    /** @return array<string, string> */
    private function kbankPayload(Deposit $deposit, string $amount, ?string $reference, string $transactionId): array
    {
        return [
            'event_type' => 'credit',
            'bank_txn_id' => $transactionId,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => 'THB',
            'timestamp' => (string) time(),
        ];
    }

    /** @param array<string, string> $payload */
    private function signature(array $payload, string $secret, string $prefix = ''): string
    {
        return hash_hmac('sha256', $prefix.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $secret);
    }
}
