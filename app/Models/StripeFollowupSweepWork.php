<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Read-only historical worker hint, never financial clearance or lease capability. */
final class StripeFollowupSweepWork extends Model
{
    protected $table = 'stripe_followup_sweep_work';

    protected $guarded = ['*'];

    protected $hidden = ['lease_token', 'configuration_hash', 'source_hash', 'last_event_id'];

    protected $casts = ['generation' => 'integer', 'budget_used' => 'integer', 'available_epoch' => 'integer'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::saving(static fn () => throw new \LogicException('Sweep metadata is written only by its domain coordinator.'));
        self::deleting(static fn () => throw new \LogicException('Sweep history cannot be deleted by model instances.'));
    }
}
