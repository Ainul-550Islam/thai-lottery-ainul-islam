<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;

/**
 * Service providing projection data for the public Results landing page.
 *
 * Ensures no direct database queries are run in Blade views, and provides
 * safe, explicit allowlist-based mapping for result source provenance.
 */
final class ResultsPageService
{
    /**
     * @return array{
     *     currentStatus: ?Draw,
     *     rows: list<array{
     *         draw_number: string,
     *         draw_date: ?string,
     *         first_prize: ?string,
     *         second_prize: mixed,
     *         third_prize: mixed,
     *         consolation_prizes: mixed,
     *         source_state: string,
     *         fixture_sample: bool,
     *         result_version: string,
     *     }>
     * }
     */
    public function resultsData(): array
    {
        $currentStatus = Draw::query()->orderByDesc('scheduled_at')->first();

        $latestQuery = Draw::query()
            ->whereIn('status', ['result_published', 'completed'])
            ->where('scheduled_at', '<=', now())
            ->orderByDesc('scheduled_at')
            ->limit(12);

        $rows = [];
        foreach ($latestQuery->get() as $draw) {
            $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
            if ($result === null) {
                continue;
            }

            $meta = is_array($result->metadata) ? $result->metadata : [];
            $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
            $provider = strtolower(trim((string) ($lane['import_provider'] ?? '')));

            // Explicit allowlist mapping: unknown never degrades to official
            $sourceState = match ($provider) {
                'official', 'official_api', 'official_scraper', 'glo_official' => GloSourceState::OfficialSourceVerified,
                'fixture', 'fixture_only' => GloSourceState::FixtureOnly,
                'internal', 'manual', 'admin_entry' => GloSourceState::InternalReconciled,
                'not_configured' => GloSourceState::NotConfigured,
                default => GloSourceState::Unavailable,
            };

            $rows[] = [
                'draw_number' => (string) $draw->draw_number,
                'draw_date' => $draw->scheduled_at?->toDateString(),
                'first_prize' => $result->first_prize,
                'second_prize' => $result->second_prize ?? [],
                'third_prize' => $result->third_prize ?? [],
                'consolation_prizes' => $result->consolation_prizes ?? [],
                'source_state' => $sourceState->value,
                'fixture_sample' => $sourceState === GloSourceState::FixtureOnly,
                'result_version' => (string) ($lane['import_fingerprint'] ?? ('pub-'.$result->getKey())),
            ];
        }

        return [
            'currentStatus' => $currentStatus,
            'rows' => $rows,
        ];
    }
}
