<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use PHPUnit\Framework\Attributes\Test;

final class PaymentWebhookSignatureVerificationTest extends PaymentTestCase
{
    #[Test]
    public function stripe_webhook_signature_is_verified_using_timestamped_hmac(): void
    {
        $player = $this->createPlayer('500.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::Stripe);

        $timestamp = time();
        $payload = [
            'id' => 'evt_test_stripe_sig_123',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_12345',
                    'client_reference_id' => $deposit->reference_number,
                    'amount_total' => 100000,
                    'currency' => 'thb',
                ],
            ],
        ];

        $jsonPayload = json_encode($payload);
        $signedPayload = "{$timestamp}.{$jsonPayload}";
        $signature = hash_hmac('sha256', $signedPayload, self::STRIPE_WEBHOOK_SECRET);
        $header = "t={$timestamp},v1={$signature}";

        $response = $this->withHeaders([
            'Stripe-Signature' => $header,
        ])->postJson('/api/v1/payments/webhook/stripe', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.action_taken', 'credited');
    }

    #[Test]
    public function bkash_webhook_signature_is_verified_correctly(): void
    {
        $player = $this->createPlayer('0.00', Currency::BDT);
        $deposit = $this->createPendingDeposit($player['wallet'], '500.00', PaymentMethod::Bkash);

        $payload = [
            'paymentID' => 'BK_PAY_98765',
            'trxID' => 'TRX_BKASH_123',
            'merchantInvoiceNumber' => $deposit->reference_number,
            'amount' => '500.00',
            'currency' => 'BDT',
            'transactionStatus' => 'Completed',
        ];

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, self::BKASH_WEBHOOK_SECRET);

        $response = $this->withHeaders([
            'X-Bkash-Signature' => $signature,
        ])->postJson('/api/v1/payments/webhook/bkash', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.action_taken', 'credited');
    }

    #[Test]
    public function crypto_webhook_without_a_provider_contract_refuses_even_a_locally_computed_hmac(): void
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $deposit = $this->createPendingDeposit($player['wallet'], '250.00', PaymentMethod::Crypto);

        $payload = [
            'txid' => '0x9876543210abcdef',
            'invoice_id' => $deposit->reference_number,
            'amount' => '250.00',
            'status' => 'CONFIRMED',
            'confirmations' => 6,
        ];

        $jsonPayload = json_encode($payload);
        $signature = hash_hmac('sha256', $jsonPayload, self::CRYPTO_WEBHOOK_SECRET);

        $response = $this->withHeaders([
            'X-Crypto-Signature' => $signature,
        ])->postJson('/api/v1/payments/webhook/crypto', $payload);

        // A locally invented HMAC is not a contracted provider signature.
        // Explicitly authorized contract correction: unsupported must not credit.
        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $this->assertSame(\App\Enums\DepositStatus::Pending, $deposit->fresh()->status);
        $this->assertSame('100.00', $player['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }
}
