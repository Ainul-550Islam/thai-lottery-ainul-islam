<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloPaymentHoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * GLO-12 immutable payment-hold record created when a FROZEN ticket wins.
 *
 * While this row is Active, every payment path must fail closed. The row is
 * never deleted: release/clear transitions stamp released_at + reason and keep
 * the history for audit.
 *
 * @property int $id
 * @property string $hold_reference
 * @property int|null $claim_id
 * @property int $freeze_id
 * @property int $ticket_id
 * @property int $draw_id
 * @property string|null $winning_category
 * @property string|null $gross_prize
 * @property string|null $stamp_duty
 * @property string $hold_reason
 * @property GloPaymentHoldStatus $status
 * @property Carbon $created_at
 * @property Carbon|null $released_at
 * @property string|null $release_reason
 * @property string $fingerprint
 */
class GloPrizePaymentHold extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'hold_reference',
        'claim_id',
        'freeze_id',
        'ticket_id',
        'draw_id',
        'winning_category',
        'gross_prize',
        'stamp_duty',
        'hold_reason',
        'status',
        'created_at',
        'released_at',
        'release_reason',
        'fingerprint',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GloPaymentHoldStatus::class,
            'gross_prize' => 'string',
            'stamp_duty' => 'string',
            'created_at' => 'datetime',
            'released_at' => 'datetime',
            'audit_metadata' => 'array',
        ];
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(GloPrizeClaim::class, 'claim_id');
    }

    public function freeze(): BelongsTo
    {
        return $this->belongsTo(GloTicketFreeze::class, 'freeze_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(GloTicket::class, 'ticket_id');
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }
}
