<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** At-least-once observational routing. SQL leases are never financial clearance or provider authority. */
final class StripeFollowupSweepService
{
    public const WORK = 'stripe_followup_sweep_work';

    public const EVENTS = 'stripe_followup_sweep_events';

    public const LIMIT = 25;

    public const ENROLL_LIMIT = 100;

    public const LEASE_SECONDS = 300;

    public const ATTEMPT_BUDGET = 100;

    public const GENERATION_LIMIT = 1000000;

    public const REASONS = ['capture_now_visible', 'evidence_investigated', 'operational_retry'];

    public function sweep(?User $actor, int $limit = self::LIMIT): array
    {
        $this->top();
        $this->authorize($actor);
        if ($limit < 1 || $limit > self::LIMIT) {
            $this->refuse('stripe_sweep_limit');
        }
        $enrolled = $this->enroll($actor);
        $results = [];
        for ($i = 0; $i < $limit; $i++) {
            $lease = $this->claimNext($actor);
            if ($lease === null) {
                break; // Bounded best effort, not an authenticated empty-backlog certificate.
            }
            $results[] = isset($lease['token']) ? $this->runLease($lease, $actor) : $lease;
        }

        return ['enrolled' => $enrolled, 'processed' => count($results), 'results' => $results];
    }

    public function enroll(?User $actor): int
    {
        $this->top();
        $this->authorize($actor);
        $rows = DB::table(StripeDepositFollowupInbox::TABLE.' as i')->useWritePdo()
            ->where('i.account_scope', $this->scope())->where('i.configuration_hash', $this->configuration())
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from(self::WORK.' as w')->whereColumn('w.inbox_id', 'i.id'))
            ->orderBy('i.id')->limit(self::ENROLL_LIMIT)->get();
        $count = 0;
        foreach ($rows as $source) {
            $count += DB::transaction(function () use ($source, $actor): int {
                $staff = $this->authorize($actor);
                $current = DB::table(StripeDepositFollowupInbox::TABLE)->useWritePdo()->where('id', $source->id)->first();
                if (! $current || $current->account_scope !== $this->scope() || $current->configuration_hash !== $this->configuration()
                    || ! hash_equals($source->response_hash, $current->response_hash)) {
                    $this->refuse('stripe_sweep_scope');
                }
                $state = 'pending';
                $code = null;
                try {
                    app(StripeDepositFollowupInbox::class)->read($current);
                } catch (FinancialException $e) {
                    $state = 'held';
                    $code = $e->errorCode();
                }
                $now = now()->timestamp;
                $inserted = DB::table(self::WORK)->insertOrIgnore(['uuid' => (string) Str::uuid(), 'inbox_id' => $source->id,
                    'account_scope' => $current->account_scope, 'configuration_hash' => $current->configuration_hash,
                    'source_hash' => $current->response_hash, 'state' => $state, 'generation' => 0, 'budget_used' => 0,
                    'available_epoch' => $now, 'created_epoch' => $now, 'updated_epoch' => $now, 'last_code' => $code]);
                if ($inserted === 0) {
                    return 0;
                }
                $row = DB::table(self::WORK)->where('inbox_id', $source->id)->sole();
                $this->publish($row, [], $staff, 'enrolled');

                return 1;
            }, 3);
        }

        return $count;
    }

    /** A committed lease, not a held SQL lock; may return an operational quarantine result. */
    public function claimNext(?User $actor): ?array
    {
        $this->top();
        $this->authorize($actor);
        $id = DB::table(self::WORK)->useWritePdo()->where('account_scope', $this->scope())
            ->where('configuration_hash', $this->configuration())->whereIn('state', ['pending', 'retry', 'leased'])
            ->where('available_epoch', '<=', now()->timestamp)->orderBy('available_epoch')->orderBy('id')->value('id');
        if ($id === null) {
            return null;
        }

        return DB::transaction(function () use ($id, $actor): ?array {
            $row = DB::table(self::WORK)->where('id', $id)->lockForUpdate()->sole();
            $staff = $this->authorize($actor);
            $this->validateHeader($row);
            if (! in_array($row->state, ['pending', 'retry', 'leased'], true) || (int) $row->available_epoch > now()->timestamp) {
                return null; // Another process won; next scheduler tick can continue remaining work.
            }
            if ((int) $row->generation >= self::GENERATION_LIMIT) {
                $this->refuse('stripe_sweep_generation_full');
            }
            $code = null;
            try {
                $this->source($row);
            } catch (FinancialException $e) {
                $code = $e->errorCode();
            }
            if ($code !== null || (int) $row->budget_used >= self::ATTEMPT_BUDGET) {
                $this->publish($row, ['state' => 'held', 'last_code' => $code ?? 'stripe_sweep_budget',
                    'lease_token' => null, 'lease_actor_id' => null, 'lease_until' => null], $staff, 'exhausted');

                return ['work_id' => (int) $row->id, 'inbox_id' => (int) $row->inbox_id, 'state' => 'held', 'code' => $code ?? 'stripe_sweep_budget'];
            }
            $token = (string) Str::uuid();
            $until = now()->timestamp + self::LEASE_SECONDS;
            $this->publish($row, ['state' => 'leased', 'generation' => (int) $row->generation + 1,
                'budget_used' => (int) $row->budget_used + 1, 'lease_token' => $token, 'lease_actor_id' => (int) $staff->id,
                'lease_until' => $until, 'available_epoch' => $until], $staff, 'claimed');

            return ['work_id' => (int) $row->id, 'inbox_id' => (int) $row->inbox_id, 'generation' => (int) $row->generation + 1,
                'actor_id' => (int) $staff->id, 'token' => $token];
        }, 3);
    }

    /** Internal executor: current lease checked before dispatch and separately fenced after routing commits. */
    public function runLease(array $lease, ?User $actor): array
    {
        $this->top();
        $staff = $this->authorize($actor);
        $this->leaseShape($lease);
        $row = DB::table(self::WORK)->useWritePdo()->where('id', $lease['work_id'])->first();
        if (! $row) {
            $this->refuse();
        }
        $this->validateHeader($row);
        if (! $this->owns($row, $lease, $staff)) {
            return ['work_id' => $lease['work_id'], 'state' => 'lease_lost', 'routing_committed' => false];
        }
        $this->source($row);
        $result = null;
        $code = null;
        try {
            $result = app(StripeFollowupRoutingService::class)->route($lease['inbox_id'], $staff);
        } catch (FinancialException $e) {
            $code = $e->errorCode();
        }
        $state = DB::transaction(function () use ($lease, $actor, $result, $code): string {
            $row = DB::table(self::WORK)->where('id', $lease['work_id'])->lockForUpdate()->sole();
            $staff = $this->authorize($actor);
            $this->validateHeader($row);
            if (! $this->owns($row, $lease, $staff)) {
                return 'lease_lost';
            }
            $this->source($row);
            if ($result) {
                $history = app(StripeFollowupRoutingService::class)->history((int) $row->inbox_id, $staff);
                $proved = array_values(array_filter($history, fn ($entry) => $entry['decision_id'] === $result['decision_id']));
                if (count($proved) !== 1 || $proved[0]['state'] !== $result['state']) {
                    $this->refuse();
                }
            }
            $done = ($result['state'] ?? null) === 'routed';
            $state = $done ? 'routed' : ((int) $row->budget_used >= self::ATTEMPT_BUDGET ? 'held' : 'retry');
            $this->publish($row, ['state' => $state, 'lease_token' => null, 'lease_actor_id' => null, 'lease_until' => null,
                'available_epoch' => now()->timestamp + $this->backoff((int) $row->budget_used),
                'last_decision_id' => $result['decision_id'] ?? $row->last_decision_id,
                'last_code' => $code ?? ($done ? null : 'stripe_sweep_unmatched')], $staff, 'completed');

            return $state;
        }, 3);

        return ['work_id' => $lease['work_id'], 'inbox_id' => $lease['inbox_id'], 'state' => $state,
            'routing_committed' => $result !== null, 'routing_state' => $result['state'] ?? 'held',
            'decision_id' => $result['decision_id'] ?? null, 'code' => $code];
    }

    public function retryHeld(int $inboxId, ?User $actor, string $reason): array
    {
        $this->top();
        $this->authorize($actor);
        if (! in_array($reason, self::REASONS, true)) {
            $this->refuse('stripe_sweep_reason');
        }

        // Resolve the unique source index without holding it; contend on the same PRIMARY root as claims/completions.
        $id = DB::table(self::WORK)->useWritePdo()->where('inbox_id', $inboxId)->value('id');
        if ($id === null) {
            $this->refuse();
        }

        return DB::transaction(function () use ($inboxId, $id, $actor, $reason): array {
            $row = DB::table(self::WORK)->where('id', $id)->lockForUpdate()->first();
            if (! $row || (int) $row->inbox_id !== $inboxId) {
                $this->refuse();
            }
            // Establish any consistent snapshot only AFTER the current Work row lock.
            $staff = $this->authorize($actor);
            $this->validateHeader($row);
            $this->source($row);
            if ($row->state !== 'held') {
                $this->refuse('stripe_sweep_not_held');
            }
            if ((int) $row->generation >= self::GENERATION_LIMIT) {
                $this->refuse('stripe_sweep_generation_full');
            }
            $this->publish($row, ['state' => 'pending', 'budget_used' => 0, 'available_epoch' => now()->timestamp,
                'lease_token' => null, 'lease_actor_id' => null, 'lease_until' => null, 'last_code' => null], $staff, 'requeued', $reason);

            return ['inbox_id' => $inboxId, 'state' => 'pending', 'generation' => (int) $row->generation];
        }, 3);
    }

    public function authorize(?User $actor): User
    {
        $staff = app(StripeFollowupRoutingService::class)->authorize($actor);
        if (! config('payment.deposit_followup_sweep.stripe.enabled', false)) {
            $this->refuse('stripe_sweep_disabled');
        }

        return $staff;
    }

    private function publish(object $row, array $patch, User $actor, string $kind, ?string $reason = null): void
    {
        $this->authorize($actor);
        DB::table(self::WORK)->where('id', $row->id)->update($patch + ['updated_epoch' => now()->timestamp]);
        $after = DB::table(self::WORK)->where('id', $row->id)->sole();
        $bytes = json_encode(['header' => $this->snapshot($after), 'previous_event_id' => $row->last_event_id === null ? null : (int) $row->last_event_id,
            'actor_id' => (int) $actor->id, 'kind' => $kind, 'reason' => $reason], JSON_THROW_ON_ERROR);
        $id = DB::table(self::EVENTS)->insertGetId(['work_id' => $row->id, 'generation' => $after->generation,
            'kind' => $kind, 'actor_id' => $actor->id, 'previous_event_id' => $row->last_event_id, 'response_cipher' => Crypt::encryptString($bytes),
            'response_hash' => hash('sha256', $bytes), 'created_epoch' => now()->timestamp]);
        DB::table(self::WORK)->where('id', $row->id)->update(['last_event_id' => $id]);
    }

    private function validateHeader(object $row): void
    {
        try {
            $event = DB::table(self::EVENTS)->useWritePdo()->where('id', $row->last_event_id)->first();
            if (! $event) {
                $this->refuse();
            }
            $bytes = Crypt::decryptString($event->response_cipher);
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! hash_equals($event->response_hash, hash('sha256', $bytes)) || ($value['header'] ?? null) !== $this->snapshot($row)
                || (int) $event->work_id !== (int) $row->id || (int) $event->generation !== (int) $row->generation
                || ($value['previous_event_id'] ?? null) !== ($event->previous_event_id === null ? null : (int) $event->previous_event_id)
                || ($value['actor_id'] ?? null) !== (int) $event->actor_id || ($value['kind'] ?? null) !== $event->kind
                || $row->account_scope !== $this->scope() || $row->configuration_hash !== $this->configuration()) {
                $this->refuse();
            }
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function snapshot(object $row): array
    {
        $out = [];
        foreach (['id', 'uuid', 'inbox_id', 'account_scope', 'configuration_hash', 'source_hash', 'state', 'generation', 'budget_used',
            'lease_actor_id', 'lease_token', 'lease_until', 'available_epoch', 'last_decision_id', 'last_code', 'created_epoch', 'updated_epoch'] as $key) {
            $out[$key] = $row->{$key};
            if ($out[$key] !== null && in_array($key, ['id', 'inbox_id', 'generation', 'budget_used', 'lease_actor_id', 'lease_until', 'available_epoch', 'last_decision_id', 'created_epoch', 'updated_epoch'], true)) {
                $out[$key] = (int) $out[$key];
            }
        }

        return $out;
    }

    private function source(object $row): void
    {
        $source = DB::table(StripeDepositFollowupInbox::TABLE)->useWritePdo()->where('id', $row->inbox_id)->first();
        if (! $source || $source->account_scope !== $this->scope() || $source->configuration_hash !== $this->configuration()
            || ! hash_equals($row->source_hash, $source->response_hash)) {
            $this->refuse('stripe_sweep_scope');
        }
        app(StripeDepositFollowupInbox::class)->read($source);
    }

    private function owns(object $row, array $lease, User $actor): bool
    {
        return $row->state === 'leased' && (int) $row->generation === $lease['generation'] && $row->lease_token === $lease['token']
            && (int) $row->lease_actor_id === (int) $actor->id && $lease['actor_id'] === (int) $actor->id
            && (int) $row->inbox_id === $lease['inbox_id'] && (int) $row->lease_until > now()->timestamp;
    }

    private function leaseShape(array $lease): void
    {
        foreach (['work_id', 'inbox_id', 'generation', 'actor_id'] as $key) {
            if (! is_int($lease[$key] ?? null) || $lease[$key] < 1) {
                $this->refuse('stripe_sweep_lease');
            }
        }
        if (! is_string($lease['token'] ?? null) || ! Str::isUuid($lease['token'])) {
            $this->refuse('stripe_sweep_lease');
        }
    }

    private function backoff(int $attempt): int
    {
        return min(3600, 60 * (2 ** min(6, max(0, $attempt - 1))));
    }

    private function scope(): string
    {
        return app(DepositProviderAccountService::class)->current('stripe');
    }

    private function configuration(): string
    {
        return app(DepositCheckoutService::class)->stripeConfigurationFingerprint();
    }

    private function top(): void
    {
        app(DepositCheckoutService::class)->assertTopLevel();
    }

    private function refuse(string $code = 'stripe_sweep_unproven'): never
    {
        throw FinancialException::withCode($code, 'Stripe routing sweep configuration, current staff, source, lease or protected operational custody could not be proved. No financial action was authorized.');
    }
}
