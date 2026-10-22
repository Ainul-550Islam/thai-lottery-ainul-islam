<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates the latest result projections for the Home feed while preserving leading zeros. The aggregate reports availability, not source verification.
 */
class HomeResultFeedService
{
    public function __construct(
        private readonly GloPublicHomeService $gloHome,
        private readonly PublicLaneResultDigestService $laneResults,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getLatestResultsFeed(): array
    {
        try {
            return Cache::remember('home.result_feed.all', 60, function (): array {
                $currentGlo = $this->gloHome->currentResultCard();
                $lanes = $this->laneResults->lanes();

                $hasGloResult = ($currentGlo['has_result'] ?? false) === true;
                $laneRows = is_array($lanes['lanes'] ?? null) ? $lanes['lanes'] : [];
                $hasLaneResult = false;

                foreach ($laneRows as $lane) {
                    if (is_array($lane) && ($lane['available'] ?? false) === true) {
                        $hasLaneResult = true;
                        break;
                    }
                }

                return [
                    // This is an aggregate of multiple source states; it cannot
                    // claim the whole feed is verified from one timestamp.
                    'status' => ($hasGloResult || $hasLaneResult) ? 'AVAILABLE' : 'NO_PUBLISHED_RESULTS',
                    'has_results' => $hasGloResult || $hasLaneResult,
                    'primary_glo' => $currentGlo,
                    'lanes' => $lanes,
                    'generated_at' => now()->toIso8601String(),
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'has_results' => false,
                'primary_glo' => [
                    'status' => 'UNAVAILABLE',
                    'has_result' => false,
                    'first_prize' => null,
                    'message' => 'Results feed is temporarily unavailable.',
                ],
                'lanes' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }
    }
}
