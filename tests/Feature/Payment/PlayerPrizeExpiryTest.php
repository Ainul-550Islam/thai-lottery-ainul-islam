<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PayoutStatus;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\Prize\ClaimWindowService;
use App\Services\Prize\UnclaimedPrizeService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesPlayerPrizeClaim;

final class PlayerPrizeExpiryTest extends PaymentTestCase
{
    use CreatesPlayerPrizeClaim;

    public function test_expiry_and_disposal_are_locked_idempotent_annotations_never_financial_cancellation(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->playerClaimDeadline($f, now()->subSecond()->toIso8601String());
        $service = app(UnclaimedPrizeService::class);
        $out = $service->expireLapsedClaims(1);
        $this->assertSame(1, $out['expired_count']);
        $this->assertSame(['THB' => '400.00'], $out['totals_by_currency']);
        $this->assertTrue($out['annotation_only']);
        $row = $f['row']->fresh();
        $key = $row->metadata['unclaimed']['disposal_key'];
        $this->assertSame('expired', $this->submitPlayerClaim($f)['status']);
        $this->assertSame(PayoutStatus::Pending, $row->status);
        $this->assertSame(0, $service->expireLapsedClaims()['expired_count']);
        $this->assertSame(1, $service->sweepExpired()['swept_count']);
        $m = $row->fresh()->metadata;
        $this->assertSame($key, $m['unclaimed']['disposal_key']);
        $this->assertSame('swept', $m['unclaimed']['status']);
        $this->assertSame('wallet', $m['claim']['payout_method']);
        $this->assertSame('preserve me', $m['unrelated_fact']);
        $this->assertSame(0, $service->sweepExpired()['swept_count']);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('1000.00', $f['owner']['wallet']->fresh()->balance);
    }

    public function test_parsed_utc_instants_not_iso_lexical_order_choose_eligibility(): void
    {
        $past = $this->playerClaimFixture();
        $future = $this->playerClaimFixture();
        $this->submitPlayerClaim($past);
        $this->submitPlayerClaim($future);
        $this->playerClaimDeadline($past, now()->subMinute()->setTimezone('+14:00')->toIso8601String());
        $this->playerClaimDeadline($future, now()->addMinute()->setTimezone('-12:00')->toIso8601String());
        $out = app(UnclaimedPrizeService::class)->expireLapsedClaims();
        $this->assertSame(1, $out['expired_count']);
        $this->assertSame($past['row']->id, $out['payouts'][0]['payout_id']);
        $this->assertSame('under_review', $future['row']->fresh()->metadata['claim']['status']);
    }

    public static function protectedHistory(): array
    {
        return array_map(fn ($kind) => [$kind], ['approval', 'batch', 'transfer', 'completed', 'deleted_payment', 'sql_membership', 'common_reference']);
    }

    #[DataProvider('protectedHistory')]
    public function test_managed_terminal_or_historical_payment_obligations_are_never_expired_or_window_closed(string $kind): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        $row = $f['row']->fresh();
        $m = $row->metadata;
        if (in_array($kind, ['approval', 'batch', 'transfer'], true)) {
            $m[$kind] = null; // Even malformed historical markers must hold the obligation.
        } elseif ($kind === 'completed') {
            $row->status = PayoutStatus::Completed;
        } elseif ($kind === 'deleted_payment') {
            Payment::factory()->create(['user_id' => $row->user_id, 'payable_type' => Payout::class, 'payable_id' => $row->id])->delete();
        } elseif ($kind === 'sql_membership') {
            // Create through the real immutable SQL intake; then remove JSON hints to test the independent authority.
            $this->playerClaimDeadline($f, now()->addYear()->toIso8601String());
            $this->approvePlayerClaim($f);
            $this->intakePlayerClaim($f);
            $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
            $m = $row->fresh()->metadata;
            unset($m['approval'], $m['batch']);
        } else {
            DB::table('outbound_provider_references')->insert(['identity_key' => hash('sha256', 'SYNTHETIC common owner'),
                'payout_id' => $row->id, 'provider' => 'bank_transfer', 'account_scope' => 'SYNTHETIC TEST ONLY',
                'reference_hash' => hash('sha256', 'SYNTHETIC NOT PAYABLE'), 'created_at' => now()]);
        }
        $row->metadata = $m;
        $row->save();
        $before = $row->fresh()->getRawOriginal('metadata');
        $service = app(UnclaimedPrizeService::class);
        $this->assertSame(0, $service->expireLapsedClaims()['expired_count']);
        $this->assertSame(0, $service->sweepExpired()['swept_count']);
        $this->assertSame(0, app(ClaimWindowService::class)->closeExpired()['closed_count']);
        $this->assertSame($before, $row->fresh()->getRawOriginal('metadata'));
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('1000.00', $f['owner']['wallet']->fresh()->balance);
    }

    public function test_mixed_currency_totals_are_grouped_and_never_added_into_a_false_scalar(): void
    {
        foreach ([Currency::THB, Currency::USD] as $currency) {
            $f = $this->playerClaimFixture('400.00', $currency);
            $this->submitPlayerClaim($f);
            $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        }
        $service = app(UnclaimedPrizeService::class);
        $this->assertNull($service->pendingClaimsTotal());
        $this->assertSame('400.00', $service->pendingClaimsTotal('USD'));
        $this->assertSame(['THB' => '400.00', 'USD' => '400.00'], $service->pendingClaimsTotalsByCurrency());
        $out = $service->expireLapsedClaims();
        $this->assertSame(2, $out['expired_count']);
        $this->assertNull($out['total_expired_amount']);
        $this->assertSame(['THB' => '400.00', 'USD' => '400.00'], $out['totals_by_currency']);
        $this->assertNull($service->sweepExpired()['total_swept_amount']);
        $this->assertNull(app(ClaimWindowService::class)->closeExpired()['total_lapsed_amount']);
    }

    public function test_malformed_deadlines_unknown_snapshots_and_conflicting_disposal_are_held(): void
    {
        $bad = $this->playerClaimFixture();
        $legacy = $this->playerClaimFixture();
        $conflict = $this->playerClaimFixture();
        foreach ([$bad, $legacy, $conflict] as $f) {
            $this->submitPlayerClaim($f);
            $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        }
        $m = $bad['row']->fresh()->metadata;
        $m['claim']['window_closes_at'] = '2026-02-30T10:00:00Z';
        $bad['row']->metadata = $m;
        $bad['row']->save();
        $m = $legacy['row']->fresh()->metadata;
        unset($m['claim']['facts_hash']);
        $legacy['row']->metadata = $m;
        $legacy['row']->save();
        $m = $conflict['row']->fresh()->metadata;
        $m['unclaimed'] = ['status' => 'swept', 'disposal_key' => 'CONFLICTING HISTORICAL KEY'];
        $conflict['row']->metadata = $m;
        $conflict['row']->save();
        $this->assertSame(0, app(UnclaimedPrizeService::class)->expireLapsedClaims()['expired_count']);
        $this->assertSame('under_review', $bad['row']->fresh()->metadata['claim']['status']);
        $this->assertSame('CONFLICTING HISTORICAL KEY', $conflict['row']->fresh()->metadata['unclaimed']['disposal_key']);
        $this->assertDatabaseCount('financial_transactions', 0);
    }

    public function test_disposal_rechecks_original_key_and_does_not_repair_inconsistent_history(): void
    {
        $f = $this->playerClaimFixture();
        $this->submitPlayerClaim($f);
        $this->playerClaimDeadline($f, now()->subDay()->toIso8601String());
        $service = app(UnclaimedPrizeService::class);
        $service->expireLapsedClaims();
        $m = $f['row']->fresh()->metadata;
        $m['unclaimed']['disposal_key'] = 'SYNTHETIC CHANGED KEY';
        $f['row']->metadata = $m;
        $f['row']->save();
        $this->assertSame(0, $service->sweepExpired()['swept_count']);
        $this->assertSame('expired', $f['row']->fresh()->metadata['unclaimed']['status']);
    }
}
