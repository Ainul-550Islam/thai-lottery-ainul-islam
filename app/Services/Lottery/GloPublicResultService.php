<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\DrawStatus;
use App\Enums\GloSourceState;
use App\Exceptions\GloDealerException;
use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;

/**
 * GLO-18 public result experience: current + historical 6-digit checks,
 * result check sheet, history index with 2-year window, cache with
 * product|draw|result_version keys.
 *
 * Leading zeros always preserved (digit strings). Unverified results are
 * never labeled OFFICIAL. Source state is explicit on every payload.
 */
class GloPublicResultService
{
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly GloTicketChecker $checker,
        private readonly GloResultService $results,
    ) {}

    private function labels(): array
    {
        return (array) config('glo.result_experience.source_labels', []);
    }

    /**
     * Resolve how a draw_result's source should be labeled.
     */
    public function sourceStateFor(?DrawResult $result, ?Draw $draw): string
    {
        if ($result === null) {
            return GloSourceState::Unavailable->value;
        }

        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
        $provider = (string) ($lane['import_provider'] ?? '');

        if ($provider === 'official') {
            return GloSourceState::OfficialSourceVerified->value;
        }

        if ($provider === 'fixture') {
            return GloSourceState::FixtureOnly->value;
        }

        // Admin entry / recovery / import without official provider → internal.
        if ($draw !== null && in_array($draw->status, [DrawStatus::ResultPublished, DrawStatus::Completed], true)) {
            return GloSourceState::InternalReconciled->value;
        }

        return GloSourceState::Unavailable->value;
    }

    /**
     * Current (or named) draw result — public safe.
     *
     * @return array<string, mixed>
     */
    public function currentResult(?string $drawRef = null): array
    {
        $draw = $this->resolveDraw($drawRef);
        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        if ($result === null) {
            return [
                'draw_id' => $draw->getKey(),
                'draw_number' => $draw->draw_number,
                'draw_date' => $draw->scheduled_at?->toDateString(),
                'has_result' => false,
                'source_state' => GloSourceState::Unavailable->value,
                'message' => 'No result is recorded for this draw.',
            ];
        }

        return $this->resultPayload($draw, $result);
    }

    /**
     * 6-digit check for a draw (or latest published draw when omitted).
     * Digit string exact match — never int-cast.
     *
     * @return array<string, mixed>
     */
    public function checkSixDigit(string $number, ?string $drawRef = null): array
    {
        if (preg_match('/^\d{6}$/', $number) !== 1) {
            throw GloDealerException::invalidResultInput('number must be exactly 6 digits');
        }

        $draw = $this->resolveDraw($drawRef);
        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        $source = $this->sourceStateFor($result, $draw);

        if ($result === null) {
            return [
                'ticket_number' => $number,
                'draw_id' => $draw->getKey(),
                'draw_number' => $draw->draw_number,
                'won' => false,
                'matches' => [],
                'total_prize' => '0.00',
                'source_state' => $source,
                'message' => 'Result not available for this draw.',
            ];
        }

        try {
            $check = $this->checker->check((int) $draw->getKey(), $number);
        } catch (\InvalidArgumentException $e) {
            throw GloDealerException::invalidResultInput($e->getMessage());
        }

        return [
            'ticket_number' => $number,
            'draw_id' => $draw->getKey(),
            'draw_number' => $draw->draw_number,
            'draw_date' => $draw->scheduled_at?->toDateString(),
            'won' => $check['won'],
            'matches' => $check['matches'],
            'total_prize' => $check['total_prize'],
            'source_state' => $source,
            // Public-safe claim/payment note from public verification if any.
            'claim_hint' => $check['won']
                ? 'Winning is informational; claim follows official GLO procedures.'
                : 'No matching prize category.',
        ];
    }

    /**
     * Structured result check sheet (machine-readable — not a government document).
     *
     * @return array<string, mixed>
     */
    public function resultSheet(?string $drawRef = null): array
    {
        $payload = $this->currentResult($drawRef);
        $label = (string) ($payload['source_state'] ?? GloSourceState::Unavailable->value);
        $isVerified = $label === GloSourceState::OfficialSourceVerified->value;

        $tiers = [];

        if (! empty($payload['has_result'])) {
            foreach (['first_prize' => $payload['first_prize'] ?? null,
                'second_prize' => $payload['second_prize'] ?? [],
                'third_prize' => $payload['third_prize'] ?? [],
                'fourth' => $payload['fourth'] ?? [],
                'fifth' => $payload['fifth'] ?? [],
                'front_three' => $payload['front_three'] ?? [],
                'last_three' => $payload['last_three'] ?? [],
                'last_two' => $payload['last_two'] ?? [],
            ] as $tier => $numbers) {
                if ($numbers === null || $numbers === [] || $numbers === '') {
                    continue;
                }
                $tiers[$tier] = is_array($numbers) ? array_values($numbers) : [$numbers];
            }
        }

        return [
            'sheet_type' => 'glo_result_check_sheet',
            'draw_id' => $payload['draw_id'] ?? null,
            'draw_number' => $payload['draw_number'] ?? null,
            'draw_date' => $payload['draw_date'] ?? null,
            'categories' => $tiers,
            'source_state' => $label,
            'source_badge' => $isVerified
                ? 'GLO VERIFIED SOURCE'
                : ($label === GloSourceState::FixtureOnly->value
                    ? 'INTERNAL DEMO / FIXTURE'
                    : $label),
            'disclaimer' => 'Machine-readable result summary from this application. Not an officially issued government document.',
            'has_result' => $payload['has_result'] ?? false,
        ];
    }

    /**
     * History index: date range, product filter, pagination, 2-year window.
     * Indexed SQL — never loads full history into PHP.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function history(array $query): LengthAwarePaginator
    {
        $years = (int) config('glo.result_experience.history_years', 2);
        $maxPage = (int) config('glo.result_experience.max_history_page_size', 50);
        $perPage = (int) config('glo.result_experience.history_page_size', 20);
        $perPage = max(1, min($perPage, $maxPage));
        $page = max(1, (int) ($query['page'] ?? 1));

        $windowStart = now()->subYears($years);

        if (! empty($query['from'])) {
            try {
                $from = Carbon::parse((string) $query['from']);
            } catch (\Throwable) {
                throw GloDealerException::invalidResultInput('invalid from date');
            }
            if ($from->lt($windowStart)) {
                throw GloDealerException::historyWindowExceeded('from date is older than '.$years.' years');
            }
        }

        if (! empty($query['to'])) {
            try {
                Carbon::parse((string) $query['to']);
            } catch (\Throwable) {
                throw GloDealerException::invalidResultInput('invalid to date');
            }
        }

        $base = Draw::query()
            ->whereIn('status', [DrawStatus::ResultPublished->value, DrawStatus::Completed->value])
            ->where('scheduled_at', '>=', $windowStart);

        if (! empty($query['from'])) {
            $base->where('scheduled_at', '>=', (string) $query['from']);
        }
        if (! empty($query['to'])) {
            $base->where('scheduled_at', '<=', ((string) $query['to']).' 23:59:59');
        }
        if (! empty($query['draw_date'])) {
            $base->whereDate('scheduled_at', (string) $query['draw_date']);
        }
        if (! empty($query['product'])) {
            // Product filter joins via GLO metadata lane when present; also
            // accepts explicit product for future multi-product indexes.
            $product = (string) $query['product'];
            $base->where(function ($q) use ($product): void {
                $q->where('type', $product)
                    ->orWhere('type', '2d');
            });
        }

        $total = (clone $base)->count();
        $draws = $base->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get(['id', 'draw_number', 'scheduled_at', 'status', 'type', 'uuid']);

        $rows = [];

        /** @var Draw $draw */
        foreach ($draws as $draw) {
            $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
            $rows[] = [
                'draw_id' => $draw->getKey(),
                'draw_number' => $draw->draw_number,
                'draw_date' => $draw->scheduled_at?->toDateString(),
                'product' => $draw->type->value,
                'has_result' => $result !== null,
                'first_prize' => $result?->first_prize,
                'source_state' => $this->sourceStateFor($result, $draw),
            ];
        }

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * Cached current result body. Key includes product|draw|result_version.
     *
     * @return array<string, mixed>
     */
    public function cachedResult(?string $drawRef = null): array
    {
        $draw = $this->resolveDraw($drawRef);
        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
        $version = $this->resultVersion($draw, $result);
        $key = implode('|', [
            'glo:result',
            (string) $draw->type->value,
            (string) $draw->getKey(),
            $version,
        ]);
        $ttl = (int) config('glo.result_experience.cache_ttl_seconds', 60);

        return $this->cache->remember($key, $ttl, function () use ($draw, $result): array {
            return $result === null
                ? $this->currentResult((string) $draw->getKey())
                : $this->resultPayload($draw, $result);
        });
    }

    private function resultPayload(Draw $draw, DrawResult $result): array
    {
        $recorded = $this->results->recordedForDraw((int) $draw->getKey());

        return [
            'draw_id' => $draw->getKey(),
            'draw_number' => $draw->draw_number,
            'draw_date' => $draw->scheduled_at?->toDateString(),
            'has_result' => true,
            'first_prize' => $result->first_prize,
            'second_prize' => $recorded['second'] ?? [],
            'third_prize' => $recorded['third'] ?? [],
            'fourth' => $recorded['fourth'] ?? [],
            'fifth' => $recorded['fifth'] ?? [],
            'front_three' => $recorded['front_three'] ?? [],
            'last_three' => $recorded['last_three'] ?? [],
            'last_two' => $recorded['last_two'] ?? [],
            'adjacent_first' => $recorded['adjacent_first'] ?? [],
            'published_at' => $result->published_at?->toIso8601String(),
            'source_state' => $this->sourceStateFor($result, $draw),
            'result_version' => $this->resultVersion($draw, $result),
        ];
    }

    private function resultVersion(Draw $draw, ?DrawResult $result): string
    {
        if ($result === null) {
            return 'none-'.sha1((string) $draw->getKey());
        }

        $meta = is_array($result->metadata) ? $result->metadata : [];
        $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];

        if (! empty($lane['import_fingerprint'])) {
            return (string) $lane['import_fingerprint'];
        }

        return 'pub-'.sha1(implode('|', [
            (string) $draw->getKey(),
            (string) $result->first_prize,
            (string) ($result->published_at?->getTimestamp() ?? $result->getKey()),
        ]));
    }

    private function resolveDraw(?string $drawRef): Draw
    {
        if ($drawRef === null || $drawRef === '') {
            $draw = Draw::query()
                ->whereIn('status', [DrawStatus::Completed->value, DrawStatus::ResultPublished->value])
                ->orderByDesc('scheduled_at')
                ->orderByDesc('id')
                ->first();

            if ($draw === null) {
                $draw = Draw::query()->orderByDesc('id')->first();
            }

            if ($draw === null) {
                throw GloDealerException::invalidResultInput('no draws available');
            }

            return $draw;
        }

        $draw = ctype_digit($drawRef)
            ? Draw::query()->find((int) $drawRef)
            : Draw::query()->where('draw_number', $drawRef)->orWhere('uuid', $drawRef)->first();

        if ($draw === null) {
            throw GloDealerException::invalidResultInput('draw not found');
        }

        return $draw;
    }
}
