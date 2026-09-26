<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloDealerRequestStatus;
use App\Enums\GloDealerRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditable dealer profile-change request (GLO-15 e-Service workflow).
 *
 * Only GloDealerChangeRequestService mutates status. Approval applies the
 * requested value to GloDealer under lock; dealers never self-approve.
 *
 * @property int $id
 * @property string $request_reference
 * @property int $dealer_id
 * @property int $requested_by
 * @property GloDealerRequestType $request_type
 * @property GloDealerRequestStatus $status
 * @property string|null $old_value
 * @property string $requested_value
 * @property string|null $reason
 * @property int|null $reviewed_by
 * @property string $audit_fingerprint
 */
class GloDealerChangeRequest extends Model
{
    protected $table = 'glo_dealer_change_requests';

    protected $fillable = [
        'request_reference',
        'dealer_id',
        'requested_by',
        'request_type',
        'status',
        'old_value',
        'requested_value',
        'reason',
        'reviewed_by',
        'review_note',
        'submitted_at',
        'reviewed_at',
        'decided_at',
        'audit_fingerprint',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'request_type' => GloDealerRequestType::class,
            'status' => GloDealerRequestStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(GloDealer::class, 'dealer_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
