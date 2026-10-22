<?php

declare(strict_types=1);

namespace App\Console\Scheduling;

/**
 * Turns the configured ledger-reconciliation interval into a cron expression.
 *
 * `finance.ledger.reconciliation_interval` is a number of seconds; the scheduler
 * speaks cron. Before this class existed both that key and its sibling
 * `finance.ledger.auto_reconcile` were read by nothing at all, which meant the
 * configuration was describing a control that never happened: reconciliation ran
 * only when a person remembered to run it, while the config said it was automatic
 * and hourly.
 *
 * The mapping is deliberately narrow, because a cron field cannot express every
 * interval faithfully and a silently-wrong cadence is worse than a refused one.
 * (The expressions themselves are written as strings further down: this comment
 * describes them in words, because a step field is spelled with two characters
 * that would close this comment block if they appeared here.)
 *
 *   - a whole number of minutes that divides the hour evenly becomes a minute step
 *     field, so the runs land on exact wall-clock minutes;
 *   - a whole number of hours becomes an hour step field, and one hour is the plain
 *     hourly expression;
 *   - anything else is rounded UP to the next cadence a cron field can express, so a
 *     job never runs more often than its interval asked for;
 *   - an interval longer than a day is capped at daily, because the alternative is a
 *     reconciliation that notices nothing for a month.
 *
 * The fallback is not a guess about what the operator wanted. It is the reading
 * that keeps the detective control running - and the reason it is a fallback is
 * recorded here rather than hidden in a ternary. Malformed input (zero, negative,
 * non-numeric) reaches the same hourly answer, matching how the rest of this
 * application treats an unreadable money setting: keep the control on, tighten it
 * to the safest documented cadence, and let the config's own tests complain.
 */
final class ReconciliationCadence
{
    /**
     * The step values a cron minute field can carry that divide the hour evenly. An
     * interval that is not one of these is rounded up to the next one, so a cadence is
     * never expressed as a step that lands on a different minute each hour.
     *
     * @var list<int>
     */
    private const MINUTE_STEPS = [1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30, 60];

    /**
     * The interval the configuration declares when nothing is set: one hour.
     */
    public const DEFAULT_INTERVAL_SECONDS = 3600;

    /**
     * The expression the fallback uses. Hourly, because that is what the shipped
     * default means, so a rejected value cannot leave the control running more
     * often than the operator asked for.
     */
    public const FALLBACK_EXPRESSION = '0 * * * *';

    /**
     * Below one minute the scheduler cannot keep up: it is invoked once a minute.
     * Treating "10 seconds" as "every minute" is the honest ceiling, not a lie
     * about sub-minute cadence.
     */
    private const SHORTEST_EXPRESSIBLE_SECONDS = 60;

    /**
     * How many missed runs a control is allowed before the freshness check calls it
     * stale.
     *
     * The operational alert lane decides whether to raise a HIGH-severity audit row
     * saying "Financial Reconciliation Stale", and until now it answered from a
     * literal 24.0 hours in SystemHealthService while this file's own config key said
     * the control runs hourly. Those two numbers are about the same fact, and one of
     * them was decorative: a reconciliation that had not run in a whole day was
     * reported as merely "warn"-free for 23 of those 24 hours, and an operator who set
     * a four-hour interval got a threshold that ignored them.
     *
     * The bound is therefore derived from the cadence, with three missed runs as the
     * grace: one late tick is not an incident, and a control that has missed every
     * scheduled run for a full interval-plus is exactly when an operator wants the
     * alarm. The floor is one interval, so a very tight cadence cannot turn a single
     * slow queue into a HIGH alert about the ledger.
     */
    public const STALENESS_GRACE_FACTOR = 3;

    /**
     * Never call a reconciliation stale faster than one declared interval.
     */
    public const MINIMUM_STALENESS_SECONDS = self::DEFAULT_INTERVAL_SECONDS;

    /**
     * The staleness bound for a reconciliation that runs every $seconds.
     *
     * @param  mixed  $seconds  the same unvalidated config value expressionFor() takes
     */
    public static function stalenessThresholdSeconds(mixed $seconds): int
    {
        $interval = is_numeric($seconds) ? (int) $seconds : 0;

        if ($interval < self::SHORTEST_EXPRESSIBLE_SECONDS) {
            $interval = self::DEFAULT_INTERVAL_SECONDS;
        }

        return max(self::MINIMUM_STALENESS_SECONDS, $interval * self::STALENESS_GRACE_FACTOR);
    }

    /**
     * @param  mixed  $seconds  whatever the config holds; the cast is the validation
     */
    public static function expressionFor(mixed $seconds): string
    {
        $interval = is_numeric($seconds) ? (int) $seconds : 0;

        if ($interval < self::SHORTEST_EXPRESSIBLE_SECONDS) {
            $interval = self::DEFAULT_INTERVAL_SECONDS;
        }

        // Anything that is not a whole number of minutes cannot be a cron cadence at
        // all: the scheduler is invoked once a minute. Round up. Rounding down would
        // mean running more often than the operator asked for, and "up" is the only
        // direction that cannot turn a 60-minute job into a 55-minute one.
        $seconds = (int) (self::SHORTEST_EXPRESSIBLE_SECONDS * ceil($interval / self::SHORTEST_EXPRESSIBLE_SECONDS));
        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            foreach (self::MINUTE_STEPS as $step) {
                if ($step >= $minutes) {
                    return $step === 60
                        ? self::FALLBACK_EXPRESSION
                        : sprintf('*/%d * * * *', $step);
                }
            }
        }

        if ($minutes % 60 !== 0) {
            // Ninety minutes is not expressible as a step field; two hours is. Again
            // the rounding is upward, for the same reason.
            $hours = intdiv($minutes, 60) + 1;

            return $hours === 1
                ? self::FALLBACK_EXPRESSION
                : self::hourlyOrDaily($hours);
        }

        return self::hourlyOrDaily(intdiv($minutes, 60));
    }

    /**
     * One expression for the two hour-shaped cases, so the daily ceiling and the reason
     * for it are written once.
     *
     * An interval longer than a day cannot be expressed by a step field without
     * drifting across midnight. The choice is then between a cadence that fires sooner
     * than asked (daily) and one that fires far too late (monthly). For a control whose
     * whole job is to notice that the books disagree, "notices late" is the worse error:
     * a monthly reconciliation leaves a discrepancy in place for a month. So the
     * cadence is capped at daily, and this comment is the place that trade-off is
     * recorded rather than discovered in a diff.
     */
    private static function hourlyOrDaily(int $hours): string
    {
        if ($hours <= 1) {
            return self::FALLBACK_EXPRESSION;
        }

        if ($hours > 23) {
            return '0 0 * * *';
        }

        return sprintf('0 */%d * * *', $hours);
    }

    /**
     * Whether the scheduled reconciliation should exist at all.
     *
     * Only the boolean true enables it. config/finance.php already casts the env value
     * with (bool), so there is no second spelling of "on" for this to agree with, and a
     * permissive check here would be dead code that quietly widens what counts as consent:
     * an unset key, a typo, or a string must not create a scheduled job.
     */
    public static function isAutoReconcileEnabled(mixed $flag): bool
    {
        return $flag === true;
    }

    private function __construct()
    {
        // A namespace for two pure functions. Nothing to instantiate.
    }
}
