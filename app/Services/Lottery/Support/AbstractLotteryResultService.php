<?php

declare(strict_types=1);

namespace App\Services\Lottery\Support;

use App\Contracts\Lottery\ProvidesCurrentResult;
use App\Enums\DrawPublicationStatus;
use App\Enums\GloSourceState;
use App\Enums\ResultVersionState;
use App\Lottery\Schema\LaneSchema;
use App\Lottery\Schema\LaneSchemaFactory;
use App\Models\Support\AbstractLotteryDraw;
use App\Models\Support\AbstractLotteryResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * The public read model for a single Weekly Lottery result.
 *
 * WHY THIS IS NOT THE NATIONAL SERVICE WITH A DIFFERENT TABLE NAME
 * ---------------------------------------------------------------------------
 * The genuinely shared parts were extracted rather than copied: calendar
 * handling lives in AbstractLotteryCalendarService, provenance in
 * AbstractLotterySourceService, the version lifecycle in ResultVersionState.
 * What remains here is what actually differs - a three-field result instead of
 * a six-field one, and a first-class RESULT_UNAVAILABLE state that the
 * National lane has no concept of. Forcing those two shapes into one class
 * would have meant a projection full of nulls and a branch on product key in
 * every method, which is harder to audit than two honest classes.
 *
 * HOW "THE CURRENT RESULT" IS CHOSEN - AND WHY NOT MAX(id)
 * ---------------------------------------------------------------------------
 * MAX(id) answers "which row was inserted last", a fact about the import
 * queue rather than about the lottery. It breaks in every way that matters: a
 * backfill of 2019 draws would make a 2019 draw current; a correction for last
 * month would displace this month's; an out-of-order import would publish the
 * wrong draw; a row in CONFLICT would win by being newest.
 *
 * The selection below asks the question that matters, in order:
 *
 *   1. the draw must be publicly live (DrawPublicationStatus::isPubliclyLive);
 *   2. its draw_date must not be in the future in the MARKET timezone;
 *   3. it must have a VERIFIED version - never pending, conflict, rejected or
 *      superseded;
 *   4. that version's source state must be one publication allows;
 *   5. among survivors: latest draw_date wins; on a tie, higher source trust;
 *      on a further tie, the higher version number.
 *
 * Each is a WHERE or ORDER BY on an indexed column, so the query is a bounded
 * index scan and not a table sort.
 *
 * A CURRENT DRAW WITH NO NUMBERS STAYS CURRENT
 * ---------------------------------------------------------------------------
 * If the newest published draw has result_status = unavailable, this service
 * returns THAT draw with status RESULT_UNAVAILABLE. It does not quietly skip
 * back to an older draw and present it as the latest, because that would show
 * a visitor stale numbers under a "latest result" heading with nothing saying
 * so. The page renders the gap, and the older draws remain visible in the
 * history strip immediately below - labelled as history, which is what they
 * are.
 *
 * CACHING
 * ---------------------------------------------------------------------------
 * Keys are weekly-lottery.result.{draw}.{version} and
 * weekly-lottery.current.{version}, with the projection version folded into
 * the prefix. The version in the key is what makes a correction safe: version
 * 2 writes a different key than version 1, so superseded numbers cannot be
 * served from cache even briefly. Cached values are plain arrays - never
 * Eloquent models - so a cache hit cannot resurrect a stale relation or carry
 * a hidden column into a view.
 */
abstract class AbstractLotteryResultService implements ProvidesCurrentResult
{
    use AppliesLaneOrdering;

    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly CacheRepository $cache,
        protected readonly AbstractLotteryCalendarService $dates,
        protected readonly AbstractLotterySourceService $sources,
    ) {}

    /**
     * The config file this lane reads, e.g. 'weekly_lottery'.
     *
     * Every config lookup in this class is relative to it, so a subclass
     * cannot accidentally read another lane's page size, cache TTL or search
     * map.
     */
    abstract protected function configPrefix(): string;

    /**
     * This lane's schema: its fields, their widths, which of them are
     * searchable, and whether a date alone identifies a draw.
     *
     * Built from the lane's config file, so the engine never has to be told
     * the same fact twice.
     */
    protected function schema(): LaneSchema
    {
        return app(LaneSchemaFactory::class)->for($this->configPrefix());
    }

    /**
     * A fresh query against this lane's draw table.
     *
     * The base class never names a concrete model, which is what lets Weekly
     * and Bingo/Mega share every rule below without sharing a table.
     *
     * @return Builder<covariant AbstractLotteryDraw>
     */
    abstract protected function drawQuery(): Builder;

    /**
     * The result the landing page shows.
     *
     * @return array<string, mixed>
     */
    public function currentResult(): array
    {
        $draw = $this->publiclyVisibleQuery()
            ->tap(fn ($q) => $this->applyNewestFirst($q))
            ->with($this->publicRelations())
            ->limit(5)
            ->get()
            ->sort($this->preferenceComparator(...))
            ->first();

        if (! $draw instanceof AbstractLotteryDraw) {
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

        if (! $draw instanceof AbstractLotteryDraw) {
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

        if (! $draw instanceof AbstractLotteryDraw) {
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
     * @return Builder<AbstractLotteryDraw>
     */
    public function publiclyVisibleQuery(): Builder
    {
        $today = $this->dates->toIsoDate($this->dates->today());

        return $this->drawQuery()
            // Both publicly-live publication states, read from the enum rather
            // than listed by hand, so a change to what "live" means cannot
            // leave this query behind.
            ->whereIn('publication_status', $this->publiclyLiveStatuses())
            // Plain comparison, not whereDate(): the column stores an exact
            // 'Y-m-d' string, so this is a sargable range scan on the index
            // instead of a function applied to every row.
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
     * @return array<string, mixed>
     */
    public function projectDraw(AbstractLotteryDraw $draw): array
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

    /** <lane>.result.{draw}.{version} */
    public function resultCacheKey(string $reference, int $version): string
    {
        return $this->prefix().'.result.'.$reference.'.'.$version;
    }

    /** <lane>.current.{version} */
    public function currentCacheKey(int $version): string
    {
        return $this->prefix().'.current.'.$version;
    }

    /**
     * Drop every cached projection for one draw.
     *
     * Called by the import service when a new version is published. Because
     * the key carries the version, the OLD key simply stops being requested;
     * this forget() retires it rather than leaving it to expire.
     */
    public function forgetDraw(string $reference, int $version): void
    {
        $this->cache->forget($this->resultCacheKey($reference, $version));
        $this->cache->forget($this->currentCacheKey($version));
    }

    /**
     * The shape returned when there is nothing to show.
     *
     * A real, renderable projection - not an exception and not a fabricated
     * result. 'status' is drawn from the closed public lookup vocabulary in
     * config, so an unknown status can never reach a template.
     *
     * @return array<string, mixed>
     */
    public function unavailableProjection(string $status): array
    {
        $allowed = $this->config->get($this->configPrefix().'.public_lookup_states');
        $status = is_array($allowed) && in_array($status, $allowed, true) ? $status : 'UNAVAILABLE';

        return [
            'status' => $status,
            'available' => false,
            'has_numbers' => false,
            'draw' => null,
            'numbers' => null,
            'provenance' => $this->sources->publicProvenance(null),
            'integrity' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildProjection(AbstractLotteryDraw $draw): array
    {
        $result = $draw->currentResult;
        $version = $draw->currentVersion;

        if (! $result instanceof AbstractLotteryResult) {
            return $this->unavailableProjection('RESULT_NOT_FOUND');
        }

        $date = CarbonImmutable::parse(
            (string) $draw->draw_date->format('Y-m-d'),
            $this->dates->timezone(),
        );

        $numbers = $result->toPublicArray();
        $hasNumbers = (bool) $numbers['available'];

        return [
            // A draw that published nothing is FOUND but UNAVAILABLE. The two
            // are different facts and the page says which one it is.
            'status' => $hasNumbers ? 'RESULT_FOUND' : 'RESULT_UNAVAILABLE',
            'available' => true,
            'has_numbers' => $hasNumbers,
            'draw' => [
                'reference' => (string) $draw->draw_reference,
                'date' => $this->dates->projection($date),
                // Only lanes whose schema says a date is not enough carry a
                // draw time. Emitting a null 'time_local' for every other lane
                // would put a field in the public JSON contract that means
                // nothing there.
                ...$this->drawTimeProjection($draw),
                'timezone' => (string) $draw->draw_timezone,
                'published_at' => $draw->published_at?->toIso8601String(),
            ],
            'numbers' => $numbers,
            'provenance' => $this->sources->publicProvenance($version),
            'integrity' => $version?->integrityProjection(),
        ];
    }

    /**
     * Tie-break between candidate draws that share the latest date.
     *
     * Only ever applied to a handful of already-filtered rows (limit 5), so
     * this is an in-memory comparison of a tiny set, not a sort of the table.
     */
    protected function preferenceComparator(AbstractLotteryDraw $a, AbstractLotteryDraw $b): int
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
    protected function publiclyLiveStatuses(): array
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
    protected function publishableSourceStates(): array
    {
        $allowed = $this->config->get($this->configPrefix().'.publication.publishable_source_states');

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

    protected function cacheEnabled(): bool
    {
        return (bool) $this->config->get($this->configPrefix().'.cache.enabled', true);
    }

    protected function ttl(): int
    {
        $ttl = $this->config->get($this->configPrefix().'.cache.ttl_seconds');

        return is_int($ttl) && $ttl > 0 ? $ttl : 300;
    }

    protected function prefix(): string
    {
        $prefix = $this->config->get($this->configPrefix().'.cache.key_prefix');
        $prefix = is_string($prefix) && $prefix !== ''
            ? $prefix
            : str_replace('_', '-', $this->configPrefix());

        $projection = $this->config->get($this->configPrefix().'.projection_version');
        $projection = is_string($projection) && $projection !== '' ? $projection : '1';

        return $prefix.'.v'.$projection;
    }

    /**
     * The draw-time block, for lanes that have one.
     *
     * 'time_local' is the canonical 'HH:MM' clock reading and is what an API
     * consumer should compare or sort on. 'time_display' is the same reading
     * formatted for a human and is never parsed back - a display string that
     * something depends on stops being a display string.
     *
     * @return array<string, string|null>
     */
    protected function drawTimeProjection(AbstractLotteryDraw $draw): array
    {
        if (! $this->schema()->hasDrawTimes) {
            return [];
        }

        $local = $draw->getAttribute('draw_time_local');
        $local = is_string($local) && $local !== '' ? $local : null;

        $format = $this->config->get($this->configPrefix().'.draw_times.display_format');
        $format = is_string($format) && $format !== '' ? $format : 'H:i';

        $display = null;

        if ($local !== null) {
            // Formatted from the clock reading itself, with no date and no
            // timezone attached, so nothing here can move a 21:00 draw to
            // another day.
            [$hour, $minute] = array_pad(explode(':', $local, 2), 2, '00');
            $display = CarbonImmutable::createFromTime((int) $hour, (int) $minute, 0)->format($format);
        }

        return [
            'time_local' => $local,
            'time_display' => $display,
        ];
    }
}
