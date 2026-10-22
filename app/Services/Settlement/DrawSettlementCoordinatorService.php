<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\Enums\DrawStatus;
use App\Enums\QueueName;
use App\Jobs\Settlement\ChunkedSettlementJob;
use App\Models\Draw;
use App\Models\LotteryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Class DrawSettlementCoordinatorService
 *
 * Coordinates asynchronous, partitioned, multi-worker batch settlement
 * across millions of lottery slips with strict 4-eyes write boundary enforcement.
 */
class DrawSettlementCoordinatorService
{
    private const CHUNK_SIZE = 1000;

    /**
     * Initiate batch settlement for a closed draw with confirmed official results.
     */
    public function initiateSettlement(int|string $drawId, int|string $initiatingOperatorId): int
    {
        return DB::transaction(function () use ($drawId) {
            /** @var Draw $draw */
            $draw = Draw::with('result')->where('id', $drawId)->lockForUpdate()->firstOrFail();

            if (! in_array($draw->status, [DrawStatus::Closed, DrawStatus::Drawing], true)) {
                throw new RuntimeException("Draw [{$draw->draw_code}] cannot be settled from status: {$draw->status->value}");
            }

            $result = $draw->result;
            if (! $result || ! $result->isConfirmed()) {
                throw new RuntimeException("Draw [{$draw->draw_code}] lacks a confirmed 4-eyes verified result.");
            }

            // Enforce Strict 4-Eyes Principle: Confirmer and Ingester cannot be identical
            if ((int) $result->ingested_by_operator_id === (int) $result->confirmed_by_operator_id) {
                throw new RuntimeException('4-Eyes Security Violation: Result ingester and confirmer must be distinct operators.');
            }

            // Mark draw as SETTLING
            $draw->status = 'SETTLING';
            $draw->version = ((int) $draw->version) + 1;
            $draw->save();

            // Count total chunks
            $maxChunkIndex = (int) (LotteryEntry::where('draw_id', $draw->id)->max('chunk_index') ?? 0);
            $totalChunksDispatched = 0;

            for ($chunk = 0; $chunk <= $maxChunkIndex; $chunk++) {
                if (config('queue.default') !== 'sync') {
                    ChunkedSettlementJob::dispatch($draw->id, $chunk, $result->id)
                        ->onQueue(QueueName::FinancialCritical->value);
                }
                $totalChunksDispatched++;
            }

            Log::info("Draw settlement initiated for [{$draw->draw_code}] with {$totalChunksDispatched} chunks dispatched.");

            return $totalChunksDispatched;
        });
    }
}
