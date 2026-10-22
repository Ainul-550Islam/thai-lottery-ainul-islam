<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Prize\PrizeClaimData;
use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Exceptions\PrizeClaimException;
use App\Jobs\DisburseApprovedPrizeJob;
use App\Models\Draw;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Prize\ClaimWindowService;
use App\Services\Prize\PrizeClaimService;
use App\Services\Prize\PrizeClaimValidationService;
use App\Services\Prize\PrizeDisbursementService;
use App\Services\Prize\UnclaimedPrizeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\Support\CreatesPlayerPrizeClaim;

final class PlayerPrizeClaimLifecycleTest extends PaymentTestCase
{
    use CreatesPlayerPrizeClaim;

    public function test_claim_preserves_original_routing_and_foreign_metadata_and_replays_read_only(): void
    {
        $f = $this->playerClaimFixture();
        $first = $this->submitPlayerClaim($f);
        $this->assertSame('under_review', $first['status']);
        $m = $f['row']->fresh()->metadata;
        $this->assertSame('wallet', $m['claim']['payout_method']);
        $this->assertArrayHasKey('beneficiary', $m['claim']);
        $this->assertSame('preserve me', $m['unrelated_fact']);
        $audits = DB::table('audit_logs')->count();
        $second = $this->submitPlayerClaim($f);
        $this->assertTrue($second['no_op']);
        $this->assertSame($m, $f['row']->fresh()->metadata);
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public static function wrongOriginalFacts(): array
    {
        return array_map(fn ($field) => [$field], ['owner', 'draw', 'currency', 'amount', 'direct_link', 'duplicate', 'deleted', 'terminal', 'financial_history']);
    }

    #[DataProvider('wrongOriginalFacts')]
    public function test_incompatible_or_ambiguous_original_obligations_never_become_claims(string $field): void
    {
        $f = $this->playerClaimFixture();
        $row = $f['row'];
        if ($field === 'owner') {
            $row->user_id = User::factory()->create()->id;
        } elseif ($field === 'draw') {
            $row->draw_id = Draw::factory()->create()->id;
        } elseif ($field === 'currency') {
            $row->currency = Currency::USD;
        } elseif ($field === 'amount') {
            $row->amount = '401.00';
        } elseif ($field === 'direct_link') {
            $other = $this->playerClaimFixture();
            $f['bet']->payout_id = $other['row']->id;
            $f['bet']->save();
        } elseif ($field === 'duplicate') {
            $copy = $row->replicate();
            $copy->reference_number .= '-DUP';
            $copy->save();
        } elseif ($field === 'deleted') {
            $row->delete();
        } elseif ($field === 'terminal') {
            $row->status = PayoutStatus::Completed;
        } else {
            $m = $row->metadata;
            $m['approval'] = ['status' => 'unknown'];
            $row->metadata = $m;
        }
        $row->save();
        $before = $row->fresh()->getRawOriginal('metadata');
        $this->expectException(PrizeClaimException::class);
        try {
            $this->submitPlayerClaim($f);
        } finally {
            $this->assertSame($before, $row->fresh()->getRawOriginal('metadata'));
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_unknown_historical_anchor_and_invalid_existing_window_do_not_get_extended(): void
    {
        $f = $this->playerClaimFixture();
        $f['draw']->completed_at = null;
        $f['draw']->save();
        $f['bet']->won_at = null;
        $f['bet']->save();
        try {
            $this->submitPlayerClaim($f);
            $this->fail('Unknown anchor must be held.');
        } catch (PrizeClaimException) {
            $this->assertArrayNotHasKey('claim_window', $f['row']->fresh()->metadata);
        }
        $m = $f['row']->fresh()->metadata;
        $m['claim_window'] = ['status' => 'nonsense', 'window_closes_at' => 'next year'];
        $f['row']->metadata = $m;
        $f['row']->save();
        $this->assertFalse(app(ClaimWindowService::class)->allowsClaim($f['row']));
        $this->expectException(PrizeClaimException::class);
        $this->submitPlayerClaim($f);
    }

    public function test_review_threshold_is_exact_and_bad_policy_fails_into_review_not_auto_approval(): void
    {
        $f = $this->playerClaimFixture('99.99');
        $this->assertSame('approved', $this->submitPlayerClaim($f)['status']);
        $other = $this->playerClaimFixture('99.99');
        config(['lottery.claims.review_threshold' => '1e100']);
        $this->assertSame('under_review', $this->submitPlayerClaim($other)['status']);
    }

    public function test_fresh_explicit_staff_review_is_required_and_self_review_is_refused(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $reviewer = $f['reviewer'];
        $reviewer->syncRoles([]);
        try {
            $this->approvePlayerClaim($f);
            $this->fail('Revoked staff must not review.');
        } catch (AuthorizationException) {
            $this->assertSame('under_review', $f['row']->fresh()->metadata['claim']['status']);
        }
        $reviewer->assignRole('admin');
        $owner = $f['owner']['user'];
        $owner->assignRole('admin');
        try {
            app(PrizeClaimService::class)->approve($f['row']->id, $owner->id, 'Synthetic self-review is not independent.', true);
            $this->fail('A beneficiary must not review their own claim.');
        } catch (PrizeClaimException) {
            $this->assertSame('under_review', $f['row']->fresh()->metadata['claim']['status']);
        }
        $this->approvePlayerClaim($f);
        $this->assertSame('approved', $f['row']->fresh()->metadata['claim']['status']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_review_needs_explicit_duty_even_with_admin_role_and_active_status(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo(PayoutTransferAccess::CLAIM_REVIEW);
        $this->expectException(AuthorizationException::class);
        $this->approvePlayerClaim($f);
    }

    public function test_rejected_claim_is_terminal_and_reason_is_hashed_not_retained(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $reason = 'PRIVATE synthetic rejection rationale must not be stored raw.';
        app(PrizeClaimService::class)->reject($f['row']->id, $f['reviewer']->id, $reason, true);
        $before = $f['row']->fresh()->metadata;
        $this->assertSame(hash('sha256', $reason), $before['claim']['rejection_reason_hash']);
        $this->assertStringNotContainsString($reason, json_encode($before));
        $this->assertSame('rejected', $this->submitPlayerClaim($f)['status']);
        $this->assertSame($before, $f['row']->fresh()->metadata);
    }

    public function test_financial_intake_cannot_bypass_under_review_and_legacy_job_cannot_fabricate_payment(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        try {
            app(PayoutApprovalIntakeService::class)->request($f['maker'], $f['row']->id, 'wallet', null, 'Synthetic attempt to bypass player review.');
            $this->fail('Under-review claim cannot enter financial intake.');
        } catch (PrizeClaimException) {
            $this->assertArrayNotHasKey('approval', $f['row']->fresh()->metadata);
        }
        $out = (new DisburseApprovedPrizeJob($f['row']->id))->handle(app(PrizeDisbursementService::class));
        $this->assertFalse($out['disbursed']);
        $this->assertNotNull($out['skipped']);
        $this->assertSame(PayoutStatus::Pending, $f['row']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('prize_disbursements', 0);
    }

    public function test_only_balanced_canonical_wallet_execution_marks_claim_paid_and_replay_is_read_only(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        $this->intakePlayerClaim($f);
        $this->travel(800)->days(); // An in-flight approved obligation does not forfeit by clock.
        $this->assertSame(0, app(UnclaimedPrizeService::class)->expireLapsedClaims()['expired_count']);
        $out = app(ApprovedWalletPayoutService::class)->execute($f['row']->id);
        $this->assertSame('paid', $out['result']);
        $row = $f['row']->fresh();
        $this->assertSame('paid', $row->metadata['claim']['status']);
        $this->assertSame((int) $row->financial_transaction_id, $row->metadata['claim']['financial_transaction_id']);
        $this->assertSame('canonical_payment', $row->metadata['claim_window']['closed_via']);
        $this->assertSame('1400.00', $f['owner']['wallet']->fresh()->balance);
        $before = $row->getRawOriginal('metadata');
        $auditCount = DB::table('audit_logs')->count();
        $this->assertSame('replayed', app(ApprovedWalletPayoutService::class)->execute($row->id)['result']);
        $this->assertTrue($this->submitPlayerClaim($f)['no_op']);
        $this->assertSame($before, $row->fresh()->getRawOriginal('metadata'));
        $this->assertSame($auditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_status_only_completion_and_inactive_claimant_are_not_payment_proof(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->approvePlayerClaim($f);
        try {
            app(ClaimWindowService::class)->closeAsPaid($f['row']);
            $this->fail('A pending payout cannot close as paid.');
        } catch (PrizeClaimException) {
            $this->assertSame('approved', $f['row']->fresh()->metadata['claim']['status']);
        }
        $f['owner']['user']->status = UserStatus::Suspended;
        $f['owner']['user']->save();
        $this->expectException(PrizeClaimException::class);
        $this->submitPlayerClaim($f);
    }

    public function test_read_only_preview_uses_original_identity_and_does_not_register_or_extend_a_window(): void
    {
        $f = $this->playerClaimFixture();
        $data = new PrizeClaimData($f['bet']->id, $f['owner']['user']->id,
            PrizePayoutMethod::Wallet, null, null, null);
        $service = app(PrizeClaimValidationService::class);
        $this->assertTrue($service->mayProceed($data));
        $this->assertArrayNotHasKey('claim_window', $f['row']->fresh()->metadata);
        $other = $this->playerClaimFixture();
        $f['bet']->payout_id = $other['row']->id;
        $f['bet']->save();
        $verdict = $service->validate($data);
        $this->assertFalse($verdict->eligible);
        $this->assertArrayNotHasKey('payout_id', $verdict->context);
        $this->assertSame('0.00', $verdict->amount);
    }

    public function test_stale_window_registration_preserves_fresh_server_metadata(): void
    {
        $f = $this->playerClaimFixture();
        $fresh = $f['row']->fresh();
        $m = $fresh->metadata;
        $m['arrived_after_original_model_read'] = 'preserve latest original facts';
        $fresh->metadata = $m;
        $fresh->save();
        app(ClaimWindowService::class)->register($f['row'], $f['bet']);
        $this->assertSame('preserve latest original facts', $f['row']->fresh()->metadata['arrived_after_original_model_read']);
        $this->assertSame('wallet', $f['row']->fresh()->metadata['claim']['payout_method']);
    }

    public function test_ticket_link_must_be_an_original_confirmed_owned_receipt(): void
    {
        $f = $this->playerClaimFixture();
        $ticket = Ticket::factory()->create(['user_id' => $f['reviewer']->id,
            'draw_id' => $f['draw']->id, 'currency' => Currency::THB]);
        $ticket->status = TicketStatus::Confirmed;
        $ticket->save();
        $f['bet']->ticket_id = $ticket->id;
        $f['bet']->save();
        $f['row']->ticket_id = $ticket->id;
        $f['row']->save();
        try {
            $this->submitPlayerClaim($f);
            $this->fail('A foreign receipt must not establish a player claim.');
        } catch (PrizeClaimException) {
            $this->assertArrayNotHasKey('status', $f['row']->fresh()->metadata['claim']);
        }
        $ticket->user_id = $f['owner']['user']->id;
        $ticket->save();
        $this->assertSame('under_review', $this->submitPlayerClaim($f)['status']);
    }
}
