<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\GloSourceState;
use App\Enums\RiskLevel;
use App\Exceptions\GloDealerException;
use App\Models\AuditLog;
use App\Models\GloDealer;
use App\Models\GloSalesPoint;
use App\Models\GloSalesPointHistory;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * GLO-16 daily sales-point management + public finder.
 *
 * Dealer daily location update:
 *  - own dealer only, active status, valid coordinates, today's date
 *  - unique (dealer, date) — duplicate refused; history append-only
 *  - current public location = projection of the latest valid record
 *
 * Public search:
 *  - bounded page size and result count
 *  - optional radius with haversine filter when coordinates provided
 *  - never invents distances when coordinates missing
 *  - public payload excludes dealer_ref, audit, credentials, internal IDs
 */
class GloSalesPointService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Record today's sales location for the dealer's current point.
     *
     * @param array{
     *     display_name?: string,
     *     address?: string|null,
     *     province?: string|null,
     *     district?: string|null,
     *     subdistrict?: string|null,
     *     latitude?: float|string|null,
     *     longitude?: float|string|null,
     *     public_contact?: string|null,
     *     effective_date?: string|null
     * } $input
     */
    public function updateDailyLocation(GloDealer $dealer, array $input, User $actor): GloSalesPointHistory
    {
        if ((int) $dealer->user_id !== (int) $actor->getKey()) {
            throw GloDealerException::forbidden('update another dealer\'s sales location');
        }

        if (! $dealer->status->mayUpdateSalesLocation()) {
            throw GloDealerException::notActive('update sales location');
        }

        $today = now()->toDateString();
        $effectiveDate = (string) ($input['effective_date'] ?? $today);

        // Only today's location (or an explicit equal-today date) — no
        // arbitrary historical backdating.
        if ($effectiveDate !== $today) {
            throw GloDealerException::invalidDate('only today\'s sales location may be recorded');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            throw GloDealerException::invalidDate('expected YYYY-MM-DD');
        }

        $lat = $this->parseCoordinate($input['latitude'] ?? null, 'latitude');
        $lng = $this->parseCoordinate($input['longitude'] ?? null, 'longitude');

        if (($lat === null) !== ($lng === null)) {
            throw GloDealerException::invalidCoordinates('latitude and longitude must both be present or both absent');
        }

        $displayName = trim((string) ($input['display_name'] ?? $dealer->sales_location ?? 'Sales point'));
        if ($displayName === '' || mb_strlen($displayName) > 255) {
            throw GloDealerException::invalidCoordinates('display_name required (max 255)');
        }

        return $this->db->connection()->transaction(function () use ($dealer, $actor, $input, $lat, $lng, $displayName, $effectiveDate): GloSalesPointHistory {
            // Duplicate update for the same day refused under lock.
            $dup = GloSalesPointHistory::query()
                ->where('dealer_id', $dealer->getKey())
                ->whereDate('effective_date', $effectiveDate)
                ->lockForUpdate()
                ->exists();

            if ($dup) {
                throw GloDealerException::duplicateLocationUpdate($effectiveDate);
            }

            $point = GloSalesPoint::query()
                ->where('dealer_id', $dealer->getKey())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($point === null) {
                $point = GloSalesPoint::create([
                    'sales_point_code' => 'SP-'.Str::upper(Str::random(12)),
                    'dealer_id' => $dealer->getKey(),
                    'display_name' => $displayName,
                    'status' => 'active',
                    'verification_state' => 'unverified',
                    'valid_from' => now()->startOfDay(),
                    'source' => 'operator',
                    'source_state' => GloSourceState::InternalReconciled->value,
                    'metadata' => ['synthetic_fixture' => false, 'dealer_owned' => true],
                ]);
            }

            $point->display_name = $displayName;
            $point->address = $input['address'] ?? $point->address;
            $point->province = $input['province'] ?? $point->province;
            $point->district = $input['district'] ?? $point->district;
            $point->subdistrict = $input['subdistrict'] ?? $point->subdistrict;
            $point->latitude = $lat !== null ? number_format($lat, 7, '.', '') : $point->latitude;
            $point->longitude = $lng !== null ? number_format($lng, 7, '.', '') : $point->longitude;
            $point->public_contact = array_key_exists('public_contact', $input)
                ? $input['public_contact']
                : $point->public_contact;
            $point->valid_from = now()->startOfDay();
            $point->valid_to = null;
            $point->save();

            // Mirror into dealer profile sales_location text (canonical).
            $dealer->sales_location = $displayName.($point->address !== null ? ', '.$point->address : '');
            if (($input['province'] ?? null) !== null) {
                $dealer->province = (string) $input['province'];
            }
            if (($input['district'] ?? null) !== null) {
                $dealer->district = (string) $input['district'];
            }
            $dealer->save();

            $history = GloSalesPointHistory::create([
                'sales_point_id' => $point->getKey(),
                'dealer_id' => $dealer->getKey(),
                'display_name' => $displayName,
                'address' => $point->address,
                'province' => $point->province,
                'district' => $point->district,
                'subdistrict' => $point->subdistrict,
                'latitude' => $point->latitude,
                'longitude' => $point->longitude,
                'effective_date' => $effectiveDate,
                'source' => 'operator',
                'source_state' => GloSourceState::InternalReconciled->value,
                'metadata' => [
                    'action_type' => 'glo_daily_sales_location_updated',
                    'sales_point_code' => $point->sales_point_code,
                ],
            ]);

            AuditLog::create([
                'user_id' => $actor->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => GloSalesPoint::class,
                'auditable_id' => $point->getKey(),
                'description' => 'glo_daily_sales_location_updated',
                'metadata' => [
                    'action_type' => 'glo_daily_sales_location_updated',
                    'dealer_id' => $dealer->getKey(),
                    'effective_date' => $effectiveDate,
                    'sales_point_code' => $point->sales_point_code,
                    'has_coordinates' => $lat !== null,
                ],
            ]);

            return $history;
        });
    }

    /**
     * Public sales-point search — bounded, privacy-safe.
     *
     * @param array{
     *     latitude?: float|string|null,
     *     longitude?: float|string|null,
     *     radius?: float|int|string|null,
     *     province?: string|null,
     *     district?: string|null,
     *     q?: string|null,
     *     page?: int
     * } $query
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function publicSearch(array $query): LengthAwarePaginator
    {
        $maxPage = (int) config('glo.sales_points.max_page_size', 50);
        $defaultPage = (int) config('glo.sales_points.public_page_size', 20);
        $maxResults = (int) config('glo.sales_points.max_results', 50);

        $perPage = max(1, min($defaultPage, $maxPage));
        $page = max(1, (int) ($query['page'] ?? 1));

        $lat = isset($query['latitude']) && $query['latitude'] !== null && $query['latitude'] !== ''
            ? $this->parseCoordinate($query['latitude'], 'latitude')
            : null;
        $lng = isset($query['longitude']) && $query['longitude'] !== null && $query['longitude'] !== ''
            ? $this->parseCoordinate($query['longitude'], 'longitude')
            : null;

        if (($lat === null) !== ($lng === null)) {
            throw GloDealerException::invalidCoordinates('latitude and longitude must be paired');
        }

        $radius = null;
        if ($lat !== null) {
            $maxRadius = (float) config('glo.sales_points.max_radius_km', 50.0);
            $defaultRadius = (float) config('glo.sales_points.default_radius_km', 10.0);
            $requested = isset($query['radius']) && $query['radius'] !== null && $query['radius'] !== ''
                ? (float) $query['radius']
                : $defaultRadius;

            if ($requested <= 0 || $requested > $maxRadius) {
                throw GloDealerException::invalidCoordinates('radius must be between 0 and '.$maxRadius.' km');
            }
            $radius = $requested;
        }

        $base = GloSalesPoint::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', now());
            });

        if (! empty($query['province'])) {
            $base->where('province', 'like', '%'.trim((string) $query['province']).'%');
        }
        if (! empty($query['district'])) {
            $base->where('district', 'like', '%'.trim((string) $query['district']).'%');
        }
        if (! empty($query['q'])) {
            $term = trim((string) $query['q']);
            $base->where(function ($q) use ($term): void {
                $q->where('display_name', 'like', '%'.$term.'%')
                    ->orWhere('address', 'like', '%'.$term.'%');
            });
        }

        // Cap total scanned rows before PHP distance filter.
        $candidateCap = max($maxResults * 10, 200);
        /** @var Collection<int, GloSalesPoint> $candidates */
        $candidates = $base->orderBy('id')->limit($candidateCap)->get();

        $rows = [];
        $totalFiltered = 0;

        foreach ($candidates as $point) {
            $distance = $lat !== null ? $point->distanceKmFrom($lat, $lng) : null;

            if ($radius !== null && $distance !== null && $distance > $radius) {
                continue;
            }

            $totalFiltered++;
            $rows[] = $this->publicRow($point, $distance);
        }

        // Hard bound on public result count.
        $rows = array_slice($rows, 0, $maxResults);
        $totalFiltered = min($totalFiltered, $maxResults);

        $offset = ($page - 1) * $perPage;
        $slice = array_slice($rows, $offset, $perPage);

        return new LengthAwarePaginator(
            $slice,
            $totalFiltered,
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
    }

    /**
     * Public-safe row: no internal dealer IDs, no audit, no credentials.
     * Contact only when explicitly set as public_contact.
     *
     * @return array<string, mixed>
     */
    public function publicRow(GloSalesPoint $point, ?float $distanceKm): array
    {
        return [
            'code' => $point->sales_point_code,
            'name' => $point->display_name,
            'address' => $point->address,
            'province' => $point->province,
            'district' => $point->district,
            'subdistrict' => $point->subdistrict,
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'distance_km' => $distanceKm !== null ? number_format($distanceKm, 2, '.', '') : null,
            'public_contact' => $point->public_contact,
            'status' => $point->status,
            'verification_state' => $point->verification_state,
            'source_state' => $point->source_state,
            'source' => $point->source,
        ];
    }

    /**
     * Operator verification of a sales point.
     */
    public function verify(GloSalesPoint $point, User $operator, string $state): GloSalesPoint
    {
        if (! in_array($state, ['verified', 'rejected', 'unverified'], true)) {
            throw GloDealerException::invalidCoordinates('unknown verification state');
        }

        $allowed = $operator->can('manage glo sales points') || $operator->hasRole('super-admin');
        if (! $allowed) {
            throw GloDealerException::forbidden('verify sales points');
        }

        return $this->db->connection()->transaction(function () use ($point, $operator, $state): GloSalesPoint {
            $fresh = GloSalesPoint::query()->whereKey($point->getKey())->lockForUpdate()->firstOrFail();
            $fresh->verification_state = $state;
            $fresh->save();

            AuditLog::create([
                'user_id' => $operator->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::Medium,
                'auditable_type' => GloSalesPoint::class,
                'auditable_id' => $fresh->getKey(),
                'description' => 'glo_sales_point_verified',
                'metadata' => [
                    'action_type' => 'glo_sales_point_verified',
                    'state' => $state,
                    'sales_point_code' => $fresh->sales_point_code,
                ],
            ]);

            return $fresh;
        });
    }

    /**
     * Official sync is NOT_CONFIGURED — no authorized public sales-point feed.
     *
     * @return array{status: string, source_state: string, detail: string}
     */
    public function officialSyncStatus(): array
    {
        $mode = (string) config('glo.sales_points.official_sync.mode', 'not_configured');

        return [
            'status' => $mode === 'not_configured' ? 'not_configured' : $mode,
            'source_state' => $mode === 'not_configured'
                ? GloSourceState::NotConfigured->value
                : GloSourceState::OfficialSourceConfigured->value,
            'detail' => $mode === 'not_configured'
                ? 'No authorized GLO sales-point feed is configured for this project.'
                : 'Official sales-point sync mode: '.$mode,
        ];
    }

    private function parseCoordinate(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw GloDealerException::invalidCoordinates($field.' must be numeric');
        }

        $f = (float) $value;

        if ($field === 'latitude' && ($f < -90.0 || $f > 90.0)) {
            throw GloDealerException::invalidCoordinates('latitude out of range');
        }
        if ($field === 'longitude' && ($f < -180.0 || $f > 180.0)) {
            throw GloDealerException::invalidCoordinates('longitude out of range');
        }

        return $f;
    }
}
