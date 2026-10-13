<?php

declare(strict_types=1);

namespace App\Listeners\Wallet;

use App\Events\Wallet\ConversionCompleted;
use App\Events\Wallet\ConversionRejected;
use App\Events\Wallet\ConversionReversed;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mirrors every conversion outcome into the platform-wide audit log.
 *
 * WHY THERE ARE TWO AUDIT TABLES AND WHY THIS IS NOT DUPLICATION
 * `wallet_conversion_audits` is the feature's own record: it holds the full
 * check map, the balances before and after, and the fee rule version, and it is
 * written inside the money transaction because a completed movement must be
 * explainable by the same commit that produced it. `audit_logs` is the
 * platform's operator-facing trail, the one an auditor already knows how to
 * query across every module. Neither replaces the other, and losing the
 * cross-module view of conversions would mean an investigator had to know this
 * feature exists in order to find its history.
 *
 * WHY A FAILURE HERE CANNOT BREAK A CONVERSION
 * The listener runs AFTER the money transaction has committed, and it swallows
 * its own failures after logging them. The alternative — letting an audit write
 * throw — would mean a successful money movement surfacing to the member as an
 * error, and a member who believes the conversion failed is a member who tries
 * again. The trade is deliberate: a missing audit row is recoverable from the
 * feature's own table; a duplicate conversion is not.
 *
 * IT IS SAFE WHEN `audit_logs` IS ABSENT
 * The table's existence is checked once per call. A deployment migrating this
 * feature ahead of the audit table would otherwise fail every conversion
 * outright, which is the worst possible ordering. Absence is logged as a
 * warning once, at the moment it is noticed, rather than silently ignored.
 */
class RecordConversionAuditTrail
{
    public function handle(ConversionCompleted|ConversionReversed|ConversionRejected $event): void
    {
        try {
            if (! Schema::hasTable('audit_logs')) {
                Log::warning('conversion.audit_log_table_missing', [
                    'hint' => 'audit_logs is absent; the conversion outcome was recorded only in wallet_conversion_audits.',
                    'event' => $event::class,
                ]);

                return;
            }

            [$action, $reference, $userId, $payload] = $this->describe($event);

            AuditLog::query()->create([
                'user_id' => $userId,
                'action' => $action,
                // Risk level is carried so an operator's alert rules can filter the
                // trail without parsing the action string: a completed movement is
                // routine, a reversal is an intervention worth surfacing.
                'risk_level' => $event instanceof ConversionReversed ? 'medium' : 'low',
                'auditable_type' => 'wallet_conversion',
                'auditable_id' => $reference,
                'description' => $this->describeSentence($action, $reference),
                'metadata' => $payload,
                'ip_address' => null,
            ]);
        } catch (Throwable $exception) {
            // Never rethrow: the money has already moved.
            Log::error('conversion.audit_trail_failed', [
                'event' => $event::class,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: array<string, mixed>}
     */
    private function describe(ConversionCompleted|ConversionReversed|ConversionRejected $event): array
    {        if ($event instanceof ConversionCompleted) {
            return [
                'wallet_conversion.completed',
                $event->reference,
                $event->userId,
                $event->toArray(),
            ];
        }

        if ($event instanceof ConversionReversed) {
            return [
                'wallet_conversion.reversed',
                $event->reversalReference,
                $event->userId,
                $event->toArray(),
            ];
        }

        return [
            'wallet_conversion.rejected',
            $event->channel->value.'|'.$event->direction->value,
            $event->userId,
            $event->toArray(),
        ];
    }

    /**
     * A one-line description an operator can read in the audit table without
     * opening the payload. Deliberately free of balances: the numbers live in
     * `metadata` for those who need them, and a description that quoted a
     * balance would put money figures into every screen that lists audit rows.
     */
    private function describeSentence(string $action, string $reference): string
    {
        return match ($action) {
            'wallet_conversion.completed' => 'Cash/Win wallet conversion completed.',
            'wallet_conversion.reversed' => 'Cash/Win wallet conversion reversed by an operator.',
            default => 'Cash/Win wallet conversion refused.',
        }.' Ref: '.$reference;
    }
}
