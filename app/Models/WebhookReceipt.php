<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An accepted or refused webhook delivery.
 *
 * This is an OBSERVABILITY record, not a financial one. It exists so that
 * "how many webhooks were replayed against us last night" is a question with an
 * answer, instead of a warning line buried in a log file. It deliberately holds
 * no payload and no PII beyond the source IP.
 *
 * @property int $id
 * @property string $gateway
 * @property string $nonce
 * @property int $signature_timestamp
 * @property string $status
 * @property string|null $ip_address
 * @property string|null $path
 * @property \Illuminate\Support\Carbon $received_at
 */
class WebhookReceipt extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REPLAYED = 'replayed';

    public const STATUS_REJECTED = 'rejected';

    public $timestamps = false;

    protected $fillable = [
        'gateway',
        'nonce',
        'signature_timestamp',
        'status',
        'ip_address',
        'path',
        'received_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_timestamp' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
