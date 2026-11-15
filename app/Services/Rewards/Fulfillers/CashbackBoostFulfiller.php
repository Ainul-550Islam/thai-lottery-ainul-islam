<?php

declare(strict_types=1);

namespace App\Services\Rewards\Fulfillers;

use App\Enums\RewardClaimStatus;
use App\Exceptions\FinancialException;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use Illuminate\Support\Carbon;

final class CashbackBoostFulfiller implements RewardFulfiller
{
    /**
     * Stores extra_bps + window timestamps in claim payload (calculator input only).
     *
     * @return array{status: RewardClaimStatus, financial_transaction_id: null, payload: array<string, mixed>}
     *
     * @throws FinancialException
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($item->payload) ? $item->payload : [];
        $extraBps = (int) ($payload['extra_bps'] ?? 0);
        $durationDays = (int) ($payload['duration_days'] ?? config('rewards.catalog.default_expiry_days', 7));

        if ($extraBps <= 0 || $durationDays <= 0) {
            throw FinancialException::withCode(
                'invalid_cashback_boost_payload',
                'Cashback boost reward payload requires positive extra_bps and duration_days.',
            );
        }

        $startsAt = Carbon::now();
        $endsAt = $startsAt->copy()->addDays($durationDays);

        return [
            'status' => RewardClaimStatus::Completed,
            'financial_transaction_id' => null,
            'payload' => [
                'member_id' => (int) $member->getKey(),
                'claim_id' => (int) $claim->getKey(),
                'extra_bps' => $extraBps,
                'duration_days' => $durationDays,
                'boost_starts_at' => $startsAt->toIso8601String(),
                'boost_ends_at' => $endsAt->toIso8601String(),
            ],
        ];
    }
}
