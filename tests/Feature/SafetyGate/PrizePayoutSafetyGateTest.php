<?php

declare(strict_types=1);

namespace Tests\Feature\SafetyGate;

use App\DTOs\BetPurchaseData;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Enums\PayoutStatus;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\Payout;
use App\Models\Wallet;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultPublicationService;
use App\Services\Draw\RealPrizeSettlementService;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Payment\PaymentTestCase;

/**
 * P0-B: prize payout safety gate — DISABLED / DRY_RUN / LIVE.
 *
 * Default DISABLED never credits a wallet. DRY_RUN never credits. LIVE below
 * the configurable auto-approve threshold credits exactly once (replay safe).
 * LIVE at/above the threshold becomes Pending (PENDING_APPROVAL) and never
 * credits. The threshold itself is config, never a hardcoded constant.
 */
final class PrizePayoutSafetyGateTest extends PaymentTestCase
{
    private function configure(string $mode, string $autoBelow = '1000000.00'): void
    {
        config([
            'finance.prize_payout.safety_mode' => $mode,
            'finance.prize_payout.auto_approve_below' => $autoBelow,
            'finance.prize_payout.require_approval_at_or_above' => $autoBelow,
        ]);
        // safetyMode() is resolved per call — no cached config issues.
    }

    /**
     * Open 3D draw, place a winning 3d_direct '123' bet (prize 9000.00 at 10.00
     * stake), publish first_prize 456123 + bottom_two 45, close the draw.
     *
     * @return array{draw: Draw, wallet: Wallet, player: array<string, mixed>}
     */
    private function setupWinningDraw(string $idempotencySuffix): array
    {
        $player = $this->createPlayer('100.00', Currency::THB);
        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        $limit = new NumberLimit;
        $limit->draw_id = $draw->getKey();
        $limit->bet_type = BetType::ThreeD;
        $limit->number = '123';
        $limit->max_amount = '100000.00';
        $limit->current_amount = '0.00';
        $limit->status = LimitStatus::Active;
        $limit->save();

        app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: (int) $player['user']->getKey(),
            drawId: (int) $draw->getKey(),
            marketKey: '3d_direct',
            rawNumber: '123',
            rawStake: '10.00',
            idempotencyKey: 'safety-gate-'.$idempotencySuffix,
        ));

        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw = $lifecycle->markResultPending($draw);
        app(DrawResultPublicationService::class)->publish((int) $draw->getKey(), [
            'first_prize' => '456123',
            'bottom_two' => '45',
        ]);

        return ['draw' => $draw, 'wallet' => $player['wallet'], 'player' => $player];
    }

    private function settleOnce(Draw $draw): void
    {
        $lifecycle = app(DrawLifecycleService::class);
        $job = new ProcessPrizeSettlementJob((int) $draw->getKey());
        $job->handle(app(RealPrizeSettlementService::class), $lifecycle);
    }

    #[Test]
    public function default_safety_mode_is_disabled_and_never_credits(): void
    {
        $this->configure('DISABLED');
        $fixture = $this->setupWinningDraw('disabled');

        $this->assertSame('DISABLED', app(RealPrizeSettlementService::class)->safetyMode());

        $this->settleOnce($fixture['draw']);

        $payout = Payout::query()->where('draw_id', $fixture['draw']->getKey())->firstOrFail();
        $this->assertSame(PayoutStatus::Pending, $payout->status);
        // Start 100 − stake 10 = 90: no prize credit under DISABLED.
        $balanceAfterSettle = (string) $fixture['wallet']->refresh()->balance;
        $this->assertSame('90.00', $balanceAfterSettle);

        // Replay is still a no-op (one payout row, still no credit).
        $this->settleOnce($fixture['draw']);
        $this->assertSame(1, Payout::query()->where('draw_id', $fixture['draw']->getKey())->count());
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
    }

    #[Test]
    public function dry_run_mode_computes_but_never_credits(): void
    {
        $this->configure('DRY_RUN');
        $fixture = $this->setupWinningDraw('dryrun');

        $this->settleOnce($fixture['draw']);

        $payout = Payout::query()->where('draw_id', $fixture['draw']->getKey())->firstOrFail();
        $this->assertSame(PayoutStatus::Pending, $payout->status);
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame('DRY_RUN', app(RealPrizeSettlementService::class)->safetyMode());
    }

    #[Test]
    public function live_auto_small_payout_credits_exactly_once(): void
    {
        $this->configure('LIVE');
        $fixture = $this->setupWinningDraw('live-small');

        $this->settleOnce($fixture['draw']);

        $payout = Payout::query()->where('draw_id', $fixture['draw']->getKey())->firstOrFail();
        $this->assertSame(PayoutStatus::Completed, $payout->status);

        $balanceAfterFirst = (string) $fixture['wallet']->refresh()->balance;
        // 90 remaining stake balance + prize credit (9000.00 at 10 stake 3d direct).
        $this->assertNotSame('90.00', $balanceAfterFirst, 'LIVE must credit the prize');

        // Double settle / concurrent replay must not double-pay.
        $this->settleOnce($fixture['draw']);
        $this->assertSame($balanceAfterFirst, (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame(1, Payout::query()->where('draw_id', $fixture['draw']->getKey())->count());
    }

    #[Test]
    public function live_above_threshold_never_credits_without_approval(): void
    {
        // Threshold forced to 0.01 so the 9000.00 auto prize requires approval.
        $this->configure('LIVE', '0.01');
        $fixture = $this->setupWinningDraw('live-approve');

        $this->settleOnce($fixture['draw']);

        $payout = Payout::query()->where('draw_id', $fixture['draw']->getKey())->firstOrFail();
        $this->assertSame(PayoutStatus::Pending, $payout->status);
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);

        // Second run still never credits.
        $this->settleOnce($fixture['draw']);
        $this->assertSame('90.00', (string) $fixture['wallet']->refresh()->balance);
        $this->assertSame(1, Payout::query()->where('draw_id', $fixture['draw']->getKey())->count());
    }

    #[Test]
    public function requires_approval_is_config_threshold_driven_not_hardcoded(): void
    {
        $this->configure('LIVE', '1000000.00');
        $svc = app(RealPrizeSettlementService::class);

        $this->assertFalse($svc->requiresApproval('999999.99'));
        $this->assertTrue($svc->requiresApproval('1000000.00'));
        $this->assertTrue($svc->requiresApproval('1000000.01'));

        // requiresApproval reads auto_approve_below (exclusive auto bound).
        config(['finance.prize_payout.auto_approve_below' => '50000.00']);
        $this->assertFalse($svc->requiresApproval('49999.99'));
        $this->assertTrue($svc->requiresApproval('50000.00'));
    }
}
