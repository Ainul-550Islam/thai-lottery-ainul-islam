<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Notification & Financial Alert Job.
 *
 * Dispatches critical financial discrepancy alerts, suspicious activity flags,
 * and operator alerts to configured channels without blocking monetary workflows.
 */
class SendFinancialAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff delays in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30, 60];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly RiskLevel $severity = RiskLevel::High,
        public readonly array $metadata = [],
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    /**
     * Execute the notification alert dispatch.
     */
    public function handle(): void
    {
        Log::channel('single')->log(
            $this->severity === RiskLevel::Critical ? 'critical' : 'warning',
            sprintf('[FINANCIAL ALERT] %s: %s', $this->title, $this->message),
            $this->metadata,
        );

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            // Not AuditAction::Reconcile. This job is the alerting side of the platform, and
            // it used to file every alert it sent - queue backlog, failed-job spikes,
            // anomalies - as a reconciliation run. That was not cosmetic: the freshness check
            // that decides whether to raise the stale-reconciliation alert selects the newest
            // row with action=reconcile, so each alert wrote the evidence that the
            // reconciliation had just happened. The alarm reset the clock it was watching,
            // which made the control unable to stay breached and let /api/health report
            // 'pass' about a ledger nobody had checked. A lane that reports its own
            // inaction to itself is worse than a lane nobody runs, because it looks green.
            //
            // security_alert is the action this record actually is: it already exists in the
            // enum, the column is a 32-char string with no DB enum of its own, and nothing
            // else filters on it for money semantics.
            'action' => AuditAction::SecurityAlert,
            'risk_level' => $this->severity,
            'auditable_type' => null,
            'auditable_id' => null,
            'description' => sprintf('%s: %s', $this->title, $this->message),
            // The lane name is a default the caller can override; the alert row is
            // about this run, so it has no subject row to name.
            'metadata' => $this->metadata + ['lane' => 'financial_alert'],
        ]);
        $log->save();
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendFinancialAlertJob failed to deliver alert', [
            'title' => $this->title,
            'error' => $exception->getMessage(),
        ]);
    }
}
