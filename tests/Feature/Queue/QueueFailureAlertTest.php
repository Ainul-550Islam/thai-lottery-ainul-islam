<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\RiskLevel;
use App\Services\Observability\OperationalAlertService;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class QueueFailureAlertTest extends TestCase
{
    public static function moneyQueues(): array
    {
        return [['financial-critical'], ['webhooks'], ['reconciliation'], ['finance-webhooks'], ['finance-reconciliation']];
    }

    #[DataProvider('moneyQueues')]
    public function test_money_queue_failure_reaches_the_actual_alert_service_without_copying_exception_secrets(string $queue): void
    {
        $secret = 'SYNTHETIC_PROVIDER_PASSWORD_NOT_FOR_LOGS';
        $service = Mockery::mock(OperationalAlertService::class);
        $service->shouldReceive('alertFinancialAnomaly')->once()->withArgs(
            function (string $title, string $message, RiskLevel $severity, array $metadata) use ($queue, $secret): bool {
                return $title === 'Queue job failed' && $severity === RiskLevel::Critical
                    && str_contains($message, $queue) && $metadata['money_lane'] === true
                    && ! str_contains(json_encode($metadata, JSON_THROW_ON_ERROR), $secret);
            },
        )->andReturn(true);
        $this->app->instance(OperationalAlertService::class, $service);
        Log::spy();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('getQueue')->andReturn($queue);
        $job->shouldReceive('getJobId')->andReturn('synthetic-job');
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('payload')->andReturn(['displayName' => 'SyntheticJob', 'correlation_id' => 'synthetic-request']);

        Event::dispatch(new JobFailed('database', $job, new RuntimeException($secret)));
        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'queue.job_failed'
                && $context['queue'] === $queue && $context['money_lane'] === true
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $secret),
        );
    }

    public function test_worker_loop_samples_each_named_queue_and_throttles_repeated_checks(): void
    {
        config(['queue.alert_backlog_threshold' => 3, 'queue.backlog_sample_seconds' => 30]);
        Log::spy();
        $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $connection->shouldReceive('size')->once()->with('financial-critical')->andReturn(4);
        $connection->shouldReceive('size')->once()->with('webhooks')->andReturn(0);
        Queue::shouldReceive('connection')->once()->with('database')->andReturn($connection);

        Event::dispatch(new Looping('database', 'financial-critical,webhooks'));
        Event::dispatch(new Looping('database', 'financial-critical,webhooks'));
        Log::shouldHaveReceived('warning')->once()->with('queue.backlog_growing', [
            'connection' => 'database', 'queue' => 'financial-critical', 'size' => 4,
        ]);
    }

    public function test_backlog_monitor_failure_does_not_kill_the_worker_or_log_exception_secrets(): void
    {
        Log::spy();
        $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
        $connection->shouldReceive('size')->once()->with('financial-critical')
            ->andThrow(new RuntimeException('SYNTHETIC_REDIS_PASSWORD_DO_NOT_LOG'));
        Queue::shouldReceive('connection')->once()->with('redis')->andReturn($connection);

        Event::dispatch(new Looping('redis', 'financial-critical'));
        Log::shouldHaveReceived('warning')->once()->with('queue.backlog_check_failed', [
            'connection' => 'redis', 'exception_class' => RuntimeException::class,
        ]);
    }

    public function test_failed_log_channel_does_not_stop_the_worker_either(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'queue-log-test-');
        $this->assertNotFalse($path);
        $previous = ini_set('error_log', $path);
        try {
            $connection = Mockery::mock(\Illuminate\Contracts\Queue\Queue::class);
            $connection->shouldReceive('size')->once()->with('financial-critical')
                ->andThrow(new RuntimeException('SYNTHETIC_BROKER_SECRET'));
            Queue::shouldReceive('connection')->once()->with('redis')->andReturn($connection);
            Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('SYNTHETIC_LOG_SECRET'));

            Event::dispatch(new Looping('redis', 'financial-critical'));
            $output = file_get_contents($path);
            $this->assertStringContainsString('queue.backlog_check_failed: logging unavailable', $output);
            $this->assertStringNotContainsString('SYNTHETIC_', $output);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($path);
        }
    }
}
