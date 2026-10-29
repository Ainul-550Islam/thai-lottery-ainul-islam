<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\PaymentWebhookData;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\PaymentWebhookException;
use App\Jobs\Payment\ProcessPaymentWebhookJob;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Payment\PaymentWebhookVerificationService;

/** Unsupported crypto has no provider authentication or settlement proof. */
final class CryptoIngressSafetyTest extends PaymentTestCase
{
    public function test_previously_queued_crypto_dto_cannot_credit_a_wallet(): void
    {
        $player = $this->createPlayer('0.00', Currency::USD);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Crypto);
        $payload = new WebhookPayload(
            'crypto', 'crypto-queue-test', WebhookEventType::DepositSuccess,
            'fabricated-crypto-transaction', $deposit->reference_number, '100.00', Currency::USD, true,
        );

        $result = (new ProcessPaymentWebhookJob($payload))->handle($this->webhookService());

        self::assertFalse($result->success, 'A queued DTO is not provider-authenticated evidence; wallet balance is '.(string) $player['wallet']->fresh()->balance.'.');
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        self::assertSame(0, Payment::query()->count());
    }

    public function test_generic_hmac_envelope_cannot_credit_unsupported_crypto(): void
    {
        $player = $this->createPlayer('0.00', Currency::USD);
        $deposit = $this->createPendingDeposit($player['wallet'], '100.00', PaymentMethod::Crypto);
        $payment = Payment::factory()->create([
            'user_id' => $player['user']->id, 'payable_type' => Deposit::class, 'payable_id' => $deposit->id,
            'method' => PaymentMethod::Crypto, 'gateway' => 'crypto', 'gateway_reference' => 'fabricated-crypto-reference',
            'currency' => Currency::USD, 'amount' => '100.00', 'fee' => '0.00', 'status' => PaymentStatus::Pending,
        ]);
        $payload = ['id' => 'crypto-envelope-test', 'provider' => 'crypto', 'status' => 'succeeded',
            'reference' => 'fabricated-crypto-reference', 'amount' => '100.00', 'currency' => 'USD'];
        $signature = app(PaymentWebhookVerificationService::class)->expectedSignatureFor('crypto', $payload);
        $envelope = PaymentWebhookData::fromInput('crypto', 'crypto-envelope-test', 'payment.success', $signature, now()->toIso8601String(), $payload);

        try {
            $this->webhookService()->processEnvelope($envelope);
            self::fail('A locally invented generic HMAC is not a crypto provider contract; wallet balance is '.(string) $player['wallet']->fresh()->balance.'.');
        } catch (PaymentWebhookException $exception) {
            self::assertSame(PaymentWebhookException::CODE_MALFORMED, $exception->errorCode());
        }
        self::assertSame(DepositStatus::Pending, $deposit->fresh()->status);
        self::assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }
}
