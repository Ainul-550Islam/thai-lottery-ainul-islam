<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\PublicPages\ResultsPageService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public Results Web Controller.
 *
 * Exposes authoritative historical and latest lottery results across all
 * supported lottery lanes without executing raw SQL or leaking unpublished data.
 */
class ResultsController
{
    public function __construct(
        private readonly ResultsPageService $resultsPageService,
    ) {
    }

    /**
     * Render the public results index page.
     */
    public function index(Request $request): View
    {
        $data = $this->resultsPageService->resultsData();

        return view('results.index', [
            'currentStatus' => $data['currentStatus'],
            'rows' => $data['rows'],
        ]);
    }

    /**
     * Search published results by winning number or draw number.
     */
    public function search(Request $request): View
    {
        $rawQuery = (string) $request->input('q', '');
        $query = trim(strip_tags($rawQuery));

        $results = [];

        if ($query !== '' && strlen($query) <= 30) {
            $drawQuery = Draw::query()
                ->whereIn('status', [DrawStatus::ResultPublished->value, DrawStatus::Completed->value])
                ->where('scheduled_at', '<=', now())
                ->orderByDesc('scheduled_at');

            // Search by draw number, date, or match winning numbers
            if (is_numeric($query)) {
                $draws = (clone $drawQuery)
                    ->where(function ($q) use ($query): void {
                        $q->where('draw_number', $query)
                            ->orWhereHas('result', function ($rq) use ($query): void {
                                $rq->where('first_prize', 'LIKE', '%' . $query . '%');
                            });
                    })
                    ->limit(20)
                    ->get();
            } else {
                $draws = (clone $drawQuery)
                    ->whereDate('scheduled_at', $query)
                    ->limit(20)
                    ->get();
            }

            foreach ($draws as $draw) {
                $res = DrawResult::query()->where('draw_id', $draw->getKey())->first();
                if ($res === null) {
                    continue;
                }

                $meta = is_array($res->metadata) ? $res->metadata : [];
                $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
                $provider = strtolower(trim((string) ($lane['import_provider'] ?? '')));

                $sourceState = match ($provider) {
                    'official', 'official_api', 'official_scraper', 'glo_official' => GloSourceState::OfficialSourceVerified,
                    'fixture', 'fixture_only' => GloSourceState::FixtureOnly,
                    'internal', 'manual', 'admin_entry' => GloSourceState::InternalReconciled,
                    default => GloSourceState::Unavailable,
                };

                $results[] = [
                    'draw_number' => (string) $draw->draw_number,
                    'draw_date' => $draw->scheduled_at?->toDateString(),
                    'first_prize' => (string) $res->first_prize,
                    'bottom_two' => $meta['bottom_two'] ?? $meta['two_digit_bottom'] ?? null,
                    'source_state' => $sourceState->value,
                    'fixture_sample' => $sourceState === GloSourceState::FixtureOnly,
                ];
            }
        }

        return view('results.search', [
            'query' => $query,
            'results' => $results,
        ]);
    }
}
