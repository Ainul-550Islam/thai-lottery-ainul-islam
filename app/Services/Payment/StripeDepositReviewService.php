<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\DepositStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\DepositCompletionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable operator review, NOT refund execution, reversal, clearance or a withdrawal gate. */
final class StripeDepositReviewService
{
    public const CASES = 'stripe_deposit_review_cases';

    public const OBSERVATIONS = 'stripe_deposit_review_observations';

    public const ACKNOWLEDGEMENTS = 'stripe_deposit_review_acknowledgements';

    public const SCAN_LIMIT = 5000;

    public const CASE_LIMIT = 100;

    public function inspect(Deposit $route, ?User $actor): ?array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);

        return DB::transaction(fn (): ?array => $this->inspectInOriginalTransaction($route, $actor), 3);
    }

    /** SQL-only coordinator entry; re-acquires the original ordered locks and never accepts caller proof. */
    public function inspectInOriginalTransaction(Deposit $route, ?User $actor): ?array
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse('stripe_review_transaction_required', 'A coordinated original review requires an existing SQL root.');
        }

        return DB::transaction(function () use ($route, $actor): ?array {
            [$deposit, , $payment] = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
            $staff = $this->authorize($actor);
            $case = $this->refresh($deposit, $payment, $staff);
            $this->authorize($actor);

            return $case ? $this->summary($case) : null;
        }, 3);
    }

    public function acknowledge(Deposit $route, ?User $actor, string $uuid, int $version, string $hash, string $note): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);
        $note = trim($note);
        if (mb_strlen($note) < 16 || mb_strlen($note) > 2000 || ! Str::isUuid($uuid)
            || $version < 1 || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
            $this->refuse('stripe_review_input', 'A current case/version/evidence token and a 16–2000 character review note are required.');
        }
        $result = DB::transaction(function () use ($route, $actor, $uuid, $version, $hash, $note): array {
            [$deposit, , $payment] = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
            $staff = $this->authorize($actor);
            $case = $this->refresh($deposit, $payment, $staff);
            $staff = $this->authorize($actor);
            if (! $case || $case->uuid !== $uuid || (int) $case->version !== $version || ! hash_equals($case->evidence_hash, $hash)) {
                // Commit newly discovered evidence, but NEVER acknowledge a newer version with an old screen token.
                return ['stale' => true];
            }
            $ack = DB::table(self::ACKNOWLEDGEMENTS)->where('case_id', $case->id)->where('case_version', $version)->first();
            if ($ack) {
                $this->validateAck($case, $ack);
                if ((int) $ack->actor_id !== (int) $staff->id || ! hash_equals($ack->note_hash, hash('sha256', $note))) {
                    $this->refuse('stripe_review_conflict', 'This version already has a different immutable acknowledgement.');
                }

                return $this->summary($case) + ['replayed' => true];
            }
            $bytes = $this->encode(['case_uuid' => $case->uuid, 'version' => $version, 'evidence_hash' => $hash,
                'actor_id' => (int) $staff->id, 'note' => $note]);
            DB::table(self::ACKNOWLEDGEMENTS)->insert(['case_id' => $case->id, 'actor_id' => $staff->id,
                'case_version' => $version, 'evidence_hash' => $hash, 'note_cipher' => Crypt::encryptString($bytes),
                'note_hash' => hash('sha256', $note), 'created_at' => now()]);
            DB::table(self::CASES)->where('id', $case->id)->update(['state' => 'acknowledged', 'updated_at' => now()]);
            $case->state = 'acknowledged';

            return $this->summary($case) + ['replayed' => false];
        }, 3);
        if ($result['stale'] ?? false) {
            $this->refuse('stripe_review_stale', 'Evidence changed or the case token is not current. Inspect the case again; no acknowledgement was written.');
        }

        return $result;
    }

    /** Explicit inspect action: returns safe event summaries and authorized decrypted review notes, not raw payloads. */
    public function details(Deposit $route, ?User $actor): ?array
    {
        $case = $this->inspect($route, $actor);
        if ($case === null) {
            return null;
        }
        $id = DB::table(self::CASES)->where('uuid', $case['case_uuid'])->sole()->id;
        $events = [];
        foreach (DB::table(self::OBSERVATIONS)->where('case_id', $id)->where('case_version', '<=', $case['version'])->orderBy('inbox_id')->get() as $row) {
            $b = $this->decode($row->response_cipher);
            $p = $b['source']['payload'];
            $facts = $p['data']['object'];
            $events[] = ['event_id' => $p['id'], 'type' => $p['type'], 'created' => $p['created'],
                'amount_minor' => is_int($facts['amount'] ?? null) ? $facts['amount'] : null,
                'currency' => is_string($facts['currency'] ?? null) ? $facts['currency'] : null,
                'status' => is_string($facts['status'] ?? null) ? mb_substr($facts['status'], 0, 80) : null];
        }
        $notes = [];
        foreach (DB::table(self::ACKNOWLEDGEMENTS)->where('case_id', $id)->where('case_version', '<=', $case['version'])->orderBy('case_version')->get() as $row) {
            $b = $this->decode($row->note_cipher);
            $notes[] = ['version' => (int) $row->case_version, 'actor_id' => (int) $row->actor_id, 'note' => $b['note']];
        }

        $this->authorize($actor);

        return $case + ['events' => $events, 'acknowledgements' => $notes];
    }

    /** UI hint only. The mutation paths re-prove the original graph, receipt and complete bounded history. */
    public function current(Deposit $route, ?User $actor): ?array
    {
        $this->authorize($actor);
        $case = DB::table(self::CASES)->useWritePdo()->where('deposit_id', $route->id)->first();

        return $case ? $this->summary($case) : null;
    }

    private function refresh(Deposit $deposit, Payment $payment, User $actor): ?object
    {
        $capture = app(StripeDepositSettlementService::class)->observedCapture($payment);
        if ($deposit->status !== DepositStatus::Confirmed || $capture === null) {
            $this->refuse();
        }
        app(DepositCompletionService::class)->verifySettled($deposit);
        $receipt = data_get($payment->metadata, StripeDepositSettlementService::MARKER);
        $case = DB::table(self::CASES)->where('deposit_id', $deposit->id)->lockForUpdate()->first();
        if ($case && ((int) $case->payment_id !== (int) $payment->id || $case->settlement_receipt_uuid !== $receipt)) {
            $this->refuse();
        }
        $stored = $case ? DB::table(self::OBSERVATIONS)->where('case_id', $case->id)->orderBy('inbox_id')->lockForUpdate()->get()->all() : [];
        if ($case) {
            $this->validateCase($case, $stored);
        }
        // Full rescan, not an id cursor: a lower-ID late commit must not be silently skipped.
        // Scan cap is over all account/configuration-bound signed inbox rows, not just matching rows. Saturation refuses ALL writes/acks.
        $rows = DB::table(StripeDepositFollowupInbox::TABLE)->useWritePdo()
            ->where('account_scope', app(DepositProviderAccountService::class)->stored($deposit))
            ->where('configuration_hash', app(DepositCheckoutService::class)->stripeConfigurationFingerprint())
            ->orderBy('id')->limit(self::SCAN_LIMIT + 1)->lockForUpdate()->get();
        if ($rows->count() > self::SCAN_LIMIT) {
            $this->refuse('stripe_review_scan_incomplete', 'Signed follow-up history exceeds this bounded review scan. No partial review or acknowledgement is accepted.');
        }
        $sources = [];
        foreach ($rows as $row) {
            $source = app(StripeDepositFollowupInbox::class)->read($row);
            if ($this->isFollowup($source, $capture['references'], $deposit)) {
                $sources[(int) $row->id] = ['source' => $source, 'hash' => hash('sha256', $this->encode($source))];
            }
        }
        foreach ($stored as $observation) {
            if (! isset($sources[$observation->inbox_id]) || ! hash_equals($observation->source_hash, $sources[$observation->inbox_id]['hash'])) {
                $this->refuse(); // Deleted/reclassified/changed source evidence is not silently replaced.
            }
            unset($sources[$observation->inbox_id]);
        }
        if (count($stored) + count($sources) > self::CASE_LIMIT) {
            $this->refuse('stripe_review_case_incomplete', 'Case exceeds the supported100 observations. No partial review or acknowledgement is accepted.');
        }
        if ($sources === []) {
            return $case;
        }
        if (! $case) {
            $id = DB::table(self::CASES)->insertGetId(['uuid' => (string) Str::uuid(), 'deposit_id' => $deposit->id,
                'payment_id' => $payment->id, 'settlement_receipt_uuid' => $receipt, 'version' => 0,
                'observation_count' => 0, 'evidence_hash' => '', 'state' => 'open', 'created_at' => now(), 'updated_at' => now()]);
            $case = DB::table(self::CASES)->where('id', $id)->sole();
        }
        $version = (int) $case->version + 1;
        foreach ($sources as $webhookId => $item) {
            $bytes = $this->encode(['case_uuid' => $case->uuid, 'deposit_id' => (int) $deposit->id,
                'payment_id' => (int) $payment->id, 'receipt_uuid' => $receipt, 'version' => $version,
                'actor_id' => (int) $actor->id, 'source' => $item['source']]);
            DB::table(self::OBSERVATIONS)->insert(['case_id' => $case->id, 'inbox_id' => $webhookId,
                'actor_id' => $actor->id, 'case_version' => $version, 'source_hash' => $item['hash'],
                'response_cipher' => Crypt::encryptString($bytes), 'response_hash' => hash('sha256', $bytes), 'created_at' => now()]);
        }
        $all = DB::table(self::OBSERVATIONS)->where('case_id', $case->id)->orderBy('inbox_id')->get()->all();
        DB::table(self::CASES)->where('id', $case->id)->update(['version' => $version, 'observation_count' => count($all),
            'evidence_hash' => $this->evidenceHash($case, $all), 'state' => 'open', 'updated_at' => now()]);

        return DB::table(self::CASES)->where('id', $case->id)->sole();
    }

    private function isFollowup(array $source, array $references, Deposit $deposit): bool
    {
        $payload = $source['payload'];
        if (! is_array($payload)) {
            return false;
        }
        $facts = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;
        $keys = ['reference', 'gateway_reference', 'payment_reference', 'id', 'payment_intent', 'charge'];
        $named = array_filter(array_map(fn ($key) => is_string($facts[$key] ?? null) ? $facts[$key] : null, $keys));
        if (array_intersect($references, $named) === []) {
            return false;
        }
        [$session, $intent, $charge] = $references;
        foreach (['payment_intent' => $intent, 'charge' => $charge] as $key => $expected) {
            if (isset($facts[$key]) && $facts[$key] !== $expected) {
                $this->refuse();
            }
        }
        foreach (['reference', 'gateway_reference', 'payment_reference'] as $key) {
            if (isset($facts[$key]) && ! in_array($facts[$key], $references, true)) {
                $this->refuse();
            }
        }
        $expectedId = match ($facts['object'] ?? null) {
            'checkout.session' => $session, 'payment_intent' => $intent, 'charge' => $charge, default => null,
        };
        if (($expectedId !== null && ($facts['id'] ?? null) !== $expectedId)
            || (isset($facts['currency']) && $facts['currency'] !== strtolower($deposit->currency->value))
            || (isset($facts['livemode']) && $facts['livemode'] !== str_contains((string) config('payment.gateways.stripe.secret'), '_live_'))
            || (isset($facts['amount_refunded']) && (! is_int($facts['amount_refunded']) || $facts['amount_refunded'] < 0))
            || ! is_string($source['event_type']) || preg_match('/^[a-z0-9_.:-]{1,100}$/iD', $source['event_type']) !== 1
            || (isset($payload['type']) && $payload['type'] !== $source['event_type'])) {
            $this->refuse();
        }

        return in_array($facts['object'] ?? null, ['refund', 'dispute'], true)
            || preg_match('/refund|disput/i', $source['event_type']) === 1
            || ($facts['refunded'] ?? false) === true || ($facts['disputed'] ?? false) === true
            || (is_int($facts['amount_refunded'] ?? null) && $facts['amount_refunded'] > 0);
    }

    private function validateCase(object $case, array $observations): void
    {
        if ($observations === [] || (int) $case->version < 1 || (int) $case->version > count($observations)
            || count($observations) > self::CASE_LIMIT || count($observations) !== (int) $case->observation_count
            || ! hash_equals($case->evidence_hash, $this->evidenceHash($case, $observations))) {
            $this->refuse();
        }
        $versions = [];
        foreach ($observations as $o) {
            $b = $this->decode($o->response_cipher);
            if (! hash_equals($o->response_hash, hash('sha256', $this->encode($b)))
                || ($b['case_uuid'] ?? null) !== $case->uuid || ($b['deposit_id'] ?? null) !== (int) $case->deposit_id
                || ($b['payment_id'] ?? null) !== (int) $case->payment_id || ($b['receipt_uuid'] ?? null) !== $case->settlement_receipt_uuid
                || ($b['version'] ?? null) !== (int) $o->case_version || ($b['actor_id'] ?? null) !== (int) $o->actor_id
                || data_get($b, 'source.inbox_id') !== (int) $o->inbox_id
                || ! hash_equals($o->source_hash, hash('sha256', $this->encode($b['source'] ?? [])))) {
                $this->refuse();
            }
            $versions[(int) $o->case_version] = true;
        }
        $keys = array_keys($versions);
        sort($keys);
        if ($keys !== range(1, (int) $case->version)) {
            $this->refuse();
        }
        $acks = DB::table(self::ACKNOWLEDGEMENTS)->where('case_id', $case->id)->orderBy('case_version')->get();
        foreach ($acks as $ack) {
            $this->validateAck($case, $ack);
        }
        $hasCurrent = $acks->contains(fn ($ack) => (int) $ack->case_version === (int) $case->version);
        if ($case->state !== ($hasCurrent ? 'acknowledged' : 'open')) {
            $this->refuse();
        }
    }

    private function validateAck(object $case, object $ack): void
    {
        $b = $this->decode($ack->note_cipher);
        $observations = DB::table(self::OBSERVATIONS)->where('case_id', $case->id)->where('case_version', '<=', $ack->case_version)->orderBy('inbox_id')->get()->all();
        if ((int) $ack->case_version < 1 || (int) $ack->case_version > (int) $case->version
            || ($b['case_uuid'] ?? null) !== $case->uuid || ($b['version'] ?? null) !== (int) $ack->case_version
            || ($b['actor_id'] ?? null) !== (int) $ack->actor_id || ($b['evidence_hash'] ?? null) !== $ack->evidence_hash
            || ! is_string($b['note'] ?? null) || ! hash_equals($ack->note_hash, hash('sha256', $b['note']))
            || ! hash_equals($ack->evidence_hash, $this->evidenceHash($case, $observations))) {
            $this->refuse();
        }
    }

    private function evidenceHash(object $case, array $observations): string
    {
        return hash('sha256', $this->encode([$case->uuid, (int) $case->deposit_id, (int) $case->payment_id,
            $case->settlement_receipt_uuid, array_map(fn ($o) => [(int) $o->inbox_id, $o->source_hash], $observations)]));
    }

    private function summary(object $case): array
    {
        return ['case_uuid' => $case->uuid, 'version' => (int) $case->version, 'evidence_hash' => $case->evidence_hash,
            'state' => $case->state, 'observation_count' => (int) $case->observation_count];
    }

    private function encode(array $value): string
    {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($bytes) > 1048576) {
            $this->refuse();
        }

        return $bytes;
    }

    private function decode(string $cipher): array
    {
        try {
            $value = json_decode(Crypt::decryptString($cipher), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value)) {
                $this->refuse();
            }

            return $value;
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function authorize(?User $actor): User
    {
        $staff = app(DepositReviewAccess::class)->authorize($actor);
        if (! config('payment.deposit_review.stripe.enabled', false)) {
            $this->refuse('stripe_review_disabled', 'Stripe deposit follow-up review is disabled on this server.');
        }

        return $staff;
    }

    private function refuse(string $code = 'stripe_review_unproven', string $message = 'Original capture, review custody or linked signed follow-up source evidence cannot be proven.'): never
    {
        throw FinancialException::withCode($code, $message);
    }
}
