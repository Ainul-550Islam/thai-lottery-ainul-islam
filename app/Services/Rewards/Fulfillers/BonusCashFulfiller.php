<?php

declare(strict_types=1);

namespace App\Services\Rewards\Fulfillers;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RewardClaimStatus;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;

final class BonusCashFulfiller implements RewardFulfiller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLockService $walletLocks,
        private readonly IdempotencyService $idempotency,
    ) {}

    /**
     * Credits the member's BONUS wallet ONLY.
     *
     * @return array{status: RewardClaimStatus, financial_transaction_id: int, payload: array<string, mixed>}
     *
     * @throws FinancialException
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array
    {
        /** @var array<string, mixed> $payload */
        $payload = is_array($item->payload) ? $item->payload : [];
        $rawAmount = (string) ($payload['amount'] ?? '0.00');
        $currencyCode = strtoupper((string) ($payload['currency'] ?? config('rewards.currency', 'THB')));
        $currency = Currency::from($currencyCode);
        $amount = Money::of($rawAmount, $currency);
        $amount->assertPositive('bonus cash reward amount');

        $bonusWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Bonus);
        $lockedBonusWallet = $this->walletLocks->lock((int) $bonusWallet->getKey());

        $txKey = $this->idempotency->deterministicKey(
            'reward-bonus-cash',
            sprintf('claim:%d|member:%d', (int) $claim->getKey(), (int) $member->getKey()),
        );

        $tx = $this->wallets->credit(
            wallet: $lockedBonusWallet,
            amount: $amount,
            type: FinancialTransactionType::Adjustment,
            idempotencyKey: $txKey,
            options: [
                'description' => sprintf('Reward redemption %s (%d pts) -> bonus wallet', $item->code, $claim->points_spent),
                'reference_type' => RewardClaim::class,
                'reference_id' => (int) $claim->getKey(),
                'metadata' => [
                    'source' => 'reward_bonus_cash',
                    'reward_claim_id' => (int) $claim->getKey(),
                    'reward_item_code' => $item->code,
                    'wallet_type' => WalletType::Bonus->value,
                ],
            ],
        );

        return [
            'status' => RewardClaimStatus::Completed,
            'financial_transaction_id' => (int) $tx->getKey(),
            'payload' => [
                'credited_wallet_type' => WalletType::Bonus->value,
                'credited_wallet_id' => (int) $lockedBonusWallet->getKey(),
                'amount' => $amount->toString(),
                'currency' => $currency->value,
            ],
        ];
    }
}
