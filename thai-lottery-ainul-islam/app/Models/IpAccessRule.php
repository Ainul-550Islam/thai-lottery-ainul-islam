<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Network\IpRangeMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/** Persistence shape only; no management authorization or live policy activation. */
final class IpAccessRule extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'starts_at_epoch' => 'integer',
            'expires_at_epoch' => 'integer',
            'revoked_at_epoch' => 'integer',
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $rule): void {
            $rule->ip_range = $rule->validatedRange();
        });
    }

    /** Also used by the reader to validate active rows written outside Eloquent. */
    public function validatedRange(): string
    {
        $attributes = $this->getAttributes();
        $range = (new IpRangeMatcher)->normalizeRange($attributes['ip_range'] ?? null);
        $reason = $attributes['reason_code'] ?? null;
        $start = $this->epoch($attributes['starts_at_epoch'] ?? null);
        $end = $this->epoch($attributes['expires_at_epoch'] ?? null);
        $this->epoch($attributes['revoked_at_epoch'] ?? null);
        if ($range === null || ! is_string($reason) || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reason)
            || $start === null || ($end !== null && $end <= $start)) {
            throw new InvalidArgumentException('Invalid IP access rule.');
        }

        return $range;
    }

    private function epoch(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((! is_int($value) && (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,11})$/D', $value)))
            || (int) $value < 0 || (int) $value > 253402300799) {
            throw new InvalidArgumentException('Invalid IP access rule.');
        }

        return (int) $value;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
