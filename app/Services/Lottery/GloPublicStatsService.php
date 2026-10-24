<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloSalesPoint;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Public, database-backed platform statistics for the Home page.
 *
 * NEVER invents marketing counters. Every figure is a COUNT over real tables,
 * cached with a short bounded TTL and no private user segments. When a query
 * fails the section reports UNAVAILABLE rather than a fabricated number.
 */
class GloPublicStatsService
{
    /**
     * @return array{
     *     status: string,
     *     generated_at: string,
     *     metrics: list<array{key: string, label: string, value: string, available: bool}>,
     *     message: string|null,
     * }
     */
    public function publicStats(): array
    {
        $ttl = max(30, (int) config('home.stats_cache_ttl_seconds', 300));

        try {
            /** @var array{status: string, generated_at: string, metrics: list<array<string, mixed>>, message: string|null} $payload */
            $payload = Cache::remember('home.stats', $ttl, function (): array {
                $members = (int) User::query()->whereNull('deleted_at')->count();
                $publishedResults = (int) DrawResult::query()->whereNull('deleted_at')->count();
                $salesPoints = (int) GloSalesPoint::query()
                    ->where('status', 'active')
                    ->where(function ($q): void {
                        $q->whereNull('valid_to')->orWhere('valid_to', '>=', now());
                    })
                    ->count();
                $drawsCompleted = (int) Draw::query()
                    ->whereIn('status', ['completed', 'result_published'])
                    ->count();

                return [
                    'status' => 'VERIFIED',
                    'generated_at' => Carbon::now()->toIso8601String(),
                    'metrics' => [
                        [
                            'key' => 'registered_members',
                            'label' => 'Registered members',
                            'value' => (string) $members,
                            'available' => true,
                        ],
                        [
                            'key' => 'published_results',
                            'label' => 'Published results',
                            'value' => (string) $publishedResults,
                            'available' => true,
                        ],
                        [
                            'key' => 'sales_points',
                            'label' => 'Active sales points',
                            'value' => (string) $salesPoints,
                            'available' => true,
                        ],
                        [
                            'key' => 'draws_completed',
                            'label' => 'Draws completed',
                            'value' => (string) $drawsCompleted,
                            'available' => true,
                        ],
                    ],
                    'message' => null,
                ];
            });

            return $payload;
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'generated_at' => Carbon::now()->toIso8601String(),
                'metrics' => [],
                'message' => 'Statistics are temporarily unavailable.',
            ];
        }
    }

    /**
     * Force-refresh the public stats cache (used when a verified result lands).
     */
    public function flush(): void
    {
        Cache::forget('home.stats');
    }
}
