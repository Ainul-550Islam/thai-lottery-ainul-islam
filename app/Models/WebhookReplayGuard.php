<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One distinct signed webhook payload, and how many times it has been delivered.
 *
 * This is NOT a payment record and NOT an audit record. It is the durable
 * substrate under the replay control: the UNIQUE (gateway, nonce) index on this
 * table is what actually refuses a replayed webhook, and this model is how the
 * rest of the application observes that.
 *
 * `deliveries` is deliberately mutable — a provider retrying a webhook it
 * believes failed is normal, expected behaviour, not an attack. What matters is
 * that the WORK happens once; the *observation* of a repeat is what this row
 * accumulates.
 *
 * @property int $id
 * @property string $gateway
 * @property string $nonce
 * @property string|null $signature
 * @property string|null $payload_hash
 * @property int $deliveries
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $expires_at
 * @property string|null $first_ip
 * @property string|null $last_ip
 */
class WebhookReplayGuard extends Model
{
    protected $table = 'webhook_replay_guards';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'gateway',
        'nonce',
        'signature',
        'payload_hash',
        'deliveries',
        'first_seen_at',
        'last_seen_at',
        'expires_at',
        'first_ip',
        'last_ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deliveries' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Records that have not yet passed their retention window.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where(function (Builder $inner): void {
            $inner->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Records due for pruning.
     *
     * @param  Builder<self>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    /**
     * Whether this payload has been delivered more than once.
     */
    public function isReplay(): bool
    {
        return $this->deliveries > 1;
    }
}
