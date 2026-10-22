<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\WithdrawalException;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Payment\WithdrawalProviderAccountService;
use App\Services\Payment\WithdrawalProviderReferenceService;

/** Original local obligation and accounting proof; never provider authentication. */
final class WithdrawalIntegrityGuard
{
    public const TERMS_KEY = 'outbound_original_terms_hash';

    public function termsFingerprint(Withdrawal $row): string
    {
        $terms = [$row->reference_number, (int) $row->user_id, (int) $row->wallet_id,
            $row->currency->value, (string) $row->amount, (string) $row->fee, (string) $row->net_amount,
            $row->method->value, $row->payout_details, data_get($row->metadata, 'outbound_provider_account')];
        // Keep every old fingerprint byte-identical when there is no fresh source marker.
        if (array_key_exists(WithdrawalInitialHoldService::ORIGIN_KEY, $row->metadata ?? [])) {
            $terms[] = [WithdrawalInitialHoldService::ORIGIN_KEY => $row->metadata[WithdrawalInitialHoldService::ORIGIN_KEY]];
        }

        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR));
    }

    public function assertTerms(Withdrawal $row, Wallet $wallet): void
    {
        $hash = ($row->metadata ?? [])[self::TERMS_KEY] ?? null;
        if (($hash !== null && (! is_string($hash) || ! hash_equals($hash, $this->termsFingerprint($row))))
            || $row->trashed() || $wallet->trashed() || (int) $row->wallet_id !== (int) $wallet->id
            || (int) $row->user_id !== (int) $wallet->user_id || $row->currency !== $wallet->currency
            || bccomp((string) $row->amount, '0', 2) <= 0 || bccomp((string) $row->fee, '0', 2) < 0
            || bccomp((string) $row->net_amount, '0', 2) <= 0
            || bccomp(bcsub((string) $row->amount, (string) $row->fee, 2), (string) $row->net_amount, 2) !== 0) {
            $this->refuse();
        }
    }

    /** A Payment label is not proof, but a contradictory original Payment blocks finality. */
    public function assertPayment(Withdrawal $row): void
    {
        $payments = Payment::withTrashed()->where('payable_type', Withdrawal::class)->where('payable_id', $row->id)->limit(2)->get();
        $accounts = app(WithdrawalProviderAccountService::class);
        $provider = $accounts->canonical($row->method->value);
        if ($row->provider !== null && $accounts->canonical($row->provider) !== $provider) {
            $this->refuse();
        }
        if ($payments->count() > 1) {
            $this->refuse();
        }
        $payment = $payments->first();
        if ($payment && ($payment->trashed() || $payment->status !== PaymentStatus::Captured
            || (int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
            || $accounts->canonical($payment->method->value) !== $provider || $accounts->canonical((string) $payment->gateway) !== $provider
            || ! is_string($row->provider_reference) || $row->provider_reference === '' || $payment->gateway_reference !== $row->provider_reference
            || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0)) {
            $this->refuse();
        }
        app(WithdrawalProviderReferenceService::class)->assertBound($row, $payment);
    }

    public function assertProof(Withdrawal $row, Wallet $wallet, FinancialTransaction $tx, string $canonicalKey): void
    {
        $this->assertTerms($row, $wallet);
        $this->assertPayment($row);
        if ($tx->trashed() || $tx->type !== TransactionType::Withdrawal || $tx->status !== TransactionStatus::Completed
            || $tx->idempotency_key !== $canonicalKey || $tx->reference_type !== Withdrawal::class || (int) $tx->reference_id !== (int) $row->id
            || (int) $tx->wallet_id !== (int) $wallet->id || (int) $tx->user_id !== (int) $row->user_id || $tx->currency !== $row->currency
            || bccomp((string) $tx->amount, (string) $row->amount, 2) !== 0 || bccomp((string) $tx->fee, (string) $row->fee, 2) !== 0) {
            $this->refuse();
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
        $entries = $tx->ledgerEntries()->withTrashed()->with('ledgerAccount')->get();
        $debits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Debit);
        $credits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Credit);
        if ($entries->count() !== 2 || $debits->count() !== 1 || $credits->count() !== 1
            || $debits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $row->currency)
            || $credits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_WITHDRAWAL_CLEARING, $row->currency)
            || (int) $debits->first()->wallet_id !== (int) $wallet->id
            || $entries->contains(fn ($e) => $e->trashed() || $e->currency !== $row->currency
                || bccomp((string) $e->amount, (string) $row->amount, 2) !== 0)) {
            $this->refuse();
        }
        app(WithdrawalReservationService::class)->assertConsumed($row, $wallet, $tx);
    }

    public function refuse(): never
    {
        throw WithdrawalException::withReason('Original withdrawal terms, owned reservation or canonical debit requires controlled review; no settlement or historical repair authorized.', 'withdrawal_original_evidence_conflict');
    }
}
