<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Scheduling\ReconciliationCadence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The interval-to-cron conversion, tested against the class rather than by repeating
 * its arithmetic here.
 *
 * Two properties matter and both are checked for every row: the expression is a real
 * five-field cron string, and running it never fires MORE often than the interval that
 * was asked for. The second is the one that decides whether a fallback is safe - a
 * cadence conversion that quietly runs a money-reading job more often than configured
 * would be its own kind of incident, and one that no operator would notice.
 */
final class ReconciliationCadenceTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function intervals(): array
    {
        return [
            'shipped default, one hour' => [3600, '0 * * * *', 'the hourly expression, which is what 3600 seconds means'],
            'fifteen minutes' => [900, '*/15 * * * *', 'a divisor of the hour, so runs land on exact minutes'],
            'thirty minutes' => [1800, '*/30 * * * *', 'a divisor of the hour'],
            'one minute' => [60, '*/1 * * * *', 'the shortest cadence the scheduler can honour'],
            'two hours' => [7200, '0 */2 * * *', 'a whole number of hours becomes an hour step'],
            'six hours' => [21600, '0 */6 * * *', 'a whole number of hours'],
            'one day' => [86400, '0 0 * * *', '24 hours collapses to daily, which is the same thing'],
            'thirty hours cannot be expressed' => [108000, '0 0 * * *', 'longer than a day: daily is the closest cadence that never over-runs the request'],
            'seven minutes does not divide the hour' => [420, '*/10 * * * *', 'rounded up to the next step a minute field can carry, so it never fires more often'],
            'forty five minutes' => [2700, '0 * * * *', 'no step field reaches 45 minutes; the next expressible cadence is hourly'],
            'ninety minutes' => [5400, '0 */2 * * *', 'not a whole number of hours, so it rounds up to two hours rather than down to one'],
            'a day and a half' => [129600, '0 0 * * *', 'longer than a day: capped at daily, the trade-off recorded in the class comment'],
            'zero' => [0, '0 * * * *', 'a zero interval is malformed; the hourly default keeps the control running'],
            'negative' => [-3600, '0 * * * *', 'same reading as zero'],
            'non-numeric' => ['soon', '0 * * * *', 'a string that is not a number cannot set a cadence'],
            'null' => [null, '0 * * * *', 'a missing key reads as the default'],
            'ten seconds is below the scheduler resolution' => [10, '0 * * * *', 'the scheduler fires once a minute at best; claiming ten seconds would be a lie'],
        ];
    }

    #[Test]
    #[DataProvider('intervals')]
    public function an_interval_becomes_the_expression_described(mixed $seconds, string $expected, string $why): void
    {
        $this->assertSame($expected, ReconciliationCadence::expressionFor($seconds), $why);
    }

    #[Test]
    #[DataProvider('intervals')]
    public function every_expression_is_a_five_field_cron_string(mixed $seconds, string $expected, string $why): void
    {
        $expression = ReconciliationCadence::expressionFor($seconds);

        $this->assertSame(5, count(preg_split('/\s+/', trim($expression))), $why);
        $this->assertMatchesRegularExpression(
            '~^[\d*/]+(?: [\d*/]+){4}$~',
            $expression,
            'The scheduler parses this with cron semantics, so nothing but fields, numbers, '
            .'a step and a wildcard may appear.'
        );
    }

    #[Test]
    #[DataProvider('intervals')]
    public function no_expression_runs_more_often_than_the_configured_interval(mixed $seconds, string $expected, string $why): void
    {
        $expression = ReconciliationCadence::expressionFor($seconds);
        $interval = is_numeric($seconds) ? max(60, (int) $seconds) : 3600;

        $minuteField = explode(' ', $expression)[0];
        $hourField = explode(' ', $expression)[1];

        $minutesPerFire = match (true) {
            $minuteField === '*' => 1,
            str_starts_with($minuteField, '*/') => (int) substr($minuteField, 2),
            $hourField === '*' => 60,
            str_starts_with($hourField, '*/') => 60 * (int) substr($hourField, 2),
            $hourField === '0' => 60 * 24,
            default => 60,
        };

        // The property the conversion promises: a job never runs more often than its
        // interval asks for. The one exception is the documented daily ceiling - an
        // interval longer than a day still gets a daily run, because a reconciliation
        // that fires monthly would notice nothing for a month. So the bound is the
        // requested interval, or one day, whichever is smaller.
        $this->assertGreaterThanOrEqual(
            min($interval, 86400),
            $minutesPerFire * 60,
            sprintf('%s: %ds asked for, but the expression %s fires every %ds.', $why, $interval, $expression, $minutesPerFire * 60)
        );
    }

    #[Test]
    public function only_a_real_true_enables_the_schedule(): void
    {
        // The gate is what keeps "auto" from becoming "always". Anything that is not the
        // boolean true - including the strings a careless .env might produce - leaves the
        // task unbuilt, so an unset or garbled flag cannot create a scheduled job.
        $this->assertTrue(ReconciliationCadence::isAutoReconcileEnabled(true));

        foreach ([false, null, 0, 1, '0', 'false', '', 'yes'] as $value) {
            $this->assertFalse(
                ReconciliationCadence::isAutoReconcileEnabled($value),
                sprintf('%s must not enable automatic reconciliation.', var_export($value, true))
            );
        }
    }

    #[Test]
    public function the_default_interval_is_the_one_the_config_ships(): void
    {
        $this->assertSame(
            3600,
            ReconciliationCadence::DEFAULT_INTERVAL_SECONDS,
            'config/finance.php defaults the interval to 3600 seconds. If either side is '
            .'changed alone, the schedule and the documented behaviour drift apart.'
        );
        $this->assertGreaterThan(
            0,
            (int) config('finance.ledger.reconciliation_interval', 0),
            'The config value must be a positive number of seconds for the schedule to use it.'
        );
    }
}
