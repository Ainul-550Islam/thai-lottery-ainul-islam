<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\Betting\BetCancellationData;
use App\Enums\AuditAction;
use App\Enums\BetCancellationReason;
use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\RiskLevel;
use App\Enums\UserStatus;
use App\Exceptions\DrawLifecycleException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\User;
use App\Services\Betting\BetCancellationService;
use App\Services\Finance\WalletLockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One root transaction: all original wallets -> current staff grants -> draw ->
 * complete owned membership -> existing per-bet accounting/refund/commission proof.
 * No asynchronous money, historical adoption, chunked partial completion or retries.
 * This instance is the non-serializable, short-lived dispatch capability. Its private
 * context exists ONLY on the stack of a freshly authorized cancellation. A stored
 * preparing/refunded/completed row cannot reconstruct permission to move money.
 */
final class DrawCancellationService
{
    private ?array $dispatch = null;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly WalletLockService $wallets,
        private readonly BetCancellationService $cancellations,
        private readonly DrawLifecycleService $lifecycle,
    ) {}

    private function __clone() {}

    public function __serialize(): array
    {
        throw new LogicException('Draw cancellation dispatch cannot be serialized.');
    }

    public function cancel(int $actorUserId, int $drawId, string $reason): array
    {
        $reason = trim($reason);
        if ($this->database->transactionLevel() !== 0 || $this->dispatch !== null) {
            $this->refuse('draw_cancellation_root_required');
        }
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1f\x7f]/u', $reason)) {
            $this->refuse('draw_cancellation_reason_invalid');
        }
        // Hints grant nothing. Re-read both complete membership and wallet sets
        // with CURRENT locks after acquiring all hinted wallets in numeric order.
        $hint = $this->membership($drawId, false);
        $hintWallets = $this->walletIds($hint, false);

        return $this->database->transaction(function () use ($actorUserId, $drawId, $reason, $hint, $hintWallets): array {
            $locked = $hintWallets === [] ? [] : $this->wallets->lockMany($hintWallets);
            $this->authorize($actorUserId);
            $draw = Draw::query()->whereKey($drawId)->lockForUpdate()->first();
            if (! $draw) {
                throw DrawLifecycleException::notFound($drawId);
            }
            $current = $this->membership($drawId, true);
            // A racing purchase/commission payout may have changed the lock set.
            // Never acquire a newly discovered wallet underneath an already held draw.
            if ($current !== $hint || $this->walletIds($current, true) !== $hintWallets) {
                $this->refuse('draw_cancellation_membership_changed');
            }
            $owner = DB::table('draw_cancellation_ownerships')->where('draw_id', $drawId)->lockForUpdate()->first();
            if ($owner) {
                $this->assertTerms($owner, $draw);
                if ($owner->reason !== $reason || $owner->state !== 'completed' || ! $owner->completed_at
                    || $draw->status !== DrawStatus::Cancelled) {
                    $this->refuse('draw_cancellation_review_required');
                }
                $outcomes = $this->proveOutcomes($owner, $draw, $locked);
                $this->assertOutcomeSeal($owner, $outcomes);

                return $this->result($owner, $outcomes, true);
            }
            if (! (bool) config('lottery.draw_cancellation.enabled', true)) {
                $this->refuse('draw_cancellation_disabled');
            }
            if (! $this->lifecycle->canTransition($draw, DrawLifecycleState::Cancelled)) {
                $this->refuse('draw_cancellation_state_refused');
            }
            // Conservative: no payout or settlement history is auto-reconciled.
            if (DB::table('payouts')->where('draw_id', $drawId)->lockForUpdate()->exists()
                || DB::table('settlement_runs')->where('draw_id', $drawId)->lockForUpdate()->exists()) {
                $this->refuse('draw_cancellation_settlement_review_required');
            }
            $cap = (int) config('lottery.draw_cancellation.max_bets', 100);
            if ($cap < 1 || $cap > 1000 || count($current) > $cap) {
                $this->refuse('draw_cancellation_capacity_review_required');
            }
            $this->assertMembership($current);
            $participants = [];
            foreach ($current as $member) {
                $claim = DB::table('bet_purchase_ownerships')->where('id', $member['ownership_id'])->lockForUpdate()->first();
                if (! $claim || ! in_array($claim->state, ['active', 'cancelled'], true)
                    || ($claim->state === 'cancelled' && $claim->cancelled_reason === BetCancellationReason::DrawCancelled->value)) {
                    $this->refuse('draw_cancellation_review_required');
                }
                $participants[] = [...$member, 'initial_state' => $claim->state,
                    'initial_refund_id' => $claim->refund_transaction_id === null ? null : (int) $claim->refund_transaction_id];
            }
            $owner = (object) ['draw_id' => $drawId, 'actor_user_id' => $actorUserId,
                'previous_status' => $draw->status->value, 'reason' => $reason,
                'participants' => json_encode($participants, JSON_THROW_ON_ERROR)];
            $owner->terms_hash = $this->termsHash($owner, $draw);
            $owner->id = DB::table('draw_cancellation_ownerships')->insertGetId([
                'draw_id' => $drawId, 'actor_user_id' => $actorUserId, 'previous_status' => $owner->previous_status,
                'reason' => $reason, 'participants' => $owner->participants, 'terms_hash' => $owner->terms_hash,
                'state' => 'preparing', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->dispatch = ['id' => (int) $owner->id, 'draw_id' => $drawId, 'terms_hash' => $owner->terms_hash,
                'participants' => $participants, 'wallets' => $locked];
            try {
                foreach ($participants as $member) {
                    if ($member['initial_state'] === 'active') {
                        $this->cancellations->cancel(new BetCancellationData($member['user_id'], (string) $member['bet_id'],
                            BetCancellationReason::DrawCancelled), $this);
                    }
                }
                $outcomes = $this->proveOutcomes($owner, $draw, $locked);
                $owner->outcomes = json_encode($outcomes, JSON_THROW_ON_ERROR);
                $owner->completed_at = now()->toDateTimeString();
                $owner->outcome_hash = $this->hash([$owner->terms_hash, $outcomes, $owner->completed_at]);
                DB::table('draw_cancellation_ownerships')->where('id', $owner->id)->update([
                    'state' => 'refunded', 'outcomes' => $owner->outcomes, 'outcome_hash' => $owner->outcome_hash,
                    'completed_at' => $owner->completed_at, 'updated_at' => now(),
                ]);
                $this->lifecycle->transitionTo($draw, DrawLifecycleState::Cancelled,
                    ['source' => 'owned-draw-cancellation', 'actor_user_id' => $actorUserId,
                        'reason' => $reason, 'draw_cancellation_id' => (int) $owner->id], $this);
                DB::table('draw_cancellation_ownerships')->where('id', $owner->id)->update([
                    'state' => 'completed', 'updated_at' => now(),
                ]);

                $audit = new AuditLog;
                $audit->fill(['user_id' => $actorUserId, 'action' => AuditAction::Update, 'risk_level' => RiskLevel::Medium,
                    'auditable_type' => Draw::class, 'auditable_id' => $drawId,
                    'description' => 'Atomically cancelled draw after proving all owned refunds.',
                    'metadata' => ['draw_cancellation_id' => (int) $owner->id, 'reason' => $reason,
                        'terms_hash' => $owner->terms_hash, 'outcome_hash' => $owner->outcome_hash,
                        'refund_transaction_ids' => array_column($outcomes, 'refund_transaction_id')]]);
                $audit->save();

                return $this->result($owner, $outcomes, false);
            } finally {
                $this->dispatch = null;
            }
        }, 1);
    }

    /** Internal typed capability check; request arrays/context never authorize it. */
    public function assertBetPermit(Bet $bet, BetCancellationData $data): int
    {
        $owner = $this->dispatchOwner('preparing');
        $member = collect($this->dispatch['participants'])->firstWhere('bet_id', (int) $bet->id);
        if (! $member || $member['initial_state'] !== 'active' || (int) $bet->draw_id !== (int) $owner->draw_id
            || (int) $bet->user_id !== $data->userId || $data->reason !== BetCancellationReason::DrawCancelled) {
            $this->refuse('draw_cancellation_dispatch_refused');
        }

        return (int) $owner->id;
    }

    public function assertLifecyclePermit(Draw $draw): void
    {
        $owner = $this->dispatchOwner('refunded');
        if ((int) $owner->draw_id !== (int) $draw->id || $owner->previous_status !== $draw->status->value) {
            $this->refuse('draw_cancellation_dispatch_refused');
        }
        $this->assertTerms($owner, $draw);
        $this->assertOutcomeSeal($owner, $this->proveOutcomes($owner, $draw, $this->dispatch['wallets']));
    }

    private function dispatchOwner(string $state): object
    {
        if ($this->dispatch === null || $this->database->transactionLevel() < 1) {
            $this->refuse('draw_cancellation_dispatch_refused');
        }
        $owner = DB::table('draw_cancellation_ownerships')->where('id', $this->dispatch['id'])->lockForUpdate()->first();
        if (! $owner || $owner->state !== $state
            || ($state === 'preparing' ? $owner->completed_at !== null : $owner->completed_at === null)
            || ! hash_equals($this->dispatch['terms_hash'], $owner->terms_hash)) {
            $this->refuse('draw_cancellation_dispatch_refused');
        }

        return $owner;
    }

    private function membership(int $drawId, bool $lock): array
    {
        $query = DB::table('bets as b')->leftJoin('bet_purchase_ownerships as p', 'p.bet_id', '=', 'b.id')
            ->where('b.draw_id', $drawId)->orderBy('b.id')
            ->select(['b.id', 'b.user_id', 'b.deleted_at', 'p.id as ownership_id', 'p.wallet_id', 'p.original_transaction_id', 'p.original_hash', 'p.terms_hash']);
        if ($lock) {
            $query->lockForUpdate();
        }
        // Protocol hard ceiling; current NEW-dispatch capacity cannot revoke
        // a completed original receipt's read-only replay.
        $cap = 1000;
        $rows = $query->limit($cap + 1)->get();
        if ($rows->count() > $cap) {
            $this->refuse('draw_cancellation_capacity_review_required');
        }

        return $rows->map(fn ($r): array => ['bet_id' => (int) $r->id, 'user_id' => (int) $r->user_id,
            'deleted' => $r->deleted_at !== null, 'ownership_id' => (int) $r->ownership_id,
            'wallet_id' => (int) $r->wallet_id, 'original_transaction_id' => (int) $r->original_transaction_id,
            'original_hash' => $r->original_hash, 'terms_hash' => $r->terms_hash])->all();
    }

    private function assertMembership(array $members): void
    {
        foreach ($members as $member) {
            if ($member['deleted'] || $member['ownership_id'] < 1 || $member['wallet_id'] < 1 || $member['original_transaction_id'] < 1) {
                $this->refuse('draw_cancellation_unowned_history');
            }
        }
    }

    private function walletIds(array $members, bool $lock): array
    {
        $ids = array_column($members, 'wallet_id');
        $query = DB::table('agent_commission_payouts as p')->join('bet_commission_ownerships as c', 'c.id', '=', 'p.source_ownership_id')
            ->whereIn('c.purchase_ownership_id', array_column($members, 'ownership_id'))->orderBy('p.id')->select('p.wallet_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $ids = array_values(array_unique(array_filter(array_merge($ids, $query->get()->pluck('wallet_id')->map(fn ($id): int => (int) $id)->all()))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function proveOutcomes(object $owner, Draw $draw, array $locked): array
    {
        $participants = $this->decode($owner->participants);
        $current = $this->membership((int) $draw->id, true);
        $this->assertMembership($current);
        if ($current !== array_map(fn ($p): array => array_diff_key($p, array_flip(['initial_state', 'initial_refund_id'])), $participants)
            || $this->walletIds($current, true) !== array_keys($locked)) {
            $this->refuse('draw_cancellation_membership_changed');
        }
        // Unsupported anonymous/bulk reservations are never swept or refunded
        // from a counter. Every current draw limit must close exactly to zero.
        $limits = DB::table('number_limits')->where('draw_id', $draw->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($limits as $limit) {
            foreach (['current_amount', 'current_payout_exposure'] as $column) {
                if (! preg_match('/^0+(?:\.0+)?$/D', (string) $limit->{$column})) {
                    $this->refuse('draw_cancellation_unmanaged_risk_review_required');
                }
            }
        }
        $outcomes = [];
        foreach ($participants as $member) {
            $result = $this->cancellations->proveOwnedCancellation($member['bet_id'], $locked);
            $claim = DB::table('bet_purchase_ownerships')->where('id', $member['ownership_id'])->lockForUpdate()->first();
            if ($member['initial_state'] === 'active') {
                if ($result->reason !== BetCancellationReason::DrawCancelled
                    || (int) ($result->refund->metadata['draw_cancellation_id'] ?? 0) !== (int) $owner->id) {
                    $this->refuse('draw_cancellation_outcome_unproven');
                }
            } elseif ($member['initial_state'] !== 'cancelled' || $member['initial_refund_id'] !== (int) $result->refund->id) {
                $this->refuse('draw_cancellation_outcome_unproven');
            }
            $outcomes[] = ['bet_id' => $member['bet_id'], 'ownership_id' => $member['ownership_id'],
                'refund_transaction_id' => (int) $result->refund->id, 'refund_hash' => $claim->refund_hash];
        }

        return $outcomes;
    }

    private function termsHash(object $owner, Draw $draw): string
    {
        return $this->hash(['owned-draw-cancellation-v1', (int) $draw->id, $draw->uuid, $draw->draw_number, $draw->currency->value,
            (int) $owner->actor_user_id, $owner->previous_status, $owner->reason, $this->decode($owner->participants)]);
    }

    private function assertTerms(object $owner, Draw $draw): void
    {
        if (! hash_equals($owner->terms_hash, $this->termsHash($owner, $draw))) {
            $this->refuse('draw_cancellation_terms_unproven');
        }
    }

    private function assertOutcomeSeal(object $owner, array $outcomes): void
    {
        if (! is_string($owner->outcome_hash) || $this->decode($owner->outcomes) !== $outcomes
            || ! hash_equals($owner->outcome_hash, $this->hash([$owner->terms_hash, $outcomes, $owner->completed_at]))) {
            $this->refuse('draw_cancellation_outcome_unproven');
        }
    }

    private function result(object $owner, array $outcomes, bool $replayed): array
    {
        return ['draw_id' => (int) $owner->draw_id, 'ownership_id' => (int) $owner->id, 'replayed' => $replayed, 'proof_scope' => 'original_owned_refunds',
            'bet_count' => count($outcomes), 'newly_refunded_count' => $replayed ? 0
                : count(array_filter($this->decode($owner->participants), fn ($p): bool => $p['initial_state'] === 'active')),
            'refund_transaction_ids' => array_column($outcomes, 'refund_transaction_id')];
    }

    private function authorize(int $actorUserId): void
    {
        $actor = User::query()->whereKey($actorUserId)->lockForUpdate()->first();
        if (! $actor || $actor->status !== UserStatus::Active || (bool) config('permission.teams', false)) {
            throw new AuthorizationException('Current active staff and explicit manage draws permission required.');
        }
        // Current locking reads, not a Gate super-admin bypass or cached relations.
        $roles = $actor->roles()->where('guard_name', 'web')->whereIn('name', ['admin', 'super-admin'])->orderBy('roles.id')->lockForUpdate()->get();
        $direct = $actor->permissions()->where('guard_name', 'web')->where('name', 'manage draws')->lockForUpdate()->get();
        $grants = DB::table(config('permission.table_names.role_has_permissions').' as g')
            ->join(config('permission.table_names.permissions').' as p', 'p.id', '=', 'g.permission_id')
            ->whereIn('g.role_id', $roles->modelKeys())->where('p.guard_name', 'web')->where('p.name', 'manage draws')->lockForUpdate()->get();
        if ($roles->isEmpty() || ($direct->isEmpty() && $grants->isEmpty())) {
            throw new AuthorizationException('Current active staff and explicit manage draws permission required.');
        }
    }

    private function decode(mixed $json): array
    {
        try {
            $value = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $value = null;
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $this->refuse('draw_cancellation_record_unproven');
        }

        return $value;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function refuse(string $code): never
    {
        throw new DrawLifecycleException('Owned draw cancellation refused; controlled review may be required.', $code);
    }
}
