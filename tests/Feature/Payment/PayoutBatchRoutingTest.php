<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Finance\PayoutBatchData;
use App\Exceptions\PayoutBatchException;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Finance\PayoutBatchService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;

final class PayoutBatchRoutingTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_external_route_enqueues_only_record_executor_once(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        $service = app(PayoutBatchRoutingService::class);
        $this->assertTrue($service->dispatchApproved($batch, $f['reviewer'])['enqueued']);
        $this->assertFalse($service->dispatchApproved($batch, $f['reviewer'])['enqueued']);
        $this->assertDatabaseCount('jobs', 1);
        $payload = DB::table('jobs')->sole()->payload;
        $this->assertStringContainsString('ExecutePayoutTransferJob', $payload);
        $this->assertStringNotContainsString('ProcessPayoutBatchJob', $payload);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame('processing', $batch->fresh()->status->value);
    }

    public function test_wallet_route_queues_only_wallet_executor(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['reviewer']);
        $this->assertStringContainsString('ProcessPayoutBatchJob', DB::table('jobs')->sole()->payload);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_outer_transaction_rollback_preserves_pending_batch_and_no_queue_message(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        DB::beginTransaction();
        try {
            app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['reviewer']);
            $this->assertDatabaseCount('jobs', 1);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('pending', $batch->fresh()->status->value);
        $this->assertNull($batch->fresh()->claimed_at);
    }

    public function test_http_batch_create_route_and_detail_work_with_server_owned_totals(): void
    {
        $f = $this->approvedIntake('bank_transfer');
        $this->actingAs($f['reviewer']);
        $r = $this->postJson(route('admin.payout-approvals.create-batch'), ['payout_ids' => [$f['row']->id]])->assertOk()->assertJsonPath('data.aggregate_amount', '400.00');
        $id = $r->json('data.id');
        $this->get(route('admin.payout-approvals.batch', $id))->assertOk()->assertSee('Batch #');
        $this->postJson(route('admin.payout-approvals.route-batch', $id))->assertOk()->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_mixed_wallet_and_external_methods_are_not_one_batch(): void
    {
        $a = $this->approvedIntake();
        $b = $this->approvedIntake('bank_transfer');
        $this->expectException(PayoutBatchException::class);
        app(PayoutApprovalIntakeService::class)->batch($a['reviewer'], [$a['row']->id, $b['row']->id]);
    }

    public function test_replayed_creation_checks_amount_instead_of_returning_a_fast_path(): void
    {
        $f = $this->approvedIntake();
        app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchService::class)->create(new PayoutBatchData([$f['row']->reference_number], '401.00', $f['row']->currency, null));
    }

    public function test_duplicate_members_and_reassignment_are_refused(): void
    {
        $a = $this->approvedIntake();
        $b = $this->approvedIntake();
        app(PayoutApprovalIntakeService::class)->batch($a['reviewer'], [$a['row']->id]);
        $this->expectException(PayoutBatchException::class);
        app(PayoutApprovalIntakeService::class)->batch($b['reviewer'], [$a['row']->id, $b['row']->id]);
    }

    public function test_disabled_safety_mode_cannot_enqueue_or_claim(): void
    {
        $f = $this->approvedIntake();
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        config(['finance.prize_payout.safety_mode' => 'DISABLED']);
        $this->expectException(PayoutBatchException::class);
        app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['reviewer']);
    }
}
