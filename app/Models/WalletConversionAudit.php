<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletConversionChannel;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One auditable event on the conversion lane — a quote issued, a conversion
 * completed, a refusal with its named reason. Written for successes AND for
 * refusals, because "nothing happened" is exactly the claim support must be
 * able to prove.
 */
class WalletConversionAudit extends Model
{
    protected $table = 'wallet_conversion_audits';

    protected $fillable = [
        'wallet_conversion_id',
        'reference',
        'user_id',
        'event',
        'direction',
        'error_code',
        'message',
        'checks',
        'metadata',
        'channel',
        'actor_user_id',
        'ip_address',
        'user_agent',
        'request_id',
        'context',
        'recorded_at',
    ];

    protected static function booted(): void
    {
        static::creating(static function (self $audit): void {
            $audit->recorded_at ??= Carbon::now();
        });
        static::updating(static function (): void {
            throw new DomainException('Conversion audit entries are append-only.');
        });
        static::deleting(static function (): void {
            throw new DomainException('Conversion audit entries are append-only.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => WalletConversionChannel::class,
            'checks' => 'array',
            'metadata' => 'array',
            'context' => 'array',
            'recorded_at' => 'datetime',
        ];
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

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('recorded_at', [$from, $to]);
    }

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(WalletConversion::class, 'wallet_conversion_id');
    }
}
