<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Exceptions\SettlementPreviewException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\SettlementPreview;
use App\Models\SettlementPreviewBet;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Chunked, resumable, READ-ONLY settlement rehearsal.
 *
 * A preview walks a published draw in bounded chunks, prices every selection
 * with the same resolver the real run uses, and records what WOULD be owed —
 * in the preview tables only. It writes no wallet, no ledger entry and no
 * obligation row, and it never transitions the draw. The public surface is
 * deliberately shaped so that no caller can supply money, winners, or an
 * execution mode: the inputs are a draw id and a chunk size, and the mode is
 * a constant, not a parameter.
 *
 * WHY CHUNKED
 * A whole-draw rehearsal in one transaction has the same lock and memory
 * shape as the whole-draw settlement it rehearses. Keyset chunks
 * (id greater than the cursor, ordered, bounded) keep the rehearsal cheap
 * enough to run on draw night, and the cursor makes it resumable.
 *
 * WHY THE RULES FINGERPRINT
 * The pricing rules are pinned at open. A chunk that finds the live rules
 * have drifted refuses (RULES_CHANGED) rather than mixing two rule sets into
 * one total an operator would reconcile against.
 *
 * EXACT ARITHMETIC ONLY: totals accumulate as decimal strings through bcmath.
 */
final class ChunkedSettlementPreviewService
{
    /**
     * The pricing-rule config keys pinned into every preview. Keys are named
     * at the narrowest scope that prices a selection: the whole pricing tree
     * and any credential or automation flag are deliberately excluded.
     *
     * @var list<string>
     */
    public const RULE_CONFIG_KEYS = [
        'lottery.markets',
        'lottery.payouts.multiplier_source',
        'finance.precision',
        'lottery.settlement.chunk_size',
    ];

    public function __construct(
        private readonly Repository $config,
        private readonly DrawSettlementSimulationService $simulation,
        private readonly SelectionSettlementResolver $selections,
    ) {}

    /**
     * Open a preview for a published draw.
     */
    public function start(int $drawId, int $chunkSize): SettlementPreview
    {
        if ($chunkSize < 1 || $chunkSize > 5000) {
            throw new SettlementPreviewException(
                SettlementPreviewException::INVALID_CHUNK_SIZE,
                'Preview chunk size must be between 1 and 5000.',
                ['chunk_size' => $chunkSize],
            );
        }

        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            throw new SettlementPreviewException(
                SettlementPreviewException::PREVIEW_NOT_FOUND,
                sprintf('Draw %d does not exist.', $drawId),
                ['draw_id' => $drawId],
            );
        }

        // Validates the published result before a single bet is priced; a
        // tampered or missing result refuses here, not at the ninetieth chunk.
        $this->simulation->publishedResultFor($draw);

        $preview = new SettlementPreview;
        $preview->forceFill([
            'draw_id' => $drawId,
            'mode' => SettlementPreview::MODE,
            'status' => SettlementPreview::STATUS_OPEN,
            'chunk_size' => $chunkSize,
            'cursor_bet_id' => 0,
            'bets_total' => $this->countBets($drawId),
            'bets_processed' => 0,
            'chunks_processed' => 0,
            'selections_evaluated' => 0,
            'winning_selections' => 0,
            'total_stake' => '0.00',
            'total_prize' => '0.00',
            'currency' => null,
            'rules_fingerprint' => $this->rulesFingerprint(),
            'started_at' => now(),
            'completed_at' => null,
        ]);
        $preview->save();

        return $preview;
    }

    /**
     * Price the next chunk of bets. The expected cursor is the caller's proof
     * it is continuing THIS rehearsal and not racing another driver.
     */
    public function processChunk(int $previewId, int $expectedCursor): SettlementPreview
    {
        $preview = $this->findPreview($previewId);

        if ((int) $preview->cursor_bet_id !== $expectedCursor) {
            throw new SettlementPreviewException(
                SettlementPreviewException::CURSOR_MISMATCH,
                sprintf(
                    'Preview %d is at cursor %d, not %d; another driver has advanced it.',
                    $previewId,
                    (int) $preview->cursor_bet_id,
                    $expectedCursor,
                ),
                ['preview_id' => $previewId, 'expected_cursor' => $expectedCursor],
            );
        }

        if (! hash_equals((string) $preview->rules_fingerprint, $this->rulesFingerprint())) {
            throw new SettlementPreviewException(
                SettlementPreviewException::RULES_CHANGED,
                'The pricing rules changed since this preview was opened; start a new preview.',
                ['preview_id' => $previewId],
            );
        }

        $draw = Draw::query()->find((int) $preview->draw_id);

        if (! $draw instanceof Draw) {
            throw new SettlementPreviewException(
                SettlementPreviewException::PREVIEW_NOT_FOUND,
                sprintf('Draw %d does not exist.', (int) $preview->draw_id),
                ['preview_id' => $previewId],
            );
        }

        $result = $this->simulation->publishedResultFor($draw);

        $bets = Bet::query()
            ->where('draw_id', (int) $preview->draw_id)
            ->whereNull('deleted_at')
            ->where('id', '>', $expectedCursor)
            ->orderBy('id')
            ->limit((int) $preview->chunk_size)
            ->get();

        if ($bets->isEmpty()) {
            return $preview;
        }

        DB::transaction(function () use ($preview, $bets, $result): void {
            $stakeDelta = '0.00';
            $prizeDelta = '0.00';
            $selectionsDelta = 0;
            $winnersDelta = 0;
            $lastBetId = (int) $preview->cursor_bet_id;

            foreach ($bets as $bet) {
                $betStake = '0.00';
                $betPrize = '0.00';
                $betSelections = 0;
                $betWinners = 0;

                $items = BetItem::query()
                    ->where('bet_id', (int) $bet->getKey())
                    ->orderBy('id')
                    ->get();

                foreach ($items as $item) {
                    $record = $this->selections->resolve($item, $bet, $result);

                    $betSelections++;
                    $betStake = bcadd($betStake, $record->stake, 2);
                    $betPrize = bcadd($betPrize, $record->simulatedPrize, 2);

                    if ($record->isWinner()) {
                        $betWinners++;
                    }
                }

                $row = SettlementPreviewBet::query()->firstOrNew([
                    'settlement_preview_id' => (int) $preview->getKey(),
                    'bet_id' => (int) $bet->getKey(),
                ]);
                $row->forceFill([
                    'user_id' => $bet->user_id !== null ? (int) $bet->user_id : null,
                    'selections_evaluated' => $betSelections,
                    'winning_selections' => $betWinners,
                    'is_winner' => $betWinners > 0,
                    'stake' => $betStake,
                    'simulated_prize' => $betPrize,
                    'currency' => $this->currencyOf($bet),
                ]);
                $row->save();

                $selectionsDelta += $betSelections;
                $winnersDelta += $betWinners;
                $stakeDelta = bcadd($stakeDelta, $betStake, 2);
                $prizeDelta = bcadd($prizeDelta, $betPrize, 2);
                $lastBetId = (int) $bet->getKey();

                if ($preview->currency === null) {
                    $currency = $this->currencyOf($bet);
                    if ($currency !== null) {
                        $preview->currency = $currency;
                    }
                }
            }

            $preview->cursor_bet_id = $lastBetId;
            $preview->bets_processed = (int) $preview->bets_processed + $bets->count();
            $preview->chunks_processed = (int) $preview->chunks_processed + 1;
            $preview->selections_evaluated = (int) $preview->selections_evaluated + $selectionsDelta;
            $preview->winning_selections = (int) $preview->winning_selections + $winnersDelta;
            $preview->total_stake = bcadd((string) $preview->total_stake, $stakeDelta, 2);
            $preview->total_prize = bcadd((string) $preview->total_prize, $prizeDelta, 2);
            $preview->save();
        });

        return $preview->refresh();
    }

    /**
     * Close a preview once every bet has been priced.
     */
    public function finalize(int $previewId): SettlementPreview
    {
        $preview = $this->findPreview($previewId);

        if ($preview->isComplete()) {
            return $preview;
        }

        $remaining = Bet::query()
            ->where('draw_id', (int) $preview->draw_id)
            ->whereNull('deleted_at')
            ->where('id', '>', (int) $preview->cursor_bet_id)
            ->count();

        if ($remaining > 0) {
            throw new SettlementPreviewException(
                SettlementPreviewException::PREVIEW_INCOMPLETE,
                sprintf('Preview %d still has %d bet(s) past its cursor.', $previewId, $remaining),
                ['preview_id' => $previewId, 'remaining_bets' => $remaining],
            );
        }

        $preview->status = SettlementPreview::STATUS_COMPLETED;
        $preview->completed_at = now();
        $preview->save();

        return $preview;
    }

    /**
     * The rehearsed totals, exactly as recorded.
     *
     * @return array<string, string|int|null>
     */
    public function summary(int $previewId): array
    {
        $preview = $this->findPreview($previewId);

        return [
            'preview_id' => (int) $preview->getKey(),
            'draw_id' => (int) $preview->draw_id,
            'mode' => (string) $preview->mode,
            'status' => (string) $preview->status,
            'bets_total' => (int) $preview->bets_total,
            'bets_processed' => (int) $preview->bets_processed,
            'chunks_processed' => (int) $preview->chunks_processed,
            'selections_evaluated' => (int) $preview->selections_evaluated,
            'winning_selections' => (int) $preview->winning_selections,
            'total_stake' => (string) $preview->total_stake,
            'total_prize' => (string) $preview->total_prize,
            'currency' => $preview->currency !== null ? (string) $preview->currency : null,
            'rules_fingerprint' => (string) $preview->rules_fingerprint,
        ];
    }

    /**
     * Hash of the pricing rules currently in force.
     */
    public function rulesFingerprint(): string
    {
        $rules = [];

        foreach (self::RULE_CONFIG_KEYS as $key) {
            $rules[$key] = $this->config->get($key);
        }

        return hash('sha256', (string) json_encode($rules, JSON_UNESCAPED_SLASHES));
    }

    private function findPreview(int $previewId): SettlementPreview
    {
        $preview = SettlementPreview::query()->find($previewId);

        if (! $preview instanceof SettlementPreview) {
            throw new SettlementPreviewException(
                SettlementPreviewException::PREVIEW_NOT_FOUND,
                sprintf('Settlement preview %d does not exist.', $previewId),
                ['preview_id' => $previewId],
            );
        }

        return $preview;
    }

    private function countBets(int $drawId): int
    {
        return Bet::query()
            ->where('draw_id', $drawId)
            ->whereNull('deleted_at')
            ->count();
    }

    private function currencyOf(Bet $bet): ?string
    {
        $currency = $bet->currency ?? null;

        if ($currency instanceof \BackedEnum) {
            return (string) $currency->value;
        }

        return is_string($currency) && $currency !== '' ? $currency : null;
    }
}
