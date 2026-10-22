<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Managed, immutable compensation owner; writes belong exclusively to the recovery service SQL root. */
final class StripeRefundRecoveryIntent extends Model
{
    protected $table = 'stripe_refund_recovery_intents';

    protected $guarded = ['*'];

    protected $hidden = ['response_cipher', 'response_hash', 'configuration_hash', 'identity_key'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::saving(static fn () => throw new \LogicException('Refund recovery custody is append-only.'));
        self::deleting(static fn () => throw new \LogicException('Refund recovery custody cannot be deleted.'));
    }
}
