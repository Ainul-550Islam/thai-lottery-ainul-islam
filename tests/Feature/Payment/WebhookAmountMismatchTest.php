<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Services\Finance\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class WebhookAmountMismatchTest extends Batch01TestCase
{
    public function test_correctly_signed_amount_mismatch_is_refused_without_wallet_credit(): void
    {
        $this->migrateDatabase();
        $now = now();
        $userId = DB::table('users')->insertGetId([
            'name' => 'Deposit Player', 'email' => 'deposit-player@example.test', 'username' => 'deposit-player',
            'password' => Hash::make('test-password'), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $walletId = DB::table('wallets')->insertGetId([
            'user_id' => $userId, 'type' => 'primary', 'status' => 'active', 'currency' => 'THB',
            'balance' => '100.00', 'locked_balance' => '0.00', 'total_deposited' => '100.00',
            'total_withdrawn' => '0.00', 'total_wagered' => '0.00', 'total_won' => '0.00',
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('deposits')->insert([
            'reference_number' => 'DP-AMOUNT-MISMATCH-01', 'user_id' => $userId, 'wallet_id' => $walletId,
            'method' => PaymentMethod::Stripe->value, 'provider' => 'stripe', 'status' => 'pending',
            'currency' => 'THB', 'amount' => '250.00', 'fee' => '0.00', 'net_amount' => '250.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $secret = 'unit-test-webhook-signing-secret';
        config([
            'payment.webhook.verify_signature' => true,
            'payment.webhook.max_age_seconds' => 300,
            'payment.gateways.stripe.webhook_secret' => $secret,
        ]);
        $event = [
            'id' => 'evt_test_amount_mismatch',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_01', 'client_reference_id' => 'DP-AMOUNT-MISMATCH-01',
                'amount_total' => 30000, 'currency' => 'thb', 'payment_intent' => 'pi_test_01',
            ]],
        ];
        $raw = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);
        $request = Request::create('/api/v1/payment/webhook/stripe', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.$signature,
        ], $raw);

        $response = app(PaymentWebhookController::class)->handle('stripe', $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('100.00', Money::of((string) DB::table('wallets')->where('id', $walletId)->value('balance'), Currency::THB)->toString());
        self::assertSame('pending', DB::table('deposits')->where('reference_number', 'DP-AMOUNT-MISMATCH-01')->value('status'));
        self::assertSame(0, DB::table('financial_transactions')->count());
    }
}
