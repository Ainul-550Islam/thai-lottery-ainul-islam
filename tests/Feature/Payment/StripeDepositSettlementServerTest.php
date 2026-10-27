<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\DepositSettlementAccess;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\StripeDepositSettlementService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RunsServerFinancialRaces;

final class StripeDepositSettlementServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    public function test_two_real_waiters_and_two_provider_response_arrivals_credit_one_canonical_transaction(): void
    {
        $this->requireServerRace();
        $this->seed(RolePermissionSeeder::class);
        $f = $this->createPlayer('0.00');
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $actor->givePermissionTo(DepositSettlementAccess::RECONCILE);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_SYNTHETICNATIVECAPTURE',
            'url' => 'https://checkout.stripe.com/c/pay/SYNTHETIC'])]);
        $out = app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-native-capture-key');
        $d = $out['deposit'];
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $common = ['currency' => 'thb', 'amount' => 10000, 'livemode' => false, 'created' => now()->timestamp];
        $proof = [
            'refunds' => ['object' => 'list', 'has_more' => false, 'data' => []],
            'session' => array_merge($common, ['object' => 'checkout.session', 'id' => $out['payment']->gateway_reference,
                'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_SYNTHETICNATIVE123',
                'client_reference_id' => $d->reference_number, 'amount_total' => 10000,
                'metadata' => ['checkout_attempt' => $a->uuid, 'deposit_id' => (string) $d->id, 'deposit_reference' => $d->reference_number,
                    'user_id' => (string) $d->user_id, 'wallet_id' => (string) $d->wallet_id]]),
            'intent' => array_merge($common, ['object' => 'payment_intent', 'id' => 'pi_SYNTHETICNATIVE123', 'status' => 'succeeded',
                'amount_received' => 10000, 'amount_capturable' => 0, 'latest_charge' => 'ch_SYNTHETICNATIVE123']),
            'charge' => array_merge($common, ['object' => 'charge', 'id' => 'ch_SYNTHETICNATIVE123', 'status' => 'succeeded',
                'payment_intent' => 'pi_SYNTHETICNATIVE123', 'amount_captured' => 10000, 'amount_refunded' => 0,
                'paid' => true, 'captured' => true, 'refunded' => false, 'disputed' => false, 'failure_code' => null, 'review' => null]),
        ];
        $command = ['action' => 'stripe_deposit_settlement', 'deposit_id' => $d->id, 'actor_id' => $actor->id, 'proof' => $proof];
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id, afterChildren: function ($directory): void {
            $files = glob($directory.'/*.capture-ready');
            $this->assertCount(2, $files);
            foreach ($files as $file) {
                $marker = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame(4, $marker['gets']);
                $this->assertFalse($marker['pdo_transaction']);
            }
        });
        $this->assertSame(['capture_reconciled', 'capture_reconciled'], array_column($results, 'outcome'), json_encode($results));
        $this->assertSame([4, 4], array_column($results, 'gets'));
        $this->assertSame(1, count(array_filter(array_column($results, 'credited'))));
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseCount(StripeDepositSettlementService::TABLE, 1);
        $this->assertDatabaseCount('stripe_deposit_capture_owners', 1);
        $this->assertSame((string) $d->net_amount, $f['wallet']->fresh()->balance);
        $this->assertSame('captured', Payment::query()->sole()->status->value);
        $this->assertNotNull(DB::table(StripeDepositSettlementService::TABLE)->sole()->applied_at);
        $this->assertSame(1, (int) DB::table(DepositCheckoutService::TABLE)->sole()->attempt_count);
        app(DepositCompletionService::class)->verifySettled($d->fresh());
    }
}
