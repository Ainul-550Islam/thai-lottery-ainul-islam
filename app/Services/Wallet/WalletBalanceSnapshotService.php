<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletBalanceSnapshot;
use Illuminate\Support\Carbon;

/**
 * The SOLE writer of wallet_balance_snapshots.
 *
 * READS THE WALLET, NEVER WRITES IT
 * The balance written here is read from wallets.balance — a column owned by
 * WalletService and reached only through the model. This class never credits,
 * debits, locks or unlocks anything, and it never posts a ledger entry. It takes a
 * photograph of a number somebody else is responsible for.
 *
 * IDEMPOTENT PER DAY BY CONSTRUCTION
 * The unique key is (member_id, wallet_type, snapshot_date) and the write is an
 * upsert, so running the job twice, three times, or after a crash produces one
 * row per day. There is no "already ran" flag and no distributed lock: the
 * database constraint is the answer, which is the only kind of answer that
 * survives two application servers starting at once.
 *
 * CHUNKED
 * Members are iterated in id chunks so a table of a million rows does not become
 * one transaction, and the job can resume where it stopped without any bookkeeping
 * (a re-run simply re-upserts the days it already covered).
 */
final class WalletBalanceSnapshotService
{
    /**
     * Capture one member's wallets for the given day.
     *
     * @return int number of rows written
     */
    public function captureForMember(int $memberId, ?Carbon $day = null): int
    {
        /*
         * THE DATE IS A CARBON INSTANCE, NOT A STRING, AND THAT IS A BUG FIX.
         *
         * `snapshot_date` carries a `date` cast, which serialises to
         * "Y-m-d 00:00:00" on WRITE. Passing the string "Y-m-d" as the match key
         * therefore compares "2026-11-13" against a stored "2026-11-13 00:00:00" -
         * equal in MySQL, NOT equal in SQLite - so updateOrCreate silently missed,
         * took the INSERT branch, and hit the unique key. A scheduled job that
         * throws on its second run of the day is a job that never produces a
         * baseline, and the trend figure quietly disappears.
         *
         * Handing updateOrCreate the same Carbon instance the cast will serialise
         * makes the WHERE and the INSERT agree on every driver.
         */
        $moment = $day ?? Carbon::now();
        $date = $moment->copy()->startOfDay();
        $now = $moment->copy()->startOfDay();

        /** @var \Illuminate\Support\Collection<int, Wallet> $wallets */
        $wallets = Wallet::query()->where('user_id', $memberId)->get();

        $written = 0;

        foreach ($wallets as $wallet) {
            $type = $wallet->type;

            if (! $type instanceof WalletType) {
                $type = WalletType::tryFrom((string) $type) ?? WalletType::Primary;
            }

            WalletBalanceSnapshot::query()->updateOrCreate(
                [
                    'member_id' => $memberId,
                    'wallet_type' => $type->value,
                    'snapshot_date' => $date,
                ],
                [
                    'currency' => (string) $wallet->currency?->value ?? (string) $wallet->currency ?? 'THB',
                    // Decimal casts hand back strings; concatenating is safe and
                    // normalises to 2dp without a float ever appearing.
                    'balance' => bcadd((string) $wallet->balance, '0.00', 2),
                    'locked_balance' => bcadd((string) $wallet->locked_balance, '0.00', 2),
                    'captured_at' => $now,
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * Capture every wallet in chunks, optionally limited to a member id window.
     *
     * The chunk callback form is used so the job can report progress to a queue
     * dashboard without holding a cursor open across a long run.
     *
     * @return array{members: int, rows: int}
     */
    public function captureAll(?Carbon $day = null, int $chunk = 500, ?int $afterMemberId = null): array
    {
        $members = 0;
        $rows = 0;

        $query = User::query()->select('id')->orderBy('id');

        if ($afterMemberId !== null) {
            $query->where('id', '>', $afterMemberId);
        }

        $query->chunkById(max(1, $chunk), function ($batch) use ($day, &$members, &$rows): void {
            foreach ($batch as $user) {
                $members++;
                $rows += $this->captureForMember((int) $user->id, $day);
            }
        });

        return ['members' => $members, 'rows' => $rows];
    }

    /**
     * The last snapshot at or before the horizon, for the trend calculation.
     *
     * Lives here (rather than in WalletOverviewService) so the table has exactly
     * one owner: the writer and the reader of a table are the same class, and the
     * overview composes this service instead of querying the model itself.
     */
    public function baselineFor(int $memberId, WalletType $type, Carbon $horizon): ?WalletBalanceSnapshot
    {
        /** @var WalletBalanceSnapshot|null $snapshot */
        $snapshot = WalletBalanceSnapshot::query()
            ->forMember($memberId)
            ->ofType($type)
            ->atOrBefore($horizon)
            ->orderByDesc('snapshot_date')
            ->first();

        return $snapshot;
    }
}
