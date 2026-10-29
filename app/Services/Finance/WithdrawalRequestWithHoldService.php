<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\PaymentMethod;
use App\Exceptions\WithdrawalException;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\PaymentGatewayManager;
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
        return DB::transaction(function () use ($wallet, $amount, $method, $idempotencyKey, $options): Withdrawal {
            // Lock before the canonical pre-policy reads can establish an old RR snapshot.
            $wallet = app(WalletLockService::class)->lock((int) $wallet->id);
            $existing = $idempotencyKey === null ? null : $this->withdrawals->findByIdempotencyKey($idempotencyKey);
            $possibleReplay = $existing instanceof Withdrawal && (int) $existing->wallet_id === (int) $wallet->id;

            // The switch and eligibility guard prevent new obligations, not a
            // read-only replay of an existing hold. The canonical service below
            // still verifies every replay's terms and the original hold ownership.
            if (! $possibleReplay) {
                if (! (bool) config('payment.withdrawal.enabled', false)) {
                    throw WithdrawalException::withReason('New withdrawal requests are disabled.', 'withdrawals_disabled');
                }
                // Manual is the existing internal/legacy request path, not a
                // claim of verified payout. Obligations still need reconciliation.
                if ($method !== PaymentMethod::Manual
                    && (! in_array($method->value, (array) config('payment.withdrawal.allowed_methods', []), true)
                        || ! app(PaymentGatewayManager::class)->isWithdrawalCapable($method, $amount->currency()))) {
                    throw WithdrawalException::withReason('The withdrawal method is unavailable for this currency and market.', 'withdrawal_method_unavailable');
                }
            }
            // Retain the canonical source/player lock through the first hold.
            $row = $this->withdrawals->request($wallet, $amount, $method, $idempotencyKey, $options, initialHoldRequired: true);
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
