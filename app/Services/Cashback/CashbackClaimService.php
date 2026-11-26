<?php

declare(strict_types=1);

namespace App\Services\Cashback;

use App\Enums\AuditAction;
use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\CashbackAccrual;
use App\Models\CashbackPolicy;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Home\DashboardCacheInvalidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CashbackClaimService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLockService $walletLocks,
        private readonly IdempotencyService $idempotency,
        private readonly AccountGradeEvaluator $gradeEvaluator,
    ) {}

    /**
     * @return array{
     *     claimed_amount: string,
     *     currency: string,
     *     claimed_count: int,
     *     transaction_id: int,
     *     wallet_type: string,
     *     replayed: bool
     * }
     *
     * @throws FinancialException
     */
    public function claim(User $member, string $idempotencyKey): array
    {
        Money::assertExactArithmeticIsAvailable();

        $trimmedKey = trim($idempotencyKey);
        if ($trimmedKey === '') {
            throw FinancialException::withCode(
                'cashback_idempotency_key_required',
                'An idempotency key is required to claim cashback.',
            );
        }

        if (! (bool) config('rewards.cashback.enabled', true)) {
            throw FinancialException::withCode(
                'cashback_disabled',
                'Cashback claims are currently unavailable.',
            );
        }

        $financialKey = $this->idempotency->deterministicKey(
            'cashback-claim',
            sprintf('%d|%s', (int) $member->getKey(), $trimmedKey),
        );

        $currency = Currency::from(strtoupper((string) config('rewards.currency', 'THB')));
        $targetWalletType = $this->resolveTargetWalletType();
        $wallet = $this->wallets->getOrCreateWallet($member, $currency, $targetWalletType);

        return DB::transaction(function () use ($member, $wallet, $currency, $targetWalletType, $financialKey, $trimmedKey): array {
            // LOCK ORDER (shared with CashbackAccrualService::reverse and the reward
            // redemption flow): member row -> accrual rows -> wallet. Taking the wallet
            // first here while reverse() takes accruals first would allow a deadlock.
            User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            $existingTx = $this->idempotency->existingFor($financialKey);
            if ($existingTx instanceof FinancialTransaction) {
                if ((int) $existingTx->user_id !== (int) $member->getKey()) {
                    throw FinancialException::withCode(
                        'cashback_idempotency_conflict',
                        'This idempotency key belongs to another operation.',
                    );
                }

                $claimedCount = CashbackAccrual::query()
                    ->where('member_id', $member->getKey())
                    ->where('claim_transaction_id', $existingTx->getKey())
                    ->count();

                return [
                    'claimed_amount' => bcadd((string) $existingTx->amount, '0.00', 2),
                    'currency' => $currency->value,
                    'claimed_count' => $claimedCount,
                    'transaction_id' => (int) $existingTx->getKey(),
                    'wallet_type' => $this->walletLabel($targetWalletType),
                    'replayed' => true,
                ];
            }

            app(RealMoneyGate::class)->assertOpen('cashback_claim');

            $now = Carbon::now();
            $rows = CashbackAccrual::query()
                ->where('member_id', $member->getKey())
                ->claimable($now)
                ->with('policy')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $totalAmount = '0.00';
            $minClaim = $this->resolveMinimumClaim($member);

            foreach ($rows as $row) {
                $totalAmount = bcadd($totalAmount, (string) $row->accrual_amount, 2);
                if ($row->policy instanceof CashbackPolicy && bccomp((string) $row->policy->min_claim, $minClaim, 2) > 0) {
                    $minClaim = bcadd((string) $row->policy->min_claim, '0.00', 2);
                }
            }

            if ($rows->isEmpty() || bccomp($totalAmount, '0.00', 2) <= 0) {
                throw FinancialException::withCode(
                    'cashback_nothing_claimable',
                    'You have no claimable cashback available.',
                    ['claimable_amount' => '0.00', 'min_claim' => $minClaim],
                );
            }

            if (bccomp($totalAmount, $minClaim, 2) < 0) {
                throw FinancialException::withCode(
                    'cashback_below_minimum_claim',
                    sprintf('Claimable cashback (%s %s) is below the minimum claim threshold (%s %s).', $totalAmount, $currency->value, $minClaim, $currency->value),
                    ['claimable_amount' => $totalAmount, 'min_claim' => $minClaim],
                );
            }

            /** @var CashbackAccrual $firstRow */
            $firstRow = $rows->first();

            $lockedWallet = $this->walletLocks->lock((int) $wallet->getKey());

            foreach ($rows as $row) {
                $row->status = CashbackAccrualStatus::Claimed;
                $row->claimed_at = $now;
                $row->save();
            }

            $transaction = $this->wallets->credit(
                wallet: $lockedWallet,
                amount: Money::of($totalAmount, $currency),
                type: FinancialTransactionType::Adjustment,
                idempotencyKey: $financialKey,
                options: [
                    'description' => sprintf('Cashback claim (%d accrual rows)', $rows->count()),
                    'reference_type' => CashbackAccrual::class,
                    'reference_id' => (int) $firstRow->getKey(),
                    'metadata' => [
                        'source' => 'cashback_claim',
                        'client_idempotency_key' => $trimmedKey,
                        'accrual_ids' => $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                        'wallet_target' => $this->walletLabel($targetWalletType),
                    ],
                ],
            );

            CashbackAccrual::query()
                ->whereIn('id', $rows->pluck('id')->all())
                ->update(['claim_transaction_id' => (int) $transaction->getKey()]);

            AuditLog::query()->create([
                'user_id' => (int) $member->getKey(),
                'action' => AuditAction::Payout->value,
                'auditable_type' => FinancialTransaction::class,
                'auditable_id' => (int) $transaction->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Member claimed accrued cashback.',
                'metadata' => [
                    'claimed_amount' => $totalAmount,
                    'currency' => $currency->value,
                    'claimed_count' => $rows->count(),
                    'wallet_id' => (int) $lockedWallet->getKey(),
                ],
            ]);

            app(DashboardCacheInvalidator::class)->forget($member);

            return [
                'claimed_amount' => $totalAmount,
                'currency' => $currency->value,
                'claimed_count' => $rows->count(),
                'transaction_id' => (int) $transaction->getKey(),
                'wallet_type' => $this->walletLabel($targetWalletType),
                'replayed' => false,
            ];
        }, 5);
    }

    private function resolveMinimumClaim(User $member): string
    {
        $grade = $this->gradeEvaluator->evaluate($member)->level;
        /** @var CashbackPolicy|null $policy */
        $policy = CashbackPolicy::query()->activeForGrade($grade)->first();

        if ($policy instanceof CashbackPolicy) {
            return bcadd((string) $policy->min_claim, '0.00', 2);
        }

        return bcadd((string) config('rewards.cashback.default_min_claim', '10.00'), '0.00', 2);
    }

    private function resolveTargetWalletType(): WalletType
    {
        $configured = strtolower(trim((string) config('rewards.cashback.target_wallet', 'main')));

        return match ($configured) {
            'main', 'primary' => WalletType::Primary,
            'bonus' => WalletType::Bonus,
            'commission' => WalletType::Commission,
            default => WalletType::Primary,
        };
    }

    private function walletLabel(WalletType $type): string
    {
        return $type === WalletType::Primary ? 'main' : $type->value;
    }
}
