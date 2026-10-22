<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\FinancialHold;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\StripeRefundRecoveryIntent;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\Withdrawal;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** Exact local accounting proof, not authentication of a bank refund or an arbitrary historical journal. */
final class ReversalIntegrityGuard
{
    public function refuse(string $code = 'reversal_accounting_conflict'): never
    {
        throw FinancialException::withCode($code, 'Reversal requires unambiguous original accounting and a matching canonical compensation; controlled review is required.');
    }

    public function assertUnmanaged(FinancialTransaction $tx): void
    {
        if (in_array($tx->reference_type, [Deposit::class, Withdrawal::class, Payout::class, WalletConversion::class, Bet::class, StripeRefundRecoveryIntent::class, FinancialHold::class], true)) {
            $this->refuse('reversal_requires_domain_workflow');
        }
        if (str_starts_with((string) $tx->idempotency_key, StripeRefundRecoveryService::PREFIX)
            || DB::table('stripe_refund_recoveries')->where('financial_transaction_id', $tx->id)->lockForUpdate()->exists()) {
            $this->refuse('reversal_requires_domain_workflow');
        }
        // A forged/removed polymorphic label must not bypass an original obligation's durable link.
        foreach ([Deposit::class, Withdrawal::class, Payout::class] as $model) {
            if ($model::withTrashed()->where('financial_transaction_id', $tx->id)->lockForUpdate()->exists()) {
                $this->refuse('reversal_requires_domain_workflow');
            }
        }
        if (DB::table('bet_purchase_ownerships')->where(fn ($q) => $q->where('original_transaction_id', $tx->id)->orWhere('refund_transaction_id', $tx->id))->lockForUpdate()->exists()) {
            $this->refuse('reversal_requires_domain_workflow');
        }
        if (DB::table('financial_hold_claims')->where('financial_transaction_id', $tx->id)->lockForUpdate()->exists()) {
            $this->refuse('reversal_requires_domain_workflow');
        }
        if (DB::table('agent_commission_payouts')->where('financial_transaction_id', $tx->id)->lockForUpdate()->exists()
            || DB::table('agent_commission_compensations')->where('compensation_transaction_id', $tx->id)->lockForUpdate()->exists()) {
            $this->refuse('reversal_requires_domain_workflow');
        }
        if (WalletConversion::query()->where('financial_transaction_id', $tx->id)->lockForUpdate()->exists()) {
            $this->refuse('reversal_requires_domain_workflow');
        }
    }

    public function entries(FinancialTransaction $tx): Collection
    {
        $query = LedgerEntry::withTrashed()->where('financial_transaction_id', $tx->id)->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate(); // Current reads even in an ambient InnoDB repeatable-read snapshot.
        }

        return $query->get();
    }

    /** A wallet operation has exactly one full-amount liability leg and its canonical counterpart. */
    public function assertOriginal(FinancialTransaction $tx, ?Wallet $wallet, Collection $entries): ?LedgerEntryType
    {
        $this->assertUnmanaged($tx);

        return $this->assertAccounting($tx, $wallet, $entries);
    }

    /** Proof only, never permission to mutate a managed obligation. */
    public function assertAccounting(FinancialTransaction $tx, ?Wallet $wallet, Collection $entries): ?LedgerEntryType
    {
        $engine = FinancialTransactionType::fromTransactionType($tx->type);
        $namedEngine = $tx->metadata['engine_type'] ?? null;
        if ($namedEngine !== null) {
            $engine = is_string($namedEngine) ? FinancialTransactionType::tryFrom($namedEngine) : null;
            if ($engine === null || $engine->toTransactionType() !== $tx->type) {
                $this->refuse();
            }
        }
        if ($tx->trashed() || ! $engine->isReversible() || ! $tx->processed_at
            || ! in_array($tx->status, [TransactionStatus::Completed, TransactionStatus::Reversed], true)
            || bccomp((string) $tx->amount, '0.00', 2) <= 0 || bccomp((string) $tx->fee, '0.00', 2) !== 0
            || ! is_string($tx->idempotency_key) || trim($tx->idempotency_key) === '' || $entries->count() < 2) {
            $this->refuse();
        }
        if (($wallet === null) !== ($tx->wallet_id === null)
            || ($wallet && ($wallet->trashed() || (int) $wallet->id !== (int) $tx->wallet_id
                || (int) $wallet->user_id !== (int) $tx->user_id || $wallet->currency !== $tx->currency))) {
            $this->refuse();
        }
        if (! $wallet && (! in_array($engine, [FinancialTransactionType::Adjustment, FinancialTransactionType::Settlement], true)
            || ($namedEngine !== null && $engine !== FinancialTransactionType::Settlement))) {
            $this->refuse(); // Dropping a wallet from a player-money header cannot turn it into a standalone journal.
        }
        $debits = '0.00';
        $credits = '0.00';
        $walletLeg = null;
        $accountQuery = LedgerAccount::withTrashed()->whereIn('id', $entries->pluck('ledger_account_id'))->orderBy('id');
        if (DB::transactionLevel() > 0) {
            $accountQuery->lockForUpdate();
        }
        $accounts = $accountQuery->get()->keyBy('id');
        foreach ($entries as $entry) {
            $account = $accounts->get($entry->ledger_account_id);
            if ($entry->trashed() || ! $entry->posted_at || $entry->currency !== $tx->currency
                || bccomp((string) $entry->amount, '0.00', 2) <= 0 || ! $account || $account->trashed()
                || $account->currency !== $tx->currency) {
                $this->refuse();
            }
            if ($entry->type === LedgerEntryType::Debit) {
                $debits = bcadd($debits, (string) $entry->amount, 2);
            } else {
                $credits = bcadd($credits, (string) $entry->amount, 2);
            }
            if ($entry->wallet_id !== null && (! $wallet || (int) $entry->wallet_id !== (int) $wallet->id)) {
                $this->refuse();
            }
            // Older postings copy the transaction wallet onto BOTH entries. Only the
            // canonical liability account identifies a wallet movement; never the first wallet_id match.
            if ($wallet && $account->code === LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $tx->currency)) {
                if ($walletLeg !== null || (int) $entry->wallet_id !== (int) $wallet->id
                    || bccomp((string) $entry->amount, (string) $tx->amount, 2) !== 0) {
                    $this->refuse();
                }
                $walletLeg = $entry;
            }
        }
        // No tolerance, mixed currency, zero-value or balanced-but-wrong-amount acceptance.
        if (bccomp($debits, (string) $tx->amount, 2) !== 0 || bccomp($credits, (string) $tx->amount, 2) !== 0) {
            $this->refuse();
        }
        if ($wallet) {
            if (! $walletLeg || $entries->count() !== 2 || ($engine->walletSide() !== null && $engine->walletSide() !== $walletLeg->type)) {
                $this->refuse();
            }
            $other = $entries->first(fn (LedgerEntry $entry): bool => $entry->id !== $walletLeg->id);
            $expected = app(WalletService::class)->counterpartAccountCode($engine, $walletLeg->type);
            if ($other->type !== $walletLeg->type->opposite() || ($other->wallet_id !== null && (int) $other->wallet_id !== (int) $wallet->id)
                || $accounts->get($other->ledger_account_id)->code !== LedgerPostingService::accountCodeFor($expected, $tx->currency)) {
                $this->refuse();
            }
        }

        return $walletLeg?->type;
    }

    /** Stable identity/economics/entry provenance; lifecycle stamps change when the original is marked Reversed. */
    public function fingerprint(FinancialTransaction $tx, Collection $entries): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $tx->id, 'uuid' => $tx->uuid, 'reference' => $tx->reference_number,
            'key' => $tx->idempotency_key, 'user' => $tx->user_id, 'wallet' => $tx->wallet_id,
            'type' => $tx->type->value, 'engine' => $tx->metadata['engine_type'] ?? null,
            'currency' => $tx->currency->value, 'amount' => (string) $tx->amount, 'fee' => (string) $tx->fee,
            'reference_type' => $tx->reference_type, 'reference_id' => $tx->reference_id,
            'entries' => $entries->map(fn (LedgerEntry $entry): array => [
                (int) $entry->id, (int) $entry->ledger_account_id, $entry->type->value,
                (string) $entry->amount, $entry->currency->value, $entry->wallet_id,
                $entry->reference_type, $entry->reference_id,
            ])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function assertCompensation(FinancialTransaction $original, FinancialTransaction $reversal, Collection $originalEntries, string $key): void
    {
        $metadata = $reversal->metadata ?? [];
        if ($reversal->trashed() || $reversal->type !== FinancialTransactionType::Reversal->toTransactionType()
            || $reversal->status !== TransactionStatus::Completed || ! $reversal->processed_at
            || $reversal->idempotency_key !== $key || $reversal->reference_type !== FinancialTransaction::class
            || (int) $reversal->reference_id !== (int) $original->id || $reversal->wallet_id !== $original->wallet_id
            || $reversal->user_id !== $original->user_id || $reversal->currency !== $original->currency
            || bccomp((string) $reversal->amount, (string) $original->amount, 2) !== 0
            || bccomp((string) $reversal->fee, '0.00', 2) !== 0
            || ($metadata['engine_type'] ?? null) !== FinancialTransactionType::Reversal->value
            || ($metadata['reversal_of_transaction_id'] ?? null) !== (int) $original->id
            || ($metadata['reversal_source_hash'] ?? null) !== $this->fingerprint($original, $originalEntries)) {
            $this->refuse();
        }
        $mirrors = $this->entries($reversal);
        if ($mirrors->count() !== $originalEntries->count()) {
            $this->refuse();
        }
        $seen = [];
        foreach ($mirrors as $entry) {
            $id = $entry->metadata['reversal_of_ledger_entry_id'] ?? null;
            $source = is_int($id) ? $originalEntries->firstWhere('id', $id) : null;
            if (! $source || isset($seen[$id]) || $entry->trashed() || ! $entry->posted_at
                || $entry->type !== $source->type->opposite() || $entry->ledger_account_id !== $source->ledger_account_id
                || $entry->wallet_id !== $source->wallet_id || $entry->currency !== $source->currency
                || bccomp((string) $entry->amount, (string) $source->amount, 2) !== 0
                || $entry->reference_type !== FinancialTransaction::class || (int) $entry->reference_id !== (int) $original->id) {
                $this->refuse();
            }
            $seen[$id] = true;
        }
    }
}
