<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Services\Prize\ClaimWindowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CloseExpiredClaimWindowsJob implements ShouldBeUnique, ShouldQueue
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
        $this->onQueue(QueueName::Reconciliation->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'close_expired_claim_windows_'.now()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(ClaimWindowService $service): array
    {
        Log::info('CloseExpiredClaimWindowsJob: starting daily close sweep', [
            'attempt' => $this->attempts(),
        ]);

        $summary = $service->closeExpired($this->chunkSize) + ['executed_at' => now()->toIso8601String()];
        $failedCount = (int) ($summary['failed_count'] ?? 0);

        Log::info('CloseExpiredClaimWindowsJob: sweep complete', [
            'closed_count' => $summary['closed_count'],
            'total_lapsed_amount' => $summary['total_lapsed_amount'],
            'failed_count' => $failedCount,
        ]);

        if ($summary['closed_count'] > 0 || $failedCount > 0) {
            $this->recordSummary($summary);
        }

        if ($failedCount > 0) {
            throw new RuntimeException(sprintf(
                'CloseExpiredClaimWindowsJob completed with %d row failure(s).',
                $failedCount,
            ));
        }

        return $summary;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CloseExpiredClaimWindowsJob: sweep run failed', [
            'exception' => $exception?->getMessage(),
            'attempt' => $this->attempts(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function recordSummary(array $summary): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => ($summary['failed_count'] ?? 0) > 0 ? RiskLevel::High : RiskLevel::Medium,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf(
                'Daily claim-window sweep closed %d window(s); %d failed.',
                (int) ($summary['closed_count'] ?? 0),
                (int) ($summary['failed_count'] ?? 0),
            ),
            'metadata' => [
                'lane' => 'claim_window_sweep',
                'closed_count' => (int) ($summary['closed_count'] ?? 0),
                'total_lapsed_amount' => $summary['total_lapsed_amount'] ?? null,
                'totals_by_currency' => $summary['totals_by_currency'] ?? [],
                'failed_count' => (int) ($summary['failed_count'] ?? 0),
                'failed_ids' => $summary['failed_ids'] ?? [],
                'chunk_size' => $this->chunkSize,
            ],
        ]);

        $log->save();
    }
}
