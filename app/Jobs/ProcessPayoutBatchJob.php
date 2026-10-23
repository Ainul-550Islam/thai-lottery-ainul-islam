<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PayoutBatchStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\PayoutBatch;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutBatchMembershipService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessPayoutBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 60];

    public int $timeout = 300;

    public int $uniqueFor = 300;

    /**
     * @param  list<int>|null  $payoutIds
     */
    public function __construct(
        public readonly ?int $batchTag = null,
        public readonly ?array $payoutIds = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'payout_batch_'.($this->batchTag ?? 'sweep_'.now()->format('Y-m-d-H-i'));
    }

    /**
     * @return array{processed: int, paid: int, failed: int, held: int, replayed: int, total_paid: string}
     */
    public function handle(WalletService $walletService): array
    {
        $batch = $this->batchTag !== null ? PayoutBatch::query()->find($this->batchTag) : null;
        if ($this->batchTag !== null && (! $batch || ($batch->metadata['execution_lane'] ?? null) !== 'wallet')) {
            return [
                'processed' => 0,
                'paid' => 0,
                'failed' => 0,
                'held' => 0,
                'replayed' => 0,
                'total_paid' => '0.00',
            ];
        }

        $ids = [];
        if ($batch instanceof PayoutBatch) {
            $memberIds = app(PayoutBatchMembershipService::class)->members($batch)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $ids = is_array($this->payoutIds) && $this->payoutIds !== []
                ? array_values(array_intersect($memberIds, array_map('intval', $this->payoutIds)))
                : $memberIds;
        } elseif (is_array($this->payoutIds)) {
            $ids = array_values(array_unique(array_map('intval', $this->payoutIds)));
        }

        $paid = 0;
        $failed = 0;
        $held = 0;
        $replayed = 0;
        $totalPaid = '0.00';
        $executor = app(ApprovedWalletPayoutService::class);

        foreach ($ids as $payoutId) {
            try {
                $outcome = $executor->execute((int) $payoutId);
                if (($outcome['result'] ?? null) === 'paid') {
                    $paid++;
                    $totalPaid = bcadd($totalPaid, (string) ($outcome['amount'] ?? '0.00'), 2);
                } elseif (($outcome['result'] ?? null) === 'replayed') {
                    $replayed++;
                } else {
                    $held++;
                }
            } catch (Throwable) {
                $held++;
            }
        }

        if ($batch instanceof PayoutBatch) {
            try {
                $current = app(PayoutBatchService::class)->refresh($batch);
                if ($current->status === PayoutBatchStatus::Processing && (int) $current->processed_count === (int) $current->member_count) {
                    app(PayoutBatchService::class)->settle($current);
                }
            } catch (Throwable) {
                // Uncertain or incomplete batch stays Processing for operator inspection.
            }
        }

        return [
            'processed' => $paid + $replayed,
            'paid' => $paid,
            'failed' => $failed,
            'held' => $held,
            'replayed' => $replayed,
            'total_paid' => $totalPaid,
        ];
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical('ProcessPayoutBatchJob: batch execution failed', [
            'batch_tag' => $this->batchTag,
            'exception_class' => $exception !== null ? $exception::class : null,
        ]);

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Payout batch job failed at batch_tag [%s]: %s',
                (string) ($this->batchTag ?? 'sweep'),
                $exception?->getMessage() ?? 'Unknown failure',
            ),
            'metadata' => [
                'lane' => 'payout_batch',
                'batch_tag' => $this->batchTag,
                'exception_class' => $exception !== null ? $exception::class : null,
            ],
        ]);
        $log->save();
    }
}
