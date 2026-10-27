<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Services\Prize\UnclaimedPrizeService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesExternalPayout;
use Tests\Support\CreatesPlayerPrizeClaim;

/** Actual independent MariaDB connections; both children must directly wait on the parent's payout PRIMARY lock. */
final class PlayerPrizeClaimServerRaceTest extends PaymentTestCase
{
    use CreatesExternalPayout;
    use CreatesPlayerPrizeClaim;

    public function test_two_independent_reviews_have_one_immutable_verdict(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $a = ['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'action' => 'claim_review'];
        $out = $this->payoutServerRace([$a, array_merge($a, ['actor_id' => $f['checker']->id])], 'payouts', $f['row']->id);
        sort($out);
        $this->assertSame(['approved', 'refused'], $out);
        $this->assertSame('approved', $f['row']->fresh()->metadata['claim']['status']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_two_expiry_workers_write_one_disposal_identity_and_one_row_audit(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        $a = ['payout_id' => $f['row']->id, 'actor_id' => $f['reviewer']->id, 'action' => 'claim_expire'];
        $out = $this->payoutServerRace([$a, $a], 'payouts', $f['row']->id);
        sort($out);
        $this->assertSame(['expired', 'held'], $out);
        $this->assertSame('expired', $f['row']->fresh()->metadata['claim']['status']);
        $this->assertSame(1, DB::table('audit_logs')->where('description', 'Legacy prize expire annotation recorded; no funds moved or payout cancelled.')->count());
        $this->assertSame(0, app(UnclaimedPrizeService::class)->expireLapsedClaims()['expired_count']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_expiry_and_late_financial_intake_cannot_bypass_deadline_in_either_lock_order(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        $a = ['payout_id' => $f['row']->id, 'actor_id' => $f['maker']->id, 'action' => 'claim_expire'];
        $out = $this->payoutServerRace([$a, array_merge($a, ['action' => 'claim_intake'])], 'payouts', $f['row']->id);
        sort($out);
        $this->assertSame(['expired', 'refused'], $out);
        $m = $f['row']->fresh()->metadata;
        $this->assertSame('expired', $m['claim']['status']);
        $this->assertArrayNotHasKey('approval', $m);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_financially_managed_wallet_completion_is_not_forfeited_by_competing_expiry_worker(): void
    {
        $this->requirePayoutServerRace();
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $this->intakePlayerClaim($f);
        $clock = now()->addDays(800)->toIso8601String();
        $a = ['payout_id' => $f['row']->id, 'actor_id' => $f['maker']->id, 'action' => 'claim_expire', 'now' => $clock];
        $out = $this->payoutServerRace([$a, array_merge($a, ['action' => 'claim_wallet'])], 'payouts', $f['row']->id);
        sort($out);
        $this->assertSame(['held', 'paid'], $out);
        $m = $f['row']->fresh()->metadata;
        $this->assertSame('paid', $m['claim']['status']);
        $this->assertArrayNotHasKey('unclaimed', $m);
        $this->assertSame('canonical_payment', $m['claim_window']['closed_via']);
        $this->assertSame('1400.00', $f['owner']['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }
}
