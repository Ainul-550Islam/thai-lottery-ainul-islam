<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Models\Draw;
use Illuminate\Support\Facades\Cache;

/**
 * Combines verified GLO draw / result / prize state for public Home cards
 * while preserving GLO service boundaries (does not reimplement result
 * parsing, prize catalogue or live provider logic).
 */
class GloPublicHomeService
{
    public function __construct(
        private readonly GloPublicResultService $publicResults,
        private readonly GloNextDrawService $nextDraw,
        private readonly GloPublicPrizeSummaryService $prizes,
        private readonly GloLiveDrawService $liveDraw,
        private readonly GloPrizeCatalogue $catalogue,
    ) {}

    /**
     * Current verified (or honestly labeled) result card payload.
     *
     * @return array<string, mixed>
     */
    public function currentResultCard(): array
    {
        try {
            $payload = Cache::remember('home.current-result', 60, function (): array {
                $draw = Draw::query()
                    ->whereIn('status', [DrawStatus::ResultPublished->value, DrawStatus::Completed->value])
                    ->orderByDesc('scheduled_at')
                    ->orderByDesc('id')
                    ->first();

                if (! $draw instanceof Draw) {
                    return [
                        'status' => 'NO_VERIFIED_RESULT',
                        'has_result' => false,
                        'draw_number' => null,
                        'draw_date' => null,
                        'first_prize' => null,
                        'second_prize' => [],
                        'third_prize' => [],
                        'front_three' => [],
                        'last_three' => [],
                        'last_two' => [],
                        'adjacent_first' => [],
                        'source_state' => GloSourceState::Unavailable->value,
                        'result_version' => null,
                        'message' => 'No verified result available',
                    ];
                }

                $body = $this->publicResults->currentResult((string) $draw->getKey());
                $has = (bool) ($body['has_result'] ?? false);

                if (! $has) {
                    return [
                        'status' => 'NO_VERIFIED_RESULT',
                        'has_result' => false,
                        'draw_number' => $body['draw_number'] ?? $draw->draw_number,
                        'draw_date' => $body['draw_date'] ?? $draw->scheduled_at?->toDateString(),
                        'first_prize' => null,
                        'second_prize' => [],
                        'third_prize' => [],
                        'front_three' => [],
                        'last_three' => [],
                        'last_two' => [],
                        'adjacent_first' => [],
                        'source_state' => (string) ($body['source_state'] ?? GloSourceState::Unavailable->value),
                        'result_version' => null,
                        'message' => 'No verified result available',
                    ];
                }

                $source = (string) ($body['source_state'] ?? GloSourceState::Unavailable->value);

                return [
                    'status' => $source === GloSourceState::OfficialSourceVerified->value
                        ? 'VERIFIED'
                        : 'AVAILABLE',
                    'has_result' => true,
                    'draw_number' => $body['draw_number'] ?? null,
                    'draw_date' => $body['draw_date'] ?? null,
                    // Digit strings — leading zeros preserved; never int-cast.
                    'first_prize' => isset($body['first_prize']) ? (string) $body['first_prize'] : null,
                    'second_prize' => array_values(array_map('strval', (array) ($body['second_prize'] ?? []))),
                    'third_prize' => array_values(array_map('strval', (array) ($body['third_prize'] ?? []))),
                    'front_three' => array_values(array_map('strval', (array) ($body['front_three'] ?? []))),
                    'last_three' => array_values(array_map('strval', (array) ($body['last_three'] ?? []))),
                    'last_two' => array_values(array_map('strval', (array) ($body['last_two'] ?? []))),
                    'adjacent_first' => array_values(array_map('strval', (array) ($body['adjacent_first'] ?? []))),
                    'source_state' => $source,
                    'result_version' => $body['result_version'] ?? null,
                    'message' => null,
                ];
            });

            return is_array($payload) ? $payload : ['status' => 'UNAVAILABLE'];
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'has_result' => false,
                'draw_number' => null,
                'draw_date' => null,
                'first_prize' => null,
                'second_prize' => [],
                'third_prize' => [],
                'front_three' => [],
                'last_three' => [],
                'last_two' => [],
                'adjacent_first' => [],
                'source_state' => GloSourceState::Unavailable->value,
                'result_version' => null,
                'message' => 'Result temporarily unavailable',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function nextDrawCard(): array
    {
        try {
            return Cache::remember('home.next-draw', 30, fn (): array => $this->nextDraw->nextDraw());
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'draw_id' => null,
                'draw_number' => null,
                'draw_status' => null,
                'scheduled_at_iso' => null,
                'scheduled_at_display' => null,
                'timezone' => GloNextDrawService::TIMEZONE,
                'source' => 'none',
                'message' => 'Next draw temporarily unavailable',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function liveCard(): array
    {
        try {
            $status = $this->liveDraw->liveStatus();
            $liveStatus = strtolower((string) ($status['live_status'] ?? 'unavailable'));
            $replayStatus = strtolower((string) ($status['replay_status'] ?? 'unavailable'));
            $publicStatus = match ($liveStatus) {
                'configured' => 'CONFIGURED',
                'not_configured' => 'NOT_CONFIGURED',
                default => 'UNAVAILABLE',
            };
            $publicReplayStatus = match ($replayStatus) {
                'configured' => 'CONFIGURED',
                'not_configured' => 'NOT_CONFIGURED',
                default => 'UNAVAILABLE',
            };

            return [
                'status' => $publicStatus,
                'live_status' => $liveStatus,
                'replay_status' => $publicReplayStatus,
                'source_state' => (string) ($status['source_state'] ?? ($liveStatus === 'not_configured'
                    ? GloSourceState::NotConfigured->value
                    : GloSourceState::Unavailable->value)),
                'provider' => $status['provider'] ?? null,
                'embed_url' => $status['embed_url'] ?? null,
                'message' => (string) ($status['message'] ?? ''),
            ];
        } catch (\Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'live_status' => 'unavailable',
                'replay_status' => 'unavailable',
                'source_state' => GloSourceState::Unavailable->value,
                'provider' => null,
                'embed_url' => null,
                'message' => 'Live provider temporarily unavailable',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function prizeCard(): array
    {
        try {
            return $this->prizes->highlight();
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

    /**
     * GLO product catalogue (L6 / N3) projected from application config.
     * This is catalogue metadata only; it is not a live source verification or
     * confirmation that tickets are currently available.
     *
     * @return array{status: string, products: list<array<string, mixed>>}
     */
    public function productSummary(): array
    {
        $l6Price = (string) config('glo.l6.ticket_price', '0.00');
        $n3Price = (string) config('glo.n3.ticket_price', '0.00');
        $firstPrize = null;

        foreach ($this->catalogue->prizes() as $prize) {
            if (($prize['tier'] ?? '') === 'first') {
                $firstPrize = (string) $prize['amount'];
                break;
            }
        }

        return [
            'status' => 'CATALOGUE_ONLY',
            'products' => [
                [
                    'code' => 'L6',
                    'name' => 'L6 lottery product',
                    'ticket_price' => $l6Price,
                    'currency' => 'THB',
                    'digits' => (int) config('glo.l6.digits', 6),
                    'prize_model' => 'configured_l6_schedule',
                    'headline_prize' => $firstPrize,
                    'notes' => 'Prize and claim details come from this project’s configuration; confirm current rules and availability with the responsible source.',
                ],
                [
                    'code' => 'N3',
                    'name' => 'N3 lottery product',
                    'ticket_price' => $n3Price,
                    'currency' => 'THB',
                    'digits' => (int) config('glo.n3.digits', 3),
                    'prize_model' => (string) config('glo.n3.prize_engine.mode', 'pool_variable'),
                    'headline_prize' => null,
                    'notes' => 'Prize calculation follows the configured N3 rules; check the current published rules for details.',
                ],
            ],
        ];
    }

    /**
     * Drop Home caches that depend on a newly verified result.
     */
    public function flushResultCaches(): void
    {
        Cache::forget('home.current-result');
        Cache::forget('home.prize-summary');
        Cache::forget('home.stats');
    }
}
