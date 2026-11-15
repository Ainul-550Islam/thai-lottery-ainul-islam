<?php

declare(strict_types=1);

namespace App\Services\Rewards\Fulfillers;

use App\Enums\Currency;
use App\Enums\RewardClaimStatus;
use App\Exceptions\FinancialException;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Rewards\FreeBetVoucherService;
use Illuminate\Support\Carbon;

final class FreeBetFulfiller implements RewardFulfiller
{
    public function __construct(
        private readonly FreeBetVoucherService $vouchers,
    ) {}

    /**
     * @return array{status: RewardClaimStatus, financial_transaction_id: null, payload: array<string, mixed>}
     *
     * @throws FinancialException
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($item->payload) ? $item->payload : [];
        $rawAmount = (string) ($payload['amount'] ?? '0.00');
        $currencyCode = strtoupper((string) ($payload['currency'] ?? config('rewards.currency', 'THB')));
        $expiresInDays = max(1, (int) ($payload['expires_in_days'] ?? config('rewards.free_bet.default_expiry_days', 14)));

        $amount = Money::of($rawAmount, Currency::from($currencyCode));
        $expiresAt = Carbon::now()->addDays($expiresInDays);

        $voucher = $this->vouchers->issue(
            member: $member,
            claim: $claim,
            amount: $amount,
            expiresAt: $expiresAt,
            metadata: [
                'reward_item_code' => $item->code,
                'points_spent' => $claim->points_spent,
            ],
        );

        return [
            'status' => RewardClaimStatus::Completed,
            'financial_transaction_id' => null,
            'payload' => [
                'voucher_id' => (int) $voucher->getKey(),
                'voucher_code' => $voucher->code,
                'amount' => (string) $voucher->amount,
                'currency' => $voucher->currency,
                'expires_at' => $voucher->expires_at->toIso8601String(),
            ],
        ];
    }
}
