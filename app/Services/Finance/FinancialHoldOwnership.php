<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\FinancialHoldStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\FinancialHold;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/** Caller owns WALLET first. No expiry, aggregate inference, takeover or repair capability. */
final class FinancialHoldOwnership
{
    public function inputHash(FinancialHoldData $data): string
    {
        return hash('sha256', json_encode([$data->walletId, $data->amount, $data->currency,
            $data->reason, $data->sourceReference, $data->expiresAt], JSON_THROW_ON_ERROR));
    }

    public function termsHash(FinancialHold $row, Wallet $wallet): string
    {
        return hash('sha256', json_encode([(int) $row->id, $row->hold_key, (int) $row->wallet_id,
            (int) $wallet->user_id, $this->amount($row), $row->currency, $row->reason,
            $row->source_reference, $row->expires_at?->utc()->toIso8601String()], JSON_THROW_ON_ERROR));
    }

    public function amount(FinancialHold $row): string
    {
        $value = $row->getAttribute('exact_amount');
        if (! is_string($value) || preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $value) !== 1 || bccomp($value, '0', 2) <= 0) {
            $this->refuse();
        }

        return bcadd($value, '0', 2);
    }

    public function claim(FinancialHold $row, Wallet $wallet): object
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        $claim = DB::table('financial_hold_claims')->where('financial_hold_id', $row->id)->lockForUpdate()
            ->selectRaw('*, CAST(amount AS CHAR) AS exact_amount')->first();
        if ($claim === null || (int) $row->wallet_id !== (int) $wallet->id || $wallet->trashed()
            || (int) $claim->wallet_id !== (int) $wallet->id || (int) $claim->user_id !== (int) $wallet->user_id
            || $claim->source_reference !== $row->source_reference
            || $row->currency !== $wallet->currency->value || $claim->currency !== $row->currency
            || ! is_string($claim->exact_amount) || preg_match('/^\d{1,18}(?:\.\d{1,2})?$/D', $claim->exact_amount) !== 1
            || bccomp($claim->exact_amount, $this->amount($row), 2) !== 0
            || ! hash_equals($claim->terms_hash, $this->termsHash($row, $wallet))) {
            $this->refuse();
        }

        return $claim;
    }

    /** A current/locking read, never an ambient repeatable-read snapshot. */
    public function minimum(Wallet $wallet): string
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        $rows = FinancialHold::query()->where('wallet_id', $wallet->id)
            ->whereIn('status', [FinancialHoldStatus::Active->value, FinancialHoldStatus::Reviewed->value])
            ->orderBy('id')->lockForUpdate()->selectRaw('financial_holds.*, CAST(amount AS CHAR) AS exact_amount')->get();
        $ids = [];
        $total = '0.00';
        foreach ($rows as $row) {
            $claim = $this->claim($row, $wallet);
            if ($claim->state !== 'active' || $claim->closed_at !== null || $claim->financial_transaction_id !== null) {
                $this->refuse();
            }
            $ids[] = (int) $row->id;
            $total = bcadd($total, $this->amount($row), 2);
        }
        // A hidden/deleted/repointed business row cannot lower an active claim.
        $active = DB::table('financial_hold_claims')->where('wallet_id', $wallet->id)->where('state', 'active')
            ->orderBy('id')->lockForUpdate()->pluck('financial_hold_id')->map(fn ($id) => (int) $id)->all();
        sort($active);
        sort($ids);
        if ($active !== $ids) {
            $this->refuse();
        }

        return $total;
    }

    public function assertState(FinancialHold $row, Wallet $wallet, string $state): object
    {
        $claim = $this->claim($row, $wallet);
        if ($claim->state !== $state || ($state === 'active' ? $claim->closed_at !== null : $claim->closed_at === null)
            || ($state !== 'consumed' && $claim->financial_transaction_id !== null)) {
            $this->refuse();
        }

        $expected = match ($state) {
            'active' => $row->status->occupiesWallet(),
            'released' => $row->status === FinancialHoldStatus::Released && $row->released_at !== null && is_array(data_get($row->evidence, 'released')),
            'expired' => $row->status === FinancialHoldStatus::Expired && $row->expired_at !== null && $row->reviewed_at !== null && is_array(data_get($row->evidence, 'expired')),
            'consumed' => $row->status === FinancialHoldStatus::Converted && $row->converted_at !== null,
            default => false,
        };
        if (! $expected) {
            $this->refuse();
        }

        return $claim;
    }

    public function canonicalKey(FinancialHold $row): string
    {
        return app(IdempotencyService::class)->deterministicKey('financial-hold-debit', (string) $row->id);
    }

    public function assertDebit(FinancialHold $row, Wallet $wallet): FinancialTransaction
    {
        $claim = $this->assertState($row, $wallet, 'consumed');
        $tx = FinancialTransaction::withTrashed()->whereKey($claim->financial_transaction_id)->lockForUpdate()->first();
        if ($row->status !== FinancialHoldStatus::Converted || $tx === null || $tx->trashed()
            || $tx->processed_at === null || $tx->status !== TransactionStatus::Completed || $tx->type !== TransactionType::Adjustment
            || $tx->idempotency_key !== $this->canonicalKey($row) || $tx->reference_type !== FinancialHold::class
            || (int) $tx->reference_id !== (int) $row->id || (int) $tx->wallet_id !== (int) $wallet->id
            || (int) $tx->user_id !== (int) $wallet->user_id || $tx->currency !== $wallet->currency
            || bccomp((string) $tx->amount, $this->amount($row), 2) !== 0 || bccomp((string) $tx->fee, '0', 2) !== 0
            || data_get($row->evidence, 'converted.reference') !== $tx->reference_number) {
            $this->refuse();
        }
        $entries = $tx->ledgerEntries()->withTrashed()->orderBy('id')->lockForUpdate()->get();
        $accounts = LedgerAccount::withTrashed()->whereIn('id', $entries->pluck('ledger_account_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($entries as $entry) {
            $entry->setRelation('ledgerAccount', $accounts->get($entry->ledger_account_id));
        }
        $debits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Debit);
        $credits = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Credit);
        if ($entries->count() !== 2 || $debits->count() !== 1 || $credits->count() !== 1
            || $debits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $wallet->currency)
            || $credits->first()->ledgerAccount?->code !== LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_ADJUSTMENT_EQUITY, $wallet->currency)
            || (int) $debits->first()->wallet_id !== (int) $wallet->id
            || ($credits->first()->wallet_id !== null && (int) $credits->first()->wallet_id !== (int) $wallet->id)
            || $entries->contains(fn ($e) => $e->trashed() || $e->posted_at === null || $e->reference_type !== FinancialHold::class || (int) $e->reference_id !== (int) $row->id || $e->ledgerAccount === null || $e->ledgerAccount->trashed() || $e->ledgerAccount->currency !== $wallet->currency || $e->currency !== $wallet->currency
                || bccomp((string) $e->amount, $this->amount($row), 2) !== 0)) {
            $this->refuse();
        }

        return $tx;
    }

    public function refuse(): never
    {
        throw FinancialException::withCode('financial_hold_ownership_conflict',
            'Financial hold ownership, original terms or canonical debit is unproved. Preserve funds for controlled review; no historical repair authorized.');
    }
}
