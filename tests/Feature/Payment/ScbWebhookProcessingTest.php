<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\ScbOpenApiWebhookService;
use InvalidArgumentException;

final class ScbWebhookProcessingTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.scb.webhook_secret' => 'test-secret-key-123',
            'payment.gateways.promptpay.enabled' => true,
            'payment.gateways.promptpay.merchant_id' => '0812345678',
        ]);
    }

    public function test_processes_valid_scb_webhook_and_credits_the_canonical_deposit_once(): void
    {
        $player = $this->createPlayer('0.00');
        $checkout = app(PaymentInitiationService::class)->initiateDeposit(
            $player['wallet'],
            Money::of('500.00', Currency::THB),
            PaymentMethod::PromptPay,
            'scb-processing-test-checkout',
        );
        $deposit = $checkout['deposit'];
        $payload = [
            'event_type' => 'credit',
            'transactionId' => 'SCB-TX-99887711',
            'billerId' => '0105558099881',
            'amount' => '500.00',
            'currency' => 'THB',
            'billPaymentRef1' => $deposit->provider_reference,
            'billPaymentRef2' => 'THAI-LOTTO',
            'timestamp' => (string) time(),
        ];
        $signature = $this->signature($payload);

        $result = app(ScbOpenApiWebhookService::class)->processWebhook($payload, $signature, '203.0.113.10');

        $this->assertTrue($result['success']);
        $this->assertSame('CREDITED', $result['status']);
        $this->assertSame('SCB-TX-99887711', $result['trans_ref']);
        $this->assertSame(DepositStatus::Confirmed, $deposit->fresh()->status);
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());

        app(ScbOpenApiWebhookService::class)->processWebhook($payload, $signature, '203.0.113.10');
        $this->assertSame('500.00', (string) $player['wallet']->fresh()->balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
    }

    public function test_rejects_invalid_scb_signature(): void
    {
        $payload = [
            'transactionId' => 'SCB-TX-INVALID-SIG',
            'amount' => '1000.00',
            'currency' => 'THB',
            'billPaymentRef1' => 'DEP-UNKNOWN-REFERENCE',
            'timestamp' => (string) time(),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SCB signature.');

        app(ScbOpenApiWebhookService::class)->processWebhook($payload, 'invalid-signature-hash', '203.0.113.10');
    }

    public function test_scb_body_timestamp_cannot_be_replaced_by_a_fresh_header(): void
    {
        $payload = [
            'event_type' => 'credit',
            'transactionId' => 'SCB-STALE-BODY',
            'amount' => '100.00',
            'currency' => 'THB',
            'billPaymentRef1' => 'DEP-UNKNOWN-REFERENCE',
            'timestamp' => (string) (time() - 301),
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid or expired SCB webhook timestamp.');
        app(ScbOpenApiWebhookService::class)->processWebhook($payload, $this->signature($payload), '203.0.113.10', (string) time());
    }

    public function test_scb_header_only_timestamp_must_be_signed(): void
    {
        $payload = [
            'event_type' => 'debit',
            'transactionId' => 'SCB-HEADER-TIMESTAMP',
            'amount' => '100.00',
            'currency' => 'THB',
            'billPaymentRef1' => 'DEP-UNKNOWN-REFERENCE',
        ];
        $timestamp = (string) time();
        $service = app(ScbOpenApiWebhookService::class);

        try {
            $service->processWebhook($payload, $this->signature($payload), '203.0.113.10', $timestamp);
            $this->fail('An unsigned header timestamp was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid SCB signature.', $exception->getMessage());
        }

        $result = $service->processWebhook($payload, $this->signature($payload, $timestamp.'.'), '203.0.113.10', $timestamp);
        $this->assertSame('ignored', $result['result']);
        $this->assertFalse($result['credited']);
    }

    public function test_scb_missing_event_type_is_ignored_after_verification(): void
    {
        $payload = [
            'transactionId' => 'SCB-MISSING-EVENT',
            'amount' => '100.00',
            'currency' => 'THB',
            'billPaymentRef1' => 'DEP-UNKNOWN-REFERENCE',
            'timestamp' => (string) time(),
        ];

        $result = app(ScbOpenApiWebhookService::class)->processWebhook($payload, $this->signature($payload), '203.0.113.10');
        $this->assertSame('ignored', $result['result']);
        $this->assertFalse($result['credited']);
    }

    /** @param array<string, string> $payload */
    private function signature(array $payload, string $prefix = ''): string
    {
        return hash_hmac('sha256', $prefix.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'test-secret-key-123');
    }
}
