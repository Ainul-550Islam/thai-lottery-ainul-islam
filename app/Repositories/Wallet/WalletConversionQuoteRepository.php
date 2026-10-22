<?php

declare(strict_types=1);

namespace App\Repositories\Wallet;

use App\Models\WalletConversionQuote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        return WalletConversionQuote::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Bind a quote to the conversion it produced. Called inside the movement's
     * transaction, under the row lock taken by findByReferenceForUpdate().
     */
    public function consume(WalletConversionQuote $quote, int $conversionId): WalletConversionQuote
    {
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
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function countOpenForUser(int $userId): int
    {
        return WalletConversionQuote::query()
            ->where('user_id', $userId)
            ->open()
            ->where('expires_at', '>', Carbon::now())
            ->count();
    }

    /**
     * Expire every open quote that is past its expiry instant. Returns the
     * number of rows affected so the command can report what it did.
     */
    public function expireStale(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        // WHY `status = 'open'` AS WELL AS THE SCOPE
        // `open()` answers "is this quote still spendable?" — and it is still true
        // of a quote whose clock ran out but which nobody has relabelled yet. The
        // sweep therefore matched the same expired row on every tick and reported
        // `1` each time it ran, forever. That number is the platform's only signal
        // of how many members are holding a dead price, and a counter that never
        // falls to zero cannot show a backlog starting or ending. A quote is
        // expired exactly once: while its status is still `open`.
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
}
