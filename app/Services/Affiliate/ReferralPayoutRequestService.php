<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Account\MemberBankAccountService;
use App\Services\Finance\IdempotencyService;
use App\Services\Home\DashboardCacheInvalidator;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use App\Services\Payment\PaymentGatewayManager;

/**
 * Thin adapter delegating commission-wallet payout requests directly to the
 * canonical WithdrawalRequestWithHoldService without duplicating payout logic.
 */
final class ReferralPayoutRequestService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WithdrawalRequestWithHoldService $withdrawalRequests,
        private readonly MemberBankAccountService $bankAccounts,
        private readonly IdempotencyService $idempotency,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * @return array{withdrawal: Withdrawal, replayed: bool}
     *
     * @throws FinancialException
     * @throws InsufficientBalanceException
     * @throws WithdrawalException
     */
    public function requestPayout(
        User $member,
        string $amount,
        string $idempotencyKey,
        ?int $bankAccountId = null,
        ?string $currencyCode = null,
    ): array {
        $trimmedKey = trim($idempotencyKey);
        if ($trimmedKey === '') {
            throw FinancialException::withCode(
                'referral_payout_idempotency_key_required',
                'An idempotency key is required for referral payout requests.',
            );
        }

        $currency = Currency::from(strtoupper($currencyCode ?? (string) config('rewards.currency', 'THB')));
        $money = Money::of($amount, $currency);
        $money->assertPositive('referral payout amount');

        $verifiedBankAccount = $this->bankAccounts->resolveForWithdrawal($member, $bankAccountId);
        $commissionWallet = $this->wallets->getOrCreateWallet($member, $currency, WalletType::Commission);

        $canonicalKey = $this->idempotency->deterministicKey(
            'referral-payout',
            sprintf('%d|%s', (int) $member->getKey(), $trimmedKey),
        );

        $method = $this->gateways->isWithdrawalCapable(PaymentMethod::BankTransfer, $currency)
            ? PaymentMethod::BankTransfer
            : PaymentMethod::Manual;

        $withdrawal = $this->withdrawalRequests->request(
            wallet: $commissionWallet,
            amount: $money,
            method: $method,
            idempotencyKey: $canonicalKey,
            options: [
                'member_bank_account_id' => (int) $verifiedBankAccount->getKey(),
                'payout_details' => [
                    'bank_code' => $verifiedBankAccount->bank_code,
                    'account_name' => $verifiedBankAccount->account_name,
                    'account_last4' => $verifiedBankAccount->last4,
                ],
                'metadata' => [
                    'source' => 'referral_commission_payout',
                    'client_idempotency_key' => $trimmedKey,
                ],
            ],
        );

        app(DashboardCacheInvalidator::class)->forget($member);

        return [
            'withdrawal' => $withdrawal,
            'replayed' => ! $withdrawal->wasRecentlyCreated,
        ];
    }
}
