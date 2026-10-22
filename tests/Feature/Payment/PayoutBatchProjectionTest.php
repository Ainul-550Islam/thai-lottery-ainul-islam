<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\PayoutBatchException;
use App\Exceptions\PayoutTransferException;
use App\Models\Payment;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Payment\PayoutTransferGuard;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class PayoutBatchProjectionTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_external_projection_is_derived_after_real_completion_and_does_not_increment_on_replay(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $data = app(PayoutTransferGuard::class)->fromRow($f['row']->fresh());
        $service = app(PayoutTransferService::class);
        $service->transfer($data);
        $service->complete($data, 'SYNTHETIC-PROJECTION', $f['reviewer'], true, 'Independent synthetic final provider evidence.');
        $first = app(PayoutBatchRoutingService::class)->refresh($batch, $f['reviewer']);
        $this->assertSame('completed', $first->status->value);
        $this->assertSame(1, $first->paid_count);
        $this->assertSame('400.00', (string) $first->paid_amount);
        $again = app(PayoutBatchService::class)->recordOutcome($batch, $f['row']->reference_number, 'replayed', '400.00');
        $this->assertSame(1, $again->paid_count);
        $this->assertSame(1, $again->processed_count);
        $this->assertSame(0, $again->replayed_count);
    }

    public function test_caller_cannot_mark_a_pending_member_paid(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchService::class)->recordOutcome($batch, $f['row']->reference_number, 'paid', '400.00');
    }

    public function test_incomplete_batch_cannot_be_settled_by_a_claimed_count(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $batch->refresh()->update(['processed_count' => 100, 'paid_count' => 100, 'paid_amount' => '40000.00']);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchService::class)->settle($batch);
    }

    public function test_preexecution_cancellation_retains_membership_and_prevents_reroute(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->cancel($batch, 'Operator stops this original plan before execution.', $f['reviewer']->id);
        $this->assertDatabaseCount('payout_batch_members', 1);
        $this->assertSame('pending', $f['row']->fresh()->status->value);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['reviewer']);
    }

    public function test_populated_membership_prevents_destructive_schema_rollback(): void
    {
        $f = $this->approvedIntake();
        app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        $migration = require base_path('database/migrations/2026_10_15_000400_create_payout_batch_members_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_server_duplicate_wallet_execution_posts_once(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $command = ['action' => 'wallet', 'payout_id' => (int) $f['row']->id, 'actor_id' => (int) $f['reviewer']->id];
        $out = $this->payoutServerRace([$command, $command], 'wallets', (int) $f['owner']['wallet']->id);
        sort($out);
        $this->assertSame(['paid', 'replayed'], $out);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame('1400.00', (string) $f['owner']['wallet']->fresh()->balance);
    }

    public function test_server_duplicate_route_claim_enqueues_once(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        $command = ['action' => 'route', 'payout_id' => (int) $f['row']->id, 'batch_id' => (int) $batch->id, 'actor_id' => (int) $f['reviewer']->id];
        $out = $this->payoutServerRace([$command, $command], 'payouts', (int) $f['row']->id);
        sort($out);
        $this->assertSame(['queued', 'replayed'], $out);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('processing', $batch->fresh()->status->value);
    }

    public function test_balanced_but_wrong_ledger_amount_cannot_be_counted_paid(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        app(ApprovedWalletPayoutService::class)->execute($f['row']->id);
        DB::table('ledger_entries')->update(['amount' => '1.00']);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchService::class)->refresh($batch);
    }

    public function test_missing_external_payment_cannot_be_counted_paid(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $data = app(PayoutTransferGuard::class)->fromRow($f['row']->fresh());
        $service = app(PayoutTransferService::class);
        $service->transfer($data);
        $service->complete($data, 'SYNTHETIC-MISSING-PAYMENT', $f['reviewer'], true, 'Independent synthetic final provider evidence.');
        Payment::query()->firstOrFail()->delete();
        $this->expectException(PayoutTransferException::class);
        app(PayoutBatchService::class)->refresh($batch);
    }
}
