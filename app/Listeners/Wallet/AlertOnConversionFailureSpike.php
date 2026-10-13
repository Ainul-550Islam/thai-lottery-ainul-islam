<?php

declare(strict_types=1);

namespace App\Listeners\Wallet;

use App\Enums\RiskLevel;
use App\Events\Wallet\ConversionRejected;
use App\Services\Observability\OperationalAlertService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escalates a burst of refusals to the operations channel.
 *
 * WHAT PROBLEM THIS SOLVES
 * Every individual refusal is correct behaviour: a member without balance is
 * refused, a member who has not verified is refused, and both are supposed to
 * happen. The defect this listener catches is a CORRELATED refusal — a rule
 * misconfigured, a fee disabled, a wallet type missing after a bad deploy. In
 * that state the platform is quietly telling every member "no" while each
 * request, examined alone, looks like normal policy enforcement.
 *
 * WHY THE THRESHOLD IS PER REFUSAL CODE
 * Counting all refusals together would mean a busy afternoon of underfunded
 * members drowns the one signal that matters, and an operator would learn to
 * ignore the alert. Counting per code means "insufficient_balance" can be noisy
 * without ever masking "fee_rule_missing" — and the codes that indicate a
 * deployment fault get their own, much lower threshold.
 *
 * WHY IT ROUTES THROUGH OperationalAlertService
 * That service already owns alert throttling and de-duplication for the finance
 * lane, so a spike produces one alert per cooldown window rather than one per
 * refused request. Re-implementing throttling here would produce exactly the
 * alert storm this listener exists to prevent.
 *
 * A FAILURE TO ALERT IS NOT A FAILURE TO REFUSE
 * Every path is guarded: if the alert service is unavailable, the refusal has
 * already been recorded by the audit listener and the member has already
 * received their answer. Nothing here may throw.
 */
class AlertOnConversionFailureSpike
{
    /**
     * Refusal codes that mean "the platform is broken", not "the member asked
     * for something they cannot have". These get a much lower threshold because
     * a single one of them is already unusual.
     *
     * @var list<string>
     */
    private const OPERATOR_FAULT_CODES = [
        'fee_rule_missing',
        'conversion_fee_rule_missing',
        'feature_disabled',
        'conversion_disabled',
        'conversion_wallet_lock_failed',
        'conversion_requires_transaction',
        'conversion_currency_mismatch',
        'conversion_same_wallet',
        'conversion_idempotency_conflict',
    ];

    public function __construct(
        private readonly OperationalAlertService $alerts,
    ) {
    }

    public function handle(ConversionRejected $event): void
    {
        try {
            $threshold = in_array($event->errorCode, self::OPERATOR_FAULT_CODES, true)
                ? (int) config('conversions.alerts.operator_fault_threshold', 1)
                : (int) config('conversions.alerts.refusal_spike_threshold', 25);

            $windowCount = $this->windowCount($event->errorCode);

            if ($windowCount < $threshold) {
                return;
            }

            $this->alerts->alertFinancialAnomaly(
                title: 'Wallet conversion refusals spiking',
                message: sprintf(
                    '%d conversions were refused with code [%s] in the last window (threshold %d). Direction: %s. This is either a demand surge or a misconfiguration; check the refusal dashboard before assuming demand.',
                    $windowCount,
                    $event->errorCode,
                    $threshold,
                    $event->direction->value,
                ),
                severity: in_array($event->errorCode, self::OPERATOR_FAULT_CODES, true)
                    ? RiskLevel::Critical
                    : RiskLevel::High,
                metadata: [
                    'error_code' => $event->errorCode,
                    'window_count' => $windowCount,
                    'threshold' => $threshold,
                    'direction' => $event->direction->value,
                    'channel' => $event->channel->value,
                    'context' => $event->context,
                ],
                // Five minutes of quiet per code: long enough that a genuine surge
                // produces one page, short enough that a persistent fault is not
                // buried by its own de-duplication.
                throttleSeconds: 300,
            );
        } catch (Throwable $exception) {
            Log::warning('conversion.alert_dispatch_failed', [
                'error_code' => $event->errorCode,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The refusal count for this code inside the metrics window.
     *
     * The counter is maintained by PublishConversionMetrics; when that listener
     * is not registered — or the cache driver is flushable — the count reads
     * zero and this listener stays silent rather than alerting on a number it
     * cannot vouch for.
     */
    private function windowCount(string $errorCode): int
    {
        return (int) (\Illuminate\Support\Facades\Cache::get('conversion:metrics:rejected:'.$errorCode) ?? 0);
    }
}
