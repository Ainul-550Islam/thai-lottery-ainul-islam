<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Payment\DepositCheckoutAccess;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\PaymentInitiationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RunsServerFinancialRaces;

final class StripeCheckoutReconciliationServerTest extends PaymentTestCase
{
    use RunsServerFinancialRaces;

    public function test_two_current_staff_lookups_really_wait_and_publish_only_one_original_receipt(): void
    {
        $this->requireServerRace();
        $this->seed(RolePermissionSeeder::class);
        $f = $this->createPlayer('0.00');
        $actor = User::factory()->create();
        $actor->assignRole('admin');
        $actor->givePermissionTo(DepositCheckoutAccess::RECONCILE);
        Http::preventStrayRequests();
        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        try {
            app(PaymentInitiationService::class)->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'synthetic-native-lookup-request');
            $this->fail('Synthetic uncertain outcome did not occur.');
        } catch (FinancialException $e) {
            $this->assertSame('checkout_outcome_unknown', $e->errorCode());
        }
        $d = Deposit::query()->sole();
        $command = ['action' => 'checkout_lookup', 'deposit_id' => $d->id, 'actor_id' => $actor->id];
        $results = $this->serverRace([$command, $command], 'wallets', $f['wallet']->id);
        $this->assertSame(['lookup_recovered', 'lookup_recovered'], array_column($results, 'outcome'), json_encode($results));
        $this->assertDatabaseCount('deposit_checkout_lookup_receipts', 1);
        $a = DB::table(DepositCheckoutService::TABLE)->sole();
        $receipt = DB::table('deposit_checkout_lookup_receipts')->sole();
        $this->assertSame($a->lookup_receipt_uuid, $receipt->uuid);
        $this->assertSame('projected', $a->state);
        $this->assertSame(1, (int) $a->attempt_count);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $before = (array) $a;
        app(DepositCheckoutService::class)->recover($d);
        $this->assertSame($before, (array) DB::table(DepositCheckoutService::TABLE)->sole());
    }
}
