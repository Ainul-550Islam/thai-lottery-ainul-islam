<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Services\Prize\UnclaimedPrizeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ExpireUnclaimedPrizesJob implements ShouldBeUnique, ShouldQueue
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
        return 'expire_unclaimed_prizes_'.now()->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function handle(UnclaimedPrizeService $service): ?array
    {
        Log::info('ExpireUnclaimedPrizesJob: starting daily sweep', [
            'attempt' => $this->attempts(),
        ]);

        $summary = $service->expireLapsedClaims($this->chunkSize);
        $failedCount = (int) ($summary['failed_count'] ?? 0);

        Log::info('ExpireUnclaimedPrizesJob: sweep complete', [
            'expired_count' => $summary['expired_count'],
            'total_expired_amount' => $summary['total_expired_amount'],
            'failed_count' => $failedCount,
        ]);

        if ($summary['expired_count'] > 0 || $failedCount > 0) {
            $this->recordSummary($summary);
        }

        if ($failedCount > 0) {
            throw new RuntimeException(sprintf(
                'ExpireUnclaimedPrizesJob completed with %d row failure(s).',
                $failedCount,
            ));
        }

        return $summary;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ExpireUnclaimedPrizesJob: sweep run failed', [
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
                'Daily unclaimed-prize sweep expired %d claim(s); %d failed.',
                (int) ($summary['expired_count'] ?? 0),
                (int) ($summary['failed_count'] ?? 0),
            ),
            'metadata' => [
                'lane' => 'prize_claim_sweep',
                'expired_count' => (int) ($summary['expired_count'] ?? 0),
                'total_expired_amount' => $summary['total_expired_amount'] ?? null,
                'totals_by_currency' => $summary['totals_by_currency'] ?? [],
                'failed_count' => (int) ($summary['failed_count'] ?? 0),
                'failed_ids' => $summary['failed_ids'] ?? [],
                'chunk_size' => $this->chunkSize,
            ],
        ]);

        $log->save();
    }
}
