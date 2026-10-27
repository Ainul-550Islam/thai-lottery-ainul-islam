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
     * A member counts as active while their last authenticated request is inside
     * this window. It is a constant, not a marketing dial: the same number labels
     * the metric that the query filters on.
     */
    public const ACTIVE_WINDOW_MINUTES = 15;

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

            // Session presence moves far faster than the slow counters above, so it is
            // read live on every call and never frozen into the cached payload.
            $payload['metrics'] = array_values(array_filter(
                $payload['metrics'] ?? [],
                static fn (array $metric): bool => ($metric['key'] ?? null) !== 'active_members',
            ));
            array_unshift($payload['metrics'], [
                'key' => 'active_members',
                'label' => sprintf('Members active in the last %d minutes', self::ACTIVE_WINDOW_MINUTES),
                'value' => (string) $this->activeMembersNow(),
                'available' => true,
            ]);

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
     * Members with an authenticated request inside the presence window.
     *
     * Session-based, not "registered": a soft-deleted account can never be active,
     * and a member who has never been seen carries a null stamp and is excluded by
     * the window comparison itself.
     */
    public function activeMembersNow(): int
    {
        try {
            return (int) User::query()
                ->whereNull('deleted_at')
                ->whereNotNull('last_seen_at')
                ->where('last_seen_at', '>=', Carbon::now()->subMinutes(self::ACTIVE_WINDOW_MINUTES))
                ->count();
        } catch (\Throwable $e) {
            report($e);

            return 0;
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
