<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\PaymentStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\WithdrawalProviderAccountService;
use Illuminate\Support\Facades\DB;

/** Exact original deposit terms and actual financial proof, not confirmation labels or caller-supplied transaction IDs. */
final class DepositIntegrityGuard
{
    public const TERMS_KEY = 'inbound_original_terms_hash';

    public function termsFingerprint(Deposit $row): string
    {
        return hash('sha256', json_encode([$row->reference_number, (int) $row->user_id, (int) $row->wallet_id,
            $row->currency->value, (string) $row->amount, (string) $row->fee, (string) $row->net_amount,
            $row->method->value], JSON_THROW_ON_ERROR));
    }

    public function assertTerms(Deposit $row, Wallet $wallet): void
    {
        $metadata = $row->metadata ?? [];
        if (array_key_exists(self::TERMS_KEY, $metadata)
            && (! is_string($metadata[self::TERMS_KEY]) || ! hash_equals($metadata[self::TERMS_KEY], $this->termsFingerprint($row)))) {
            $this->refuse();
        }
        $user = User::query()->useWritePdo()
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->lockForUpdate())
            ->find($row->user_id);
        if ($row->trashed() || ! $user || $wallet->trashed()
            || (int) $row->wallet_id !== (int) $wallet->id || (int) $row->user_id !== (int) $wallet->user_id
            || $row->currency !== $wallet->currency || bccomp((string) $row->amount, '0', 2) <= 0
            || bccomp((string) $row->fee, '0', 2) < 0 || bccomp((string) $row->net_amount, '0', 2) <= 0
            || bccomp(bcsub((string) $row->amount, (string) $row->fee, 2), (string) $row->net_amount, 2) !== 0) {
            $this->refuse();
        }
    }

    public function reference(Deposit $row, array $options): array
    {
        $accounts = app(WithdrawalProviderAccountService::class);
        $provider = $accounts->canonical($row->method->value);
        foreach ([$row->provider, $options['provider'] ?? null] as $value) {
            if ($value !== null && (! is_string($value) || $accounts->canonical($value) !== $provider)) {
                $this->refuse();
            }
        }
        $reference = array_key_exists('provider_reference', $options) ? $options['provider_reference'] : $row->provider_reference;
        if (! is_string($reference) || $reference === '' || trim($reference) !== $reference || strlen($reference) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $reference) || ($row->provider_reference !== null && $row->provider_reference !== $reference)) {
            $this->refuse();
        }

        return [$provider, $reference];
    }

    public function payment(Deposit $row, string $provider, string $reference, bool $lock = true): ?Payment
    {
        $query = Payment::withTrashed()->where('payable_type', Deposit::class)->where('payable_id', $row->id)->limit(2);
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() > 1) {
            $this->refuse();
        }
        $payment = $rows->first();
        $accounts = app(WithdrawalProviderAccountService::class);
        if ($payment && ($payment->trashed() || $payment->status !== PaymentStatus::Captured
            || (int) $payment->user_id !== (int) $row->user_id || $payment->currency !== $row->currency
            || $accounts->canonical($payment->method->value) !== $provider || $accounts->canonical((string) $payment->gateway) !== $provider
            || $payment->gateway_reference !== $reference || bccomp((string) $payment->amount, (string) $row->amount, 2) !== 0
            || bccomp((string) $payment->fee, (string) $row->fee, 2) !== 0)) {
            $this->refuse();
        }

        return $payment;
    }

    public function assertProof(Deposit $row, Wallet $wallet, FinancialTransaction $tx, string $canonicalKey): void
    {
        $this->assertTerms($row, $wallet);
        if ($tx->trashed() || $tx->type !== TransactionType::Deposit || $tx->status !== TransactionStatus::Completed
            || $tx->idempotency_key !== $canonicalKey || $tx->reference_type !== Deposit::class || (int) $tx->reference_id !== (int) $row->id
            || (int) $tx->wallet_id !== (int) $wallet->id || (int) $tx->user_id !== (int) $row->user_id || $tx->currency !== $row->currency
            || bccomp((string) $tx->amount, (string) $row->net_amount, 2) !== 0) {
            $this->refuse();
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
        $entries = $tx->ledgerEntries()->withTrashed()->with('ledgerAccount')->get();
        $debits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Debit);
        $credits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Credit);
        if ($entries->count() !== 2 || $debits->count() !== 1 || $credits->count() !== 1
            || $debits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_SYSTEM_CASH, $row->currency)
            || $credits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $row->currency)
            || (int) $credits->first()->wallet_id !== (int) $wallet->id
            || $entries->contains(fn ($e) => $e->trashed() || $e->currency !== $row->currency || bccomp((string) $e->amount, (string) $row->net_amount, 2) !== 0)) {
            $this->refuse();
        }
    }

    public function refuse(): never
    {
        throw FinancialException::withCode('deposit_original_evidence_conflict', 'Original deposit terms, provider ownership or canonical financial evidence requires controlled review; no credit or historical repair was authorized.');
    }
}
