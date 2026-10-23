<?php

declare(strict_types=1);

namespace App\Services\ResultIngestion;

use App\Models\DrawResult;
use App\Services\Settlement\DrawSettlementCoordinatorService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Class DrawResultConfirmationService
 *
 * Implements Stage 2 of the GLO 4-Eyes Write Boundary:
 * Enforces dual-operator verification before official publication and automatic settlement trigger.
 */
class DrawResultConfirmationService
{
    public function __construct(
        private readonly DrawSettlementCoordinatorService $settlementCoordinator
    ) {}

    /**
     * Confirm a draft result with strict 4-eyes separation of duties.
     */
    public function confirmResult(int|string $drawResultId, int|string $confirmingOperatorId): DrawResult
    {
        return DB::transaction(function () use ($drawResultId, $confirmingOperatorId) {
            /** @var DrawResult $drawResult */
            $drawResult = DrawResult::with('draw')->where('id', $drawResultId)->lockForUpdate()->firstOrFail();

            if ($drawResult->verification_status === 'CONFIRMED') {
                return $drawResult;
            }

            if ((int) $drawResult->ingested_by_operator_id === (int) $confirmingOperatorId) {
                throw new RuntimeException(
                    "4-Eyes Governance Violation: Ingesting operator [{$drawResult->ingested_by_operator_id}] cannot confirm the result."
                );
            }

            if (empty($drawResult->payload_fingerprint)) {
                throw new RuntimeException('Security Violation: Result payload has no cryptographic fingerprint.');
            }

            $metadata = $drawResult->metadata ?? [];
            $metadata['verification_status'] = 'CONFIRMED';
            $metadata['confirmed_by_operator_id'] = (int) $confirmingOperatorId;
            $metadata['confirmed_at'] = now()->toIso8601String();

            $drawResult->forceFill([
                'published_at' => now(),
                'metadata' => $metadata,
            ])->save();

            $this->settlementCoordinator->initiateSettlement(
                drawId: (int) $drawResult->draw_id,
                initiatingOperatorId: (int) $confirmingOperatorId
            );

            return $drawResult->fresh();
        });
    }
}
