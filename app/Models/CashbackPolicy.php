<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountGradeLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $grade
 * @property int $rate_bps
 * @property string $period
 * @property string $basis
 * @property string $min_claim
 * @property string $max_per_period
 * @property int $expiry_days
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CashbackPolicy extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'grade',
        'rate_bps',
        'period',
        'basis',
        'min_claim',
        'max_per_period',
        'expiry_days',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_bps' => 'integer',
            'min_claim' => 'decimal:2',
            'max_per_period' => 'decimal:2',
            'expiry_days' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveForGrade(Builder $query, AccountGradeLevel|string $grade): Builder
    {
        $key = $grade instanceof AccountGradeLevel ? $grade->value : $grade;

        return $query
            ->where('grade', $key)
            ->where('active', true);
    }

    /**
     * @return HasMany<CashbackAccrual, self>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(CashbackAccrual::class, 'policy_id');
    }

    public function gradeEnum(): AccountGradeLevel
    {
        return AccountGradeLevel::fromKeyOrDefault((string) $this->grade);
    }
}
