<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Payment\PayoutTransferGuard;
use App\Services\Payment\PayoutTransferService;
use App\Services\Prize\PrizeClaimService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\CreatesExternalPayout;
use Tests\Support\CreatesPlayerPrizeClaim;

final class PlayerPrizeClaimCompletionTest extends PaymentTestCase
{
    use CreatesExternalPayout;
    use CreatesPlayerPrizeClaim;

    public function test_external_canonical_result_pays_the_original_claim_without_crediting_wallet(): void
    {
        $f = $this->playerClaimFixture();
        $this->verifyUserKycForTest($f['owner']['user']);
        $m = $f['row']->metadata;
        $m['claim']['payout_method'] = 'bank_transfer';
        $f['row']->metadata = $m;
        $f['row']->save();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $intake = app(PayoutApprovalIntakeService::class);
        $intake->request($f['maker'], $f['row']->id, 'bank_transfer', ['bank_name' => 'SYNTHETIC BANK',
            'account_name' => 'SYNTHETIC PERSON', 'account_number' => 'NOT PAYABLE'], 'Synthetic original external destination request.');
        $intake->decide($f['checker'], $f['row']->id, 'approved', true, 'Synthetic independent original financial facts review.');
        $batch = $intake->batch($f['maker'], [$f['row']->id]);
        app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['maker']);
        $row = $f['row']->fresh();
        $id = $this->archiveSyntheticPayoutEvidence($row, $f['maker'], $f['reviewer']);
        $data = app(PayoutTransferGuard::class)->fromRow($row);
        $service = app(PayoutTransferService::class);
        $service->transfer($data);
        $service->complete($data, 'SYNTHETIC-PLAYER-ARCHIVE-ONLY', $f['reviewer'], true, 'Synthetic final-result archive, not authenticated provider evidence.', $id);
        $row->refresh();
        $this->assertSame('paid', $row->metadata['claim']['status']);
        $this->assertSame('canonical_payment', $row->metadata['claim_window']['closed_via']);
        $this->assertSame('bank_transfer', $row->metadata['claim']['payout_method']);
        $this->assertSame('NOT PAYABLE', $row->metadata['claim']['beneficiary']['account_number']);
        $this->assertSame('1000.00', $f['owner']['wallet']->fresh()->balance);
        $this->assertSame(1, DB::table('payout_transfer_evidence')->whereNotNull('consumed_at')->count());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertTrue($this->submitPlayerClaim($f)['no_op']);
    }

    public function test_failure_after_credit_inside_paid_claim_bridge_rolls_back_all_financial_and_claim_writes(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $this->intakePlayerClaim($f);
        $before = $f['row']->fresh()->getRawOriginal('metadata');
        $sawRealCanonicalPosting = false;
        Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log) use (&$sawRealCanonicalPosting): void {
            if ($log->description === 'Player claim marked paid from exact canonical financial evidence.') {
                $sawRealCanonicalPosting = DB::table('financial_transactions')->count() === 1 && DB::table('ledger_entries')->count() === 2;
                throw new \RuntimeException('Synthetic failure at post-ledger claim bridge.');
            }
        });
        try {
            app(ApprovedWalletPayoutService::class)->execute($f['row']->id);
            $this->fail('Bridge failure must abort the canonical money transaction.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic failure at post-ledger claim bridge.', $e->getMessage());
        }
        $this->assertTrue($sawRealCanonicalPosting);
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal('metadata'));
        $this->assertSame('1000.00', $f['owner']['wallet']->fresh()->balance);
        $this->assertNull($f['row']->fresh()->financial_transaction_id);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_fabricated_paid_metadata_is_not_a_readable_or_replayable_paid_claim(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $m = $f['row']->fresh()->metadata;
        $m['claim']['status'] = 'paid';
        $f['row']->metadata = $m;
        $f['row']->save();
        $this->assertNull(app(PrizeClaimService::class)->claimFor($f['row']->id));
        $this->expectException(PrizeClaimException::class);
        $this->submitPlayerClaim($f);
    }

    public function test_original_fact_drift_after_financial_approval_blocks_wallet_execution(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $this->intakePlayerClaim($f);
        $f['bet']->actual_payout = '399.00';
        $f['bet']->save();
        $this->expectException(PrizeClaimException::class);
        try {
            app(ApprovedWalletPayoutService::class)->execute($f['row']->id);
        } finally {
            $this->assertSame('approved', $f['row']->fresh()->metadata['claim']['status']);
            $this->assertSame('1000.00', $f['owner']['wallet']->fresh()->balance);
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }
}
