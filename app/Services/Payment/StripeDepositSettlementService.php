<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\DepositStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositIntegrityGuard;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Staff-authorized captured-funding recovery. Authenticated GET evidence, not a fabricated webhook. */
final class StripeDepositSettlementService
{
    public const TABLE = 'stripe_deposit_settlement_receipts';

    public const MARKER = 'stripe_deposit_settlement_receipt';

    public function reconcile(Deposit $route, ?User $actor): array
    {
        app(DepositCheckoutService::class)->assertTopLevel();
        $this->authorize($actor);
        $snapshot = DB::transaction(function () use ($route, $actor): array {
            $context = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
            $this->authorize($actor);
            [$row, , $payment, $a] = $context;
            $receipt = $this->receipt($row, $payment, $a);
            if ($row->status === DepositStatus::Confirmed) {
                app(DepositCompletionService::class)->verifySettled($row);

                return ['context' => $context, 'done' => true];
            }
            $this->eligible($row, $payment);

            return ['context' => $context, 'receipt' => $receipt, 'done' => false];
        });
        [$row, , , $a, $reference] = $snapshot['context'];
        if ($snapshot['done']) {
            return ['deposit' => $row, 'credited' => false, 'replayed' => true];
        }
        $receipt = $snapshot['receipt'];
        if (! $receipt || now()->getTimestamp() - (int) $receipt->observed_epoch > 300) {
            // No framework or PDO transaction spans any provider request. No HTTP retry.
            $proof = app(StripeDepositCaptureLookup::class)->read($row, $a, $reference);
            $observed = now()->getTimestamp();
            $receipt = DB::transaction(function () use ($row, $actor, $a, $proof, $observed): object {
                [$current, , $payment, $attempt, $session] = app(DepositCheckoutService::class)->lockedStripeSettlement($row);
                $staff = $this->authorize($actor);
                if (! hash_equals($this->binding($row, $a), $this->binding($current, $attempt))) {
                    $this->refuse();
                }
                app(StripeDepositCaptureLookup::class)->validate($proof, $current, $attempt, $session, $observed);
                $existing = $this->receipt($current, $payment, $attempt);
                $intent = $proof['intent']['id'];
                $charge = $proof['charge']['id'];
                $this->own($attempt, $intent, $charge);
                if ($existing && ($existing->intent_reference !== $intent || $existing->charge_reference !== $charge)) {
                    $this->refuse();
                }
                if ($existing && (now()->getTimestamp() - (int) $existing->observed_epoch <= 300 || $existing->applied_at !== null)) {
                    return $existing; // Concurrent custody wins; never overwrite its bytes.
                }
                $this->eligible($current, $payment, allowConfirmed: true);
                $uuid = (string) Str::uuid();
                $binding = $this->binding($current, $attempt);
                $bytes = json_encode(['binding' => $binding, 'actor_id' => (int) $staff->id,
                    'observed_epoch' => $observed, 'proof' => $proof], JSON_THROW_ON_ERROR);
                if (strlen($bytes) > 1048576) {
                    $this->refuse();
                }
                DB::table(self::TABLE)->insert(['uuid' => $uuid, 'attempt_id' => $attempt->id, 'actor_id' => $staff->id,
                    'binding_hash' => $binding, 'session_reference' => $session, 'intent_reference' => $intent,
                    'charge_reference' => $charge, 'response_cipher' => Crypt::encryptString($bytes),
                    'response_hash' => hash('sha256', $bytes), 'observed_epoch' => $observed,
                    'applied_at' => null, 'created_at' => now()]);
                DB::table(DepositCheckoutService::TABLE)->where('id', $attempt->id)->update(['settlement_receipt_uuid' => $uuid]);

                return DB::table(self::TABLE)->where('uuid', $uuid)->sole();
            }, 3); // SQL only. Re-entering this closure cannot repeat any GET or POST.
        }

        return $this->apply($route, $actor, $receipt->uuid);
    }

    private function apply(Deposit $route, ?User $actor, string $uuid): array
    {
        return DB::transaction(function () use ($route, $actor, $uuid): array {
            [$row, , $payment, $a] = app(DepositCheckoutService::class)->lockedStripeSettlement($route);
            $staff = $this->authorize($actor);
            $receipt = $this->receipt($row, $payment, $a);
            if (! $receipt || $receipt->uuid !== $uuid) {
                $this->refuse();
            }
            $replayed = $row->status === DepositStatus::Confirmed;
            if ($replayed) {
                app(DepositCompletionService::class)->verifySettled($row);
            } else {
                if (now()->getTimestamp() - (int) $receipt->observed_epoch > 300 || $receipt->applied_at !== null) {
                    $this->refuse();
                }
                $this->eligible($row, $payment);
                if ($row->status === DepositStatus::Pending) {
                    app(DepositApprovalService::class)->approve($row, (int) $staff->id, 'Verified original Stripe captured-funding receipt.');
                    $row->refresh();
                }
                $payment->status = PaymentStatus::Captured;
                $payment->captured_at = now();
                $payment->save();
                app(DepositCompletionService::class)->complete($row, options: ['provider' => 'stripe',
                    'provider_reference' => $receipt->session_reference, 'actor_user_id' => (int) $staff->id,
                    'metadata' => ['stripe_settlement_receipt' => $uuid]]);
            }
            // Same transaction as canonical credit: no marker or applied label survives a failed admission/journal write.
            $metadata = $payment->metadata ?? [];
            if (isset($metadata[self::MARKER]) && $metadata[self::MARKER] !== $uuid) {
                $this->refuse();
            }
            $metadata[self::MARKER] = $uuid;
            $payment->metadata = $metadata;
            $payment->save();
            DB::table(self::TABLE)->where('id', $receipt->id)->whereNull('applied_at')->update(['applied_at' => now()]);

            return ['deposit' => $row->fresh(), 'credited' => ! $replayed, 'replayed' => $replayed, 'receipt_uuid' => $uuid];
        }, 3);
    }

    /** Reporting only: no wallet lock acquisition under the reconciliation service's Payment lock. */
    public function observedCapture(Payment $payment): ?array
    {
        $marker = data_get($payment->metadata, self::MARKER);
        if ($marker === null) {
            if (DB::table(self::TABLE)->join('deposit_checkout_attempts as a', 'a.id', '=', self::TABLE.'.attempt_id')
                ->where('a.payment_id', $payment->id)->whereNotNull(self::TABLE.'.applied_at')->exists()) {
                $this->refuse();
            }

            return null;
        }
        $row = $payment->payable_type === Deposit::class ? Deposit::query()->find($payment->payable_id) : null;
        $a = $row ? DB::table(DepositCheckoutService::TABLE)->where('deposit_id', $row->id)->first() : null;
        if (! $row || ! $a || $a->provider !== 'stripe' || $a->state !== 'projected' || (int) $a->attempt_count !== 1
            || (int) $a->payment_id !== (int) $payment->id || data_get($payment->metadata, DepositCheckoutService::MARKER) !== $a->uuid
            || $payment->gateway !== 'stripe' || $payment->method !== $row->method || (int) $payment->user_id !== (int) $row->user_id
            || $payment->currency !== $row->currency || $payment->gateway_reference !== $row->provider_reference
            || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0
            || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0
            || ! hash_equals($a->terms_hash, app(DepositIntegrityGuard::class)->termsFingerprint($row))
            || ! hash_equals($a->configuration_hash, app(DepositCheckoutService::class)->stripeConfigurationFingerprint())
            || $a->account_scope !== app(DepositProviderAccountService::class)->assertActive($row)) {
            $this->refuse();
        }
        $receipt = $this->receipt($row, $payment, $a);
        if (! $receipt || $receipt->uuid !== $marker || $receipt->applied_at === null) {
            $this->refuse();
        }

        return ['amount' => (string) $row->amount, 'references' => [$receipt->session_reference, $receipt->intent_reference, $receipt->charge_reference]];
    }

    private function receipt(Deposit $row, Payment $payment, object $a): ?object
    {
        $r = DB::table(self::TABLE)->where('uuid', $a->settlement_receipt_uuid)->first();
        if ($a->settlement_receipt_uuid === null) {
            if (DB::table(self::TABLE)->where('attempt_id', $a->id)->exists() || data_get($payment->metadata, self::MARKER) !== null) {
                $this->refuse();
            }

            return null;
        }
        try {
            if (! $r || (int) $r->attempt_id !== (int) $a->id || $r->binding_hash !== $this->binding($row, $a)
                || $r->session_reference !== $payment->gateway_reference || $r->session_reference !== $row->provider_reference) {
                $this->refuse();
            }
            $bytes = Crypt::decryptString($r->response_cipher);
            if (! hash_equals($r->response_hash, hash('sha256', $bytes))) {
                $this->refuse();
            }
            $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (($data['binding'] ?? null) !== $r->binding_hash || ($data['actor_id'] ?? null) !== (int) $r->actor_id
                || ($data['observed_epoch'] ?? null) !== (int) $r->observed_epoch || (int) $r->observed_epoch > now()->getTimestamp() + 5
                || data_get($data, 'proof.intent.id') !== $r->intent_reference || data_get($data, 'proof.charge.id') !== $r->charge_reference) {
                $this->refuse();
            }
            app(StripeDepositCaptureLookup::class)->validate($data['proof'], $row, $a, $r->session_reference, (int) $r->observed_epoch);
            $owner = DB::table('stripe_deposit_capture_owners')->where('attempt_id', $a->id)->first();
            if (! $owner || $owner->intent_key !== $this->ownerKey($a, $r->intent_reference)
                || $owner->charge_key !== $this->ownerKey($a, $r->charge_reference)) {
                $this->refuse();
            }
            $marker = data_get($payment->metadata, self::MARKER);
            if (($r->applied_at !== null && $marker !== $r->uuid) || ($marker !== null && $marker !== $r->uuid)) {
                $this->refuse();
            }

            return $r;
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    private function own(object $a, string $intent, string $charge): void
    {
        $ik = $this->ownerKey($a, $intent);
        $ck = $this->ownerKey($a, $charge);
        $owners = DB::table('stripe_deposit_capture_owners')->where('attempt_id', $a->id)
            ->orWhere('intent_key', $ik)->orWhere('charge_key', $ck)->lockForUpdate()->get();
        if ($owners->isEmpty()) {
            DB::table('stripe_deposit_capture_owners')->insert(['attempt_id' => $a->id, 'intent_key' => $ik, 'charge_key' => $ck, 'created_at' => now()]);
        } elseif ($owners->count() !== 1 || (int) $owners->sole()->attempt_id !== (int) $a->id
            || $owners->sole()->intent_key !== $ik || $owners->sole()->charge_key !== $ck) {
            $this->refuse();
        }
    }

    private function ownerKey(object $a, string $id): string
    {
        return hash('sha256', json_encode(['stripe-captured-funding', $a->account_scope, $id], JSON_THROW_ON_ERROR));
    }

    private function binding(Deposit $row, object $a): string
    {
        return hash('sha256', json_encode([$a->uuid, (int) $a->payment_id, $a->fence, $a->terms_hash,
            $a->request_hash, $a->configuration_hash, $a->account_scope, $a->dispatch_key, $a->response_hash,
            app(DepositIntegrityGuard::class)->termsFingerprint($row)], JSON_THROW_ON_ERROR));
    }

    private function eligible(Deposit $row, Payment $payment, bool $allowConfirmed = false): void
    {
        if ($allowConfirmed && $row->status === DepositStatus::Confirmed) {
            app(DepositCompletionService::class)->verifySettled($row);

            return;
        }
        if (! in_array($row->status, [DepositStatus::Pending, DepositStatus::Approved], true)
            || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Authorized], true)
            || $row->financial_transaction_id !== null) {
            $this->refuse();
        }
    }

    private function authorize(?User $actor): User
    {
        $staff = app(DepositSettlementAccess::class)->authorize($actor);
        if (! config('payment.deposit_settlement.stripe.enabled', false)) {
            $this->refuse();
        }

        return $staff;
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('stripe_settlement_unproven', 'Original Stripe capture custody, identity, freshness or replay evidence cannot be proven.');
    }
}
