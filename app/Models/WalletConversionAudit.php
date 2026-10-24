<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletConversionChannel;
use Illuminate\Database\Eloquent\Builder;
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
        'event',
        'error_code',
        'channel',
        'actor_user_id',
        'ip_address',
        'user_agent',
        'request_id',
        'context',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => WalletConversionChannel::class,
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

    public function conversion(): BelongsTo
    {
        return $this->belongsTo(WalletConversion::class, 'wallet_conversion_id');
    }
}
