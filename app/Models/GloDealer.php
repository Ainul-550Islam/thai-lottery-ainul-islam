<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloDealerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * GLO e-Service dealer profile (GLO-15).
 *
 * User-bound. Additive to Agent (commission) and RetailVendor (quota channel)
 * — those rows are optionally linked via agent_id / retail_vendor_id and are
 * never replaced. dealer_ref is a SYNTHETIC project reference.
 *
 * Profile fields are ONLY mutated by GloDealerChangeRequestService after an
 * approved request — never by direct dealer write.
 *
 * @property int $id
 * @property int $user_id
 * @property string $dealer_ref
 * @property string $dealer_type
 * @property GloDealerStatus $status
 * @property string $verification_state
 * @property string|null $display_name
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $sales_location
 */
class GloDealer extends Model
{
    protected $table = 'glo_dealers';

    protected $fillable = [
        'user_id',
        'retail_vendor_id',
        'agent_id',
        'dealer_ref',
        'dealer_type',
        'status',
        'verification_state',
        'display_name',
        'address',
        'phone',
        'province',
        'district',
        'subdistrict',
        'sales_location',
        'capabilities',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GloDealerStatus::class,
            'capabilities' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function retailVendor(): BelongsTo
    {
        return $this->belongsTo(RetailVendor::class, 'retail_vendor_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(GloDealerChangeRequest::class, 'dealer_id');
    }

    public function salesPoints(): HasMany
    {
        return $this->hasMany(GloSalesPoint::class, 'dealer_id');
    }

    public function isActive(): bool
    {
        return $this->status === GloDealerStatus::Active;
    }

    /**
     * Synthetic reference — documented as never a real GLO identity.
     */
    public static function makeDealerRef(int $userId): string
    {
        return sprintf('SYN-DEALER-%d-%s', $userId, substr(sha1('glo-dealer-ref|'.$userId), 0, 8));
    }
}
