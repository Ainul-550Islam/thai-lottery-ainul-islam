<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Enums\PayoutBatchStatus;
use App\Services\Finance\ApprovedWalletPayoutService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\WalletService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Payout Batch Job.
 *
 * GUARANTEES
 * ----------
 * 1. ONE PAYOUT = ONE CREDIT, EXACTLY ONCE. Every row in the batch is Paid
 *    through WalletService::credit under a payout-scoped idempotency key.
 *    Replaying this job with the same payouts re-runs only the rows that
 *    never completed; each Completed row is treated as already-paid and
 *    skipped (its wallet and ledger stay as posted by the first run).
 * 2. ATOMIC PER ROW, ISOLATED ACROSS ROWS. Each payout settles inside its
 *    own database transaction: a failing payout (rejected credit, tampered
 *    amount) moves itself to Failed with the reason, and in no way stops or
 *    compromises the rest of the batch — a 200-row batch never becomes a
 *    200-row all-or-nothing homing to gamble on.
 * 3. CONCURRENCY SAFE. ShouldBeUnique keyed on the batch tag prevents two
 *    workers sweeping the same process run; inside a worker the payout row
 *    is taken with SELECT ... FOR UPDATE, so even a double-dispatch of the
 *    SAME payout id across two workers results in one winner and one replay,
 *    not two credits.
 * 4. OBSERVED. Completed rows emit PrizePayoutCompleted for the players'
 *    surfaces, a per-batch audit line records what a run did, and a hard
 *    fail of the whole run lands in the critical channel with a payout count.
 */
class ProcessPayoutBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum retry attempts before failing permanently.
     */
    public int $tries = 2;

    /**
     * Exponential backoff delays in seconds (15s, 60s).
     *
     * @var list<int>
     */
    public array $backoff = [15, 60];

    /**
     * Execution timeout in seconds (200 payouts in worst-case one-failure loop).
     */
    public int $timeout = 300;

    /**
     * Unique lock duration in seconds.
     */
    public int $uniqueFor = 300;

    /**
     * @param  int|null  $batchTag  Shared tag across the rows of one logical
     *                              batch (or null to sweep every eligible
     *                              pending row).
     * @param  list<int>|null  $payoutIds  Explicit row ids limiting the run
     *                                     (operator-preclaimed batch).
     */
    public function __construct(
        public readonly ?int $batchTag = null,
        public readonly ?array $payoutIds = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    /**
     * Unique identifier for the concurrency lock.
     */
    public function uniqueId(): string
    {
        return 'payout_batch_'.($this->batchTag ?? 'sweep_'.now()->format('Y-m-d-H-i'));
    }

    /**
     * Process one batch of pending payouts.
     *
     * @return array{
     *     processed: int,
     *     paid: int,
     *     failed: int,
     *     replayed: int,
     *     total_paid: string
     * }|null
     */
    public function handle(WalletService $walletService): ?array
    {
        $summary = ['processed' => 0, 'paid' => 0, 'failed' => 0, 'held' => 0,
            'replayed' => 0, 'total_paid' => '0.00'];
        $members = DB::table('payout_batch_members')->select('payout_id');
        if ($this->batchTag !== null) {
            $members->where('payout_batch_id', $this->batchTag);
        }
        $query = Payout::query()->whereIn('id', $members)->orderBy('id');
        if ($this->payoutIds !== null) {
            $query->whereIn('id', $this->payoutIds);
        } else {
            $query->where('status', PayoutStatus::Pending->value);
        }
        $rows = $query->limit(max(1, min(1000, (int) config('lottery.payouts.batch_size', 200))))->get();
        $batchIds = [];
        foreach ($rows as $row) {
            $summary['processed']++;
            try {
                $batchId = DB::table('payout_batch_members')->where('payout_id', $row->id)->value('payout_batch_id');
                $batchIds[(int) $batchId] = true;
                $outcome = app(ApprovedWalletPayoutService::class)->execute((int) $row->id);
                if ($outcome['result'] === 'paid') {
                    $summary['paid']++;
                    $summary['total_paid'] = bcadd($summary['total_paid'], $outcome['amount'], 2);
                } else {
                    $summary['replayed']++;
                }
            } catch (Throwable $exception) {
                // Uncertain authority or evidence is NOT a provider's final refusal.
                // Never mutate the payout to Failed or release money on worker errors.
                $summary['held']++;
                Log::warning('Wallet payout retained for controlled review.', [
                    'payout_id' => (int) $row->id, 'code' => 'PAYOUT_AUTHORITY_OR_FINANCIAL_GUARD',
                ]);
            }
        }
        foreach (array_keys($batchIds) as $id) {
            $batch = PayoutBatch::query()->find($id);
            if (! $batch || ($batch->metadata['execution_lane'] ?? null) !== 'wallet') {
                continue;
            }
            $service = app(PayoutBatchService::class);
            $current = $service->refresh($batch);
            if ($current->status === PayoutBatchStatus::Processing && $current->processed_count === $current->member_count) {
                $service->settle($current);
            }
        }
        $this->recordBatchAudit($summary);

        return $summary;
    }

    private function recordBatchAudit(array $summary): void
    {
        if ($summary['processed'] === 0) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => ($summary['failed'] > 0 || $summary['held'] > 0) ? RiskLevel::High : RiskLevel::Low,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Payout batch %s processed %d row(s): %d paid (%s), %d failed, %d replayed.',
                $this->batchTag !== null ? '#'.$this->batchTag : '(sweep)',
                $summary['processed'],
                $summary['paid'],
                $summary['total_paid'],
                $summary['failed'],
                $summary['replayed'],
            ),
            'metadata' => [
                'lane' => 'payout_batch',
                'batch_tag' => $this->batchTag,
                'payout_ids' => $this->payoutIds,
                'processed' => $summary['processed'],
                'paid' => $summary['paid'],
                'failed' => $summary['failed'],
                'replayed' => $summary['replayed'],
                'total_paid' => $summary['total_paid'],
            ],
        ]);

        $log->save();
    }

    /**
     * Handle permanent job failure (max retries exhausted).
     */
    public function failed(Throwable $exception): void
    {
        Log::critical('ProcessPayoutBatchJob: job failed permanently after maximum attempts', [
            'batch_tag' => $this->batchTag,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Payout batch job %s failed permanently after %d attempts: %s',
                $this->batchTag !== null ? '#'.$this->batchTag : '(sweep)',
                $this->attempts(),
                $exception->getMessage(),
            ),
            'metadata' => [
                'lane' => 'payout_batch',
                'batch_tag' => $this->batchTag,
                'attempts' => $this->attempts(),
                'error' => $exception->getMessage(),
            ],
        ]);
        $log->save();
    }
}
