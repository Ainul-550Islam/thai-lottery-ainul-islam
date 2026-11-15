<?php

declare(strict_types=1);

namespace App\Listeners\LotteryGames;

use App\Events\BetCancelled;
use App\Models\Bet;
use App\Models\GameBetAttribution;
use App\Models\GameNumberExposure;
use App\Services\LotteryGames\GameAuditRecorder;
use App\Services\LotteryGames\LiabilityExposureService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give reserved liability back when a game bet is cancelled.
 *
 * THE FIGURE COMES FROM THE BET'S OWN RECORD, NOT FROM CONFIG
 * The release subtracts exactly what the placement reserved, read from the
 * attribution row written at placement time. Recomputing it from the game's CURRENT
 * multiplier would release a different number after any configuration change, and the
 * exposure row would drift silently — the operator's risk screen would be wrong in the
 * direction that hides exposure.
 *
 * WHY NOT FROM bets.metadata
 * That row is hashed into the Batch-1 ownership proof. The attribution table exists
 * precisely so the lane never writes to it (see App\Models\GameBetAttribution); the
 * metadata branch below is a read-only fallback for rows written by an older build.
 *
 * A FAILURE HERE IS REPORTED, NEVER THROWN
 * The cancellation has already committed: the player's money is back and the bet is
 * marked cancelled. Throwing would not roll that back, would not help the player, and
 * would surface as a 500 on a request that succeeded. The failure is logged at warning
 * level with the identifiers an operator needs to correct it by hand.
 */
final class ReleaseExposureOnBetCancelled
{
    public function __construct(
        private readonly LiabilityExposureService $exposure,
        private readonly GameAuditRecorder $audit,
    ) {}

    public function handle(BetCancelled $event): void
    {
        $bet = $event->bet;
        $attribution = GameBetAttribution::query()->where('bet_id', (int) $bet->getKey())->first();

        // The column is authoritative (GameBetPlacementService stamps it), but a bet
        // written before a lane was upgraded may only carry the code in its metadata.
        $gameCode = (string) ($bet->game_code ?? $attribution?->game_code ?? $bet->metadata['game_code'] ?? '');

        if ($gameCode === '') {
            // A bet that belongs to no digit game has no reservation to release. This
            // is the normal path for every pre-batch-2 bet, so it is silent.
            return;
        }

        $drawId = (int) $bet->draw_id;
        $metadata = is_array($bet->metadata) ? $bet->metadata : [];

        $number = (string) ($attribution?->number ?? $metadata['game_number'] ?? $metadata['number'] ?? '');
        $betType = (string) ($attribution?->bet_type ?? $metadata['game_bet_type'] ?? '');
        $stake = (string) ($attribution?->stake ?? $bet->stake_amount);

        if ($number === '' || $betType === '') {
            // Without the number and the market the reservation cannot be identified,
            // and releasing "something" would drift the exposure.
            Log::warning('lottery_games.exposure_release_skipped', [
                'bet_id' => $bet->getKey(),
                'game_code' => $gameCode,
                'reason' => 'the bet does not carry the selection identity',
            ]);

            return;
        }

        try {
            $row = GameNumberExposure::query()
                ->where('draw_id', $drawId)
                ->where('number', $number)
                ->where('bet_type', $betType)
                ->first();

            if (! $row instanceof GameNumberExposure) {
                // Already released (or never reserved): nothing to do, and creating a
                // negative exposure to "prove" a release would be worse than silence.
                return;
            }

            $this->exposure->release($drawId, $gameCode, [[
                'number' => $number,
                'bet_type' => $betType,
                'stake' => $stake,
                'potential_payout' => $this->payoutUsedBy($bet, $attribution, $number, $betType, $drawId),
            ]]);

            $this->audit->record(
                GameAuditRecorder::BET_EXPOSURE_RELEASED,
                $gameCode,
                null,
                'bet',
                (int) $bet->getKey(),
                [
                    'draw_id' => $drawId,
                    'bet_type' => $betType,
                    'number' => $number,
                    'stake' => $stake,
                    'reason' => $event->reason,
                ],
            );

            Log::info('lottery_games.exposure_release_succeeded', [
                'bet_id' => $bet->getKey(),
                'game_code' => $gameCode,
                'draw_id' => $drawId,
                'bet_type' => $betType,
                'reason' => $event->reason,
            ]);
        } catch (Throwable $e) {
            Log::warning('lottery_games.exposure_release_failed', [
                'bet_id' => $bet->getKey(),
                'game_code' => $gameCode,
                'draw_id' => $drawId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The payout that was reserved for this bet, in priority order:
     * attribution row -> older metadata stamp -> the exposure row itself.
     */
    private function payoutUsedBy(
        Bet $bet,
        ?GameBetAttribution $attribution,
        string $number,
        string $betType,
        int $drawId,
    ): string {
        if ($attribution instanceof GameBetAttribution) {
            return $attribution->payout();
        }

        $metadata = is_array($bet->metadata) ? $bet->metadata : [];
        $payout = $metadata['game_potential_payout'] ?? null;

        if (is_string($payout) && $payout !== '') {
            return $payout;
        }

        $exposure = GameNumberExposure::query()
            ->where('draw_id', $drawId)
            ->where('number', $number)
            ->where('bet_type', $betType)
            ->first();

        return $exposure instanceof GameNumberExposure
            ? bcadd((string) $exposure->potential_payout_total, '0', 2)
            : '0.00';
    }
}
