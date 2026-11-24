<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\MoneyOperationControl;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The runtime operational control over money movement.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * `RealMoneyGate` answers "may this DEPLOYMENT move money at all" from
 * configuration, and changing it means redeploying - the right weight for a
 * launch decision, far too slow for an incident. This service is the runtime
 * latch beside it: an authorized administrator can halt money movement while the
 * release stays exactly as it is, and the change leaves evidence behind.
 *
 * The two are in SERIES, never in competition. Money moves only when the master
 * switch is open AND this control is open AND every other gate is satisfied.
 * Nothing here can substitute for any of them.
 *
 * THIS CONTROL CANNOT ACTIVATE ANYTHING
 * `open()` removes a halt; it does not authorize live money. Opening it in a
 * deployment whose master switch is closed changes nothing, and
 * `RealMoneyTechnicalReadinessService::liveOperationsOpen()` answers false in
 * this phase whatever this row says. That is deliberate: technical readiness and
 * operational control are both evidence, and authorisation is a separate concern
 * that no code path in this phase can grant.
 *
 * CLOSED IS THE DEFAULT, AND CLOSED IS THE FALLBACK
 * A missing row, an unrecognised state value, an unreadable row and an
 * unreachable database all answer CLOSED. The distinction between "an
 * administrator halted this" and "we cannot read the control" is preserved in
 * the refusal code (`real_money_control_closed` versus
 * `real_money_control_unavailable`) because the two need different responses,
 * but both refuse: a control whose storage is down is not a control that has
 * been released.
 *
 * NO CHANGE WITHOUT EVIDENCE
 * The audit entry is written INSIDE the same transaction as the state change, so
 * a control change that cannot be audited does not happen. An unaudited
 * emergency shutdown is indistinguishable from an unexplained outage, and the
 * point of the mechanism is that an operator can always answer who stopped money
 * and why.
 *
 * NO PUBLIC SURFACE
 * There is no route, no controller and no console flag anywhere in this phase
 * that opens this control from an HTTP request. Callers are server-side code
 * holding an authenticated `User`, and `assertAuthorized()` refuses anybody who
 * is not an administrator.
 */
final class MoneyShutdownControlService
{
    /**
     * The control's identity. One key, one row, one current state.
     */
    public const CONTROL_KEY = 'real_money.live_operations';

    /** Money movement is halted. The default and every fallback. */
    public const STATE_CLOSED = MoneyOperationControl::STATE_CLOSED;

    /** This control permits money movement; every other gate still applies. */
    public const STATE_OPEN = MoneyOperationControl::STATE_OPEN;

    /**
     * The current state of the control.
     *
     * Never throws. A control that throws when its storage is unavailable would
     * turn a database incident into an unhandled error on every money path, and
     * the caller's `try/catch` would then decide the answer - which is exactly
     * how a fail-closed design becomes fail-open in practice.
     */
    public function state(): string
    {
        $row = $this->readRow();

        // An absent row and an unreadable one both answer CLOSED. Only the exact
        // string 'open' opens anything, so a corrupted or mistyped value halts
        // money instead of being interpreted generously.
        return $row !== null && $row->state === self::STATE_OPEN
            ? self::STATE_OPEN
            : self::STATE_CLOSED;
    }

    public function isClosed(): bool
    {
        return $this->state() === self::STATE_CLOSED;
    }

    public function isOpen(): bool
    {
        return $this->state() === self::STATE_OPEN;
    }

    /**
     * A read-only snapshot for reports and console output.
     *
     * Carries no credentials and no personal data: the actor is an id, the
     * reason is the operator's own sentence, and `storage_available` says
     * whether the answer came from the table or from the fallback.
     *
     * @return array{
     *     control_key: string,
     *     state: 'closed'|'open',
     *     storage_available: bool,
     *     permits_money: bool,
     *     activates_live_operations: false,
     *     revision: int|null,
     *     previous_state: string|null,
     *     reason: string|null,
     *     changed_at: string|null,
     *     changed_by_user_id: int|null
     * }
     */
    public function status(): array
    {
        $row = $this->readRow();
        $state = $row !== null && $row->state === self::STATE_OPEN ? self::STATE_OPEN : self::STATE_CLOSED;

        return [
            'control_key' => self::CONTROL_KEY,
            'state' => $state,
            'storage_available' => $this->storageAvailable(),
            'permits_money' => $state === self::STATE_OPEN,

            // A constant, published so that no consumer of this snapshot can
            // mistake the control for an activation: whatever the state says,
            // this mechanism never opens live money operations.
            'activates_live_operations' => false,

            'revision' => $row !== null ? (int) $row->revision : null,
            'previous_state' => $row?->previous_state,
            'reason' => $row?->reason,
            'changed_at' => $row?->changed_at?->toIso8601String(),
            'changed_by_user_id' => $row?->changed_by_user_id !== null ? (int) $row->changed_by_user_id : null,
        ];
    }

    /**
     * Refuse unless the runtime control permits money movement.
     *
     * Mirrors `RealMoneyGate::assertOpen()` so a call site can consult both with
     * the same shape, and throws BEFORE any balance is read so a refused
     * operation leaves wallets, holds and the ledger exactly as it found them.
     *
     * @throws FinancialException real_money_control_unavailable when the control could not be read
     * @throws FinancialException real_money_control_closed when the control is closed
     */
    public function assertOpen(string $operation = 'real_money'): void
    {
        if (! $this->storageAvailable()) {
            throw $this->refusal('real_money_control_unavailable', $operation, 'the runtime money control could not be read, so money movement is refused until it can be');
        }

        if ($this->state() === self::STATE_OPEN) {
            return;
        }

        // Storage is readable and the answer is still closed. Whether no
        // administrator ever set the control or one deliberately closed it, the
        // refusal is the same; `status()` carries the distinction for whoever
        // has to act on it.
        throw $this->refusal('real_money_control_closed', $operation, 'the runtime money control is closed, so money movement is halted');
    }

    /**
     * Remove the halt. Does NOT authorize live money operations.
     *
     * @return array{changed: bool, state: string, revision: int, previous_state: string|null}
     *
     * @throws FinancialException real_money_control_forbidden when the actor is not an administrator
     * @throws FinancialException real_money_control_reason_required when no reason was given
     * @throws FinancialException real_money_control_conflict when the expected revision no longer holds
     * @throws FinancialException real_money_control_unavailable when the change cannot be persisted or audited
     */
    public function open(User $actor, string $reason, ?int $expectedRevision = null): array
    {
        return $this->transition($actor, self::STATE_OPEN, $reason, $expectedRevision);
    }

    /**
     * Halt money movement. The safe direction, and available to any administrator.
     *
     * @return array{changed: bool, state: string, revision: int, previous_state: string|null}
     *
     * @throws FinancialException real_money_control_forbidden when the actor is not an administrator
     * @throws FinancialException real_money_control_reason_required when no reason was given
     * @throws FinancialException real_money_control_conflict when the expected revision no longer holds
     * @throws FinancialException real_money_control_unavailable when the change cannot be persisted or audited
     */
    public function close(User $actor, string $reason, ?int $expectedRevision = null): array
    {
        return $this->transition($actor, self::STATE_CLOSED, $reason, $expectedRevision);
    }

    /**
     * Only an administrator may change an operational control.
     *
     * The same seat test `AdminOperationService::assertAuthorized()` applies, so
     * "who may touch money controls" has one answer in this codebase rather than
     * a second, slightly different one that a reviewer has to reconcile. A
     * normal user - including an authenticated, verified, KYC-cleared player -
     * is refused by name.
     *
     * @throws FinancialException real_money_control_forbidden
     */
    public static function assertAuthorized(User $actor): void
    {
        if ($actor->isAdmin() || $actor->isSuperAdmin()) {
            return;
        }

        throw FinancialException::withCode(
            'real_money_control_forbidden',
            'Only an administrator may change a money operation control.',
            ['control_key' => self::CONTROL_KEY, 'actor_user_id' => (int) $actor->id],
        );
    }

    /**
     * One transition: authorize, lock, verify the revision, write, audit.
     *
     * The row is locked for the duration, so two administrators cannot interleave
     * contradictory updates; the revision check adds the optimistic half, so a
     * caller acting on a state it read earlier is refused instead of silently
     * overwriting a change it never saw.
     *
     * The audit entry is written in the SAME transaction: if the change cannot be
     * evidenced, the change does not happen.
     *
     * @return array{changed: bool, state: string, revision: int, previous_state: string|null}
     */
    private function transition(User $actor, string $target, string $reason, ?int $expectedRevision): array
    {
        self::assertAuthorized($actor);

        $reason = trim($reason);

        if ($reason === '') {
            // Its own code rather than the storage failure code: an operator who
            // sees `real_money_control_unavailable` starts checking the database,
            // and the actual problem is that they were asked why.
            throw FinancialException::withCode(
                'real_money_control_reason_required',
                'A money operation control change requires a reason: the audit trail is the only record of why money stopped or resumed.',
                ['control_key' => self::CONTROL_KEY, 'requested_state' => $target],
            );
        }

        try {
            return DB::transaction(function () use ($actor, $target, $reason, $expectedRevision): array {
                $row = $this->lockRow();

                if ($expectedRevision !== null && (int) $row->revision !== $expectedRevision) {
                    throw FinancialException::withCode(
                        'real_money_control_conflict',
                        'The money operation control changed since it was read; re-read it before deciding.',
                        [
                            'control_key' => self::CONTROL_KEY,
                            'expected_revision' => $expectedRevision,
                            'actual_revision' => (int) $row->revision,
                            'current_state' => (string) $row->state,
                        ],
                    );
                }

                $previous = (string) $row->state;

                // Replaying the state it already holds is not a change: no new
                // revision, no audit entry, and no way to manufacture a trail of
                // transitions that never happened.
                if ($previous === $target) {
                    return [
                        'changed' => false,
                        'state' => $target,
                        'revision' => (int) $row->revision,
                        'previous_state' => $row->previous_state,
                    ];
                }

                $row->previous_state = $previous;
                $row->state = $target;
                $row->reason = mb_substr($reason, 0, 190);
                $row->changed_by_user_id = (int) $actor->id;
                $row->changed_at = now();
                $row->revision = (int) $row->revision + 1;
                $row->save();

                $this->audit($actor, $row, $previous, $target);

                return [
                    'changed' => true,
                    'state' => $target,
                    'revision' => (int) $row->revision,
                    'previous_state' => $previous,
                ];
            });
        } catch (FinancialException $exception) {
            // Authorization, conflict and reason refusals are the service's own
            // answers and must reach the caller unchanged.
            throw $exception;
        } catch (Throwable $exception) {
            // Anything else - the table is missing, the connection is down, the
            // audit insert failed - means the control could not be changed AND
            // could not be evidenced. Refuse rather than leave the caller
            // guessing whether the state moved.
            throw FinancialException::withCode(
                'real_money_control_unavailable',
                'The money operation control could not be changed: '.$exception::class,
                ['control_key' => self::CONTROL_KEY, 'requested_state' => $target],
            );
        }
    }

    /**
     * The control row, locked, created in its default state if absent.
     *
     * Creation happens under the unique index: if two writers race to seed the
     * row, one insert wins and the loser re-reads the winner's row and locks it,
     * so both end up serialised on the same state rather than each believing it
     * created the control.
     */
    private function lockRow(): MoneyOperationControl
    {
        $row = MoneyOperationControl::query()
            ->where('control_key', self::CONTROL_KEY)
            ->lockForUpdate()
            ->first();

        if ($row instanceof MoneyOperationControl) {
            return $row;
        }

        try {
            return MoneyOperationControl::query()->create([
                'control_key' => self::CONTROL_KEY,
                'state' => self::STATE_CLOSED,
                'previous_state' => null,
                'reason' => null,
                'changed_by_user_id' => null,
                'changed_at' => null,
                'revision' => 0,
            ])->refresh();
        } catch (QueryException) {
            $existing = MoneyOperationControl::query()
                ->where('control_key', self::CONTROL_KEY)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof MoneyOperationControl) {
                return $existing;
            }

            throw FinancialException::withCode(
                'real_money_control_unavailable',
                'The money operation control row could not be created or re-read.',
                ['control_key' => self::CONTROL_KEY],
            );
        }
    }

    /**
     * Record actor, timestamp, previous state and new state.
     *
     * Written inside the caller's transaction, so a control change that cannot be
     * audited is rolled back rather than left as an unexplained state.
     */
    private function audit(User $actor, MoneyOperationControl $row, string $previous, string $target): void
    {
        AuditLog::query()->create([
            'user_id' => (int) $actor->id,
            'action' => AuditAction::ConfigChange,

            // Opening a money control is the more consequential direction: it
            // removes a halt. Both are recorded at high risk or above so neither
            // can be lost in a low-risk stream.
            'risk_level' => $target === self::STATE_OPEN ? RiskLevel::Critical : RiskLevel::High,

            'auditable_type' => MoneyOperationControl::class,
            'auditable_id' => (int) $row->id,
            'description' => $target === self::STATE_OPEN
                ? 'Money operation control opened; this removes a halt and does not authorize live money operations.'
                : 'Money operation control closed; money movement is halted.',
            'old_values' => ['state' => $previous, 'revision' => (int) $row->revision - 1],
            'new_values' => ['state' => $target, 'revision' => (int) $row->revision],
            'metadata' => [
                'control_key' => self::CONTROL_KEY,
                'reason' => (string) $row->reason,
                'actor_user_id' => (int) $actor->id,
                'changed_at' => $row->changed_at?->toIso8601String(),
                // Recorded so a reviewer can see that the control was exercised
                // without any HTTP surface being involved.
                'surface' => 'server-side',
            ],
        ]);
    }

    /**
     * The row, or null when it is absent OR unreadable.
     *
     * The two are deliberately indistinguishable here and distinguished by
     * `storageAvailable()`: every caller wants "what is the state", and the
     * answer is CLOSED either way.
     */
    private function readRow(): ?MoneyOperationControl
    {
        try {
            $row = MoneyOperationControl::query()->where('control_key', self::CONTROL_KEY)->first();

            return $row instanceof MoneyOperationControl ? $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Can the control's storage be read at all?
     *
     * Kept separate from `state()` so a refusal can tell an operator the
     * difference between "an administrator closed this" and "we cannot see the
     * control", which are the same answer and two very different incidents.
     */
    private function storageAvailable(): bool
    {
        try {
            MoneyOperationControl::query()->where('control_key', self::CONTROL_KEY)->exists();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function refusal(string $code, string $operation, string $detail): FinancialException
    {
        return FinancialException::withCode(
            $code,
            $detail,
            ['operation' => $operation, 'control_key' => self::CONTROL_KEY],
        );
    }
}
