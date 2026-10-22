<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Read-only staff inbox projection. Signature custody is written ONLY by the domain inbox service. */
final class StripeDepositFollowup extends Model
{
    protected $table = 'stripe_deposit_followup_inbox';

    protected $guarded = ['*'];

    protected $hidden = ['response_cipher', 'response_hash', 'event_key', 'configuration_hash'];

    protected $casts = ['received_epoch' => 'integer', 'created_at' => 'datetime'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::saving(static fn () => throw new \LogicException('Signed inbox models are read-only.'));
        self::deleting(static fn () => throw new \LogicException('Signed inbox models cannot delete custody.'));
    }

    public function latestRouting(): HasOne
    {
        return $this->hasOne(StripeFollowupRouteDecision::class, 'inbox_id')->latestOfMany();
    }

    public function sweepWork(): HasOne
    {
        return $this->hasOne(StripeFollowupSweepWork::class, 'inbox_id');
    }
}
