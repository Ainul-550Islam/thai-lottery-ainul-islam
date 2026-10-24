<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletConversionChannel;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One auditable event on the conversion lane — a quote issued, a conversion
 * completed, a refusal with its named reason. Written for successes AND for
 * refusals, because "nothing happened" is exactly the claim support must be
 * able to prove.
 */
class WalletConversionAudit extends Model
{
    public $timestamps = false;

    protected $table = 'wallet_conversion_audits';

    protected $fillable = [
        'wallet_conversion_id',
        'reference',
        'user_id',
        'actor_user_id',
        'event',
        'direction',
        'channel',
        'error_code',
        'message',
        'checks',
        'context',
        'metadata',
        'ip_address',
        'user_agent',
        'request_id',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => WalletConversionChannel::class,
            'checks' => 'array',
            'context' => 'array',
            'metadata' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->recorded_at ??= Carbon::now();
        });

        static::updating(function (): void {
            throw new DomainException('Wallet conversion audit records are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new DomainException('Wallet conversion audit records are append-only and cannot be deleted.');
        });
    }

    /**
     * The full trail for one conversion reference — the same reference a
     * member sees on their receipt, so the timeline can be pulled without
     * knowing any internal identifier.
     */
    public function scopeForReference(Builder $query, string $reference): Builder
    {
        return $query->where('reference', $reference);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(WalletConversion::class, 'wallet_conversion_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
