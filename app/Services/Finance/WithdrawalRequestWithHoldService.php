<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;

/**
 * Atomic fresh initial-hold ownership for the member API.
 * Approval transfers this original to canonical ownership without holding twice.
 */
final class WithdrawalRequestWithHoldService
{
    public function __construct(
        private readonly WithdrawalService $withdrawals,
        private readonly WithdrawalInitialHoldService $initial,
    ) {}

    /** @param array<string, mixed> $options */
    public function request(Wallet $wallet, Money $amount, PaymentMethod $method, ?string $idempotencyKey = null, array $options = []): Withdrawal
    {
        $options['metadata'] = array_merge(is_array($options['metadata'] ?? null) ? $options['metadata'] : [], [WithdrawalInitialHoldService::ORIGIN_KEY => 'v1']);

        return DB::transaction(function () use ($wallet, $amount, $method, $idempotencyKey, $options): Withdrawal {
            // Lock before the canonical pre-policy reads can establish an old RR snapshot.
            $wallet = app(WalletLockService::class)->lock((int) $wallet->id);
            // Retain the canonical source/player lock through the first hold.
            $row = $this->withdrawals->request($wallet, $amount, $method, $idempotencyKey, $options);
            if ($row->wasRecentlyCreated) {
                $this->initial->reserveNewRequest($row, $wallet);
            } else {
                $locked = app(WalletLockService::class)->lock((int) $wallet->id);
                $this->initial->assertReplay($row, $locked);
            }

            return $row;
        });
    }
}
