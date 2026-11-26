<?php

declare(strict_types=1);

namespace App\Contracts\LotteryGames;

/**
 * The seam between this batch and the Batch-1 free-bet voucher wallet.
 *
 * WHY AN INTERFACE AND NOT A DIRECT CALL
 * Batch 1 owns the voucher lifecycle (issuance, expiry, consumption, reversal).
 * The digit-game lane must spend a voucher without owning it, and must keep
 * working identically when Batch 1 is not installed. The adapter resolves an
 * implementation named in config('lottery_games.funding.free_bet_provider');
 * when that class is absent the lane fails closed with
 * game_funding_unavailable instead of pretending a free bet was funded.
 *
 * IMPLEMENTATION CONTRACT
 *  - validateUsable() must be read-only and must not consume.
 *  - consume() must be idempotent per (userId, voucherId, idempotencyKey):
 *    the placement pipeline calls it exactly once, but a retried request must
 *    not be able to spend the same voucher twice.
 *  - both throw on refusal; neither returns a false-y "ok".
 */
interface FreeBetVoucherProvider
{
    /**
     * @return array{voucher_id: string, value: string, currency: string, expires_at: string|null}
     */
    public function validateUsable(int $userId, string $voucherId): array;

    /**
     * Spend the voucher once and return the funded amount.
     *
     * @return array{voucher_id: string, funded_amount: string, currency: string}
     */
    public function consume(int $userId, string $voucherId, string $idempotencyKey): array;

    /**
     * Give the spend back when the placement it funded did not happen.
     */
    public function reverse(int $userId, string $voucherId, string $idempotencyKey): void;
}
