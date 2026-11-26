<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LoginEventOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $member_id
 * @property int|null $trusted_device_id
 * @property string $identifier_hash
 * @property string|null $ip
 * @property string|null $ip_country
 * @property string|null $device
 * @property string|null $os
 * @property string|null $browser
 * @property string|null $device_fingerprint
 * @property bool $is_new_device
 * @property LoginEventOutcome $outcome
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoginEvent extends Model
{
    use MassPrunable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'member_id',
        'trusted_device_id',
        'identifier_hash',
        'ip',
        'ip_country',
        'device',
        'os',
        'browser',
        'device_fingerprint',
        'is_new_device',
        'outcome',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_new_device' => 'boolean',
            'outcome' => LoginEventOutcome::class,
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('rewards.login_history.retention_days', 180));

        return static::query()->where('occurred_at', '<=', Carbon::now()->subDays($days));
    }

    public function maskedIp(): ?string
    {
        if ($this->ip === null || $this->ip === '') {
            return null;
        }

        if (str_contains($this->ip, ':')) {
            $segments = explode(':', $this->ip);

            return implode(':', array_slice($segments, 0, 3)).':****';
        }

        $octets = explode('.', $this->ip);
        if (count($octets) === 4) {
            return sprintf('%s.%s.%s.***', $octets[0], $octets[1], $octets[2]);
        }

        return '***';
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    /**
     * @return BelongsTo<TrustedDevice, self>
     */
    public function trustedDevice(): BelongsTo
    {
        return $this->belongsTo(TrustedDevice::class, 'trusted_device_id');
    }
}
