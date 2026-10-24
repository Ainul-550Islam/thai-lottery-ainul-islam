<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\DTOs\Wallet\ConversionResultData;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionQuoteStatus;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletType;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Finance\Money;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WalletConversionService implements WalletConversionServiceContract
{
    public function __construct(
        private readonly ConversionPolicyContract $policy,
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionQuoteService $quotes,
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly LedgerPostingService $ledger,
        private readonly FinancialTransactionService $transactions,
        private readonly Config $config,
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        return $this->quotes->quote($request);
    }

    public function convert(ConversionRequestData $request): ConversionResultData
    {
        $this->assertIdempotencyKey($request->idempotencyKey);
        $this->policy->assertAllowed($request);
        $fingerprint = hash('sha256', implode('|', [$request->userId, $request->direction->value, $request->amount, $request->currency->value, $request->quoteReference]));

        return DB::transaction(function () use ($request, $fingerprint): ConversionResultData {
            $prior = WalletConversion::query()->where('user_id', $request->userId)
                ->where('channel', $request->channel->value)->where('idempotency_key', $request->idempotencyKey)
                ->lockForUpdate()->first();
            if ($prior) {
                if (! hash_equals((string) $prior->request_fingerprint, $fingerprint)) {
                    throw ConversionException::failure('idempotency_key_conflict', 'This idempotency key was already used for a different conversion.');
                }
                return $this->resultFrom($prior, true);
            }

            $quote = $this->quotes->findValidForExecution($request);
            $this->policy->assertAllowed($request);
            $source = Wallet::query()->where('user_id', $request->userId)->where('type', $request->fromWalletType()->value)->where('currency', $request->currency->value)->firstOrFail();
            $destination = Wallet::query()->where('user_id', $request->userId)->where('type', $request->toWalletType()->value)->where('currency', $request->currency->value)->firstOrFail();
            $locked = $this->locks->lockMany([(int) $source->getKey(), (int) $destination->getKey()]);
            $source = $locked[(int) $source->getKey()];
            $destination = $locked[(int) $destination->getKey()];
            $amountIn = bcadd((string) $quote->amount_in, '0', 2);
            $feeAmount = bcadd((string) $quote->fee_amount, '0', 2);
            $amountOut = bcadd((string) $quote->amount_out, '0', 2);
            if (bccomp((string) $source->balance, $amountIn, 2) < 0) {
                throw ConversionException::failure('insufficient_balance', 'The source wallet no longer has enough available funds.');
            }

            $conversion = WalletConversion::query()->create([
                'reference' => 'WC-'.strtoupper(Str::random(24)),
                'user_id' => $request->userId,
                'source_wallet_id' => $source->getKey(),
                'destination_wallet_id' => $destination->getKey(),
                'direction' => $request->direction,
                'status' => WalletConversionStatus::Pending,
                'currency' => $request->currency,
                'amount_in' => $amountIn,
                'fee_amount' => $feeAmount,
                'amount_out' => $amountOut,
                'fee_rule_key' => $quote->fee_rule_key,
                'fee_rule_version' => $quote->fee_rule_version,
                'fee_rate' => $quote->fee_rate,
                'fee_bearer' => $quote->fee_bearer->value,
                'channel' => $request->channel->value,
                'idempotency_key' => $request->idempotencyKey,
                'quote_reference' => $quote->reference,
                'request_fingerprint' => $fingerprint,
                'metadata' => $request->metadata,
            ]);

            $sourceBalance = $this->wallets->applyDebit($source, Money::of($amountIn, $request->currency), FinancialTransactionType::Transfer);
            $destinationBalance = $this->wallets->applyCredit($destination, Money::of($amountOut, $request->currency), FinancialTransactionType::Transfer);
            $financial = $this->createFinancialTransaction($request, $conversion, $amountIn, $feeAmount);
            $conversion->financial_transaction_id = $financial->getKey();
            $conversion->status = WalletConversionStatus::Processing;
            $conversion->save();
            $this->postEntries($financial, $conversion, $source, $destination, $amountIn, $amountOut, $feeAmount, false);
            $this->transactions->transitionTo($financial, TransactionStatus::Completed);
            $financial->processed_at = now();
            $financial->save();
            $conversion->status = WalletConversionStatus::Completed;
            $conversion->source_balance_after = $sourceBalance->toString();
            $conversion->destination_balance_after = $destinationBalance->toString();
            $conversion->completed_at = now();
            $conversion->save();
            $this->quotes->consume($quote, (int) $conversion->getKey());
            return $this->resultFrom($conversion, false);
        });
    }

    public function reverse(int $conversionId, ConversionAuditContextData $context, string $reason, ?string $idempotencyKey = null): ConversionResultData
    {
        if (! (bool) $this->config->get('conversions.reversal.enabled', true)) {
            throw ConversionException::failure('conversion_reversal_disabled', 'Conversion reversals are disabled.');
        }
        if (! in_array($context->channel, [WalletConversionChannel::Admin, WalletConversionChannel::Console], true)) {
            throw ConversionException::failure('conversion_reversal_forbidden', 'Only an operator may reverse a conversion.');
        }
        $minimumReason = (int) $this->config->get('conversions.reversal.min_reason_length', 10);
        if (mb_strlen(trim($reason)) < $minimumReason) {
            throw ConversionException::failure('conversion_reversal_reason_required', 'A detailed reason is required for the reversal.');
        }

        return DB::transaction(function () use ($conversionId, $context, $reason, $idempotencyKey): ConversionResultData {
            $original = WalletConversion::query()->lockForUpdate()->find($conversionId);
            if (! $original || $original->status !== WalletConversionStatus::Completed || $original->reversal_of_id !== null) {
                throw ConversionException::failure('conversion_not_reversible', 'This conversion is not in a reversible state.');
            }
            $window = max(1, (int) $this->config->get('conversions.reversal.window_hours', 24));
            if ($original->completed_at === null || $original->completed_at->lt(now()->subHours($window))) {
                throw ConversionException::failure('conversion_reversal_window_expired', 'The conversion is outside the reversal window.');
            }
            if ($original->reversed_by_id !== null) {
                $existing = WalletConversion::query()->find($original->reversed_by_id);
                if ($existing) {
                    return $this->resultFrom($existing, true);
                }
            }
            $locked = $this->locks->lockMany([(int) $original->source_wallet_id, (int) $original->destination_wallet_id]);
            $from = $locked[(int) $original->destination_wallet_id];
            $to = $locked[(int) $original->source_wallet_id];
            $fromAmount = (string) $original->amount_out;
            $toAmount = (string) $original->amount_in;
            if (bccomp((string) $from->balance, $fromAmount, 2) < 0) {
                throw ConversionException::failure('conversion_reversal_insufficient_balance', 'The destination wallet no longer contains the amount required to reverse this conversion.');
            }
            $reverse = WalletConversion::query()->create([
                'reference' => 'WC-'.strtoupper(Str::random(24)),
                'user_id' => $original->user_id,
                'source_wallet_id' => $from->getKey(),
                'destination_wallet_id' => $to->getKey(),
                'direction' => WalletConversionDirection::WinToCash,
                'status' => WalletConversionStatus::Processing,
                'currency' => $original->currency,
                'amount_in' => $fromAmount,
                'fee_amount' => '0.00',
                'amount_out' => $toAmount,
                'fee_rule_key' => $original->fee_rule_key,
                'fee_rule_version' => $original->fee_rule_version,
                'fee_rate' => '0.00',
                'fee_bearer' => 'source',
                'channel' => $context->channel->value,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => hash('sha256', 'reverse|'.$conversionId.'|'.trim($reason)),
                'reversal_of_id' => $original->getKey(),
                'reason' => trim($reason),
                'metadata' => ['actor_user_id' => $context->actorUserId, 'request_id' => $context->requestId, 'ip_address' => $context->ipAddress],
            ]);
            $fromBalance = $this->wallets->applyDebit($from, Money::of($fromAmount, $original->currency), FinancialTransactionType::Transfer);
            $toBalance = $this->wallets->applyCredit($to, Money::of($toAmount, $original->currency), FinancialTransactionType::Transfer);
            $synthetic = new ConversionRequestData(
                direction: WalletConversionDirection::WinToCash,
                amount: $fromAmount,
                currency: $original->currency,
                channel: $context->channel,
                userId: (int) $original->user_id,
                idempotencyKey: $idempotencyKey,
                quoteReference: null,
                auditContext: $context,
                metadata: [],
            );
            $financial = $this->createFinancialTransaction($synthetic, $reverse, $fromAmount, '0.00');
            $reverse->financial_transaction_id = $financial->getKey();
            $reverse->save();
            $feeRefund = bcsub($toAmount, $fromAmount, 2);
            $this->postEntries($financial, $reverse, $from, $to, $fromAmount, $toAmount, '0.00', true, $feeRefund);
            $this->transactions->transitionTo($financial, TransactionStatus::Completed);
            $financial->processed_at = now();
            $financial->save();
            $reverse->status = WalletConversionStatus::Completed;
            $reverse->source_balance_after = $fromBalance->toString();
            $reverse->destination_balance_after = $toBalance->toString();
            $reverse->completed_at = now();
            $reverse->save();
            $original->status = WalletConversionStatus::Reversed;
            $original->reversed_by_id = $reverse->getKey();
            $original->reversed_at = now();
            $original->save();
            return $this->resultFrom($reverse, false);
        });
    }

    public function findForUser(string $reference, int $userId): ?WalletConversion
    {
        return WalletConversion::query()->where('reference', $reference)->where('user_id', $userId)->first();
    }

    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        $query = WalletConversion::query()->where('user_id', $filter->userId);
        if ($filter->direction) { $query->where('direction', $filter->direction->value); }
        if ($filter->hasStatuses()) { $query->whereIn('status', $filter->statusValues()); }
        if ($filter->from) { $query->where('created_at', '>=', $filter->from); }
        if ($filter->to) { $query->where('created_at', '<=', $filter->to); }
        if ($filter->hasSearch()) { $query->where('reference', 'like', '%'.$filter->search.'%'); }
        return $query->orderByDesc('id')->paginate($filter->perPage, ['*'], 'page', $filter->page);
    }

    public function openQuotesForUser(int $userId)
    {
        return \App\Models\WalletConversionQuote::query()->where('user_id', $userId)
            ->where('status', WalletConversionQuoteStatus::Quoted)->where('expires_at', '>', now())
            ->orderByDesc('id')->limit((int) $this->config->get('conversions.quotes.max_open_per_user', 5))->get();
    }

    /** @return array{total: int, completed: int, completed_volume_in: string, reversed: int} */
    public function summaryForUser(int $userId): array
    {
        $query = WalletConversion::query()->where('user_id', $userId);
        $completed = (clone $query)->where('status', WalletConversionStatus::Completed->value);
        return [
            'total' => (clone $query)->count(),
            'completed' => (clone $completed)->count(),
            'completed_volume_in' => bcadd((string) (clone $completed)->sum('amount_in'), '0', 2),
            'reversed' => (clone $query)->where('status', WalletConversionStatus::Reversed->value)->count(),
        ];
    }

    public function isEnabled(?WalletConversionDirection $direction = null): bool
    {
        if (! (bool) $this->config->get('conversions.enabled', false)) { return false; }
        return $direction === null || (bool) $this->config->get('conversions.directions.'.$direction->value.'.enabled', false);
    }

    private function assertIdempotencyKey(?string $key): void
    {
        if (! (bool) $this->config->get('conversions.idempotency.required', true)) { return; }
        $minimum = (int) $this->config->get('conversions.idempotency.min_key_length', 16);
        $maximum = (int) $this->config->get('conversions.idempotency.max_key_length', 128);
        if ($key === null || strlen($key) < $minimum || strlen($key) > $maximum) {
            throw ConversionException::failure('idempotency_key_required', 'A valid idempotency key is required.');
        }
    }

    private function createFinancialTransaction(ConversionRequestData $request, WalletConversion $conversion, string $amount, string $fee): FinancialTransaction
    {
        $transaction = new FinancialTransaction;
        $transaction->fill([
            'reference_number' => $this->transactions->generateReferenceNumber(),
            'user_id' => $request->userId,
            'wallet_id' => $conversion->source_wallet_id,
            'type' => TransactionType::Transfer,
            'currency' => $request->currency,
            'amount' => $amount,
            'fee' => $fee,
            'description' => 'Wallet conversion '.$conversion->reference,
            'metadata' => ['conversion_reference' => $conversion->reference, 'direction' => $conversion->direction->value],
            'idempotency_key' => 'conversion:'.$conversion->reference,
            'reference_type' => 'wallet_conversion',
            'reference_id' => $conversion->getKey(),
        ]);
        $transaction->status = TransactionStatus::Pending;
        $transaction->save();
        $this->transactions->transitionTo($transaction, TransactionStatus::Processing)->save();
        return $transaction;
    }

    private function postEntries(FinancialTransaction $financial, WalletConversion $conversion, Wallet $source, Wallet $destination, string $amountIn, string $amountOut, string $fee, bool $reverse, string $feeRefund = '0.00'): void
    {
        $currency = $conversion->currency;
        $liability = $this->ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $currency));
        $revenue = $this->ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_FEE_REVENUE, $currency));
        $entries = [
            ['ledger_account_id' => (int) $liability->getKey(), 'type' => LedgerEntryType::Debit, 'amount' => $amountIn, 'wallet_id' => (int) $source->getKey(), 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()],
            ['ledger_account_id' => (int) $liability->getKey(), 'type' => LedgerEntryType::Credit, 'amount' => $amountOut, 'wallet_id' => (int) $destination->getKey(), 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()],
        ];
        if ($reverse && bccomp($feeRefund, '0', 2) === 1) {
            $entries[] = ['ledger_account_id' => (int) $revenue->getKey(), 'type' => LedgerEntryType::Debit, 'amount' => $feeRefund, 'wallet_id' => null, 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()];
        } elseif (! $reverse && bccomp($fee, '0', 2) === 1) {
            $entries[] = ['ledger_account_id' => (int) $revenue->getKey(), 'type' => LedgerEntryType::Credit, 'amount' => $fee, 'wallet_id' => null, 'reference_type' => 'wallet_conversion', 'reference_id' => (int) $conversion->getKey()];
        }
        $this->ledger->post($financial, $entries);
    }

    private function resultFrom(WalletConversion $conversion, bool $replayed): ConversionResultData
    {
        return new ConversionResultData(
            conversionId: (int) $conversion->getKey(),
            reference: (string) $conversion->reference,
            status: $conversion->status,
            direction: $conversion->direction,
            amountIn: (string) $conversion->amount_in,
            feeAmount: (string) $conversion->fee_amount,
            amountOut: (string) $conversion->amount_out,
            currency: $conversion->currency,
            fromWalletId: (int) $conversion->source_wallet_id,
            toWalletId: (int) $conversion->destination_wallet_id,
            fromBalanceAfter: (string) ($conversion->source_balance_after ?? Wallet::query()->find($conversion->source_wallet_id)?->balance ?? '0.00'),
            toBalanceAfter: (string) ($conversion->destination_balance_after ?? Wallet::query()->find($conversion->destination_wallet_id)?->balance ?? '0.00'),
            financialTransactionId: $conversion->financial_transaction_id ? (int) $conversion->financial_transaction_id : null,
            idempotencyKey: $conversion->idempotency_key,
            replayed: $replayed,
            completedAt: $conversion->completed_at?->toDateTimeImmutable(),
            note: $conversion->reason,
        );
    }
}
