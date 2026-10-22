<?php

declare(strict_types=1);

namespace App\Repositories\Wallet;

use App\Models\WalletConversionAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Append-only persistence for conversion audit events.
 *
 * There is no update method and no delete method on this repository, and that
 * is the point: financial evidence that can be edited is not evidence. A future
 * need to correct an audit row must be expressed as a NEW row (a correction
 * event), never as a mutation of history.
 *
 * Reads are grouped by reference so an investigator can pull the timeline of one
 * conversion, and by window so a reconciliation can see every event — including
 * refusals — that occurred while money was moving.
 */
class WalletConversionAuditRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes): WalletConversionAudit
    {
        return WalletConversionAudit::query()->create($attributes);
    }

    /**
     * The full timeline for one conversion, oldest first.
     *
     * @return Collection<int, WalletConversionAudit>
     */
    public function timelineForReference(string $reference): Collection
    {
        return WalletConversionAudit::query()
            ->forReference($reference)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, WalletConversionAudit>
     */
    public function forConversion(int $conversionId, int $limit = 100): Collection
    {
        return WalletConversionAudit::query()
            ->where('wallet_conversion_id', $conversionId)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, WalletConversionAudit>
     */
    public function between(Carbon $from, Carbon $to, int $limit = 1000): Collection
    {
        return WalletConversionAudit::query()
            ->between($from, $to)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Count of refusals grouped by their stable error code, for the operations
     * dashboard. A refusal spike is the earliest visible signal of a broken
     * integration, so it is worth a first-class read.
     *
     * @return array<string, int>
     */
    public function refusalCountsSince(Carbon $since): array
    {
        return WalletConversionAudit::query()
            ->where('recorded_at', '>=', $since)
            ->whereNotNull('error_code')
            ->selectRaw('error_code, COUNT(*) as aggregate')
            ->groupBy('error_code')
            ->pluck('aggregate', 'error_code')
            ->map(static fn ($count): int => (int) $count)
            ->all();
    }

    public function countBetween(Carbon $from, Carbon $to): int
    {
        return WalletConversionAudit::query()->between($from, $to)->count();
    }
}
