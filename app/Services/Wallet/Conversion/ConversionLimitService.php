<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\Currency;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Support\Wallet\ConversionCurrency;
use App\ValueObjects\ConversionLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

/**
 * The rolling-window half of the conversion limit envelope.
 *
 * WHY THIS CLASS EXISTS AT ALL
 * ConversionLimits answers the per-transaction questions (min/max) from
 * configuration alone. The daily and monthly ceilings cannot be answered from
 * configuration: they need the member's own conversion history. This service is
 * that second half, and it is the only place in the domain that is allowed to
 * turn history into a number a limit decision is made from.
 *
 * WHY THE TOTALS ARE SUMMED IN PHP AND NOT WITH SQL SUM()
 * Two reasons, both of which have bitten real ledgers:
 *
 *  1. SQL SUM() over a decimal column is a float on SQLite and a lossy DECIMAL
 *     on some MySQL configurations. Summing 751 sub-cent-precision amounts silently drifts,
 *     and a drift of one cent in a *limit* total is a decision to admit money
 *     that the ceiling forbids. The rows are read as strings and added with
 *     bcadd, so every cent survives regardless of engine.
 *  2. A SUM() cannot tell "no rows" from "unprovable rows". Here an eligible row
 *     whose amount is not a positive exact decimal (a zero, a negative, a float
 *     artefact left by an older writer) makes the whole total UNPROVEN, and an
 *     unproven total must refuse money rather than be treated as head-room.
 *
 * WHY THE WINDOWS ARE ROLLING AND NOT CALENDAR
 * "Daily" is a rolling 24 hours and "monthly" a rolling 30 days, both inclusive
 * at one-second resolution. A calendar-day window lets a member spend the whole
 * ceiling at 23:59 and the whole ceiling again at 00:01, which is precisely the
 * behaviour a limit exists to prevent. Both windows therefore share one cutoff
 * rule — truncate the current instant to the second, subtract the length, and
 * include that exact second — so a row written one second before the boundary
 * is inside the window in both.
 *
 * WHAT THIS SERVICE DOES NOT DO
 * It writes nothing, locks nothing and never repairs a row it considers
 * unproven. Refusing with `conversion_limits_unproven` is the whole remedy: the
 * books are the authority, and a service that "fixed" a total to make it
 * summable would be inventing head-room out of an accounting defect.
 */
final class ConversionLimitService
{
    /** A rolling day, in hours. */
    public const DAILY_WINDOW_HOURS = 24;

    /** A rolling month, in days. */
    public const MONTHLY_WINDOW_DAYS = 30;

    /**
     * The statuses whose amount_in is really part of the member's conversion
     * history. `reversed` is included on purpose: the money DID move, and
     * reversing it is a second movement rather than an erasure of the first.
     * `pending`, `processing`, `failed`, `cancelled` and `quoted` are not here
     * because nothing settled in them.
     */
    private const SETTLED_STATUSES = ['completed', 'reversed'];

    public function __construct(private readonly Config $config) {}

    public function limits(Currency $currency): ConversionLimits
    {
        return ConversionLimits::fromConfig((array) $this->config->get('conversions.limits', []), $currency);
    }

    /**
     * The member's settled conversion volume inside the rolling day.
     */
    public function dailyTotal(int $userId, ?Currency $currency = null): string
    {
        $now = CarbonImmutable::now();

        return $this->windowTotal($userId, $currency, $now->subHours(self::DAILY_WINDOW_HOURS), $now);
    }

    /**
     * The member's settled conversion volume inside the rolling month.
     */
    public function monthlyTotal(int $userId, ?Currency $currency = null): string
    {
        $now = CarbonImmutable::now();

        return $this->windowTotal($userId, $currency, $now->subDays(self::MONTHLY_WINDOW_DAYS), $now);
    }

    /**
     * The head-room left in the rolling day, or null when the day is not
     * enforced. The UI prints this, so it must agree with the engine exactly —
     * it is computed from the same total and the same ceiling.
     */
    public function remainingDaily(int $userId, ?Currency $currency = null): ?string
    {
        $currency ??= ConversionCurrency::configured();
        $limits = $this->limits($currency);

        return $limits->remainingDaily($this->dailyTotal($userId, $currency));
    }

    /**
     * Advisory enforcement.
     *
     * Used by the preview/quote surfaces, which legitimately run outside a
     * transaction. It still refuses when it can see a framework transaction
     * counter that no longer corresponds to a real SQL transaction: that state
     * means a caller has committed or rolled back behind the framework's back,
     * and the history it just read is not the history the next writer will act
     * on.
     */
    public function assertAllowed(int $userId, string $amount, ?Currency $currency = null): void
    {
        $this->assertNoOrphanedFrameworkCounter();
        $this->assertWithinCeilings($userId, $amount, $currency);
    }

    /**
     * Execution enforcement.
     *
     * A conversion that is about to move money must be inside a real SQL
     * transaction, because the totals read here are only stable while the rows
     * they were read from cannot be changed by another writer. `DB::transactionLevel()`
     * alone is not that proof: it is a counter the framework keeps, and a counter
     * can be incremented without a BEGIN ever reaching the database.
     */
    public function assertAllowedForExecution(int $userId, string $amount, ?Currency $currency = null): void
    {
        $this->assertNoOrphanedFrameworkCounter();

        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure(
                'transaction_required',
                'Conversion execution must run inside a real database transaction.',
                ['user_id' => $userId],
            );
        }

        $this->assertWithinCeilings($userId, $amount, $currency);
    }

    /**
     * True when the member's history for this currency is summable at all.
     * Exposed so the console and the page can say "unproven" instead of
     * printing a number they cannot stand behind.
     */
    public function hasProvableHistory(int $userId, ?Currency $currency = null): bool
    {
        try {
            $currency ??= ConversionCurrency::configured();
            $this->dailyTotal($userId, $currency);
            $this->monthlyTotal($userId, $currency);

            return true;
        } catch (ConversionException) {
            return false;
        }
    }

    /**
     * The shared decision: read both rolling totals, then apply the ceilings.
     *
     * The attempted figure in the refusal is TOTAL + AMOUNT, not the amount
     * alone, because that is the number the ceiling is compared against and the
     * number support needs to see. The daily ceiling is checked first so a
     * member who has breached both is told the tighter, sooner-resetting limit.
     */
    private function assertWithinCeilings(int $userId, string $amount, ?Currency $currency): void
    {
        if ($userId <= 0 || preg_match('/^\d{1,18}(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            throw ConversionException::failure(
                'amount_invalid',
                'A positive exact decimal amount is required.',
                ['amount' => $amount],
            );
        }

        $currency ??= ConversionCurrency::configured();
        $limits = $this->limits($currency);

        $daily = $this->dailyTotal($userId, $currency);
        $monthly = $this->monthlyTotal($userId, $currency);

        if ($limits->enforcesDailyLimit() && bccomp(bcadd($daily, $amount, 2), (string) $limits->dailyLimit(), 2) === 1) {
            throw ConversionLimitExceededException::forDailyWindow(
                (string) $limits->dailyLimit(),
                bcadd($daily, $amount, 2),
                $currency->value,
            );
        }

        if ($limits->enforcesMonthlyLimit() && bccomp(bcadd($monthly, $amount, 2), (string) $limits->monthlyLimit(), 2) === 1) {
            throw ConversionLimitExceededException::forMonthlyWindow(
                (string) $limits->monthlyLimit(),
                bcadd($monthly, $amount, 2),
                $currency->value,
            );
        }
    }

    /**
     * An exact, engine-independent sum of the member's settled volume in one
     * currency between two instants, both inclusive at one-second resolution.
     */
    private function windowTotal(
        int $userId,
        ?Currency $currency,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): string {
        // The denomination is not optional. A total is a number of something, and
        // comparing a THB total against a configured USD ceiling would admit or
        // refuse on a quantity that means nothing.
        $currency ??= ConversionCurrency::configured();

        if ($userId <= 0) {
            throw ConversionException::failure(
                'conversion_limits_unproven',
                'A conversion limit total cannot be computed without a member.',
                ['user_id' => $userId],
            );
        }

        $rows = DB::table('wallet_conversions')
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->whereIn('status', self::SETTLED_STATUSES)
            ->whereNotNull('completed_at')
            ->whereNull('reversal_of_id')
            ->where('completed_at', '>=', $from->startOfSecond()->toDateTimeString())
            ->where('completed_at', '<=', $to->startOfSecond()->toDateTimeString())
            ->orderBy('id')
            ->select(['id', 'amount_in'])
            ->get();

        $total = '0.00';

        foreach ($rows as $row) {
            $amount = (string) $row->amount_in;

            // A settled conversion whose amount is not a positive exact decimal is
            // not a fact these books can add up. Zero and negative amounts are
            // included in that judgement: neither is a conversion, and treating
            // them as head-room would let a broken writer erase a ceiling.
            if (preg_match('/^\d{1,18}(\.\d{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) !== 1) {
                throw ConversionException::failure(
                    'conversion_limits_unproven',
                    'The stored conversion history contains an amount that cannot be proven, so no limit total is trustworthy.',
                    ['conversion_id' => (int) $row->id, 'amount_in' => $amount, 'currency' => $currency->value],
                );
            }

            $total = bcadd($total, $amount, 2);
        }

        return $total;
    }

    /**
     * The framework's transaction counter is bookkeeping; only the driver knows
     * whether a transaction is actually open. When the two disagree the caller
     * has lost its atomicity and nothing it reads here can be relied on.
     */
    private function assertNoOrphanedFrameworkCounter(): void
    {
        if (DB::transactionLevel() >= 1 && ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure(
                'transaction_required',
                'A framework transaction counter without an open database transaction cannot authorize a conversion limit decision.',
            );
        }
    }
}
