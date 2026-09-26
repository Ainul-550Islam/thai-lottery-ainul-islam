<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * GLO-specific notification delivery tracking (GLO-17).
 *
 * delivery_key is the SHA-256 of user_id|ticket_id|result_version|type —
 * the unique idempotency surface. The actual notification row lives on the
 * shared notifications table (message_fingerprint unique). Push provider is
 * recorded honestly (not_configured unless wired).
 *
 * @property int $id
 * @property string $delivery_key
 * @property int|null $notification_id
 * @property int $user_id
 * @property string $delivery_state
 * @property string $channel
 * @property string $provider
 */
class GloNotificationDelivery extends Model
{
    protected $table = 'glo_notification_deliveries';

    protected $fillable = [
        'delivery_key',
        'notification_id',
        'user_id',
        'ticket_id',
        'draw_id',
        'result_version',
        'notification_type',
        'delivery_state',
        'channel',
        'provider',
        'provider_reference',
        'failure_reason',
        'payload_summary',
        'queued_at',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_summary' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id');
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

    public static function buildKey(int $userId, int $ticketId, string $resultVersion, string $type): string
    {
        return hash('sha256', implode('|', [
            'glo-notify-v1',
            (string) $userId,
            (string) $ticketId,
            $resultVersion,
            $type,
        ]));
    }
}
