<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Contracts\Lottery\ProvidesCurrentResult;
use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Models\NationalLotteryDraw;
use App\Models\NationalLotteryResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public read model for a single National Lottery result.
 *
 * HOW "THE CURRENT RESULT" IS CHOSEN - AND WHY NOT MAX(id)
 * ---------------------------------------------------------------------------
 * MAX(id) answers "which row was inserted last", which is a fact about the
 * import queue, not about the lottery. It breaks in every way that matters:
 * a backfill of 2019 draws would make a 2019 draw "current"; a corrected
 * result inserted for last month would displace this month's; an import that
 * arrives out of order would publish the wrong draw; and a row in CONFLICT
 * would win simply by being newest.
 *
 * The selection below asks the question that actually matters, in this order:
 *
 *   1. the draw must be PUBLISHED (DrawPublicationStatus::isPubliclyLive);
 *   2. its draw_date must not be in the future in the MARKET timezone;
 *   3. it must have a version in state VERIFIED - never pending, never
 *      conflict, never rejected, never superseded;
 *   4. that version's source state must be one publication allows;
 *   5. among the survivors: latest draw_date wins; on a tie, higher source
 *      trust wins; on a further tie, the higher version_number wins.
 *
 * Every one of those five is a WHERE or an ORDER BY on an indexed column, so
 * the query is a bounded index scan and not a table sort.
 *
 * NOTHING IS INVENTED
 * ---------------------------------------------------------------------------
 * When no draw satisfies the conditions, this service returns an unavailable
 * projection carrying NO_PUBLIC_DATA. It does not reach for the most recent
 * unpublished draw, it does not reach for a fixture when the official lane is
 * configured, and it never synthesises numbers. A page with no data says so.
 *
 * CACHING
 * ---------------------------------------------------------------------------
 * Keys are national-lottery.result.{draw}.{version} exactly as specified, with
 * the projection version folded into the prefix. The VERSION in the key is
 * what makes a correction safe: version 2 writes a different key than version
 * 1, so a superseded number cannot be served from cache even for a second.
 * Cached values are plain arrays - never Eloquent models - so a cache hit
 * cannot resurrect a stale relation or carry a hidden column into a view.
 */
class NationalLotteryResultService implements ProvidesCurrentResult
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly NationalLotteryDateService $dates,
        private readonly NationalLotterySourceService $sources,
    ) {}

    /**
     * The result the landing page shows.
     *
     * @return array<string, mixed>
     */
    public function currentResult(): array
    {
        $draw = $this->publiclyVisibleQuery()
            ->orderByDesc('draw_date')
            ->orderByDesc('id')
            ->with($this->publicRelations())
            ->limit(5)
            ->get()
            ->sort($this->preferenceComparator(...))
            ->first();

        if (! $draw instanceof NationalLotteryDraw) {
            return $this->unavailableProjection('NO_PUBLIC_DATA');
        }

        return $this->projectDraw($draw);
    }

    /**
     * One draw by its public reference.
     *
     * @return array<string, mixed>
     */
    public function resultForReference(string $reference): array
    {
        $reference = trim($reference);

        // Bounded and shape-checked before it reaches the database.
        if ($reference === '' || strlen($reference) > 40 || preg_match('/^[A-Z0-9\-]+$/', $reference) !== 1) {
            return $this->unavailableProjection('INVALID_QUERY');
        }

        $draw = $this->publiclyVisibleQuery()
            ->where('draw_reference', $reference)
            ->with($this->publicRelations())
            ->first();

        if (! $draw instanceof NationalLotteryDraw) {
            return $this->unavailableProjection('RESULT_NOT_FOUND');
        }

        return $this->projectDraw($draw);
    }

    /**
     * One draw by business date.
     *
     * @return array<string, mixed>
     */
    public function resultForDate(CarbonImmutable $date): array
    {
        $draw = $this->publiclyVisibleQuery()
            ->where('draw_date', '=', $this->dates->toIsoDate($date))
            ->with($this->publicRelations())
            ->first();

        if (! $draw instanceof NationalLotteryDraw) {
            return $this->unavailableProjection('RESULT_NOT_FOUND');
        }

        return $this->projectDraw($draw);
    }

    /**
     * The base query every public read starts from.
     *
     * Exposed so History and Search reuse the SAME visibility rules. Two
     * places deciding independently what "public" means is how an unpublished
     * draw eventually leaks through the one that was not updated.
     *
     * @return Builder<NationalLotteryDraw>
     */
    public function publiclyVisibleQuery(): Builder
    {
        $today = $this->dates->toIsoDate($this->dates->today());

        return NationalLotteryDraw::query()
            // Both publicly-live publication states, read from the enum
            // rather than listed by hand, so a change to what "live" means
            // cannot leave this query behind.
            ->whereIn('publication_status', $this->publiclyLiveStatuses())
            // Plain comparison, not whereDate(): the column stores an exact
            // 'Y-m-d' string, so this is a sargable range scan on the index
            // instead of a function call applied to every row.
            ->where('draw_date', '<=', $today)
            ->whereHas('versions', function (Builder $query): void {
                $query
                    ->where('state', ResultVersionState::Verified->value)
                    ->whereIn('source_state', $this->publishableSourceStates());
            })
            ->whereHas('results', function (Builder $query): void {
                $query->where('is_current', true);
            });
    }

    /**
     * Relations every public projection needs, loaded once.
     *
     * This is the N+1 guard: a history page rendering 20 draws issues three
     * queries, not sixty-one.
     *
     * @return array<int, string>
     */
    public function publicRelations(): array
    {
        return ['currentResult', 'currentVersion'];
    }

    /**
     * Turn a loaded draw into the array a view or an API response renders.
     *
     * Cached per draw AND per version. A projection is a plain array: no
     * model, no relation, no internal id.
     *
     * @return array<string, mixed>
     */
    public function projectDraw(NationalLotteryDraw $draw): array
    {
        $version = $draw->currentVersion;
        $versionNumber = $version !== null ? (int) $version->version_number : 0;

        if (! $this->cacheEnabled()) {
            return $this->buildProjection($draw);
        }

        $key = $this->resultCacheKey((string) $draw->draw_reference, $versionNumber);

        $cached = $this->cache->remember(
            $key,
            $this->ttl(),
            fn (): array => $this->buildProjection($draw),
        );

        return is_array($cached) ? $cached : $this->buildProjection($draw);
    }

    /**
     * national-lottery.result.{draw}.{version}
     */
    public function resultCacheKey(string $reference, int $version): string
    {
        return $this->prefix().'.result.'.$reference.'.'.$version;
    }

    /**
     * Drop every cached projection for one draw.
     *
     * Called by the import service when a new version is published. Because
     * the key carries the version, the OLD key simply stops being requested;
     * this forget() is belt-and-braces for the case where the same version
     * number is re-projected after a state change.
     */
    public function forgetDraw(string $reference, int $version): void
    {
        $this->cache->forget($this->resultCacheKey($reference, $version));
    }

    /**
     * The shape returned when there is nothing to show.
     *
     * A real, honest, renderable projection - not an exception and not a
     * fabricated result. 'status' is drawn from the closed public lookup
     * vocabulary in config.
     *
     * @return array<string, mixed>
     */
    public function unavailableProjection(string $status): array
    {
        $allowed = $this->config->get('national_lottery.public_lookup_states');
        $status = is_array($allowed) && in_array($status, $allowed, true) ? $status : 'UNAVAILABLE';

        return [
            'status' => $status,
            'available' => false,
            'draw' => null,
            'numbers' => null,
            'provenance' => $this->sources->publicProvenance(null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjection(NationalLotteryDraw $draw): array
    {
        $result = $draw->currentResult;
        $version = $draw->currentVersion;

        if (! $result instanceof NationalLotteryResult) {
            return $this->unavailableProjection('RESULT_NOT_FOUND');
        }

        $date = CarbonImmutable::parse((string) $draw->draw_date->format('Y-m-d'), $this->dates->timezone());

        return [
            'status' => 'RESULT_FOUND',
            'available' => true,
            'draw' => [
                'reference' => (string) $draw->draw_reference,
                'date' => $this->dates->projection($date),
                'published_at' => $draw->published_at?->toIso8601String(),
            ],
            'numbers' => $result->toPublicArray(),
            'provenance' => $this->sources->publicProvenance($version),
        ];
    }

    /**
     * Tie-break between candidate draws that share the latest date.
     *
     * Only ever applied to a handful of already-filtered rows (limit 5), so
     * this is an in-memory comparison of a tiny set, not a sort of the table.
     */
    private function preferenceComparator(NationalLotteryDraw $a, NationalLotteryDraw $b): int
    {
        $dateComparison = strcmp(
            (string) $b->draw_date->format('Y-m-d'),
            (string) $a->draw_date->format('Y-m-d'),
        );

        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        $trustComparison = $this->sources->trustRank($this->sources->resolve($b->source_state))
            <=> $this->sources->trustRank($this->sources->resolve($a->source_state));

        if ($trustComparison !== 0) {
            return $trustComparison;
        }

        $versionA = $a->currentVersion !== null ? (int) $a->currentVersion->version_number : 0;
        $versionB = $b->currentVersion !== null ? (int) $b->currentVersion->version_number : 0;

        return $versionB <=> $versionA;
    }

    /**
     * @return list<string>
     */
    private function publiclyLiveStatuses(): array
    {
        $statuses = [];

        foreach (DrawPublicationStatus::cases() as $case) {
            if ($case->isPubliclyLive()) {
                $statuses[] = $case->value;
            }
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private function publishableSourceStates(): array
    {
        $allowed = $this->config->get('national_lottery.publication.publishable_source_states');

        if (! is_array($allowed)) {
            return [GloSourceState::OfficialSourceVerified->value];
        }

        $out = [];

        foreach ($allowed as $state) {
            if (is_string($state) && GloSourceState::tryFrom($state) !== null) {
                $out[] = $state;
            }
        }

        return $out === [] ? [GloSourceState::OfficialSourceVerified->value] : $out;
    }

    private function cacheEnabled(): bool
    {
        return (bool) $this->config->get('national_lottery.cache.enabled', true);
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('national_lottery.cache.ttl_seconds');

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('national_lottery.cache.key_prefix');
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'national-lottery';

        $projection = $this->config->get('national_lottery.projection_version');
        $projection = is_string($projection) && $projection !== '' ? $projection : '1';

        return $prefix.'.v'.$projection;
    }
}
