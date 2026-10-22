<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Staff-operated inbox-to-original routing. No provider, refund, credit, acknowledgement or release authority. */
final class StripeFollowupRoutingService
{
    public const TABLE = 'stripe_followup_route_decisions';

    public const BATCH_LIMIT = 25;

    public const HISTORY_LIMIT = 100;

    public function route(int $inboxId, ?User $actor): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);
        $snapshot = $this->inbox($inboxId);
        $source = app(StripeDepositFollowupInbox::class)->read($snapshot);
        $candidate = $this->candidate($source, $snapshot);

        return DB::transaction(function () use ($inboxId, $actor, $snapshot, $candidate): array {
            $deposit = null;
            // Never acquire a wallet after an inbox lock. A changed candidate requires a fresh top-level retry.
            if ($candidate !== null) {
                $route = Deposit::query()->useWritePdo()->find($candidate);
                if (! $route) {
                    $this->refuse();
                }
                [$deposit] = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
            }
            $current = $this->inbox($inboxId, true);
            $staff = $this->authorize($actor);
            $source = app(StripeDepositFollowupInbox::class)->read($current);
            if (! hash_equals($snapshot->response_hash, $current->response_hash) || $snapshot->event_key !== $current->event_key
                || $this->candidate($source, $current) !== $candidate) {
                $this->refuse('stripe_routing_stale');
            }
            $case = $deposit ? app(StripeDepositReviewService::class)->inspectInOriginalTransaction($deposit, $staff) : null;
            if ($deposit && (! $case || ! DB::table(StripeDepositReviewService::OBSERVATIONS)
                ->join(StripeDepositReviewService::CASES.' as c', 'c.id', '=', StripeDepositReviewService::OBSERVATIONS.'.case_id')
                ->where('c.uuid', $case['case_uuid'])->where('c.deposit_id', $deposit->id)
                ->where(StripeDepositReviewService::OBSERVATIONS.'.inbox_id', $inboxId)->exists())) {
                $this->refuse();
            }
            $staff = $this->authorize($actor);
            $facts = ['inbox_id' => $inboxId, 'event_key' => $current->event_key,
                'account_scope' => $current->account_scope, 'configuration_hash' => $current->configuration_hash,
                'source_hash' => $current->response_hash, 'body_hash' => $source['body_hash'],
                'state' => $deposit ? 'routed' : 'unmatched', 'deposit_id' => $deposit ? (int) $deposit->id : null,
                'case_uuid' => $case['case_uuid'] ?? null, 'case_version' => $case['version'] ?? null,
                'evidence_hash' => $case['evidence_hash'] ?? null];
            $key = hash('sha256', $this->encode($facts));
            $history = DB::table(self::TABLE)->where('inbox_id', $inboxId)->orderBy('id')->limit(self::HISTORY_LIMIT + 1)->get();
            if ($history->count() > self::HISTORY_LIMIT) {
                $this->refuse('stripe_routing_history_full');
            }
            foreach ($history as $entry) {
                $this->validateDecision($entry, $current, $source['body_hash']);
                if ($entry->state === 'routed' && ((int) $entry->deposit_id !== $candidate
                    || $entry->case_uuid !== ($case['case_uuid'] ?? null) || (int) $entry->case_version > ($case['version'] ?? 0)
                    || ((int) $entry->case_version === ($case['version'] ?? 0) && $entry->evidence_hash !== ($case['evidence_hash'] ?? null)))) {
                    $this->refuse();
                }
            }
            $old = $history->first(fn ($entry) => $entry->decision_key === $key);
            if ($old) {
                return $this->summary($old) + ['replayed' => true, 'case' => $case];
            }
            if ($history->count() >= self::HISTORY_LIMIT) {
                $this->refuse('stripe_routing_history_full');
            }
            $bytes = $this->encode(['facts' => $facts, 'actor_id' => (int) $staff->id]);
            $id = DB::table(self::TABLE)->insertGetId(['decision_key' => $key, 'inbox_id' => $inboxId,
                'deposit_id' => $facts['deposit_id'], 'actor_id' => $staff->id, 'state' => $facts['state'],
                'case_uuid' => $facts['case_uuid'], 'case_version' => $facts['case_version'], 'evidence_hash' => $facts['evidence_hash'],
                'source_hash' => $current->response_hash, 'response_cipher' => Crypt::encryptString($bytes),
                'response_hash' => hash('sha256', $bytes), 'created_at' => now()]);
            $decision = DB::table(self::TABLE)->where('id', $id)->sole();

            return $this->summary($decision) + ['replayed' => false, 'case' => $case];
        }, 3);
    }

    /** Each item has an independent root. Explicit result list, never a claimed all-or-nothing batch. */
    public function routeBatch(array $ids, ?User $actor): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);
        if ($ids === [] || count($ids) > self::BATCH_LIMIT || array_filter($ids, fn ($id) => ! is_int($id) || $id < 1) !== []
            || count(array_unique($ids, SORT_REGULAR)) !== count($ids)) {
            $this->refuse('stripe_routing_batch_input');
        }
        $results = [];
        foreach ($ids as $id) {
            try {
                $results[] = $this->route($id, $actor);
            } catch (FinancialException $e) {
                $results[] = ['inbox_id' => $id, 'state' => 'held', 'code' => $e->errorCode()];
            }
        }

        return $results;
    }

    public function history(int $inboxId, ?User $actor): array
    {
        $this->authorize($actor);
        $inbox = $this->inbox($inboxId);
        $source = app(StripeDepositFollowupInbox::class)->read($inbox);
        $rows = DB::table(self::TABLE)->useWritePdo()->where('inbox_id', $inboxId)->orderBy('id')->limit(self::HISTORY_LIMIT + 1)->get();
        if ($rows->count() > self::HISTORY_LIMIT) {
            $this->refuse('stripe_routing_history_full');
        }
        $out = [];
        foreach ($rows as $row) {
            $this->validateDecision($row, $inbox, $source['body_hash']);
            $out[] = $this->summary($row);
        }
        $this->authorize($actor);

        return $out;
    }

    public function authorize(?User $actor): User
    {
        $current = app(DepositReviewAccess::class)->authorize($actor);
        if (! config('payment.deposit_review.stripe.enabled', false) || ! config('payment.deposit_followup_routing.stripe.enabled', false)) {
            $this->refuse('stripe_routing_disabled');
        }

        return $current;
    }

    private function inbox(int $id, bool $lock = false): object
    {
        $q = DB::table(StripeDepositFollowupInbox::TABLE)->useWritePdo()->where('id', $id)
            ->where('account_scope', app(DepositProviderAccountService::class)->current('stripe'))
            ->where('configuration_hash', app(DepositCheckoutService::class)->stripeConfigurationFingerprint());
        if ($lock) {
            $q->lockForUpdate();
        }
        $row = $q->first();
        if (! $row) {
            $this->refuse('stripe_routing_scope');
        }

        return $row;
    }

    private function candidate(array $source, object $inbox): ?int
    {
        $facts = $source['payload']['data']['object'];
        $names = [];
        foreach (['reference', 'gateway_reference', 'payment_reference', 'id', 'payment_intent', 'charge'] as $field) {
            $value = $facts[$field] ?? null;
            if (is_string($value) && preg_match('/^(?:cs|pi|ch)_[A-Za-z0-9_]{8,240}$/D', $value) === 1) {
                $names[] = $value;
            }
        }
        if ($names === []) {
            return null;
        }
        $rows = DB::table(StripeDepositSettlementService::TABLE.' as r')->useWritePdo()
            ->join(DepositCheckoutService::TABLE.' as a', 'a.id', '=', 'r.attempt_id')
            ->whereColumn('a.settlement_receipt_uuid', 'r.uuid')->whereNotNull('r.applied_at')
            ->where('a.provider', 'stripe')->where('a.state', 'projected')
            ->where('a.account_scope', $inbox->account_scope)->where('a.configuration_hash', $inbox->configuration_hash)
            ->where(fn ($q) => $q->whereIn('r.session_reference', $names)->orWhereIn('r.intent_reference', $names)->orWhereIn('r.charge_reference', $names))
            ->distinct()->limit(2)->pluck('a.deposit_id');
        if ($rows->count() > 1) {
            $this->refuse('stripe_routing_ambiguous');
        }

        return $rows->isEmpty() ? null : (int) $rows->sole();
    }

    private function validateDecision(object $row, object $inbox, string $bodyHash): void
    {
        try {
            $bytes = Crypt::decryptString($row->response_cipher);
            $value = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            $f = $value['facts'] ?? [];
            if (! hash_equals($row->response_hash, hash('sha256', $bytes)) || ($value['actor_id'] ?? null) !== (int) $row->actor_id
                || ($f['inbox_id'] ?? null) !== (int) $inbox->id || (int) $row->inbox_id !== (int) $inbox->id
                || ($f['body_hash'] ?? null) !== $bodyHash || ($f['event_key'] ?? null) !== $inbox->event_key || ($f['source_hash'] ?? null) !== $inbox->response_hash
                || ($f['account_scope'] ?? null) !== $inbox->account_scope || ($f['configuration_hash'] ?? null) !== $inbox->configuration_hash
                || $row->source_hash !== $inbox->response_hash || ($f['state'] ?? null) !== $row->state
                || ($f['deposit_id'] ?? null) !== ($row->deposit_id === null ? null : (int) $row->deposit_id)
                || ($f['case_uuid'] ?? null) !== $row->case_uuid || ($f['case_version'] ?? null) !== ($row->case_version === null ? null : (int) $row->case_version)
                || ($f['evidence_hash'] ?? null) !== $row->evidence_hash || ! in_array($row->state, ['routed', 'unmatched'], true)
                || ! hash_equals($row->decision_key, hash('sha256', $this->encode($f)))) {
                $this->refuse();
            }
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function summary(object $row): array
    {
        return ['decision_id' => (int) $row->id, 'inbox_id' => (int) $row->inbox_id, 'state' => $row->state,
            'deposit_id' => $row->deposit_id === null ? null : (int) $row->deposit_id, 'case_uuid' => $row->case_uuid,
            'case_version' => $row->case_version === null ? null : (int) $row->case_version,
            'evidence_hash' => $row->evidence_hash, 'actor_id' => (int) $row->actor_id];
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function refuse(string $code = 'stripe_routing_unproven'): never
    {
        throw FinancialException::withCode($code, 'Signed inbox routing is disabled, stale, ambiguous or cannot prove its original/custody boundary. No financial action was authorized.');
    }
}
