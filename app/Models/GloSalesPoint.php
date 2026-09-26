<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Public sales-point projection (GLO-16). Current row is the latest valid
 * record; history is append-only in glo_sales_point_history.
 *
 * Coordinates may be SYNTHETIC_FIXTURE labeled — never presented as a real
 * GLO seller location without an authorized source.
 *
 * @property int $id
 * @property string $sales_point_code
 * @property int|null $dealer_id
 * @property string $display_name
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string $status
 * @property string $verification_state
 * @property string $source
 * @property string $source_state
 */
class GloSalesPoint extends Model
{
    protected $table = 'glo_sales_points';

    protected $fillable = [
        'sales_point_code',
        'dealer_id',
        'display_name',
        'address',
        'province',
        'district',
        'subdistrict',
        'latitude',
        'longitude',
        'public_contact',
        'status',
        'verification_state',
        'valid_from',
        'valid_to',
        'source',
        'source_state',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'string',
            'longitude' => 'string',
            'valid_from' => 'datetime',
            'valid_to' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(GloDealer::class, 'dealer_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(GloSalesPointHistory::class, 'sales_point_id');
    }

    public function isVerified(): bool
    {
        return $this->verification_state === 'verified';
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Haversine distance in kilometres. Returns null when either side lacks
     * coordinates — never invents a distance.
     */
    public function distanceKmFrom(?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null || ! $this->hasCoordinates()) {
            return null;
        }

        $lat1 = (float) $this->latitude;
        $lng1 = (float) $this->longitude;
        $earthKm = 6371.0;

        $dLat = deg2rad($lat - $lat1);
        $dLng = deg2rad($lng - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round($earthKm * $c, 2);
    }
}
