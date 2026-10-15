<?php

declare(strict_types=1);

namespace App\Services\Concerns;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\Log;

/**
 * Shared behaviour for the Part 62 read-only guards.
 *
 * Every guard answers the same three questions in the same way, so the mode
 * resolution and the audit row live here rather than being re-typed three
 * times: one place to change the default, one row shape to query.
 *
 * What this trait is NOT: a base class with behaviour of its own. It resolves
 * configuration and writes records. It cannot mutate a draw, a payout or an
 * exclusion, because nothing in it does.
 */
trait GuardedObservation
{
    protected const GUARD_MODE_LOG = 'log';

    protected const GUARD_MODE_ENFORCE = 'enforce';

    protected const GUARD_MODE_OFF = 'off';

    /** The key this guard uses inside config('settlement-guards.guards'). */
    abstract protected function guardKey(): string;

    /**
     * Resolve the effective mode. A per-guard setting wins, otherwise the
     * global default. Anything unrecognised degrades to `log`, never to `off`:
     * a typo in .env must not silently switch a guard off.
     */
    protected function guardMode(): string
    {
        if (! (bool) config('settlement-guards.enabled', true)) {
            return self::GUARD_MODE_OFF;
        }

        $own = config('settlement-guards.guards.'.$this->guardKey());
        $mode = is_string($own) && $own !== '' ? $own : (string) config('settlement-guards.mode', self::GUARD_MODE_LOG);

        return match ($mode) {
            self::GUARD_MODE_OFF => self::GUARD_MODE_OFF,
            self::GUARD_MODE_ENFORCE => self::GUARD_MODE_ENFORCE,
            default => self::GUARD_MODE_LOG,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function recordObservation(
        string $guard,
        string $verdict,
        array $context,
        ?int $userId = null,
    ): void {
        $metadata = [
            'operation' => (string) config('settlement-guards.operation', 'settlement_guard_observation'),
            'guard' => $guard,
            'mode' => $this->guardMode(),
            'verdict' => $verdict,
            'context' => $context,
        ];

        Log::info('settlement guard '.$guard.' → '.$verdict, $context);

        $audit = app(AuditLogService::class);

        $audit->log(
            $userId,
            AuditAction::SecurityAlert,
            $verdict === 'agree' ? RiskLevel::Low : RiskLevel::High,
            null,
            'Settlement guard '.$guard.': '.$verdict,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function verdict(string $guard, string $verdict, array $context): array
    {
        return array_merge(['guard' => $guard, 'verdict' => $verdict, 'mode' => $this->guardMode()], $context);
    }
}
