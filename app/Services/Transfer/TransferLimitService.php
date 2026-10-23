<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\Enums\Currency;
use App\Enums\TransferDirection;
use App\Exceptions\BalanceTransferException;
use App\Models\BalanceTransfer;
use Illuminate\Support\Carbon;

/**
 * Rolling 24-hour velocity, volume, and cooldown enforcement for balance transfers.
 */
final class TransferLimitService
{
    /**
     * Compute the remaining 24h count, 24h volume, and cooldown seconds for a player and direction.
     *
     * @return array{count_remaining: int|null, amount_remaining: string|null, cooldown_seconds_remaining: int}
     */
    public function remainingAllowance(int $userId, TransferDirection $direction): array
    {
        $dailyMaxCount = $this->dailyCountLimit($direction);
        $dailyMaxAmount = $this->dailyAmountLimit($direction);
        $cooldownSeconds = max(0, (int) config("transfer.directions.{$direction->value}.cooldown_seconds", 0));

        $now = Carbon::now();
        $windowStart = $now->copy()->subHours(24);

        $recentSettled = BalanceTransfer::query()
            ->forUser($userId)
            ->inDirection($direction)
            ->settled()
            ->where('completed_at', '>=', $windowStart)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get()
            ->filter(static fn (BalanceTransfer $row): bool => ! (bool) (($row->metadata ?? [])['is_reversal'] ?? false))
            ->values();

        $usedCount = $recentSettled->count();
        $usedAmount = '0.00';
        foreach ($recentSettled as $row) {
            $usedAmount = bcadd($usedAmount, bcadd((string) $row->amount, '0', 2), 2);
        }

        $countRemaining = $dailyMaxCount > 0
            ? max(0, $dailyMaxCount - $usedCount)
            : null;

        $amountRemaining = null;
        if (bccomp($dailyMaxAmount, '0.00', 2) === 1) {
            $diff = bcsub($dailyMaxAmount, $usedAmount, 2);
            $amountRemaining = bccomp($diff, '0.00', 2) >= 0 ? $diff : '0.00';
        }

        $cooldownRemaining = 0;
        if ($cooldownSeconds > 0) {
            $latest = $recentSettled->first();
            if (! $latest instanceof BalanceTransfer) {
                $latest = BalanceTransfer::query()
                    ->forUser($userId)
                    ->inDirection($direction)
                    ->settled()
                    ->whereNotNull('completed_at')
                    ->orderByDesc('completed_at')
                    ->orderByDesc('id')
                    ->get()
                    ->first(static fn (BalanceTransfer $row): bool => ! (bool) (($row->metadata ?? [])['is_reversal'] ?? false));
            }

            if ($latest instanceof BalanceTransfer && $latest->completed_at !== null) {
                $elapsed = (int) $latest->completed_at->diffInSeconds($now, false);
                if ($elapsed < $cooldownSeconds) {
                    $cooldownRemaining = max(1, $cooldownSeconds - max(0, $elapsed));
                }
            }
        }

        return [
            'count_remaining' => $countRemaining,
            'amount_remaining' => $amountRemaining,
            'cooldown_seconds_remaining' => $cooldownRemaining,
        ];
    }

    /**
     * Assert that a transfer of $amount in $direction is within cooldown, daily count, and daily volume limits.
     *
     * @throws BalanceTransferException
     */
    public function assertWithinLimits(
        int $userId,
        TransferDirection $direction,
        string $amount,
        Currency $currency = Currency::THB,
    ): void {
        $allowance = $this->remainingAllowance($userId, $direction);

        if ($allowance['cooldown_seconds_remaining'] > 0) {
            throw BalanceTransferException::coolingDown(
                $allowance['cooldown_seconds_remaining'],
                $direction->value,
            );
        }

        $dailyMaxCount = $this->dailyCountLimit($direction);
        if ($allowance['count_remaining'] !== null && $allowance['count_remaining'] <= 0) {
            throw BalanceTransferException::dailyCountReached(
                $dailyMaxCount,
                $direction->value,
            );
        }

        $dailyMaxAmount = $this->dailyAmountLimit($direction);
        $scaledAmount = bcadd($amount, '0', 2);
        if ($allowance['amount_remaining'] !== null && bccomp($scaledAmount, $allowance['amount_remaining'], 2) === 1) {
            throw BalanceTransferException::dailyAmountReached(
                $dailyMaxAmount,
                $allowance['amount_remaining'],
                $currency->value,
                $direction->value,
            );
        }
    }

    /**
     * Return any limit blocker machine codes for a quote without throwing.
     *
     * @return list<string>
     */
    public function quoteBlockers(int $userId, TransferDirection $direction, string $amount): array
    {
        $blockers = [];
        $allowance = $this->remainingAllowance($userId, $direction);

        if ($allowance['cooldown_seconds_remaining'] > 0) {
            $blockers[] = 'transfer_cooling_down';
        }

        if ($allowance['count_remaining'] !== null && $allowance['count_remaining'] <= 0) {
            $blockers[] = 'transfer_daily_count_reached';
        }

        $scaledAmount = bcadd($amount, '0', 2);
        if ($allowance['amount_remaining'] !== null && bccomp($scaledAmount, $allowance['amount_remaining'], 2) === 1) {
            $blockers[] = 'transfer_daily_amount_reached';
        }

        return $blockers;
    }

    private function dailyCountLimit(TransferDirection $direction): int
    {
        $configured = config("transfer.directions.{$direction->value}.daily_count_limit");
        if ($configured === null) {
            $configured = config("transfer.directions.{$direction->value}.daily_max_count", 0);
        }

        return (int) $configured;
    }

    private function dailyAmountLimit(TransferDirection $direction): string
    {
        $configured = config("transfer.directions.{$direction->value}.daily_amount_limit");
        if ($configured === null) {
            $configured = config("transfer.directions.{$direction->value}.daily_max_amount", '0.00');
        }

        $raw = (string) $configured;

        return preg_match('/^\d+(?:\.\d{1,2})?$/', trim($raw))
            ? bcadd(trim($raw), '0', 2)
            : '0.00';
    }
}
