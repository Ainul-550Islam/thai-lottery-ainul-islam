<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\IpAccessRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Conservative reservation pool: unrevoked, not-yet-expired rows, including future starts. */
final class IpRuleCapacityGuard
{
    public function assertCreateCapacity(int $now): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('IP rule capacity checks require the mutation transaction.');
        }
        // A real write serializes cooperating transactions, including on SQLite where
        // SELECT FOR UPDATE alone is ineffective. Keep the lock through insert + audit.
        if (DB::table('ip_access_rule_mutex')->where('id', 1)->increment('version') !== 1) {
            throw new \RuntimeException('IP rule capacity mutex is unavailable.');
        }
        // Locking read avoids an old consistent-read snapshot after waiting for the
        // mutex on MySQL/InnoDB. Starts are deliberately NOT filtered: future rules reserve slots.
        $reservations = IpAccessRule::query()
            ->whereNull('revoked_at_epoch')
            ->where(fn ($query) => $query->whereNull('expires_at_epoch')->orWhere('expires_at_epoch', '>', $now))
            ->orderBy('id')->limit(DatabaseIpRuleReader::MAX_ACTIVE_RULES)
            ->lockForUpdate()->get(['id']);
        if ($reservations->count() >= DatabaseIpRuleReader::MAX_ACTIVE_RULES) {
            throw ValidationException::withMessages([
                'ip_rule_capacity' => 'IP rule reservation capacity is full. Revoke a rule or wait for expiry before creating another.',
            ]);
        }
    }
}
