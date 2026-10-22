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
 * cached with a short bounded TTL and no private user segments. The section
 * reports AVAILABLE for database-derived counts, not external verification;
 * when a query fails it reports UNAVAILABLE rather than a fabricated number.
 */
class GloPublicStatsService
{
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
                $now = now();
                $members = (int) User::query()->whereNull('deleted_at')->count();
                $publishedResults = (int) DrawResult::query()
                    ->whereHas('draw', fn ($query) => $query->whereIn('status', [
                        'completed',
                        'result_published',
                    ]))
                    ->count();
                $salesPoints = (int) GloSalesPoint::query()
                    ->where('status', 'active')
                    ->where(function ($q) use ($now): void {
                        $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
                    })
                    ->where(function ($q) use ($now): void {
                        $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
                    })
                    ->count();
                $drawsCompleted = (int) Draw::query()
                    ->where('status', 'completed')
                    ->count();

                return [
                    'status' => 'AVAILABLE',
                    'generated_at' => Carbon::now()->toIso8601String(),
                    'metrics' => [
                        [
                            'key' => 'registered_members',
                            'label' => (string) trans('home.stats_registered_accounts', [], app()->getLocale()),
                            'value' => (string) $members,
                            'available' => true,
                        ],
                        [
                            'key' => 'published_results',
                            'label' => (string) trans('home.stats_published_results', [], app()->getLocale()),
                            'value' => (string) $publishedResults,
                            'available' => true,
                        ],
                        [
                            'key' => 'sales_points',
                            'label' => (string) trans('home.stats_active_sales_point_records', [], app()->getLocale()),
                            'value' => (string) $salesPoints,
                            'available' => true,
                        ],
                        [
                            'key' => 'draws_completed',
                            'label' => (string) trans('home.stats_completed_draws', [], app()->getLocale()),
                            'value' => (string) $drawsCompleted,
                            'available' => true,
                        ],
                    ],
                    'message' => null,
                ];
            });

            try {
                $activeMembers = $this->activeMembersNow();
            } catch (\Throwable $e) {
                report($e);
                $activeMembers = null;
            }

            $payload['metrics'][] = [
                'key' => 'active_members',
                'label' => (string) trans('home.stats_active_members', [
                    'minutes' => self::ACTIVE_WINDOW_MINUTES,
                ], app()->getLocale()),
                'value' => $activeMembers === null ? '0' : (string) $activeMembers,
                'available' => $activeMembers !== null,
            ];

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
     * Count non-deleted users with authenticated activity in the rolling window.
     */
    public function activeMembersNow(): int
    {
        $now = now();
        $cutoff = $now->copy()->subMinutes(self::ACTIVE_WINDOW_MINUTES);

        return (int) User::query()
            ->whereNull('deleted_at')
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $cutoff)
            ->where('last_seen_at', '<=', $now)
            ->count();
    }

    /**
     * Force-refresh the cached metrics (the live active-member count is never cached).
     */
    public function flush(): void
    {
        Cache::forget('home.stats');
    }
}
