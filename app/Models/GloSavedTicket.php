<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloSavedTicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * User's pre-draw saved ticket (GLO-17).
 *
 * Unique (user_id, ticket_id). Removal sets Inactive — historical
 * notification and audit evidence is never deleted.
 *
 * @property int $id
 * @property int $user_id
 * @property int $ticket_id
 * @property int $draw_id
 * @property string $product
 * @property string $ticket_reference
 * @property GloSavedTicketStatus $status
 * @property Carbon|null $saved_at
 * @property string $notification_state
 * @property string|null $result_version
 */
class GloSavedTicket extends Model
{
    protected $table = 'glo_saved_tickets';

    protected $fillable = [
        'user_id',
        'ticket_id',
        'draw_id',
        'product',
        'ticket_reference',
        'status',
        'saved_at',
        'removed_at',
        'notification_state',
        'notification_sent_at',
        'result_version',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => GloSavedTicketStatus::class,
            'saved_at' => 'datetime',
            'removed_at' => 'datetime',
            'notification_sent_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    public function isActive(): bool
    {
        return $this->status === GloSavedTicketStatus::Active;
    }
}
