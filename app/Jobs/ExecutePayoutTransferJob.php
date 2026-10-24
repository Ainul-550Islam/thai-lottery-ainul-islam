<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Payment\PayoutTransferGuard;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecutePayoutTransferJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [15, 60, 180];

    public int $timeout = 180;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $batchKey,
        public readonly ?int $chunkSize = null,
    ) {
        $this->onQueue(QueueName::FinancialCritical->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'execute_payout_transfer_'.$this->batchKey.'_'.now()->format('Y-m-d-H-i');
    }

    /**
     * @return array{selected: int, opened: int, skipped_wallet: int, failed: int, failures: list<array<string, mixed>>, executed_at: string}
     */
    public function handle(PayoutTransferService $service): array
    {
        Log::info('ExecutePayoutTransferJob: opening transfer lanes', [
            'batch_key' => $this->batchKey,
            'attempt' => $this->attempts(),
        ]);

        $selected = 0;
        $opened = 0;
        $skippedWallet = 0;
        $failed = 0;
        $failures = [];

        $query = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('status', PayoutStatus::Pending->value)
            ->whereRaw("json_extract(metadata, '$.batch.batch_key') = ?", [$this->batchKey])
            ->whereRaw("json_extract(metadata, '$.approval.status') = ?", [PayoutApprovalStatus::Approved->value])
            ->whereRaw("json_extract(metadata, '$.transfer.lane_status') IS NULL")
            ->orderBy('id');

        if (is_int($this->chunkSize) && $this->chunkSize > 0) {
            $query->limit($this->chunkSize);
        }

        foreach ($query->get() as $payout) {
            $selected++;

            try {
                $data = app(PayoutTransferGuard::class)->fromRow($payout);
                if ($data->method === PrizePayoutMethod::Wallet) {
                    $skippedWallet++;

                    continue;
                }
                if ($data->batchKey !== $this->batchKey) {
                    app(PayoutTransferGuard::class)->refuse($payout);
                }

                $service->transfer($data);
                $opened++;
            } catch (PayoutTransferException $e) {
                $failed++;
                $failures[] = [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => (string) $payout->reference_number,
                    'code' => 'PAYOUT_AUTHORITY_OR_FINANCIAL_GUARD',
                    'error_code' => $e->errorCode(),
                ];
            } catch (Throwable) {
                $failed++;
                $failures[] = [
                    'payout_id' => (int) $payout->getKey(),
                    'payout_reference' => (string) $payout->reference_number,
                    'code' => 'PAYOUT_AUTHORITY_OR_FINANCIAL_GUARD',
                ];
            }
        }

        $summary = [
            'selected' => $selected,
            'opened' => $opened,
            'skipped_wallet' => $skippedWallet,
            'failed' => $failed,
            'failures' => $failures,
            'executed_at' => now()->toIso8601String(),
        ];

        if ($selected > 0) {
            $this->recordSummary($summary);
        }

        return $summary;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ExecutePayoutTransferJob: run failed', [
            'batch_key' => $this->batchKey,
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array{selected: int, opened: int, skipped_wallet: int, failed: int}  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Payout transfer run for batch [%s]: %d selected, %d opened, %d internal-wallet skipped, %d failed.',
                $this->batchKey,
                $summary['selected'],
                $summary['opened'],
                $summary['skipped_wallet'],
                $summary['failed'],
            ),
            'metadata' => [
                'lane' => 'payout_transfer_run',
                'batch_key' => $this->batchKey,
                'selected' => $summary['selected'],
                'opened' => $summary['opened'],
                'skipped_wallet' => $summary['skipped_wallet'],
                'failed' => $summary['failed'],
                'action' => 'payout_transfer_run',
            ],
        ]);

        $log->save();
    }
}
