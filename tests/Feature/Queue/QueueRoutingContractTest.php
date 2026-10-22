<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Enums\QueueName;
use App\Jobs\ExpireStalePaymentIntentsJob;
use App\Jobs\ExpireWalletReservationsJob;
use App\Jobs\ProcessPaymentWebhookJob;
use App\Jobs\ReconcilePaymentProviderJob;
use App\Jobs\ReconcileWalletLedgersJob;
use App\Jobs\ReassessAmlRiskJob;
use App\Jobs\ReviewFinancialHoldsJob;
use App\Jobs\ReviewOpenComplianceCasesJob;
use App\Jobs\VerifyPendingKycDocumentsJob;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Queue routing is a contract with two ends, and for a long time only one of them
 * was written down: app/Enums/QueueName plus deployment/supervisor/.../worker.conf
 * agreed on five queues, while nine jobs handed their work to names taken from an
 * older vocabulary ("finance-reconciliation", "finance-webhooks") that no worker
 * consumes.
 *
 * It survived because every environment that runs the test suite sets
 * QUEUE_CONNECTION=sync, and the sync driver ignores the queue name altogether - so
 * dev, CI and these tests all saw the jobs "work". Only .env.production.example
 * uses redis, and there the job is pushed onto a queue nobody polls: silently never
 * executed, while the scheduler keeps enqueuing it on every tick.
 *
 * So the guards below run the real dispatch path under Queue::fake(), where the name
 * matters, and then pin the operational commands to the enum so neither end can move
 * on its own again.
 */
final class QueueRoutingContractTest extends TestCase
{
    /**
     * Every job that was found drifted, mapped to the queue it must land on.
     *
     * @return array<string, array{0: class-string, 1: QueueName}>
     */
    private static function driftedJobs(): array
    {
        return [
            'financial holds review' => [ReviewFinancialHoldsJob::class, QueueName::Reconciliation],
            'open compliance cases review' => [ReviewOpenComplianceCasesJob::class, QueueName::Reconciliation],
            'wallet reservation expiry' => [ExpireWalletReservationsJob::class, QueueName::Reconciliation],
            'payment provider reconciliation' => [ReconcilePaymentProviderJob::class, QueueName::Reconciliation],
            'wallet ledger reconciliation' => [ReconcileWalletLedgersJob::class, QueueName::Reconciliation],
            'pending KYC document verification' => [VerifyPendingKycDocumentsJob::class, QueueName::Reconciliation],
            'stale payment intent expiry' => [ExpireStalePaymentIntentsJob::class, QueueName::Reconciliation],
            'AML risk reassessment' => [ReassessAmlRiskJob::class, QueueName::Reconciliation],
            'payment webhook processing' => [ProcessPaymentWebhookJob::class, QueueName::Webhooks],
        ];
    }

    #[Test]
    public function each_job_that_had_drifted_is_pushed_onto_a_queue_the_worker_consumes(): void
    {
        Queue::fake();

        foreach (self::driftedJobs() as $label => [$jobClass, $expected]) {
            // ProcessPaymentWebhookJob is the only one whose constructor demands an
            // argument; it is never executed here, so an id that does not exist is fine.
            $job = $jobClass === ProcessPaymentWebhookJob::class
                ? new $jobClass(1)
                : new $jobClass();

            // dispatch($job), not $job->dispatch(): the trait's dispatch() is static and
            // would build a second, argumentless instance of the job.
            dispatch($job);

            Queue::assertPushedOn($expected->value, $jobClass);
        }
    }

    #[Test]
    public function no_job_is_ever_pushed_onto_a_queue_outside_the_declared_set(): void
    {
        Queue::fake();

        $consumed = array_map(
            static fn (QueueName $queue): string => $queue->value,
            QueueName::cases()
        );

        $seen = [];

        foreach (self::driftedJobs() as $label => [$jobClass]) {
            $job = $jobClass === ProcessPaymentWebhookJob::class
                ? new $jobClass(1)
                : new $jobClass();

            dispatch($job);

            Queue::assertPushed($jobClass, static function (object $pushed) use (&$seen, $consumed, $jobClass): bool {
                $seen[] = $pushed->queue;

                self::assertContains(
                    $pushed->queue,
                    $consumed,
                    "{$jobClass} was pushed onto \"{$pushed->queue}\", which no worker consumes."
                );

                return true;
            });
        }

        // A test that asserts nothing passes too, so prove what was really observed,
        // queue by queue, without depending on the order the jobs ran in.
        $this->assertSame(
            ['reconciliation' => 8, 'webhooks' => 1],
            array_count_values($seen),
            'unexpected distribution of the nine jobs across queues'
        );
    }

    #[Test]
    public function no_job_hardcodes_a_queue_name_instead_of_naming_a_case(): void
    {
        $offenders = [];

        foreach (glob(base_path('app/Jobs/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('/onQueue\(\s*[\'"]/', $source) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Jobs must route through QueueName so the supervisor configuration and the jobs cannot drift apart: '
            .implode(', ', $offenders)
        );
    }

    #[Test]
    public function the_worker_command_consumes_exactly_the_declared_queues_in_the_declared_priority(): void
    {
        $conf = (string) file_get_contents(base_path('deployment/supervisor/thai-lottery-worker.conf'));

        $this->assertSameTo(
            self::queueListFrom($conf, 'supervisor worker configuration'),
            QueueName::priorityOrder(),
            'the supervisor worker configuration'
        );
    }

    #[Test]
    public function every_worker_command_in_the_deployment_documents_consumes_every_declared_queue(): void
    {
        foreach (['deployment/README.md', 'deployment/CUTOVER-CHECKLIST.md'] as $relative) {
            $document = (string) file_get_contents(base_path($relative));

            preg_match_all('/queue:work([^\n]*)/', $document, $matches);

            $this->assertNotEmpty(
                $matches[0],
                "{$relative} no longer documents a queue:work command, so this guard has nothing to check."
            );

            foreach ($matches[1] as $options) {
                // Only the supervisor configuration has to keep the priority order; a
                // document that lists the same queues in another order is still correct.
                $this->assertSameSet(
                    array_map(static fn (QueueName $q): string => $q->value, QueueName::cases()),
                    self::queueListFrom('command=php artisan queue:work'.$options, $relative)
                );
            }
        }
    }

    #[Test]
    public function the_enum_helper_and_its_cases_cannot_drift_apart_when_a_queue_is_added(): void
    {
        $cases = array_map(static fn (QueueName $queue): string => $queue->value, QueueName::cases());
        $priority = QueueName::priorityOrder();

        // Adding a case without giving it a priority (and therefore a worker) is the
        // same mistake as adding a queue name no worker polls - one assertion each.
        $this->assertSameSet($cases, $priority);
        $this->assertSame(implode(',', $priority), QueueName::workerQueueString());
    }

    /**
     * @param  array<int, string>  $expected
     */
    private function assertSameTo(array $actual, array $expected, string $where): void
    {
        $this->assertSame(
            $expected,
            $actual,
            "{$where} does not list the queues in the order and set the enum declares."
        );
    }

    /**
     * @param  array<int, string>  $expected
     * @param  array<int, string>  $actual
     */
    private function assertSameSet(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    /**
     * @return array<int, string>
     */
    private static function queueListFrom(string $text, string $where): array
    {
        $matched = preg_match('/--queue=([^\s]+)/', $text, $matches);

        self::assertSame(
            1,
            $matched,
            "{$where} carries no --queue= option, so it would only consume the default queue."
        );

        return explode(',', $matches[1]);
    }
}
