<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\RiskLevel;
use App\Services\Observability\OperationalAlertService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queue reliability: dead-letter handling, failure alerting, correlation.
 *
 * ============================================================================
 * THE GAP THIS CLOSES
 * ============================================================================
 * The repository had a `failed_jobs` table (created by the baseline migration)
 * and `config/queue.php` declared `'failed' => ['database' => ..., 'table' =>
 * 'failed_jobs']`. NOTHING SUBSCRIBED TO FAILURES. Concretely, that meant:
 *
 *   - A prize-settlement chunk that failed permanently produced one row in a
 *     table nobody read and one line in a log file. No alert. On a draw night
 *     that is a silent, unbounded delay in paying winners.
 *   - A failed payout-transfer job was indistinguishable from a successful one
 *     from the operator's point of view until a player complained.
 *   - There was no dead-letter QUEUE (in the AMQP sense) — `failed_jobs` is a
 *     graveyard table, not a queue, and nothing could be replayed from it
 *     without hand-written SQL.
 *   - There was no correlation between a failed job and the request/webhook
 *     that caused it, because CorrelationIdMiddleware's id was never carried
 *     onto the queue payload.
 *
 * WHAT THIS PROVIDER DOES
 *   1. JobFailed  → classify by queue, alert the operational channel at a level
 *                   proportional to whether money is involved, and log with the
 *                   correlation id intact.
 *   2. JobProcessing → restore the correlation id onto the log context so every
 *                   line a job writes is traceable to its origin.
 *   3. WorkerStopping → emit a lifecycle signal so a supervisor's restarts are
 *                   visible as events rather than as gaps in the log.
 *
 * REPLAY
 * Replay is deliberately NOT automatic. Replaying a failed money job without
 * reading why it failed is how a transient bug becomes a duplicated payout.
 * `php artisan queue:dlq:replay` (app/Console/Commands/Queue/ReplayDeadLetterCommand.php)
 * requires an operator to name the job and state the reason.
 */
class QueueServiceProvider extends ServiceProvider
{
    /**
     * Queues where a failure means money did not move when it should have.
     *
     * @var list<string>
     */
    private const MONEY_QUEUES = [
        'financial-critical',
        'webhooks',
        'reconciliation',
        // Existing queued names remain classified as money until operators
        // inventory and resolve their legacy backlog.
        'settlement',
        'payouts',
        'finance',
        'finance-reconciliation',
        'finance-webhooks',
    ];

    public function boot(): void
    {
        $this->listenForJobFailures();
        $this->listenForJobLifecycle();
    }

    private function listenForJobFailures(): void
    {
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            $queue = $event->job->getQueue() ?: 'default';
            $jobName = $this->jobName($event);
            $isMoney = in_array($queue, self::MONEY_QUEUES, true);

            $context = [
                'queue' => $queue,
                'connection' => $event->connectionName,
                'job' => $jobName,
                'job_id' => $event->job->getJobId(),
                'attempts' => $event->job->attempts(),
                'correlation_id' => data_get($event->job->payload(), 'correlation_id'),
                // Driver/HTTP exceptions can embed credentials or payment data;
                // retain the class for triage without copying arbitrary messages.
                'exception_class' => $event->exception::class,
                'money_lane' => $isMoney,
            ];

            Log::critical('queue.job_failed', $context);

            // Money-lane failures are alerted unconditionally. A non-money
            // failure is alerted once it has exhausted its retries, which is
            // the point at which `failed_jobs` becomes the final resting place
            // rather than a transient retry state.
            if ($isMoney) {
                $this->alert(
                    'critical',
                    sprintf('Money-lane queue job failed: %s on [%s]', $jobName, $queue),
                    $context,
                );

                return;
            }

            if ((int) $event->job->attempts() >= 5) {
                $this->alert(
                    'warning',
                    sprintf('Queue job exhausted retries: %s on [%s]', $jobName, $queue),
                    $context,
                );
            }
        });
    }

    private function listenForJobLifecycle(): void
    {
        // Carry the correlation id from the dispatching request onto every log
        // line the job writes. Without this, a job's logs are an orphan island:
        // you can see that something failed, and you cannot see who asked for it.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $correlationId = data_get($event->job->payload(), 'correlation_id');

            if (is_string($correlationId) && $correlationId !== '') {
                Log::withContext([
                    'correlation_id' => $correlationId,
                    'queue' => $event->job->getQueue(),
                ]);
            }
        });

        Event::listen(WorkerStopping::class, function (WorkerStopping $event): void {
            Log::info('queue.worker_stopping', [
                'status' => $event->status,
                'connection' => $event->connectionName ?? null,
            ]);
        });

        // Looping carries queue NAMES, not a queue object. Sampling must never
        // take down a real worker, and must not add a query on every tight loop.
        $lastSample = [];
        Event::listen(Looping::class, function (Looping $event) use (&$lastSample): void {
            if (! is_string($event->queue) || trim($event->queue) === '') {
                return;
            }
            $names = array_values(array_unique(array_filter(array_map('trim', explode(',', $event->queue)))));
            $key = (string) $event->connectionName.'|'.implode(',', $names);
            $now = microtime(true);
            $interval = max(1, (int) config('queue.backlog_sample_seconds', 30));
            if (isset($lastSample[$key]) && $now - $lastSample[$key] < $interval) {
                return;
            }
            $lastSample[$key] = $now;

            try {
                $connection = Queue::connection($event->connectionName);
                foreach ($names as $name) {
                    $size = $connection->size($name);
                    if ($size > (int) config('queue.alert_backlog_threshold', 1000)) {
                        Log::warning('queue.backlog_growing', [
                            'connection' => $event->connectionName,
                            'queue' => $name,
                            'size' => $size,
                        ]);
                    }
                }
            } catch (Throwable $e) {
                try {
                    Log::warning('queue.backlog_check_failed', [
                        'connection' => $event->connectionName,
                        'exception_class' => $e::class,
                    ]);
                } catch (Throwable) {
                    // Logging is best-effort here too: telemetry must not stop
                    // the worker or leak a driver's exception message.
                    error_log('queue.backlog_check_failed: logging unavailable');
                }
            }
        });
    }

    private function jobName(JobFailed $event): string
    {
        $name = data_get($event->job->payload(), 'displayName');

        if (is_string($name) && $name !== '') {
            // displayName is a class name or a closure marker. Keep it short and
            // never let a closure marker leak serialised internals into a log.
            return Str::limit($name, 190, '');
        }

        return 'unknown';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function alert(string $level, string $message, array $context): void
    {
        try {
            $service = $this->app->make(OperationalAlertService::class);

            // This repository exposes alertFinancialAnomaly, not alert/dispatch.
            // Calling the real contract avoids silently dropping money failures.
            if (method_exists($service, 'alertFinancialAnomaly')) {
                $service->alertFinancialAnomaly(
                    'Queue job failed',
                    $message,
                    $level === 'critical' ? RiskLevel::Critical : RiskLevel::High,
                    $context,
                );

                return;
            }

            if (method_exists($service, 'alert')) {
                $service->alert($level, $message, $context);

                return;
            }

            if (method_exists($service, 'dispatch')) {
                $service->dispatch($level, $message, $context);
            }
        } catch (Throwable $e) {
            // An alerting failure must never be the reason a job's failure goes
            // unreported. The critical log line above already recorded it.
            Log::warning('queue.alert_dispatch_failed', [
                'exception_class' => $e::class,
                'intended_message' => $message,
            ]);
        }
    }
}
