<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\BetCancellationException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/** Current, exact, canonical local accounting; never evidence of an upstream bank operation. */
final class BetAccountingProof
{
    public function refuse(): never
    {
        throw BetCancellationException::withCode('bet_cancellation_ownership_conflict', 'Original purchase/refund ownership or accounting is inconsistent; controlled review is required.');
    }

    public function decimal(mixed $value, bool $positive = false): string
    {
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,17})\.[0-9]{2}\z/D', $value)
            || ($positive && bccomp($value, '0.00', 2) <= 0)) {
            $this->refuse();
        }

        return $value;
    }

    /** Locks headers, entries AND accounts, including soft-deleted conflicting history. */
    public function transaction(int $id, Bet $bet, Wallet $wallet, bool $refund = false): array
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        $tx = FinancialTransaction::withTrashed()->select('financial_transactions.*')
            ->selectRaw('CAST(amount AS CHAR) AS proof_amount, CAST(fee AS CHAR) AS proof_fee')
            ->whereKey($id)->lockForUpdate()->first();
        if (! $tx || $tx->trashed() || $wallet->trashed() || $tx->type !== ($refund ? TransactionType::BetRefund : TransactionType::BetPlacement)
            || $tx->status !== TransactionStatus::Completed || ! $tx->processed_at || $tx->reversed_at
            || (int) $tx->wallet_id !== (int) $wallet->id
            || (int) $tx->user_id !== (int) $bet->user_id || (int) $wallet->user_id !== (int) $bet->user_id
            || $tx->currency !== $bet->currency || $wallet->currency !== $bet->currency
            || $tx->reference_type !== Bet::class || (int) $tx->reference_id !== (int) $bet->id
            || ! is_string($tx->idempotency_key) || $tx->idempotency_key === '') {
            $this->refuse();
        }
        // Canonicalise SQL decimal representations without passing through a float.
        $betAmount = DB::table('bets')->where('id', $bet->id)->selectRaw('CAST(stake_amount AS CHAR) AS amount')->lockForUpdate()->first();
        $amount = bcadd((string) $tx->proof_amount, '0', 2);
        $this->decimal($amount, true);
        if (bccomp((string) $tx->proof_fee, '0', 2) !== 0
            || bccomp($amount, (string) $betAmount->amount, 2) !== 0
            || DB::table('financial_transactions')->where(fn ($q) => $q->where(fn ($r) => $r->where('reference_type', FinancialTransaction::class)->where('reference_id', $id))->orWhere('idempotency_key', app(IdempotencyService::class)->deterministicKey('reversal', (string) $id)))->lockForUpdate()->exists()) {
            $this->refuse();
        }
        $entries = LedgerEntry::withTrashed()->select('ledger_entries.*')->selectRaw('CAST(amount AS CHAR) AS proof_amount')
            ->where('financial_transaction_id', $id)->orderBy('id')->lockForUpdate()->get();
        if ($entries->count() !== 2) {
            $this->refuse();
        }
        $accounts = LedgerAccount::withTrashed()->whereIn('id', $entries->pluck('ledger_account_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $walletCode = LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $bet->currency);
        $counterCode = LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_BET_REVENUE, $bet->currency);
        $seen = [];
        $fingerprint = [];
        foreach ($entries as $entry) {
            $account = $accounts->get($entry->ledger_account_id);
            if (! $account || $account->trashed() || ! $account->is_active || $account->currency !== $bet->currency
                || $entry->trashed() || ! $entry->posted_at || $entry->currency !== $bet->currency
                || $entry->reference_type !== Bet::class || (int) $entry->reference_id !== (int) $bet->id
                || bccomp((string) $entry->proof_amount, $amount, 2) !== 0
                || ! in_array($account->code, [$walletCode, $counterCode], true) || isset($seen[$account->code])) {
                $this->refuse();
            }
            $isWallet = $account->code === $walletCode;
            if ($account->type !== ($isWallet ? LedgerAccountType::Liability : LedgerAccountType::Revenue)) {
                $this->refuse();
            }
            $side = ($isWallet xor $refund) ? LedgerEntryType::Debit : LedgerEntryType::Credit;
            if ($entry->type !== $side || ($isWallet && (int) $entry->wallet_id !== (int) $wallet->id)
                || (! $isWallet && $entry->wallet_id !== null && (int) $entry->wallet_id !== (int) $wallet->id)) {
                $this->refuse();
            }
            $seen[$account->code] = true;
            $fingerprint[] = [$entry->id, $entry->uuid, $entry->ledger_account_id, $entry->wallet_id, $entry->type->value, $amount, $entry->currency->value, $entry->posted_at->toDateTimeString()];
        }
        $hash = hash('sha256', json_encode([$tx->id, $tx->uuid, $tx->reference_number, $tx->idempotency_key,
            $tx->user_id, $tx->wallet_id, $tx->type->value, $tx->currency->value, $amount,
            $tx->reference_type, $tx->reference_id, $tx->metadata, $fingerprint], JSON_THROW_ON_ERROR));
        // SQL aliases must not leak into a model subsequently saved by a caller.
        foreach (['proof_amount', 'proof_fee'] as $alias) {
            unset($tx[$alias]);
        }

        return [$tx, $hash];
    }
}
