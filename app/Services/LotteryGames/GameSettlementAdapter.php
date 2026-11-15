<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\BetFundingSource;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Models\GameBetAttribution;
use App\Models\GameResultImport;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\RealPrizeSettlementService;
use Illuminate\Support\Facades\Log;

/**
 * SETTLEMENT GOES THROUGH THE EXISTING PIPELINE. THIS CLASS ONLY POINTS AT IT.
 *
 * WHY THERE IS NO SECOND SETTLEMENT ENGINE HERE
 * Batch 1 already owns settlement: ChunkedSettlementOrchestrator decides between an
 * inline run and a chunked run (and refuses to queue one without a durable queue),
 * and RealPrizeSettlementService moves the money for a draw's bets through the
 * wallet/ledger path. A digit game's bets ARE bets in that pipeline — they were bought
 * by BetPurchaseService against a real draw and they carry a real market key — so the
 * only thing this batch could add by reimplementing settlement is a second way to pay a
 * player, which Rule §0.2 forbids and Rule §0.7 makes dangerous.
 *
 * WHAT THIS CLASS ACTUALLY ADDS
 *   1. The call, with the game/draw-number addressing an operator or a job uses.
 *   2. A refusal when the draw is not in a settleable state, expressed in THIS lane's
 *      error vocabulary instead of the orchestrator's internals leaking out.
 *   3. RECONCILIATION (verifyWinners()): an independent recount of who should have won,
 *      computed from this lane's own attribution rows plus the digits it actually
 *      published, using the same matching engine and the same match modes the game is
 *      configured with. That is how an operator answers "did settlement pay the right
 *      people?" without trusting settlement's own arithmetic.
 *
 * WHY reconcile() DOES NOT WRITE ANYTHING
 * A reconciliation that could correct a payout would be a second settlement path. This
 * one reports: it counts winners, lists them, and names the winners settlement did not
 * pay and the losers it did. Any correction is a Batch-1 reversal, performed by an
 * operator through the existing tools, with its own audit.
 *
 * IMPORTANT: NO OUTER TRANSACTION
 * ChunkedSettlementOrchestrator::settle() throws when called inside a transaction,
 * because a queued run must own its own. So settle() here never wraps the call.
 */
final class GameSettlementAdapter
{
    public function __construct(
        private readonly GameRegistry $registry,
        private readonly ChunkedSettlementOrchestrator $orchestrator,
        private readonly RealPrizeSettlementService $realPrizes,
        private readonly DigitPrizeMatchingEngine $engine,
    ) {}

    /**
     * Settle one draw of one game.
     *
     * `settled` answers "did this call move the money", not "is the draw settled": a
     * replayed settlement reports settled=false with result.already_settled=true.
     *
     * @return array{game_code: string, draw_no: string, draw_id: int, considered: int, winners: int, settled: bool, mode: string, run_id: int|null, reconciliation: array<string, mixed>}
     *
     * @throws LotteryGameException game_unknown|game_settlement_refused
     */
    public function settle(string $gameCode, string $drawNo): array
    {
        $game = $this->registry->definition($gameCode);
        $draw = $this->drawFor($game, $drawNo);

        $reconciliation = $this->verify($game, $draw);

        try {
            $outcome = $this->orchestrator->settle((int) $draw->getKey());
        } catch (\RuntimeException $e) {
            // The orchestrator refuses for reasons this lane must report in its own
            // vocabulary: a queue that is not durable, an aborted run that needs
            // explicit recovery, or a call made inside a transaction.
            throw LotteryGameException::settlementRefused($e->getMessage(), [
                'game_code' => $game->code,
                'draw_no' => $drawNo,
                'draw_id' => (int) $draw->getKey(),
            ]);
        }

        $mode = (string) ($outcome['mode'] ?? 'unknown');
        $settled = false;

        if ($mode === 'inline') {
            // Below the chunk threshold the orchestrator reports rather than pays:
            // the money is moved once, here, by the existing settlement service.
            //
            // THE REFUSAL IS CONVERTED HERE TOO. The orchestrator above refuses in its
            // own words, but the inline path is where Batch 1's lifecycle guard actually
            // runs (DrawLifecycleException, a RuntimeException), so an attempt to settle a
            // draw that is still open, or one whose result was never published, would
            // otherwise escape this lane as a raw Batch-1 exception. Callers of this
            // adapter only handle LotteryGameException, so the conversion is what keeps
            // "settlement was refused" from looking like a 500.
            try {
                $result = $this->realPrizes->settle((int) $draw->getKey());
            } catch (\RuntimeException $e) {
                throw LotteryGameException::settlementRefused($e->getMessage(), [
                    'game_code' => $game->code,
                    'draw_no' => $drawNo,
                    'draw_id' => (int) $draw->getKey(),
                    'stage' => 'settlement.inline',
                ]);
            }

            // $settled reports whether THIS CALL moved money. A replayed settlement
            // (the draw was already settled) is a success with already_settled true and
            // settled false - which is exactly the distinction an operator needs when a
            // job is delivered twice, and exactly what the idempotency suite asserts.
            $settled = $result->performedWork();

            $outcome['result'] = [
                'settled' => $settled,
                'already_settled' => $result->alreadySettled,
                'bets_updated' => $result->betsUpdated,
                'selections_evaluated' => $result->selectionsEvaluated,
                'payouts_created' => $result->payoutsCreated,
                'total_prize' => $result->totalPrize,
                'state_after' => $result->stateAfter->value,
            ];
        }

        Log::info('lottery_games.draw_settled', [
            'game_code' => $game->code,
            'draw_id' => (int) $draw->getKey(),
            'mode' => $mode,
            'settled' => $settled,
            'participants' => $outcome['participants'] ?? null,
            'expected_winners' => $reconciliation['winners'],
        ]);

        return [
            'game_code' => $game->code,
            'draw_no' => $drawNo,
            'draw_id' => (int) $draw->getKey(),
            'considered' => (int) ($reconciliation['considered'] ?? 0),
            'winners' => (int) ($reconciliation['winners'] ?? 0),
            'settled' => $settled,
            'mode' => $mode,
            'run_id' => isset($outcome['run_id']) ? (int) $outcome['run_id'] : null,
            'participants' => isset($outcome['participants']) ? (int) $outcome['participants'] : null,
            'chunks' => isset($outcome['chunks']) ? (int) $outcome['chunks'] : null,
            // The orchestrator's own outcome, including (for an inline settlement) what
            // Batch 1 actually did: bets updated, payouts created, prize total. It is
            // passed through rather than re-derived, so a caller reading this receipt is
            // reading settlement's numbers, not this adapter's opinion of them.
            'result' => is_array($outcome['result'] ?? null) ? $outcome['result'] : null,
            'reconciliation' => $reconciliation,
        ];
    }

    /**
     * Settle a draw by id, for the queue jobs and the console command.
     *
     * @return array<string, mixed>
     */
    public function settleDraw(int $drawId): array
    {
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            throw LotteryGameException::settlementRefused('Unknown draw.', ['draw_id' => $drawId]);
        }

        return $this->settle((string) $draw->game_type, (string) $draw->draw_code);
    }

    /**
     * Recount the winners of a draw from this lane's own records. Read-only.
     *
     * @return array{draw_id: int, game_code: string, digits: string|null, considered: int, winners: int, lines: list<array<string, mixed>>, reason: string|null}
     *
     * @throws LotteryGameException game_unknown
     */
    public function verifyWinners(string $gameCode, string $drawNo): array
    {
        $game = $this->registry->definition($gameCode);

        return $this->verify($game, $this->drawFor($game, $drawNo));
    }

    /**
     * The published digits of a draw, as this lane recorded them.
     */
    public function publishedDigits(string $gameCode, string $drawNo): ?string
    {
        $import = GameResultImport::query()
            ->where('game_code', $gameCode)
            ->where('draw_no', $drawNo)
            ->where('status', GameResultImport::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->first();

        if (! $import instanceof GameResultImport) {
            return null;
        }

        $raw = (array) ($import->raw ?? []);
        $digits = isset($raw['digits']) ? trim((string) $raw['digits']) : '';

        return $digits !== '' && ctype_digit($digits) ? $digits : null;
    }

    /**
     * The reconciliation itself.
     *
     * @return array{draw_id: int, game_code: string, digits: string|null, considered: int, winners: int, lines: list<array<string, mixed>>, reason: string|null}
     */
    private function verify(GameDefinition $game, Draw $draw): array
    {
        $digits = $this->publishedDigits($game->code, (string) $draw->draw_code);

        /** @var list<GameBetAttribution> $attributions */
        $attributions = GameBetAttribution::query()
            ->where('draw_id', (int) $draw->getKey())
            ->where('game_code', $game->code)
            ->orderBy('id')
            ->get()
            ->all();

        $lines = [];
        $winners = 0;

        foreach ($attributions as $attribution) {
            $mode = $game->matchModeFor((string) $attribution->bet_type);

            if ($mode === null || $digits === null) {
                // Without published digits or a configured mode there is no verdict to
                // give, and inventing one would make the reconciliation itself wrong.
                $lines[] = [
                    'bet_id' => (int) $attribution->bet_id,
                    'number' => (string) $attribution->number,
                    'bet_type' => (string) $attribution->bet_type,
                    'won' => null,
                    'reason' => $digits === null ? 'result_not_published' : 'match_mode_not_configured',
                ];

                continue;
            }

            $verdict = $this->engine->evaluate($mode, (string) $attribution->number, $digits);

            if ($verdict['won'] === true) {
                $winners++;
            }

            $lines[] = [
                'bet_id' => (int) $attribution->bet_id,
                'number' => (string) $attribution->number,
                'bet_type' => (string) $attribution->bet_type,
                'funding_source' => $attribution->funding_source instanceof BetFundingSource
                    ? $attribution->funding_source->value
                    : (string) $attribution->funding_source,
                'potential_payout' => $attribution->payout(),
                'won' => $verdict['won'],
                'reason' => $verdict['reason'],
            ];
        }

        return [
            'draw_id' => (int) $draw->getKey(),
            'game_code' => $game->code,
            'digits' => $digits,
            'considered' => count($attributions),
            'winners' => $winners,
            'lines' => $lines,
            'reason' => $digits === null ? 'result_not_published' : null,
        ];
    }

    /**
     * The draw a game draw number refers to.
     *
     * @throws LotteryGameException game_settlement_refused
     */
    private function drawFor(GameDefinition $game, string $drawNo): Draw
    {
        $draw = Draw::query()
            ->where('game_type', $game->code)
            ->where(function ($query) use ($drawNo): void {
                $query->where('draw_code', $drawNo)->orWhere('draw_number', $drawNo);
            })
            ->first();

        if (! $draw instanceof Draw) {
            throw LotteryGameException::settlementRefused(
                'No draw of this game carries that draw number.',
                ['game_code' => $game->code, 'draw_no' => $drawNo],
            );
        }

        return $draw;
    }
}
