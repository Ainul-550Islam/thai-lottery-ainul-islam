<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Finance\PayoutBatchData;
use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Finance\PayoutApprovalGuard;
use App\Services\Finance\PayoutApprovalService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Finance\PayoutBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GeneratePayoutBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 90];

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'generate_payout_batch_'.now()->format('Y-m-d-H-i');
    }

    /**
     * @return array{selected: int, dispatched: int, chunk_size: int, payout_ids: list<int>, executed_at: string}
     */
    public function handle(): array
    {
        $chunkSize = $this->resolvedChunkSize();
        $candidates = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->whereNull('financial_transaction_id')
            ->whereRaw("json_extract(metadata, '$.approval.status') = ?", [PayoutApprovalStatus::Approved->value])
            ->whereRaw("json_extract(metadata, '$.batch.batch_key') IS NULL")
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payout_batch_members')->whereColumn('payout_batch_members.payout_id', 'payouts.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('prize_disbursements')->whereColumn('prize_disbursements.payout_id', 'payouts.id'))
            ->orderBy('id')
            ->limit(min($chunkSize * 10, 2000))
            ->get();

        $approval = app(PayoutApprovalService::class);
        $guard = app(PayoutApprovalGuard::class);
        $groups = [];
        $ids = [];

        foreach ($candidates as $payout) {
            if (! $approval->isApproved($payout)) {
                continue;
            }
            $method = $guard->method($payout)->value;
            $key = $payout->currency->value.':'.$method;
            $groups[$key][] = $payout;
            $ids[] = (int) $payout->getKey();
        }

        $dispatched = 0;
        $batchService = app(PayoutBatchService::class);
        $routingService = app(PayoutBatchRoutingService::class);

        foreach ($groups as $members) {
            foreach (array_chunk($members, $chunkSize) as $chunk) {
                $sum = '0.00';
                $refs = [];
                foreach ($chunk as $row) {
                    $sum = bcadd($sum, (string) $row->amount, 2);
                    $refs[] = (string) $row->reference_number;
                }

                try {
                    $batch = $batchService->create(new PayoutBatchData($refs, $sum, $chunk[0]->currency, null))['batch'];
                    if ($routingService->dispatchApproved($batch)['enqueued']) {
                        $dispatched++;
                    }
                } catch (Throwable) {
                    // Durable batch remains Pending for explicit operator routing if enqueue fails.
                }
            }
        }

        $selectedCount = count($ids);
        if ($selectedCount > 0) {
            $this->recordSummary($selectedCount, $dispatched, $chunkSize, $ids);
        }

        return [
            'selected' => $selectedCount,
            'dispatched' => $dispatched,
            'chunk_size' => $chunkSize,
            'payout_ids' => $ids,
            'executed_at' => now()->toIso8601String(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('GeneratePayoutBatchJob: run failed', [
            'exception_class' => $exception !== null ? $exception::class : null,
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  list<int>  $payoutIds
     */
    private function recordSummary(int $selected, int $dispatched, int $chunkSize, array $payoutIds): void
    {
        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Payout batch generation selected %d approved payout(s) across %d batch(es) (chunk size %d).',
                $selected,
                $dispatched,
                $chunkSize,
            ),
            'metadata' => [
                'lane' => 'payout_batch_generation',
                'selected' => $selected,
                'dispatched' => $dispatched,
                'chunk_size' => $chunkSize,
                'payout_ids_sample' => array_slice($payoutIds, 0, 50),
                'action' => 'payout_batches_generated',
            ],
        ]);
        $log->save();
    }

    private function resolvedChunkSize(): int
    {
        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            return min($this->chunkSize, 200);
        }

        $configured = (int) config('lottery.payouts.batch_size', 200);

        return $configured > 0 ? min($configured, 200) : 200;
    }
}
