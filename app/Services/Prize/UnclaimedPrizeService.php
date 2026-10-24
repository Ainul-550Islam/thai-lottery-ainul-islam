<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\PayoutStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\UnclaimedPrizeStatus;
use App\Exceptions\PrizeClaimException;
use App\Models\Payout;

final class UnclaimedPrizeService
{
    public const UNCLAIMED_METADATA_KEY = 'unclaimed';

    public function __construct(
        private readonly PrizeExpiryService $expiry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function expireLapsedClaims(?int $chunkSize = null): array
    {
        $out = $this->expiry->run('expire', $chunkSize);

        return [
            'expired_count' => $out['count'],
            'total_expired_amount' => $out['single_currency_total'],
            'totals_by_currency' => $out['totals_by_currency'],
            'payouts' => $out['events'],
            'skipped_candidates' => $out['skipped_candidates'],
            'annotation_only' => $out['annotation_only'],
            'executed_at' => $out['executed_at'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sweepExpired(?int $chunkSize = null): array
    {
        $out = $this->expiry->run('sweep', $chunkSize);

        return [
            'swept_count' => $out['count'],
            'total_swept_amount' => $out['single_currency_total'],
            'totals_by_currency' => $out['totals_by_currency'],
            'disposal_keys' => array_values(array_filter(array_column($out['events'], 'disposal_key'))),
            'skipped_candidates' => $out['skipped_candidates'],
            'annotation_only' => $out['annotation_only'],
            'executed_at' => $out['executed_at'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function unclaimedRecordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::UNCLAIMED_METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    public function disposalStatusFor(Payout $payout): ?UnclaimedPrizeStatus
    {
        $record = $this->unclaimedRecordFor($payout);

        return is_array($record) ? UnclaimedPrizeStatus::tryFrom((string) ($record['status'] ?? '')) : null;
    }

    public function lapsedClaimCount(): int
    {
        $cutoff = now();
        $guard = app(PrizeClaimGuard::class);
        $count = 0;

        $rows = Payout::query()
            ->where('status', PayoutStatus::Pending->value)
            ->whereIn('metadata->claim->status', [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ])
            ->get();

        foreach ($rows as $row) {
            if ($guard->protected($row) || array_key_exists(self::UNCLAIMED_METADATA_KEY, $row->metadata ?? [])) {
                continue;
            }
            $claim = $row->metadata['claim'] ?? null;
            if (! is_array($claim)) {
                continue;
            }
            try {
                $guard->assertSnapshot($row, $claim);
            } catch (PrizeClaimException) {
                continue;
            }
            $edge = $guard->deadline($claim['window_closes_at'] ?? null);
            if ($edge !== null && $edge->lessThanOrEqualTo($cutoff)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<string, string>
     */
    public function pendingClaimsTotalsByCurrency(?string $currency = null): array
    {
        $guard = app(PrizeClaimGuard::class);
        $query = Payout::query()
            ->where('status', PayoutStatus::Pending->value)
            ->whereIn('metadata->claim->status', [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ]);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        $totals = [];
        foreach ($query->cursor() as $row) {
            if ($guard->protected($row)) {
                continue;
            }
            $claim = $row->metadata['claim'] ?? null;
            if (! is_array($claim)) {
                continue;
            }
            try {
                $guard->assertSnapshot($row, $claim);
            } catch (PrizeClaimException) {
                continue;
            }
            $code = $row->currency->value;
            $totals[$code] = bcadd($totals[$code] ?? '0.00', (string) $row->amount, 2);
        }

        ksort($totals);

        return $totals;
    }

    public function pendingClaimsTotal(?string $currency = null): ?string
    {
        $totals = $this->pendingClaimsTotalsByCurrency($currency);

        if ($currency !== null) {
            return $totals[$currency] ?? '0.00';
        }

        return count($totals) > 1 ? null : (array_values($totals)[0] ?? '0.00');
    }
}
