<?php

declare(strict_types=1);

namespace App\Jobs\Settlement;

use App\Enums\DrawStatus;
use App\Enums\QueueName;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\LotteryEntry;
use App\Services\Broadcast\RealtimeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Class ChunkedSettlementJob
 *
 * Parallel worker processing 1,000 entries per batch with zero lock contention on the global draw table.
 */
class ChunkedSettlementJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int|string $drawId,
        public readonly int $chunkIndex,
        public readonly int|string $drawResultId
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
    }

    public function handle(RealtimeSyncService $syncService): void
    {
        /** @var DrawResult $result */
        $result = DrawResult::findOrFail($this->drawResultId);

        // Fetch all pending entries for this chunk
        $entries = LotteryEntry::where('draw_id', $this->drawId)
            ->where('chunk_index', $this->chunkIndex)
            ->where('status', 'PENDING')
            ->get();

        if ($entries->isEmpty()) {
            $this->checkIfDrawSettlementFinished($syncService);

            return;
        }

        foreach ($entries as $entry) {
            DB::transaction(function () use ($entry, $result) {
                $winEvaluation = $this->evaluateWin($entry, $result);

                if ($winEvaluation['is_winner']) {
                    $entry->status = 'WON';
                    $entry->actual_win_amount = $winEvaluation['win_amount'];
                    $entry->save();

                    // Dispatch idempotent Payout Job for each winning slip
                    if (config('queue.default') !== 'sync') {
                        ProcessWinningEntryPayoutJob::dispatch(
                            $entry->id,
                            $this->drawId,
                            $entry->user_id,
                            $winEvaluation['win_amount'],
                            $winEvaluation['tier']
                        )->onQueue(QueueName::FinancialCritical->value);
                    }
                } else {
                    $entry->status = 'LOST';
                    $entry->actual_win_amount = '0.0000';
                    $entry->save();
                }
            }, attempts: 3);
        }

        $this->checkIfDrawSettlementFinished($syncService);
    }

    /**
     * Check if entire draw settlement has completed across all chunks.
     */
    private function checkIfDrawSettlementFinished(RealtimeSyncService $syncService): void
    {
        $pendingCount = LotteryEntry::where('draw_id', $this->drawId)
            ->where('status', 'PENDING')
            ->count();

        if ($pendingCount === 0) {
            DB::transaction(function () use ($syncService) {
                /** @var Draw $draw */
                $draw = Draw::with('result')->where('id', $this->drawId)->lockForUpdate()->firstOrFail();

                if ($draw->status === DrawStatus::ResultPublished) {
                    $draw->status = 'SETTLED';
                    $draw->total_payout_amount = (string) LotteryEntry::where('draw_id', $this->drawId)
                        ->where('status', 'WON')
                        ->sum('actual_win_amount');
                    $draw->version = ((int) $draw->version) + 1;
                    $draw->save();

                    $winnersCount = LotteryEntry::where('draw_id', $this->drawId)
                        ->where('status', 'WON')
                        ->count();

                    $syncService->broadcastDrawSettlement(
                        drawId: (string) $draw->id,
                        drawCode: (string) $draw->draw_code,
                        gameType: (string) $draw->game_type,
                        firstPrize: $draw->result?->first_prize ?? '',
                        totalPayoutAmount: (string) $draw->total_payout_amount,
                        totalWinnersCount: $winnersCount
                    );

                    Log::info("Draw [{$draw->draw_code}] settlement fully finalized. Total Payout: {$draw->total_payout_amount}");
                }
            });
        }
    }

    /**
     * Evaluate winning status and tier based on official Thai Lottery rules.
     *
     * @return array{is_winner: bool, tier: string, win_amount: string}
     */
    private function evaluateWin(LotteryEntry $entry, DrawResult $result): array
    {
        $ticket = $entry->ticket_number;
        $stake = $entry->stake_amount;
        $firstPrize = $result->first_prize;
        $twoBack = $result->two_digit_back;
        $threeFront = is_array($result->three_digit_front) ? $result->three_digit_front : [];
        $threeBack = is_array($result->three_digit_back) ? $result->three_digit_back : [];

        switch ($entry->bet_type) {
            case 'DIRECT_6D':
                if ($ticket === $firstPrize) {
                    return [
                        'is_winner' => true,
                        'tier' => 'FIRST_PRIZE',
                        'win_amount' => bcmul($stake, '60000.00', 4),
                    ];
                }
                break;

            case 'FRONT_3D':
                if (in_array(substr($ticket, 0, 3), $threeFront, true)) {
                    return [
                        'is_winner' => true,
                        'tier' => 'THREE_FRONT',
                        'win_amount' => bcmul($stake, '450.00', 4),
                    ];
                }
                break;

            case 'BACK_3D':
                if (in_array(substr($ticket, -3), $threeBack, true)) {
                    return [
                        'is_winner' => true,
                        'tier' => 'THREE_BACK',
                        'win_amount' => bcmul($stake, '450.00', 4),
                    ];
                }
                break;

            case 'BACK_2D':
                if (substr($ticket, -2) === $twoBack) {
                    return [
                        'is_winner' => true,
                        'tier' => 'TWO_BACK',
                        'win_amount' => bcmul($stake, '90.00', 4),
                    ];
                }
                break;

            case 'RUNNING_NUMBER':
                $runningDigit = substr($ticket, 0, 1);
                if (str_contains($firstPrize, $runningDigit)) {
                    return [
                        'is_winner' => true,
                        'tier' => 'RUNNING_MATCH',
                        'win_amount' => bcmul($stake, '3.20', 4),
                    ];
                }
                break;
        }

        return [
            'is_winner' => false,
            'tier' => 'NONE',
            'win_amount' => '0.0000',
        ];
    }
}
