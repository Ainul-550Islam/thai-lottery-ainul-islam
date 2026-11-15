<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RewardType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property RewardType $type
 * @property int $points_cost
 * @property array<string, mixed> $payload
 * @property int|null $stock
 * @property int|null $per_member_limit
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RewardCatalogItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'points_cost',
        'payload',
        'stock',
        'per_member_limit',
        'starts_at',
        'ends_at',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RewardType::class,
            'points_cost' => 'integer',
            'payload' => 'array',
            'stock' => 'integer',
            'per_member_limit' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeAvailable(Builder $query, ?CarbonInterface $now = null): Builder
    {
        $pointInTime = $now ?? Carbon::now();

        return $query
            ->where('active', true)
            ->where(function (Builder $sub) use ($pointInTime): void {
                $sub->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $pointInTime);
            })
            ->where(function (Builder $sub) use ($pointInTime): void {
                $sub->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $pointInTime);
            })
            ->where(function (Builder $sub): void {
                $sub->whereNull('stock')
                    ->orWhere('stock', '>', 0);
            });
    }

    public function isAvailableAt(?CarbonInterface $now = null): bool
    {
        $pointInTime = $now ?? Carbon::now();

        if (! $this->active) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->gt($pointInTime)) {
            return false;
        }

        if ($this->ends_at !== null && $this->ends_at->lt($pointInTime)) {
            return false;
        }

        return $this->hasStockAvailable();
    }

    public function hasStockAvailable(int $quantity = 1): bool
    {
        if ($this->stock === null) {
            return true;
        }

        return $this->stock >= max(1, $quantity);
    }

    public function remainingStock(): ?int
    {
        return $this->stock;
    }

    /**
     * @return HasMany<RewardClaim, self>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(RewardClaim::class, 'item_id');
    }
}
