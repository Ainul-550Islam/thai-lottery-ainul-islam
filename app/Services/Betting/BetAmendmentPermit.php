<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\BetAmendmentStatus;
use App\Models\BetAmendment;
use Illuminate\Support\Facades\DB;

/** Ephemeral server-only capability, minted with a NEW owner/refund commit. Never serialized or reconstructed on replay. */
final readonly class BetAmendmentPermit
{
    private function __construct(private int $ownerId, private string $termsHash) {}

    public static function forFreshClaim(BetAmendment $fresh): self
    {
        $owner = DB::table('bet_amendment_ownerships')->where('amendment_id', $fresh->id)->lockForUpdate()->first();
        if (DB::transactionLevel() < 1 || ! $fresh->wasRecentlyCreated || $fresh->status !== BetAmendmentStatus::Pending
            || ! $owner || $owner->state !== 'attempting' || $owner->replacement_ownership_id !== null || $owner->completed_at !== null) {
            app(BetAmendmentOwnership::class)->refuse();
        }

        return new self((int) $owner->id, $owner->terms_hash);
    }

    public function authorizes(object $owner): bool
    {
        return $this->ownerId === (int) $owner->id && hash_equals($this->termsHash, $owner->terms_hash);
    }
}
