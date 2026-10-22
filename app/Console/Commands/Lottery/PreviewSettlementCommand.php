<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Services\Draw\ChunkedSettlementPreviewService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run a read-only settlement rehearsal for one published draw.
 *
 * This command exists so an operator can see, before any money moves, exactly
 * what a settlement run would record: how many bets, how many winners, and
 * the exact prize total. It is intentionally NOT scheduled and has no
 * execution-shaped options: the rehearsal is the whole product. A preview
 * that could be flipped into the real thing by an option would not be a
 * rehearsal; it would be a second, quieter settlement path.
 */
final class PreviewSettlementCommand extends Command
{
    protected $signature = 'lottery:preview-settlement
        {--draw= : The draw to rehearse, by id}
        {--chunk=500 : Bets priced per chunk}';

    protected $description = 'Rehearse settlement of a published draw without moving money';

    public function handle(ChunkedSettlementPreviewService $previews): int
    {
        $draw = $this->option('draw');

        if (! is_string($draw) || ! ctype_digit($draw)) {
            $this->error('--draw= is required and must be a draw id.');

            return self::FAILURE;
        }

        $chunk = $this->option('chunk');
        $chunkSize = is_numeric($chunk) ? (int) $chunk : 500;

        try {
            $preview = $previews->start((int) $draw, $chunkSize);

            while (! $preview->isComplete()) {
                $before = (int) $preview->cursor_bet_id;
                $preview = $previews->processChunk((int) $preview->getKey(), $before);

                if ((int) $preview->cursor_bet_id === $before) {
                    break;
                }
            }

            $preview = $previews->finalize((int) $preview->getKey());
            $summary = $previews->summary((int) $preview->getKey());
        } catch (Throwable $e) {
            $this->error(sprintf('%s: %s', $e::class, $e->getMessage()));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Preview %d for draw %d (%s): %d/%d bets, %d winning selection(s), stake %s, simulated prize %s %s.',
            (int) $summary['preview_id'],
            (int) $summary['draw_id'],
            (string) $summary['mode'],
            (int) $summary['bets_processed'],
            (int) $summary['bets_total'],
            (int) $summary['winning_selections'],
            (string) $summary['total_stake'],
            (string) $summary['total_prize'],
            (string) ($summary['currency'] ?? ''),
        ));
        $this->warn('This was a rehearsal: no wallet was credited and the draw was not transitioned.');

        return self::SUCCESS;
    }
}
