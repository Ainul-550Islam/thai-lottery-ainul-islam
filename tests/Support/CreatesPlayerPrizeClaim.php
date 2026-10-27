<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Currency;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\Payout;
use App\Models\User;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Prize\PrizeClaimGuard;
use App\Services\Prize\PrizeClaimService;
use Database\Seeders\RolePermissionSeeder;

trait CreatesPlayerPrizeClaim
{
    protected function playerClaimFixture(string $amount = '400.00', Currency $currency = Currency::THB): array
    {
        config(['lottery.claims.review_threshold' => '100.00', 'lottery.claims.window_days' => 730,
            'finance.prize_payout.safety_mode' => 'LIVE']);
        $this->seed(RolePermissionSeeder::class);
        $owner = $this->createAdmittedPlayer('1000.00', $currency);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $reviewer = User::factory()->create();
        foreach ([$maker, $checker, $reviewer] as $staff) {
            $staff->assignRole('admin');
        }
        $draw = Draw::factory()->create(['completed_at' => now()]);
        $bet = Bet::factory()->won($amount)->create(['user_id' => $owner['user']->id, 'draw_id' => $draw->id, 'currency' => $currency]);
        $row = Payout::query()->create(['reference_number' => 'SYNTHETIC-PLAYER-'.bin2hex(random_bytes(8)),
            'draw_id' => $draw->id, 'bet_id' => $bet->id, 'user_id' => $owner['user']->id,
            'wallet_id' => $owner['wallet']->id, 'amount' => $amount, 'currency' => $currency,
            'metadata' => ['claim' => ['payout_method' => 'wallet', 'beneficiary' => null], 'unrelated_fact' => 'preserve me']]);

        return compact('owner', 'maker', 'checker', 'reviewer', 'draw', 'bet', 'row');
    }

    protected function submitPlayerClaim(array $f): array
    {
        return app(PrizeClaimService::class)->claim((int) $f['bet']->id, (int) $f['owner']['user']->id);
    }

    protected function approvePlayerClaim(array $f): void
    {
        app(PrizeClaimService::class)->approve((int) $f['row']->id, (int) $f['reviewer']->id, 'Synthetic independent original winning-facts review.', true);
    }

    protected function intakePlayerClaim(array $f): void
    {
        $service = app(PayoutApprovalIntakeService::class);
        $service->request($f['maker'], (int) $f['row']->id, 'wallet', null, 'Synthetic original own-wallet destination request.');
        $service->decide($f['checker'], (int) $f['row']->id, 'approved', true, 'Synthetic independent financial request review.');
        $batch = $service->batch($f['maker'], [(int) $f['row']->id]);
        app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $f['maker']);
    }

    /** Explicit fixture only: create a coherent original deadline in the requested timezone. */
    protected function playerClaimDeadline(array $f, string $edge): void
    {
        $row = $f['row']->fresh();
        $m = $row->metadata;
        $m['claim_window']['window_closes_at'] = $edge;
        $m['claim_window']['facts_hash'] = app(PrizeClaimGuard::class)->windowFingerprint($row, $edge);
        $m['claim']['window_closes_at'] = $edge;
        $m['claim']['window_facts_hash'] = $m['claim_window']['facts_hash'];
        $row->metadata = $m;
        $row->save();
    }
}
