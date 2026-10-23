<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Events\SelfExclusionActivated;
use App\Exceptions\SelfExclusionException;
use App\Listeners\RecordSelfExclusionAudit;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\SelfExclusion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SelfExclusionService — create / activate / expire self-exclusions.
 * The live gate is DERIVED server-side (status + server clock) and
 * blocks prohibited account activity; no client may lift it.
 */
final class SelfExclusionService
{
    public function __construct(
        private readonly RecordSelfExclusionAudit $audit,
    ) {}

    /**
     * THE SEATED REQUEST: one (user, scope, reason, window) = one
     * row, replayed for free. A PLAYER under an active exclusion
     * cannot re-request (the gate refuses by name).
     */
    public function request(SelfExclusionData $data): SelfExclusion
    {
        return DB::transaction(function () use ($data): SelfExclusion {
            $playerLock = app(ResponsibleGamingPlayerLock::class);
            $playerLock->lock($data->userId);

            $fingerprint = $data->requestFingerprint();

            /** @var SelfExclusion|null $existing */
            $existing = SelfExclusion::query()
                ->where('request_fingerprint', $fingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SelfExclusion) {
                return $existing;
            }

            /** @var SelfExclusion|null $active */
            $active = SelfExclusion::query()
                ->where('user_id', $data->userId)
                ->whereIn('status', [SelfExclusionStatus::Active->value, SelfExclusionStatus::Requested->value])
                ->lockForUpdate()
                ->get()
                ->first(fn (SelfExclusion $s) => $s->currentlyGates()
                    || ($s->status === SelfExclusionStatus::Requested && $s->effective_at !== null && $s->effective_at->lte(now()) && $s->ends_at !== null && $s->ends_at->isFuture()));

            if ($active instanceof SelfExclusion) {
                throw SelfExclusionException::activeExclusion($data->userId, $active->ends_at->toIso8601String());
            }

            $legacy = ResponsibleGamingLimit::withTrashed()
                ->where('user_id', $data->userId)
                ->lockForUpdate()
                ->first();

            if ($legacy instanceof ResponsibleGamingLimit && $legacy->self_excluded_until !== null && $legacy->self_excluded_until->isFuture()) {
                throw SelfExclusionException::activeExclusion($data->userId, $legacy->self_excluded_until->toIso8601String());
            }

            /** @var SelfExclusion $row */
            $row = SelfExclusion::query()->create([
                'user_id' => $data->userId,
                'status' => SelfExclusionStatus::Requested,
                'scope' => $data->scope,
                'reason_code' => $data->reasonCode,
                'request_fingerprint' => $fingerprint,
                'effective_at' => $data->effectiveAt,
                'ends_at' => $data->endsAt,
            ]);

            return $row;
        });
    }

    /**
     * ACTIVATE: immediate-effect exclusions move Requested → Active
     * the moment the server clock crosses effective_at.
     */
    public function activate(int|SelfExclusion $exclusion): SelfExclusion
    {
        $exclusionId = $exclusion instanceof SelfExclusion ? (int) $exclusion->id : $exclusion;

        return DB::transaction(function () use ($exclusionId): SelfExclusion {
            $playerLock = app(ResponsibleGamingPlayerLock::class);
            $ownerId = $playerLock->forRecord('self_exclusions', $exclusionId);

            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusionId);
            $playerLock->assertOwner($ownerId, (int) $locked->user_id);

            if ($locked->status === SelfExclusionStatus::Active) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Active)) {
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), $locked->status->value, SelfExclusionStatus::Active->value,
                );
            }

            if ($locked->effective_at !== null && now()->lt($locked->effective_at)) {
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), $locked->status->value, SelfExclusionStatus::Active->value.' (effective time not reached)',
                );
            }

            $locked->status = SelfExclusionStatus::Active;
            $locked->activated_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);

            event(new SelfExclusionActivated($locked, $locked->activated_at->toIso8601String()));

            return $locked->refresh();
        });
    }

    /**
     * EXPIRE: the only gate-lifter — server-authoritative end time
     * must have physically passed. Replay-safe.
     */
    public function expire(SelfExclusion $exclusion): SelfExclusion
    {
        $exclusionId = (int) $exclusion->id;

        return DB::transaction(function () use ($exclusionId): SelfExclusion {
            $playerLock = app(ResponsibleGamingPlayerLock::class);
            $ownerId = $playerLock->forRecord('self_exclusions', $exclusionId);

            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusionId);
            $playerLock->assertOwner($ownerId, (int) $locked->user_id);

            if ($locked->status === SelfExclusionStatus::Expired) {
                return $locked;
            }

            if ($locked->status !== SelfExclusionStatus::Active) {
                return $locked;
            }

            if (now()->lt($locked->ends_at)) {
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), SelfExclusionStatus::Active->value, SelfExclusionStatus::Expired->value.' (end time not reached)',
                );
            }

            $locked->status = SelfExclusionStatus::Expired;
            $locked->expired_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);
            $this->audit->from($locked, 'self-exclusion expired by server horizon');

            return $locked->refresh();
        });
    }

    /**
     * CANCEL: allowed only while the exclusion was never live —
     * cancelling an ACTIVE gate is forbidden by name.
     */
    public function cancel(SelfExclusion $exclusion, string $cancelledBy): SelfExclusion
    {
        $exclusionId = (int) $exclusion->id;

        return DB::transaction(function () use ($exclusionId, $cancelledBy): SelfExclusion {
            $playerLock = app(ResponsibleGamingPlayerLock::class);
            $ownerId = $playerLock->forRecord('self_exclusions', $exclusionId);

            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusionId);
            $playerLock->assertOwner($ownerId, (int) $locked->user_id);

            if ($locked->status === SelfExclusionStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Cancelled)) {
                throw SelfExclusionException::forbiddenCancellation(self::ref($locked), $locked->status->value);
            }

            if ($locked->effective_at !== null && $locked->effective_at->lte(now())) {
                throw SelfExclusionException::forbiddenCancellation(self::ref($locked), 'effective_window_entered');
            }

            $locked->status = SelfExclusionStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancelled_by = $cancelledBy;
            $locked->save();

            $this->audit->from($locked, 'self-exclusion request withdrawn pre-activation');

            return $locked->refresh();
        });
    }

    /**
     * THE FAIL-CLOSED GATE: the player is excluded iff an ACTIVE
     * exclusion whose end is in the future exists, OR a REQUESTED
     * exclusion whose effective_at has passed and ends_at is in the future.
     */
    public function hasActiveExclusion(int $userId, bool $forUpdate = false): bool
    {
        return $this->currentActiveFor($userId, $forUpdate) instanceof SelfExclusion;
    }

    public function currentActiveFor(int $userId, bool $forUpdate = false): ?SelfExclusion
    {
        $now = now();

        $query = SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('ends_at', '>', $now)
            ->where(static function ($q) use ($now, $forUpdate): void {
                $q->where('status', SelfExclusionStatus::Active->value);
                if ($forUpdate) {
                    $q->orWhere(static function ($sub) use ($now): void {
                        $sub->where('status', SelfExclusionStatus::Requested->value)
                            ->where('effective_at', '<=', $now);
                    });
                }
            })
            ->orderByDesc('ends_at');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        /** @var SelfExclusion|null $active */
        $active = $query->first();

        return $active instanceof SelfExclusion ? $active : null;
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForActivation(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Requested->value)
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForExpiry(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();
    }

    private function stampLegacyRow(SelfExclusion $exclusion): void
    {
        /** @var ResponsibleGamingLimit|null $legacy */
        $legacy = ResponsibleGamingLimit::withTrashed()
            ->where('user_id', $exclusion->user_id)
            ->lockForUpdate()
            ->first();

        if (! $legacy instanceof ResponsibleGamingLimit) {
            $legacy = new ResponsibleGamingLimit;
            $legacy->user_id = $exclusion->user_id;
        }

        if ($exclusion->status === SelfExclusionStatus::Active) {
            $current = $legacy->self_excluded_until;

            if (! $current instanceof Carbon || $current->lt($exclusion->ends_at)) {
                $legacy->self_excluded_until = $exclusion->ends_at;
            }
        } else {
            $current = $legacy->self_excluded_until;

            if ($current instanceof Carbon && ! $current->isFuture()) {
                $legacy->self_excluded_until = null;
            }
        }

        $legacy->save();

        AuditLog::create([
            'user_id' => $exclusion->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => SelfExclusion::class,
            'auditable_id' => $exclusion->id,
            'metadata' => [
                'lane' => 'self-exclusion',
                'risk_rating' => RiskLevel::High->value,
                'status' => $exclusion->status->value,
                'reference' => self::ref($exclusion),
            ],
        ]);
    }

    private static function ref(SelfExclusion $exclusion): string
    {
        return 'SX-'.strtoupper(substr((string) $exclusion->request_fingerprint, 0, 12));
    }
}
