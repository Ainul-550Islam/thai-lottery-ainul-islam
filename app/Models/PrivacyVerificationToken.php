<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use verification token for a public privacy request.
 *
 * THE PLAIN TOKEN IS NEVER STORED. issue() returns the plaintext exactly once,
 * for the mail body, and keeps only hash('sha256', $plain). Verification hashes
 * what arrived and looks it up, then compares with hash_equals so the
 * comparison itself does not leak a prefix length.
 *
 * SINGLE USE AND EXPIRY ARE BOTH REQUIRED. A token is unusable once consumed_at
 * is set (the replay case: a mail forwarded to somebody, or a browser
 * reopened a week later) and unusable after expires_at. The caller - not this
 * model - decides what an expired token means for the request, because only the
 * service may move a request's status.
 *
 * @property int $id
 * @property int $request_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
class PrivacyVerificationToken extends Model
{
    protected $fillable = [
        'request_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataSubjectRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class, 'request_id');
    }

    /**
     * Create a token and return the plaintext ONCE.
     *
     * 32 random bytes, hex encoded: 256 bits of entropy, so guessing is not a
     * strategy even against a live endpoint.
     *
     * @return array{token: self, plain: string}
     */
    public static function issue(int $requestId, ?Carbon $expiresAt = null): array
    {
        $plain = bin2hex(random_bytes(32));

        $token = self::query()->create([
            'request_id' => $requestId,
            'token_hash' => self::hashToken($plain),
            'expires_at' => $expiresAt ?? Carbon::now()->addMinutes(
                max(5, (int) config('privacy_requests.verification_ttl_minutes', 1440)),
            ),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', trim($plain));
    }

    /**
     * Find a token by the string a visitor presented.
     */
    public static function findByPlainToken(string $plain): ?self
    {
        $plain = trim($plain);

        if ($plain === '') {
            return null;
        }

        return self::query()->where('token_hash', self::hashToken($plain))->first();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at instanceof Carbon;
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }

    /**
     * Burn the token. Returns true only for the caller that actually consumed
     * it, which is what makes concurrent clicks on the same link resolve to one
     * verification instead of two.
     */
    public function consume(): bool
    {
        if (! $this->isUsable()) {
            return false;
        }

        $this->consumed_at = Carbon::now();

        return $this->save();
    }

    /**
     * Constant-time comparison against this row's stored hash.
     */
    public function matches(string $plain): bool
    {
        return hash_equals($this->token_hash, self::hashToken($plain));
    }

    /**
     * A masked hint for an operator screen: never the token, only its prefix.
     */
    public function maskedHint(): string
    {
        return Str::limit($this->token_hash, 8, '…');
    }
}
