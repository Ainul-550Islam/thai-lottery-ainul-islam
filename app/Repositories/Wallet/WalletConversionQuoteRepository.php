<?php

declare(strict_types=1);

namespace App\Repositories\Wallet;

use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\Wallet\ConversionException;
use App\Models\WalletConversionQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Persistence for conversion quotes.
 *
 * A quote is read twice in its life: once to be DISPLAYED (unlocked, cheap) and
 * once to be EXECUTED (locked, decisive). The execution read is the one that
 * matters, because two concurrent requests presenting the same quote reference
 * must not both consume it — that would be a double movement from a single
 * offer. `findByReferenceForUpdate` is therefore the method the execution path
 * uses, and `consume()` marks the quote in the same transaction that moves the
 * money.
 */
class WalletConversionQuoteRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WalletConversionQuote
    {
        return WalletConversionQuote::query()->create($attributes);
    }

    public function findByReference(string $reference): ?WalletConversionQuote
    {
        return WalletConversionQuote::query()->where('reference', $reference)->first();
    }

    public function findByReferenceForUpdate(string $reference): ?WalletConversionQuote
    {
        $this->assertTransactionBoundary();

        return WalletConversionQuote::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ensure the member has an available open-quote slot inside the caller's
     * active SQL transaction.
     */
    public function assertOpenSlotAvailableForExecution(int $userId, int $maxOpen, ?Carbon $cutoff = null): void
    {
        $this->assertTransactionBoundary();

        $effective = max(1, $maxOpen);
        $at = ($cutoff ?? Carbon::now())->copy()->startOfSecond();

        $ids = WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->where('status', 'open')
            ->whereNull('converted_id')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $at)
            ->orderBy('id')
            ->limit($effective)
            ->lockForUpdate()
            ->pluck('id');

        if ($ids->count() >= $effective) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::RateLimited->value,
                WalletConversionRejectionReason::RateLimited->label().'.',
            );
        }
    }

    /**
     * Bind a quote to the conversion it produced. Called inside the movement's
     * transaction, under the row lock taken by findByReferenceForUpdate().
     */
    public function consume(WalletConversionQuote $quote, int $conversionId): WalletConversionQuote
    {
        $this->assertTransactionBoundary();

        $quote->forceFill([
            'converted_id' => $conversionId,
            'consumed_at' => Carbon::now(),
            'status' => 'consumed',
        ])->save();

        return $quote->refresh();
    }

    /**
     * Quotes belonging to one member that are still open and unexpired.
     *
     * @return Collection<int, WalletConversionQuote>
     */
    public function openForUser(int $userId, int $limit = 10): Collection
    {
        return WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->open()
            ->where('expires_at', '>', Carbon::now()->copy()->startOfSecond())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function countOpenForUser(int $userId): int
    {
        return WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->open()
            ->where('expires_at', '>', Carbon::now()->copy()->startOfSecond())
            ->count();
    }

    /**
     * Expire every open quote that is past its expiry instant. Returns the
     * number of rows affected so the command can report what it did.
     */
    public function expireStale(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        return WalletConversionQuote::query()
            ->open()
            ->where('status', '=', 'open')
            ->where('expires_at', '<=', $now)
            ->update([
                'status' => 'expired',
                'updated_at' => $now,
            ]);
    }

    /**
     * @return Collection<int, WalletConversionQuote>
     */
    public function expiredForUser(int $userId, int $limit = 20): Collection
    {
        return WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->where('status', 'expired')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function assertTransactionBoundary(): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            throw ConversionException::failure(
                'transaction_required',
                'Decisive quote operation requires an active SQL transaction.',
            );
        }
    }
}
