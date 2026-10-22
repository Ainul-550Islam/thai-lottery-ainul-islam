<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable read projection, not an Eloquent CRUD path or current financial clearance. */
final class StripeFollowupRouteDecision extends Model
{
    protected $table = 'stripe_followup_route_decisions';

    protected $guarded = ['*'];

    protected $hidden = ['response_cipher', 'response_hash', 'decision_key', 'source_hash'];

    protected $casts = ['case_version' => 'integer', 'created_at' => 'datetime'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::saving(static fn () => throw new \LogicException('Routing decisions are append-only domain custody.'));
        self::deleting(static fn () => throw new \LogicException('Routing decisions cannot be deleted by models.'));
    }
}
