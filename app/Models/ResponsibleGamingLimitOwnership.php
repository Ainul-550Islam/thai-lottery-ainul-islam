<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ownership record for one responsible-gaming limit VERSION.
 *
 * A limit version answers "what ceiling did the player choose". This row answers
 * the questions an auditor asks afterwards, and that a sweep must answer before
 * it may move that ceiling:
 *
 *   * WHICH request produced it (`request_identity`, `payload_hash`);
 *   * WHAT was agreed (`terms_hash` over user, type, amount, currency and the
 *     effective window);
 *   * WHAT it was measured against (`basis_hash` over the ceiling that stood,
 *     its amount, and the backend timezone);
 *   * WHEN it may take effect and when it lapses (`activation_epoch`,
 *     `expiry_epoch`), as absolute instants, so a timezone change cannot
 *     reinterpret them.
 *
 * The record is written once, in the same transaction as the version, and is
 * never edited: a changed term is a changed hash, and a changed hash is a
 * refusal. That is the point of it.
 *
 * @property int $id
 * @property int $version_id
 * @property string $request_identity
 * @property string $payload_hash
 * @property string $terms_hash
 * @property string $basis_hash
 * @property string $timezone
 * @property int $activation_epoch
 * @property int|null $expiry_epoch
 */
class ResponsibleGamingLimitOwnership extends Model
{
    protected $table = 'responsible_gaming_limit_ownerships';

    protected $fillable = [
        'version_id',
        'request_identity',
        'payload_hash',
        'terms_hash',
        'basis_hash',
        'timezone',
        'activation_epoch',
        'expiry_epoch',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activation_epoch' => 'integer',
            'expiry_epoch' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ResponsibleGamingLimitVersion, self>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ResponsibleGamingLimitVersion::class, 'version_id');
    }

    public function activatesAt(): Carbon
    {
        return Carbon::createFromTimestamp($this->activation_epoch);
    }

    public function expiresAt(): ?Carbon
    {
        return $this->expiry_epoch === null ? null : Carbon::createFromTimestamp($this->expiry_epoch);
    }
}
