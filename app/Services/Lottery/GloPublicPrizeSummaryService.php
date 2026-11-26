<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\PublicPages\PublishedResultSourceResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Public prize / jackpot highlight for the Home page.
 *
 * Amounts come from the official GLO prize catalogue (config/glo.php) and the
 * draw date / source state from the latest verified-or-published result row.
 * Never invents a rolling jackpot number and never labels a fixture as
 * "Official GLO Jackpot".
 */
class GloPublicPrizeSummaryService
{
    public function __construct(
        private readonly GloPrizeCatalogue $catalogue,
        private readonly PublishedResultSourceResolver $resolver,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     tier: string|null,
     *     label: string|null,
     *     amount: string|null,
     *     draw_number: string|null,
     *     draw_date: string|null,
     *     source_state: string,
     *     fixture_sample: bool,
     *     message: string|null,
     * }
     */
    public function highlight(): array
    {
        try {
            return Cache::remember('home.prize-summary', 60, function (): array {
                $draw = Draw::query()
                    ->whereIn('status', [DrawStatus::ResultPublished->value, DrawStatus::Completed->value])
                    ->orderByDesc('scheduled_at')
                    ->orderByDesc('id')
                    ->first();

                if (! $draw instanceof Draw) {
                    // Still show the official first-prize schedule (config data)
                    // but clearly without a current draw association.
                    $first = $this->firstPrizeEntry();

                    return [
                        'status' => $first === null ? 'UNAVAILABLE' : 'CATALOGUE_ONLY',
                        'tier' => $first['tier'] ?? null,
                        'label' => $first['label'] ?? null,
                        'amount' => $first['amount'] ?? null,
                        'draw_number' => null,
                        'draw_date' => null,
                        'source_state' => GloSourceState::NotConfigured->value,
                        'fixture_sample' => false,
                        'message' => $first === null
                            ? 'Prize summary unavailable'
                            : 'Official prize schedule; no published result yet.',
                    ];
                }

                $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
                $first = $this->firstPrizeEntry();

                if ($result === null || $first === null) {
                    return $this->unavailableForDraw($draw);
                }

                // Source disclosure only. In production a declared fixture
                // resolves to Unavailable, so demo data can never reach this
                // card; outside production it resolves to FixtureOnly.
                $source = $this->resolver->resolve($draw, $result);

                if ($source === GloSourceState::Unavailable) {
                    return $this->unavailableForDraw($draw);
                }
                return [
                    'status' => $source === GloSourceState::OfficialSourceVerified ? 'VERIFIED' : 'AVAILABLE',
                    'tier' => $first['tier'],
                    'label' => $first['label'],
                    'amount' => $first['amount'],
                    'draw_number' => (string) $draw->draw_number,
                    'draw_date' => $draw->scheduled_at?->toDateString(),
                    'source_state' => $source->value,
                    'fixture_sample' => $source === GloSourceState::FixtureOnly,
                    'message' => null,
                ];
            });
        } catch (\Throwable $e) {
            report($e);
            return [
                'status' => 'UNAVAILABLE',
                'tier' => null,
                'label' => null,
                'amount' => null,
                'draw_number' => null,
                'draw_date' => null,
                'source_state' => GloSourceState::Unavailable->value,
                'fixture_sample' => false,
                'message' => 'Prize summary unavailable',
            ];
        }
    }
    public function flush(): void
    {
        Cache::forget('home.prize-summary');
    }
    /**
     * The one "no verified summary for this draw" payload, shared by the
     * missing-result and unresolvable-source branches so they cannot drift.
     *
     * @return array{
     *     status: string,
     *     tier: null,
     *     label: null,
     *     amount: null,
     *     draw_number: mixed,
     *     draw_date: string|null,
     *     source_state: string,
     *     fixture_sample: false,
     *     message: string,
     * }
     */
    private function unavailableForDraw(Draw $draw): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'tier' => null,
            'label' => null,
            'amount' => null,
            'draw_number' => $draw->draw_number,
            'draw_date' => $draw->scheduled_at?->toDateString(),
            'source_state' => GloSourceState::Unavailable->value,
            'fixture_sample' => false,
            'message' => 'No verified prize summary for the current draw.',
        ];
    }
    /**
     * @return array{tier: string, label: string, amount: string}|null
     */
    private function firstPrizeEntry(): ?array
    {
        foreach ($this->catalogue->prizes() as $prize) {
            if (($prize['tier'] ?? '') === 'first') {
                return [
                    'tier' => (string) $prize['tier'],
                    'label' => (string) $prize['label'],
                    'amount' => (string) $prize['amount'],
                ];
            }
        }
        return null;
    }
}
