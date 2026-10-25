<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\AuditAction;
use App\Enums\PaymentStatus;
use App\Enums\RiskLevel;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalReconciliationException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\WithdrawalReconciliation;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Evidence-backed human reconciliation. Never invokes a gateway or resets a claim. */
final class WithdrawalReconciliationService
{
    public function __construct(
        private readonly WithdrawalReconciliationAccess $access,
        private readonly WithdrawalReconciliationEvidenceStore $evidence,
        private readonly WithdrawalDisbursementService $dispatch,
        private readonly WalletHoldService $holds,
        private readonly WithdrawalCompletionService $completion,
        private readonly WithdrawalApprovalService $approval,
    ) {}

    public function request(User $actor, int $withdrawalId, WithdrawalReconciliationFacts $facts, UploadedFile $file): WithdrawalReconciliation
    {
        $this->access->authorize($actor, WithdrawalReconciliationAccess::REQUEST);
        // Storage cannot participate in SQL rollback. An unreferenced encrypted blob
        // may remain after refusal; never delete a blob another committed case uses.
        $artifact = $this->evidence->store($file);

        return DB::transaction(function () use ($actor, $withdrawalId, $facts, $artifact): WithdrawalReconciliation {
            $maker = $this->access->authorize($actor, WithdrawalReconciliationAccess::REQUEST);
            [$row, $wallet, $payment] = $this->locked($withdrawalId);
            $this->assertNotBeneficiary($row, $maker);
            $this->assertOpen($row, $wallet, $payment, $facts);
            $snapshot = $this->snapshot($row, $payment);
            $key = hash('sha256', json_encode([$snapshot, $facts->values, $artifact], JSON_THROW_ON_ERROR));
            $existing = WithdrawalReconciliation::query()->where('request_key', $key)->first();
            if ($existing) {
                return $existing;
            }
            $case = new WithdrawalReconciliation;
            $case->forceFill(['withdrawal_id' => $row->id, 'payment_id' => $payment->id, 'maker_id' => $maker->id,
                'request_key' => $key, 'snapshot_hash' => $snapshot, 'facts' => $facts->values,
                'evidence_sha256' => $artifact['sha256'], 'evidence_bytes' => $artifact['bytes'], 'status' => 'pending'])->save();
            $this->audit($case, $maker, 'Provider evidence submitted for independent reconciliation review.');

            return $case;
        }, 3);
    }

    /** Sensitive, pending-case-only target inspection for the independent checker. No financial mutation. */
    public function destinationForReview(User $actor, WithdrawalReconciliation $candidate): array
    {
        $this->access->authorize($actor, WithdrawalReconciliationAccess::REVIEW);

        return DB::transaction(function () use ($actor, $candidate): array {
            $checker = $this->access->authorize($actor, WithdrawalReconciliationAccess::REVIEW);
            $route = WithdrawalReconciliation::query()->findOrFail($candidate->getKey());
            [$row, $wallet, $payment] = $this->locked((int) $route->withdrawal_id);
            $case = WithdrawalReconciliation::query()->lockForUpdate()->findOrFail($route->id);
            $this->assertNotBeneficiary($row, $checker);
            if ((int) $checker->id === (int) $case->maker_id || $case->status !== 'pending') {
                throw new WithdrawalReconciliationException('independent_pending_review_required');
            }
            $this->assertOpen($row, $wallet, $payment, WithdrawalReconciliationFacts::fromArray($case->facts));
            if (! hash_equals($case->snapshot_hash, $this->snapshot($row, $payment))) {
                throw new WithdrawalReconciliationException('evidence_snapshot_changed');
            }
            $details = $this->destinationDetails($row);
            $this->audit($case, $checker, 'Independent checker inspected the original private payout destination.');

            return ['withdrawal_id' => (int) $row->id, 'payout_details' => $details];
        }, 3);
    }

    public function decide(User $actor, WithdrawalReconciliation $candidate, string $decision, string $note, bool $evidenceVerified): WithdrawalReconciliation
    {
        $this->access->authorize($actor, WithdrawalReconciliationAccess::REVIEW);
        if (! in_array($decision, ['apply', 'reject'], true) || mb_strlen(trim($note)) < 15 || mb_strlen($note) > 1000
            || ($decision === 'apply' && ! $evidenceVerified)) {
            throw new WithdrawalReconciliationException('independent_evidence_verification_required');
        }

        return DB::transaction(function () use ($actor, $candidate, $decision, $note): WithdrawalReconciliation {
            $checker = $this->access->authorize($actor, WithdrawalReconciliationAccess::REVIEW);
            // Re-read routing identity; never trust caller-mutated model attributes.
            $route = WithdrawalReconciliation::query()->findOrFail($candidate->getKey());
            [$row, $wallet, $payment] = $this->locked((int) $route->withdrawal_id);
            $case = WithdrawalReconciliation::query()->lockForUpdate()->findOrFail($route->id);
            $this->assertNotBeneficiary($row, $checker);
            if ((int) $case->maker_id === (int) $checker->id) {
                throw new WithdrawalReconciliationException('maker_cannot_check');
            }
            if ($case->status !== 'pending') {
                if ((int) $case->checker_id === (int) $checker->id && $case->status === ($decision === 'apply' ? 'applied' : 'rejected')) {
                    return $case; // No financial mutation or rewritten decision audit on replay.
                }
                throw new WithdrawalReconciliationException('decision_already_final');
            }
            if ($decision === 'apply') {
                $maker = User::query()->find($case->maker_id);
                if (! $maker) {
                    throw new WithdrawalReconciliationException('maker_authority_no_longer_valid');
                }
                $this->access->authorize($maker, WithdrawalReconciliationAccess::REQUEST);
                $this->assertNotBeneficiary($row, $maker);
                $facts = WithdrawalReconciliationFacts::fromArray($case->facts);
                $this->assertOpen($row, $wallet, $payment, $facts);
                if ((int) $case->payment_id !== (int) $payment->id || ! hash_equals($case->snapshot_hash, $this->snapshot($row, $payment))) {
                    throw new WithdrawalReconciliationException('evidence_snapshot_changed');
                }
                // Re-decrypt and hash the actual artifact at the irreversible boundary.
                $this->evidence->read($case->evidence_sha256, (int) $case->evidence_bytes);
                $this->bindProviderReference($row, $payment, $case, $facts);
                $providerReference = $facts->values['provider_reference'];
                $payment->gateway_reference = $providerReference;
                $row->provider_reference = $providerReference;
                $row->provider = $facts->values['provider'];
                $row->save();
                if ($facts->completed()) {
                    $payment->status = PaymentStatus::Captured;
                    $payment->captured_at = now();
                    $payment->save();
                    $this->completion->complete($row, null, ['provider' => $row->provider, 'provider_reference' => $providerReference,
                        'metadata' => ['withdrawal_reconciliation_id' => (int) $case->id]]);
                } else {
                    $payment->status = PaymentStatus::Failed;
                    $payment->failed_at = now();
                    $payment->failure_reason = 'Independent provider-evidence review confirmed definitive non-acceptance.';
                    $payment->save();
                    $this->approval->markFailed($row, $payment->failure_reason, verifiedFinalFailure: true);
                }
                $row->refresh();
                $metadata = $row->metadata;
                $metadata[WithdrawalDisbursementService::CLAIM]['state'] = $facts->completed() ? 'completed' : 'failed';
                $metadata[WithdrawalDisbursementService::CLAIM]['provider_reference'] = $providerReference;
                $metadata['outbound_reconciliation_id'] = (int) $case->id;
                $row->metadata = $metadata;
                $row->save();
            }
            $case->forceFill(['status' => $decision === 'apply' ? 'applied' : 'rejected', 'checker_id' => $checker->id,
                'decision_note' => trim($note), 'decided_at' => now()])->save();
            $this->audit($case, $checker, $decision === 'apply'
                ? 'Independent provider evidence applied through canonical withdrawal finance services.'
                : 'Provider evidence case rejected; withdrawal and reservation unchanged.');

            return $case;
        }, 3);
    }

    private function locked(int $id): array
    {
        $route = Withdrawal::query()->findOrFail($id);
        $wallet = Wallet::query()->lockForUpdate()->findOrFail($route->wallet_id);
        $row = Withdrawal::query()->lockForUpdate()->findOrFail($id);
        $payments = Payment::query()->where('payable_type', Withdrawal::class)->where('payable_id', $row->id)->lockForUpdate()->limit(2)->get();
        if ($payments->count() !== 1) {
            throw new WithdrawalReconciliationException('one_bound_payment_required');
        }

        return [$row, $wallet, $payments->sole()];
    }

    private function assertOpen(Withdrawal $row, Wallet $wallet, Payment $payment, WithdrawalReconciliationFacts $facts): void
    {
        $claim = $this->dispatch->inspectReconciliationClaim($row, $wallet, $payment);
        $this->destinationDetails($row);

        if ($this->gatewayKey($row) === 'thai_bank') {
            $this->assertThaiBankAttemptAgrees($row, $facts);
        }
        $v = $facts->values;
        if ($row->status !== WithdrawalStatus::Processing || $row->financial_transaction_id !== null || $payment->status !== PaymentStatus::Pending
            || ! in_array($claim['state'], ['sending', 'unknown', 'pending', 'legacy_unknown', 'reconciliation_required'], true)
            || $v['internal_reference'] !== $row->reference_number || $v['provider'] !== $this->gatewayKey($row)
            || $v['currency'] !== $row->currency->value || $v['gross_amount'] !== (string) $row->amount
            || $v['net_amount'] !== (string) $row->net_amount
            || ($row->provider_reference && $row->provider_reference !== $v['provider_reference'])
            || ($payment->gateway_reference && $payment->gateway_reference !== $v['provider_reference'])
            || ! $this->holds->hasHoldOfAtLeast($wallet, Money::of((string) $row->amount, $row->currency))) {
            throw new WithdrawalReconciliationException('evidence_obligation_conflict');
        }
        try {
            if (! is_string($claim['started_at'] ?? null) || CarbonImmutable::parse($v['observed_at'])->isBefore(CarbonImmutable::parse($claim['started_at']))) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new WithdrawalReconciliationException('evidence_predates_dispatch_claim');
        }
    }

    private function destinationDetails(Withdrawal $row): array
    {
        $allowed = ['bank_name', 'bank_code', 'branch', 'branch_code', 'account_name', 'account_number',
            'beneficiary_name', 'beneficiary_address', 'bank_address', 'iban', 'swift', 'bic', 'routing_number',
            'phone', 'mobile', 'wallet_number', 'address', 'crypto_address', 'network', 'country', 'currency',
            'stripe_account_id', 'recipient_id', 'sort_code', 'account_type', 'method'];
        $identifiers = match ($this->gatewayKey($row)) {
            'bank_transfer' => ['account_number', 'iban'],
            'bkash', 'nagad' => ['phone', 'mobile', 'wallet_number', 'account_number'],
            'crypto' => ['address', 'crypto_address', 'account_number'],
            'stripe' => ['stripe_account_id', 'recipient_id'],
            default => [],
        };
        $details = $row->payout_details;
        if (! is_array($details) || $details === [] || array_diff(array_keys($details), $allowed)
            || array_any($details, fn ($value) => ! is_string($value) || trim($value) === '' || strlen($value) > 500)
            || ! array_any($identifiers, fn ($key) => isset($details[$key]))
            || (isset($details['method']) && $this->gateway($details['method']) !== $this->gatewayKey($row))) {
            throw new WithdrawalReconciliationException('payout_schema_requires_private_adapter_review');
        }

        return $details;
    }

    /** Unique SQL binding serializes reuse across different wallets within this lane. */
    private function bindProviderReference(Withdrawal $row, Payment $payment, WithdrawalReconciliation $case, WithdrawalReconciliationFacts $facts): void
    {
        $provider = $facts->values['provider'];
        $reference = $facts->values['provider_reference'];
        try {
            app(WithdrawalProviderReferenceService::class)->bind($row, $provider, $reference, $payment);
        } catch (FinancialException $exception) {
            if ($exception->errorCode() === 'withdrawal_provider_reference_conflict') {
                throw new WithdrawalReconciliationException('provider_reference_already_bound');
            }
            throw $exception;
        }
        // Preserve the Part28 legacy index for older reports/rollback compatibility.
        // New merchant-account scopes use only the shared scoped ownership table.
        if (app(WithdrawalProviderAccountService::class)->stored($row) === WithdrawalProviderAccountService::LEGACY) {
            DB::table('withdrawal_reconciliation_references')->insertOrIgnore([
                'provider_key' => hash('sha256', json_encode([$provider, $reference], JSON_THROW_ON_ERROR)),
                'withdrawal_id' => $row->id, 'payment_id' => $payment->id, 'reconciliation_id' => $case->id, 'created_at' => now(),
            ]);
        }
    }

    private function snapshot(Withdrawal $row, Payment $payment): string
    {
        return hash('sha256', json_encode([$row->id, $row->metadata[WithdrawalDisbursementService::CLAIM],
            $row->provider, $row->provider_reference, $payment->id, $payment->gateway_reference, $payment->metadata], JSON_THROW_ON_ERROR));
    }

    private function assertNotBeneficiary(Withdrawal $row, User $actor): void
    {
        if ((int) $row->user_id === (int) $actor->id) {
            throw new WithdrawalReconciliationException('beneficiary_cannot_reconcile_own_withdrawal');
        }
    }

    private function gateway(string $name): string
    {
        return $name === 'manual' ? 'bank_transfer' : $name;
    }

    /**
     * The gateway this withdrawal was ACTUALLY routed through.
     *
     * The disbursement persists the resolved key in the withdrawal's metadata
     * before its single external call, so reconciliation reads that key: a
     * withdrawal routed to the automated Thai bank rail is reconciled against
     * the Thai bank evidence, not against the operator lane the player
     * originally chose. Rows written before the key existed fall back to the
     * provider column and then to the method value, which is exactly the lane
     * they were paid through.
     *
     * A key this build does not know is NOT silently downgraded to the method:
     * the comparison below then fails closed, and the case stays with the desk.
     */
    private function gatewayKey(Withdrawal $row): string
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $routed = $metadata['gateway_key'] ?? null;

        if (is_string($routed) && trim($routed) !== '') {
            return $this->gateway(strtolower(trim($routed)));
        }

        $persisted = is_string($row->provider) ? trim($row->provider) : '';

        return $this->gateway($persisted !== '' ? strtolower($persisted) : $row->method->value);
    }

    /**
     * The Thai bank rail reconciles through its own durable attempt, never by
     * asking the provider to send the money again.
     *
     * A thai_bank row has exactly one ThaiBankPayoutAttempt per withdrawal
     * reference, created before the transfer was transmitted, and that attempt
     * carries the provider's own reference. Evidence that names a different
     * reference, or an outcome the attempt cannot carry, conflicts with the
     * rail's own record and is refused by name: the desk reads the attempt, it
     * does not re-dispatch.
     */
    private function assertThaiBankAttemptAgrees(Withdrawal $row, WithdrawalReconciliationFacts $facts): void
    {
        $reference = (string) $row->reference_number;

        $attempt = ThaiBankPayoutAttempt::query()
            ->where('withdrawal_reference', $reference)
            ->first();

        if (! $attempt instanceof ThaiBankPayoutAttempt) {
            throw new WithdrawalReconciliationException('thai_bank_attempt_missing');
        }

        if ((int) $attempt->withdrawal_id !== (int) $row->getKey()) {
            throw new WithdrawalReconciliationException('thai_bank_attempt_conflict');
        }

        $attemptReference = is_string($attempt->provider_reference) ? trim($attempt->provider_reference) : '';

        if ($attemptReference !== '' && ! hash_equals($attemptReference, $facts->values['provider_reference'])) {
            throw new WithdrawalReconciliationException('thai_bank_attempt_reference_conflict');
        }

        if ($facts->completed() && $attempt->state !== ThaiBankPayoutState::Settled) {
            throw new WithdrawalReconciliationException('thai_bank_attempt_not_settled');
        }

        if (! $facts->completed() && $attempt->state === ThaiBankPayoutState::Settled) {
            throw new WithdrawalReconciliationException('thai_bank_attempt_settled');
        }
    }

    private function audit(WithdrawalReconciliation $case, User $actor, string $description): void
    {
        $log = new AuditLog;
        $log->fill(['user_id' => $actor->id, 'action' => AuditAction::Withdraw, 'risk_level' => RiskLevel::Critical,
            'auditable_type' => WithdrawalReconciliation::class, 'auditable_id' => $case->id, 'description' => $description,
            'metadata' => ['withdrawal_id' => (int) $case->withdrawal_id, 'reconciliation_id' => (int) $case->id,
                'case_status' => $case->status, 'evidence_sha256' => $case->evidence_sha256]]);
        $log->save();
    }
}
