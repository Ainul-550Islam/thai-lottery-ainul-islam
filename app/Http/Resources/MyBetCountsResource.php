<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The five tab counts.
 *
 * These are COUNTS from one grouped query (MyBetsQueryService::counts()), not
 * estimates and not cached figures. The service guarantees `all` equals the sum of
 * the buckets plus the statuses that belong to none of them, and
 * MyBetsQueryTest asserts each tab equals its filtered list total.
 *
 * @property array{all:int, pending:int, confirmed:int, cancelled:int, winning:int} $resource
 */
final class MyBetCountsResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, int> $counts */
        $counts = (array) $this->resource;

        return [
            'all' => (int) ($counts['all'] ?? 0),
            'pending' => (int) ($counts['pending'] ?? 0),
            'confirmed' => (int) ($counts['confirmed'] ?? 0),
            'cancelled' => (int) ($counts['cancelled'] ?? 0),
            'winning' => (int) ($counts['winning'] ?? 0),
        ];
    }
}
