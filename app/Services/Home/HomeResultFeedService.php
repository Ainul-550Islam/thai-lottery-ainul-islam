<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Services\Lottery\GloPublicHomeService;
use Illuminate\Support\Facades\Cache;

/**
 * Provides latest verified public draw results for the Home feed while preserving leading zeros.
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

                return [
                    'status' => 'VERIFIED',
                    'primary_glo' => $currentGlo,
                    'lanes' => $lanes,
                    'verified_at' => now()->toIso8601String(),
                ];
            });
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'primary_glo' => [
                    'status' => 'UNAVAILABLE',
                    'has_result' => false,
                    'first_prize' => null,
                    'message' => 'Results feed is temporarily unavailable.',
                ],
                'lanes' => [],
                'verified_at' => now()->toIso8601String(),
            ];
        }
    }
}
