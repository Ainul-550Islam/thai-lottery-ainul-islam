<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletConversionStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Services\Finance\LedgerPostingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Current, bounded original movement proof; no repair, posting or historical balance comparison. */
final class ConversionIntegrityGuard
{
    public function assertSettled(WalletConversion $candidate): WalletConversion
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->refuse();
        }
        try {
            $row = WalletConversion::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $row) {
                $this->refuse();
            }
            $root = $row->reversal_of_id === null ? $row : WalletConversion::query()->whereKey($row->reversal_of_id)->lockForUpdate()->first();
            if (! $root || $root->reversal_of_id !== null) {
                $this->refuse();
            }
            $this->movement($root);
            $children = WalletConversion::query()->where('reversal_of_id', $root->id)->orderBy('id')->limit(2)->lockForUpdate()->get();
            // Read the stored strings and tryFrom them rather than trusting the enum
            // cast: a damaged status column is exactly the case this guard exists to
            // refuse, and a failed cast would surface as a type error instead of the
            // refusal an auditor needs to see.
            $rootStatus = WalletConversionStatus::tryFrom((string) $root->getRawOriginal('status'));
            if ($rootStatus === WalletConversionStatus::Completed) {
                if ($root->reversed_by_id !== null || $root->reversed_at !== null || $root->reversal_reason !== null || $children->isNotEmpty()) {
                    $this->refuse();
                }
            } else {
                if ($rootStatus !== WalletConversionStatus::Reversed || $children->count() !== 1 || $root->reversed_at === null) {
                    $this->refuse();
                }
                $child = $children->sole();
                $this->movement($child);
                if (WalletConversionStatus::tryFrom((string) $child->getRawOriginal('status')) !== WalletConversionStatus::Completed || (int) $root->reversed_by_id !== (int) $child->id
                    || $child->reversed_by_id !== null || $child->reversed_at !== null
                    || (int) $child->user_id !== (int) $root->user_id || $child->currency !== $root->currency
                    || $child->direction !== $root->direction->opposite()
                    || (int) $child->from_wallet_id !== (int) $root->to_wallet_id || (int) $child->to_wallet_id !== (int) $root->from_wallet_id
                    || $child->reversal_reason !== $root->reversal_reason || trim((string) $child->reversal_reason) === ''
                    || $child->fee_rate !== $root->fee_rate || $child->fee_bearer !== $root->fee_bearer
                    || $child->fee_rule_key !== $root->fee_rule_key || $child->fee_rule_version !== $root->fee_rule_version
                    || bccomp($this->exact($child, 'amount_in'), $this->exact($root, 'amount_out'), 2) !== 0
                    || bccomp($this->exact($child, 'amount_out'), $this->exact($root, 'amount_in'), 2) !== 0
                    || bccomp($this->exact($child, 'fee_amount', true), bcmul($this->exact($root, 'fee_amount', true), '-1', 2), 2) !== 0) {
                    $this->refuse();
                }
            }
            if ($row->reversal_of_id !== null && ($children->count() !== 1 || (int) $children->sole()->id !== (int) $row->id)) {
                $this->refuse();
            }

            return $row;
        } catch (QueryException|\PDOException $error) {
            // Preserve infrastructure failures and Laravel deadlock retries.
            throw $error;
        } catch (\Throwable $error) {
            throw ConversionException::failure('conversion_original_unproven',
                'Original conversion accounting requires controlled review before replay or reversal.', [], $error);
        }
    }

    private function movement(WalletConversion $row): void
    {
        // The model casts the currency, so this arrives as the enum; accept the raw
        // string too, because the proof also reads rows built by older code paths.
        $currency = $row->currency instanceof Currency ? $row->currency : Currency::from((string) $row->currency);
        $input = $this->exact($row, 'amount_in');
        $output = $this->exact($row, 'amount_out');
        $fee = $this->exact($row, 'fee_amount', $row->reversal_of_id !== null);
        if (WalletConversionStatus::tryFrom((string) $row->getRawOriginal('status'))?->isSettled() !== true || $row->completed_at === null || (int) $row->user_id < 1
            || (int) $row->from_wallet_id === (int) $row->to_wallet_id
            || $row->from_wallet_type !== $row->direction->fromType()->value || $row->to_wallet_type !== $row->direction->toType()->value
            || bccomp($input, '0', 2) <= 0 || bccomp($output, '0', 2) <= 0 || bccomp(bcsub($input, $fee, 2), $output, 2) !== 0
            || ! is_string($row->idempotency_key) || preg_match('/^[a-f0-9]{64}$/D', $row->idempotency_key) !== 1) {
            $this->refuse();
        }
        $identity = $row->reversal_of_id === null
            ? ['convert', $row->direction->value, $input, $currency->value, $row->quote_reference]
            : ['reverse', (int) $row->reversal_of_id, (int) $row->actor_user_id, $row->reversal_reason];
        if (! is_string($row->request_fingerprint) || ! hash_equals($row->request_fingerprint, hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)))) {
            $this->refuse();
        }
        $wallets = Wallet::withTrashed()->whereIn('id', [$row->from_wallet_id, $row->to_wallet_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach (['from', 'to'] as $side) {
            $wallet = $wallets->get((int) $row->{$side.'_wallet_id'});
            if (! $wallet || $wallet->trashed() || (int) $wallet->user_id !== (int) $row->user_id
                || $wallet->currency !== $currency || $wallet->type->value !== $row->{$side.'_wallet_type'}) {
                $this->refuse();
            }
        }
        $fromAfter = $this->exact($row, 'from_balance_after');
        $toAfter = $this->exact($row, 'to_balance_after');
        if (bccomp(bcsub($this->exact($row, 'from_balance_before'), $input, 2), $fromAfter, 2) !== 0
            || bccomp(bcadd($this->exact($row, 'to_balance_before'), $output, 2), $toAfter, 2) !== 0) {
            $this->refuse();
        }
        $tx = FinancialTransaction::withTrashed()->whereKey($row->financial_transaction_id)->lockForUpdate()->first();
        $reversal = $row->reversal_of_id !== null;
        if (! $tx || $tx->trashed() || $tx->status !== TransactionStatus::Completed || $tx->processed_at === null
            || $tx->reversed_by !== null || $tx->reversed_at !== null
            || (int) $tx->user_id !== (int) $row->user_id || (int) $tx->wallet_id !== (int) $row->from_wallet_id
            || $tx->currency !== $currency || $tx->type !== ($reversal ? TransactionType::Reversal : TransactionType::Transfer)
            || $tx->reference_type !== 'wallet_conversion' || (int) $tx->reference_id !== (int) $row->id
            || $tx->idempotency_key !== 'conversion:'.$row->user_id.':'.$row->idempotency_key
            || $tx->description !== ($reversal ? 'Cash/Win conversion reversal' : 'Cash/Win conversion')
            || data_get($tx->metadata, 'engine_type') !== 'transfer' || data_get($tx->metadata, 'conversion_reference') !== $row->reference
            || bccomp($this->exact($tx, 'amount'), $input, 2) !== 0
            || bccomp($this->exact($tx, 'fee'), bccomp($fee, '0', 2) >= 0 ? $fee : '0.00', 2) !== 0) {
            $this->refuse();
        }
        $expected = [
            ['2000', LedgerAccountType::Liability, LedgerEntryType::Debit, (int) $row->from_wallet_id, $input, $fromAfter],
            ['2000', LedgerAccountType::Liability, LedgerEntryType::Credit, (int) $row->to_wallet_id, $output, $toAfter],
        ];
        if (bccomp($fee, '0', 2) !== 0) {
            $expected[] = ['4100', LedgerAccountType::Revenue, bccomp($fee, '0', 2) > 0 ? LedgerEntryType::Credit : LedgerEntryType::Debit, null, ltrim($fee, '-'), null];
        }
        // Include hidden/nonposted surplus rows; no filtering can make corruption disappear.
        $entries = LedgerEntry::withTrashed()->where('financial_transaction_id', $tx->id)->orderBy('id')->limit(4)->lockForUpdate()->get();
        if ($entries->count() !== count($expected)) {
            $this->refuse();
        }
        foreach ($expected as [$code, $accountType, $type, $walletId, $amount, $balance]) {
            $matches = $entries->filter(fn ($entry) => $entry->type === $type
                && ($entry->wallet_id === null ? null : (int) $entry->wallet_id) === $walletId);
            if ($matches->count() !== 1) {
                $this->refuse();
            }
            $entry = $matches->sole();
            $account = LedgerAccount::withTrashed()->whereKey($entry->ledger_account_id)->lockForUpdate()->first();
            if ($entry->trashed() || $entry->posted_at === null || $entry->currency !== $currency
                || $entry->reference_type !== 'wallet_conversion' || (int) $entry->reference_id !== (int) $row->id
                || bccomp($this->exact($entry, 'amount'), $amount, 2) !== 0
                || ($balance === null ? $entry->getRawOriginal('balance_after') !== null : bccomp($this->exact($entry, 'balance_after'), $balance, 2) !== 0)
                || ! $account || $account->trashed() || ! $account->is_active || $account->currency !== $currency
                || $account->type !== $accountType || $account->code !== LedgerPostingService::accountCodeFor($code, $currency)) {
                $this->refuse();
            }
        }
    }

    private function exact(Model $row, string $field, bool $signed = false): string
    {
        $value = $row->getRawOriginal($field);
        if ($value === null || preg_match($signed ? '/^-?\d+(\.\d{1,2})?$/D' : '/^\d+(\.\d{1,2})?$/D', (string) $value) !== 1) {
            $this->refuse();
        }

        return bcadd((string) $value, '0', 2);
    }

    private function refuse(): never
    {
        throw ConversionException::failure('conversion_original_unproven',
            'Original conversion accounting requires controlled review before replay or reversal.');
    }
}
