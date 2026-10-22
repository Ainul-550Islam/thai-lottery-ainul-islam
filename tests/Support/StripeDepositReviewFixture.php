<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositReviewAccess;
use App\Services\Payment\DepositSettlementAccess;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\StripeDepositFollowupInbox;
use App\Services\Payment\StripeDepositSettlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

trait StripeDepositReviewFixture
{
    protected const FOLLOWUP_SECRET = 'whsec_SYNTHETIC_FOLLOWUP_ENDPOINT_12345678';

    protected function reviewFixture(): array
    {
        $this->seed(RolePermissionSeeder::class);
        config(['payment.deposit_settlement.stripe.enabled' => true, 'payment.deposit_review.stripe.enabled' => true,
            'payment.deposit_review.stripe.webhook_secret' => self::FOLLOWUP_SECRET]);
        $f = $this->createPlayer('0.00');
        $f['actor'] = User::factory()->create();
        $f['actor']->assignRole('admin');
        $f['actor']->givePermissionTo([DepositSettlementAccess::RECONCILE, DepositReviewAccess::REVIEW]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_SYNTHETICREVIEW123',
            'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC'])]);
        $out = app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-review-original-key');
        $f += ['deposit' => $out['deposit'], 'payment' => $out['payment']];
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $common = ['currency' => 'thb', 'amount' => 10000, 'livemode' => false, 'created' => now()->timestamp];
        $proof = [
            'refunds' => ['object' => 'list', 'data' => [], 'has_more' => false],
            'session' => array_merge($common, ['object' => 'checkout.session', 'id' => $out['payment']->gateway_reference,
                'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_SYNTHETICREVIEW123',
                'amount_total' => 10000, 'client_reference_id' => $out['deposit']->reference_number,
                'metadata' => ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $out['deposit']->id,
                    'deposit_reference' => $out['deposit']->reference_number, 'user_id' => (string) $f['user']->id, 'wallet_id' => (string) $f['wallet']->id]]),
            'intent' => array_merge($common, ['object' => 'payment_intent', 'id' => 'pi_SYNTHETICREVIEW123',
                'status' => 'succeeded', 'amount_received' => 10000, 'amount_capturable' => 0, 'latest_charge' => 'ch_SYNTHETICREVIEW123']),
            'charge' => array_merge($common, ['object' => 'charge', 'id' => 'ch_SYNTHETICREVIEW123',
                'payment_intent' => 'pi_SYNTHETICREVIEW123', 'amount_captured' => 10000, 'amount_refunded' => 0,
                'paid' => true, 'captured' => true, 'refunded' => false, 'disputed' => false, 'status' => 'succeeded']),
        ];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => function ($request) use ($proof) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $key = str_contains($path, '/checkout/sessions/') ? 'session' : (str_contains($path, '/payment_intents/') ? 'intent' : (str_contains($path, '/charges/') ? 'charge' : 'refunds'));

            return Http::response($proof[$key]);
        }]);
        $this->assertTrue(app(StripeDepositSettlementService::class)->reconcile($out['deposit'], $f['actor'])['credited']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        config(['payment.deposit_settlement.stripe.enabled' => false]); // Review does not grant or require new credit activation.

        return $f;
    }

    protected function followupEvent(string $type = 'refund.created', string $id = 'evt_SYNTHETICREVIEW001'): array
    {
        $facts = ['id' => 're_SYNTHETICREVIEW123', 'object' => 'refund', 'charge' => 'ch_SYNTHETICREVIEW123',
            'payment_intent' => 'pi_SYNTHETICREVIEW123', 'amount' => 2500, 'currency' => 'thb', 'status' => 'pending',
            'private' => 'SYNTHETIC-PRIVATE-FOLLOWUP-NOT-FOR-UI'];
        if ($type === 'charge.refunded') {
            $facts = ['id' => 'ch_SYNTHETICREVIEW123', 'object' => 'charge', 'payment_intent' => 'pi_SYNTHETICREVIEW123',
                'amount' => 10000, 'amount_refunded' => 2500, 'currency' => 'thb', 'status' => 'succeeded', 'refunded' => false];
        } elseif (str_contains($type, 'dispute')) {
            $facts['object'] = 'dispute';
            $facts['id'] = 'dp_SYNTHETICREVIEW123';
            $facts['status'] = 'needs_response';
        }

        return ['id' => $id, 'object' => 'event', 'type' => $type, 'created' => now()->timestamp, 'livemode' => false,
            'data' => ['object' => $facts]];
    }

    protected function signedBody(array $event, ?int $time = null): array
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $t = $time ?? now()->timestamp;

        return [$body, 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, self::FOLLOWUP_SECRET)];
    }

    protected function receiveFollowup(?array $event = null): array
    {
        [$body, $signature] = $this->signedBody($event ?? $this->followupEvent());

        return app(StripeDepositFollowupInbox::class)->receive($body, $signature);
    }

    protected function moneyPaper(): array
    {
        $all = [];
        foreach (['wallets', 'deposits', 'payments', 'financial_transactions', 'ledger_entries', 'deposit_checkout_attempts',
            'stripe_deposit_settlement_receipts', 'stripe_deposit_capture_owners'] as $table) {
            $all[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $all;
    }

    protected function reviewRefuses(callable $operation, string $code = 'stripe_review_unproven'): void
    {
        try {
            $operation();
            $this->fail('Unsafe review/custody operation was accepted.');
        } catch (FinancialException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }
}
