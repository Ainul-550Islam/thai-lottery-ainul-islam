<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\UnclaimedPrizeData;
use App\Enums\AuditAction;
use App\Enums\PrizeClaimStatus;
use App\Enums\RiskLevel;
use App\Enums\UnclaimedPrizeStatus;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UnclaimedPrizeService
{
    public const UNCLAIMED_METADATA_KEY = 'unclaimed';

    /**
     * @return array{
     *     expired_count: int,
     *     total_expired_amount: string|null,
     *     totals_by_currency: array<string, string>,
     *     annotation_only: bool,
     *     payouts: list<array{payout_id: int, amount: string, currency: string, window_closed_at: string}>,
     *     executed_at: string
     * }
     */
    public function expireLapsedClaims(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;

        $expired = [];
        $totalsByCurrency = [];
        $now = now()->toIso8601String();
        $guard = app(PrizeClaimGuard::class);

        $lockStatuses = [
            PrizeClaimStatus::Submitted->value,
            PrizeClaimStatus::UnderReview->value,
            PrizeClaimStatus::Approved->value,
        ];

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn('metadata->claim->status', $lockStatuses)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$expired, &$totalsByCurrency, $now, $guard, $lockStatuses): bool {
                foreach ($payouts as $candidate) {
                    DB::transaction(function () use ($candidate, &$expired, &$totalsByCurrency, $now, $guard, $lockStatuses): void {
                        $payout = Payout::query()->lockForUpdate()->find((int) $candidate->getKey());
                        if (! $payout instanceof Payout) {
                            return;
                        }

                        if ($guard->protected($payout)) {
                            return;
                        }

                        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
                        if (array_key_exists(self::UNCLAIMED_METADATA_KEY, $metadata)) {
                            return;
                        }

                        $claim = $metadata['claim'] ?? null;
                        if (! is_array($claim) || ! in_array($claim['status'] ?? null, $lockStatuses, true)) {
                            return;
                        }

                        try {
                            $guard->assertSnapshot($payout, $claim);
                        } catch (PrizeClaimException) {
                            return;
                        }

                        $deadline = $guard->deadline($claim['window_closes_at'] ?? null);
                        if (! $deadline instanceof Carbon || ! $deadline->isPast()) {
                            return;
                        }

                        $claim['status'] = PrizeClaimStatus::Expired->value;
                        $claim['expired_at'] = $now;

                        $disposalKey = UnclaimedPrizeData::deriveDisposalKey(
                            (string) $payout->reference_number,
                            (string) $payout->amount,
                            $payout->currency,
                            (string) ($claim['window_closes_at'] ?? ''),
                        );

                        $metadata['claim'] = $claim;
                        $metadata[self::UNCLAIMED_METADATA_KEY] = [
                            'status' => UnclaimedPrizeStatus::Expired->value,
                            'disposal_key' => $disposalKey,
                            'expired_at' => $now,
                            'swept_at' => null,
                        ];

                        $payout->metadata = $metadata;
                        $payout->save();

                        $currency = $payout->currency->value;
                        $totalsByCurrency[$currency] = bcadd($totalsByCurrency[$currency] ?? '0.00', (string) $payout->amount, 2);

                        $expired[] = [
                            'payout_id' => (int) $payout->getKey(),
                            'amount' => (string) $payout->amount,
                            'currency' => $currency,
                            'window_closed_at' => (string) ($claim['window_closes_at'] ?? ''),
                        ];

                        $this->recordAudit($payout, $claim);
                    });
                }

                return true;
            });

        return [
            'expired_count' => count($expired),
            'total_expired_amount' => count($totalsByCurrency) > 1
                ? null
                : (count($totalsByCurrency) === 1 ? (string) reset($totalsByCurrency) : '0.00'),
            'totals_by_currency' => $totalsByCurrency,
            'annotation_only' => true,
            'payouts' => $expired,
            'executed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{
     *     swept_count: int,
     *     total_swept_amount: string|null,
     *     totals_by_currency: array<string, string>,
     *     annotation_only: bool,
     *     disposal_keys: list<string>,
     *     executed_at: string
     * }
     */
    public function sweepExpired(?int $chunkSize = null): array
    {
        $chunkSize ??= 200;
        $swept = 0;
        $totalsByCurrency = [];
        $keys = [];
        $now = now()->toIso8601String();
        $guard = app(PrizeClaimGuard::class);

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->where('metadata->unclaimed->status', UnclaimedPrizeStatus::Expired->value)
            ->orderBy('id')
            ->chunkById($chunkSize, function ($payouts) use (&$swept, &$totalsByCurrency, &$keys, $now, $guard): bool {
                foreach ($payouts as $candidate) {
                    DB::transaction(function () use ($candidate, &$swept, &$totalsByCurrency, &$keys, $now, $guard): void {
                        $payout = Payout::query()->lockForUpdate()->find((int) $candidate->getKey());
                        if (! $payout instanceof Payout) {
                            return;
                        }

                        if ($guard->protected($payout)) {
                            return;
                        }

                        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
                        $lane = $metadata[self::UNCLAIMED_METADATA_KEY] ?? null;
                        $claim = $metadata['claim'] ?? null;

                        if (! is_array($lane) || ($lane['status'] ?? null) !== UnclaimedPrizeStatus::Expired->value || ! is_array($claim)) {
                            return;
                        }

                        try {
                            $guard->assertSnapshot($payout, $claim);
                        } catch (PrizeClaimException) {
                            return;
                        }

                        $expectedKey = UnclaimedPrizeData::deriveDisposalKey(
                            (string) $payout->reference_number,
                            (string) $payout->amount,
                            $payout->currency,
                            (string) ($claim['window_closes_at'] ?? ''),
                        );

                        if (! is_string($lane['disposal_key'] ?? null) || ! hash_equals($expectedKey, $lane['disposal_key'])) {
                            return;
                        }

                        $lane['status'] = UnclaimedPrizeStatus::Swept->value;
                        $lane['swept_at'] = $now;
                        $metadata[self::UNCLAIMED_METADATA_KEY] = $lane;

                        $payout->metadata = $metadata;
                        $payout->save();

                        $swept++;
                        $currency = $payout->currency->value;
                        $totalsByCurrency[$currency] = bcadd($totalsByCurrency[$currency] ?? '0.00', (string) $payout->amount, 2);
                        $keys[] = (string) $lane['disposal_key'];

                        $log = new AuditLog();
                        $log->fill([
                            'user_id' => null,
                            'action' => AuditAction::Update,
                            'risk_level' => RiskLevel::Medium,
                            'auditable_type' => Payout::class,
                            'auditable_id' => $payout->getKey(),
                            'description' => sprintf(
                                'Unclaimed prize of %s %s on payout #%d SWEPT into the disposal ledger (disposal key %s).',
                                (string) $payout->amount,
                                $payout->currency->value,
                                (int) $payout->getKey(),
                                (string) $lane['disposal_key'],
                            ),
                            'metadata' => [
                                'payout_id' => (int) $payout->getKey(),
                                'payout_reference' => (string) $payout->reference_number,
                                'disposal_key' => (string) $lane['disposal_key'],
                                'action' => 'unclaimed_prize_swept',
                            ],
                        ]);
                        $log->save();
                    });
                }

                return true;
            });

        return [
            'swept_count' => $swept,
            'total_swept_amount' => count($totalsByCurrency) > 1
                ? null
                : (count($totalsByCurrency) === 1 ? (string) reset($totalsByCurrency) : '0.00'),
            'totals_by_currency' => $totalsByCurrency,
            'annotation_only' => true,
            'disposal_keys' => $keys,
            'executed_at' => now()->toIso8601String(),
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
        $guard = app(PrizeClaimGuard::class);
        $count = 0;

        Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn('metadata->claim->status', [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ])
            ->orderBy('id')
            ->chunkById(200, function ($payouts) use (&$count, $guard): bool {
                foreach ($payouts as $payout) {
                    if ($guard->protected($payout)) {
                        continue;
                    }
                    $claim = is_array($payout->metadata['claim'] ?? null) ? $payout->metadata['claim'] : null;
                    if (! is_array($claim)) {
                        continue;
                    }
                    $deadline = $guard->deadline($claim['window_closes_at'] ?? null);
                    if ($deadline instanceof Carbon && $deadline->isPast()) {
                        $count++;
                    }
                }

                return true;
            });

        return $count;
    }

    /**
     * @return array<string, string>
     */
    public function pendingClaimsTotalsByCurrency(): array
    {
        $query = Payout::query()
            ->whereNotNull('metadata')
            ->whereNull('deleted_at')
            ->whereIn('metadata->claim->status', [
                PrizeClaimStatus::Submitted->value,
                PrizeClaimStatus::UnderReview->value,
                PrizeClaimStatus::Approved->value,
            ]);

        $totals = [];
        foreach ($query->select(['amount', 'currency'])->cursor() as $row) {
            $currency = $row->currency instanceof \BackedEnum ? (string) $row->currency->value : (string) $row->currency;
            $totals[$currency] = bcadd($totals[$currency] ?? '0.00', (string) $row->amount, 2);
        }

        return $totals;
    }

    public function pendingClaimsTotal(?string $currency = null): ?string
    {
        if ($currency !== null) {
            $totals = $this->pendingClaimsTotalsByCurrency();

            return $totals[$currency] ?? '0.00';
        }

        $totals = $this->pendingClaimsTotalsByCurrency();
        if (count($totals) > 1) {
            return null;
        }

        return count($totals) === 1 ? (string) reset($totals) : '0.00';
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordAudit(Payout $payout, array $claim): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => 'Legacy prize expire annotation recorded; no funds moved or payout cancelled.',
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'claim_status' => PrizeClaimStatus::Expired->value,
                'window_closes_at' => (string) ($claim['window_closes_at'] ?? ''),
            ],
        ]);

        $log->save();
    }
}
