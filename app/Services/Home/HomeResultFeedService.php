<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Enums\GloSourceState;
use App\Services\Lottery\GloPublicHomeService;

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
            return (function (): array {
                $currentGlo = $this->gloHome->currentResultCard();
                $lanes = $this->laneResults->lanes();
                $laneRows = is_array($lanes['lanes'] ?? null) ? $lanes['lanes'] : [];
                $hasLaneResult = count(array_filter(
                    $laneRows,
                    static fn (mixed $lane): bool => is_array($lane) && ($lane['available'] ?? false) === true,
                )) > 0;
                $hasGloResult = ($currentGlo['has_result'] ?? false) === true;
                $officialGlo = $hasGloResult
                    && ($currentGlo['source_state'] ?? null) === GloSourceState::OfficialSourceVerified->value;

                return [
                    // Only an official, provenance-verified GLO publication
                    // earns the VERIFIED label; fixture/internal data remain
                    // available but are never promoted to official output.
                    'status' => $officialGlo
                        ? 'VERIFIED'
                        : (($hasGloResult || $hasLaneResult) ? 'AVAILABLE' : 'UNAVAILABLE'),
                    'primary_glo' => $currentGlo,
                    'lanes' => $lanes,
                    'verified_at' => now()->toIso8601String(),
                ];
            })();
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
