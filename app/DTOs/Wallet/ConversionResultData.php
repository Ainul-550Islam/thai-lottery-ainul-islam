<?php

declare(strict_types=1);

namespace App\DTOs\Wallet;

use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use DateTimeImmutable;

/**
 * The outcome of an executed (or replayed) conversion.
 *
 * WHY BOTH WALLET BALANCES ARE CARRIED
 * A caller that receives "conversion completed" but does not receive the
 * resulting balances will immediately issue a second request to fetch them —
 * reading state that has already moved on. Returning the post-movement balances
 * captured inside the same transaction makes the response a complete, honest
 * statement of the new position, and gives the UI everything it needs to
 * re-render without a follow-up round trip.
 *
 * `replayed` distinguishes a fresh movement from an idempotent replay of an
 * earlier one. Two responses can be byte-identical in substance while only one
 * of them moved money, and operators debugging a double-click need to see which
 * is which.
 *
 * All monetary fields are exact decimal strings.
 */
final readonly class ConversionResultData
{
    public function __construct(
        public int $conversionId,
        public string $reference,
        public WalletConversionStatus $status,
        public WalletConversionDirection $direction,
        public string $amountIn,
        public string $feeAmount,
        public string $amountOut,
        public Currency $currency,
        public int $fromWalletId,
        public int $toWalletId,
        public string $fromBalanceAfter,
        public string $toBalanceAfter,
        public ?int $financialTransactionId = null,
        public ?string $idempotencyKey = null,
        public bool $replayed = false,
        public ?DateTimeImmutable $completedAt = null,
        public ?string $note = null,
    ) {}

    /**
     * True when money genuinely moved as a result of the call that produced
     * this object.
     */
    public function movedMoney(): bool
    {
        return $this->status->isSuccessful() && ! $this->replayed;
    }

    public function hasFee(): bool
    {
        return bccomp($this->feeAmount, '0', 2) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'conversion_id' => $this->conversionId,
            'reference' => $this->reference,
            'status' => $this->status->value,
            'direction' => $this->direction->value,
            'from_wallet_type' => $this->direction->fromType()->value,
            'to_wallet_type' => $this->direction->toType()->value,
            'amount_in' => $this->amountIn,
            'fee_amount' => $this->feeAmount,
            'amount_out' => $this->amountOut,
            'currency' => $this->currency->value,
            'from_wallet_id' => $this->fromWalletId,
            'to_wallet_id' => $this->toWalletId,
            'from_balance_after' => $this->fromBalanceAfter,
            'to_balance_after' => $this->toBalanceAfter,
            'financial_transaction_id' => $this->financialTransactionId,
            'idempotency_key' => $this->idempotencyKey,
            'replayed' => $this->replayed,
            'completed_at' => $this->completedAt?->format(DATE_ATOM),
            'note' => $this->note,
        ];
    }

    /**
     * The member-facing projection: the same numbers the member was quoted and
     * charged. Wallet ids are included because both wallets belong to the
     * member requesting them; nothing here identifies another user.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return $this->toArray();
    }
}
