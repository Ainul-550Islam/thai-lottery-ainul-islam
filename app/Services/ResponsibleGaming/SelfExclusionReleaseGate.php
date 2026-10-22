<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\Enums\SelfExclusionStatus;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Services\Concerns\GuardedObservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Is this player held, or released — and for the right reason?
 *
 * THE EXISTING GATE, MEASURED
 * `SelfExclusionService::hasActiveExclusion()` answers:
 *     status = 'active' AND ends_at > now()
 * That is fail-closed for an active exclusion and it releases on the clock,
 * which is right: a player whose period has ended must not stay locked out
 * because a scheduled sweep has not run yet.
 *
 * THE GAP THIS GUARD NAMES
 * The same predicate is fail-OPEN in the other direction. A request the player
 * has already made sits in `requested` until the activation sweep moves it to
 * `active`. Between `effective_at` passing and that sweep running,
 * `hasActiveExclusion()` returns false — so the window between "the player
 * asked to stop" and "the row says active" is a window in which they can
 * still play. Nothing in the current code looks at `requested` rows at all.
 *
 * So this gate derives the answer from the clock and the request, not from the
 * status label alone:
 *   requested + effective_at passed   → HELD (activation pending, fail-closed)
 *   active + ends_at in the future    → HELD
 *   active + ends_at passed           → RELEASED, and the stale row is flagged
 *   nothing                           → released
 *
 * IT DOES NOT WRITE TO THE EXCLUSION. It reads. `log` mode records and returns;
 * `enforce` mode raises so a caller can refuse. The activation sweep remains
 * the thing that moves a row to `active` — this guard only refuses to let the
 * gap be silent.
 */
final class SelfExclusionReleaseGate
{
    use GuardedObservation;

    public const GUARD = 'release_gate';

    protected function guardKey(): string
    {
        return self::GUARD;
    }

    /**
     * @return array{released: bool, reason: string, exclusion_id: int|null, ends_at: string|null, sweep_overdue: bool, activation_pending: bool}
     */
    public function decision(int $userId): array
    {
        /** @var SelfExclusion|null $pending */
        $pending = SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Requested->value)
            ->orderBy('effective_at')
            ->first();

        $now = now();

        if ($pending instanceof SelfExclusion && $pending->effective_at !== null && $pending->effective_at->lte($now)) {
            return [
                'released' => false,
                'reason' => 'activation_pending',
                'exclusion_id' => (int) $pending->getKey(),
                'ends_at' => $pending->ends_at?->toIso8601String(),
                'sweep_overdue' => true,
                'activation_pending' => true,
            ];
        }

        /** @var SelfExclusion|null $active */
        $active = SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->orderByDesc('ends_at')
            ->first();

        if ($active instanceof SelfExclusion) {
            $ended = $active->ends_at !== null && $active->ends_at->lte($now);

            return [
                'released' => $ended,
                'reason' => $ended ? 'period_ended' : 'active_exclusion',
                'exclusion_id' => (int) $active->getKey(),
                'ends_at' => $active->ends_at?->toIso8601String(),
                'sweep_overdue' => $ended,
                'activation_pending' => false,
            ];
        }

        if ($pending instanceof SelfExclusion) {
            return [
                'released' => true,
                'reason' => 'request_not_yet_effective',
                'exclusion_id' => (int) $pending->getKey(),
                'ends_at' => $pending->ends_at?->toIso8601String(),
                'sweep_overdue' => false,
                'activation_pending' => false,
            ];
        }

        return [
            'released' => true,
            'reason' => 'no_exclusion',
            'exclusion_id' => null,
            'ends_at' => null,
            'sweep_overdue' => false,
            'activation_pending' => false,
        ];
    }

    /**
     * Record the decision, and in `enforce` mode refuse a player the gate holds.
     *
     * @return array{released: bool, reason: string, exclusion_id: int|null, ends_at: string|null, sweep_overdue: bool, activation_pending: bool, mode: string}
     *
     * @throws RuntimeException Only in `enforce` mode, and only when not released.
     */
    public function assertMayPlay(User|int $player): array
    {
        $userId = $player instanceof User ? (int) $player->getKey() : $player;
        $mode = $this->guardMode();
        $decision = $this->decision($userId);

        if ($mode === self::GUARD_MODE_OFF) {
            return array_merge($decision, ['mode' => $mode]);
        }

        $this->recordObservation(self::GUARD, $decision['released'] ? 'released' : 'held', [
            'user_id' => $userId,
            'reason' => $decision['reason'],
            'exclusion_id' => $decision['exclusion_id'],
            'ends_at' => $decision['ends_at'],
            'sweep_overdue' => $decision['sweep_overdue'],
            'activation_pending' => $decision['activation_pending'],
        ], $userId);

        if (! $decision['released'] && $mode === self::GUARD_MODE_ENFORCE) {
            throw new RuntimeException(
                'Self-exclusion release gate holds user '.$userId.' ('.$decision['reason'].').',
            );
        }

        return array_merge($decision, ['mode' => $mode]);
    }

    /**
     * Accounts whose requested exclusion has become effective but is still
     * labelled `requested` — the exact population the fail-open window covers.
     *
     * @return list<int>
     */
    public function accountsInActivationGap(int $limit = 100): array
    {
        return DB::table('self_exclusions')
            ->where('status', SelfExclusionStatus::Requested->value)
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->limit($limit)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
