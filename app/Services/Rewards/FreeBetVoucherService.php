<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\FreeBetVoucherStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\FreeBetVoucher;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Finance\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contract owner for free-bet vouchers (Batch 1).
 *
 * Batch 2 integration call-site (NOT modified in Batch 1):
 *   - App\Services\Betting\BetPurchaseTransactionService (between Step 4 balance
 *     check and Step 9 wallet debit, delegating voucher validation/consumption
 *     alongside App\Services\Betting\BetPurchaseWalletService).
 */
final class FreeBetVoucherService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function issue(
        User $member,
        ?RewardClaim $claim,
        Money $amount,
        ?CarbonInterface $expiresAt = null,
        array $metadata = [],
    ): FreeBetVoucher {
        $amount->assertPositive('free bet voucher amount');

        $defaultDays = max(1, (int) config('rewards.free_bet.default_expiry_days', 14));
        $resolvedExpiry = $expiresAt !== null
            ? Carbon::instance($expiresAt->toDateTime())
            : Carbon::now()->addDays($defaultDays);

        return DB::transaction(function () use ($member, $claim, $amount, $resolvedExpiry, $metadata): FreeBetVoucher {
            if ($claim instanceof RewardClaim) {
                /** @var FreeBetVoucher|null $existing */
                $existing = FreeBetVoucher::query()
                    ->where('claim_id', $claim->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof FreeBetVoucher) {
                    return $existing;
                }
            }

            /** @var FreeBetVoucher $voucher */
            $voucher = FreeBetVoucher::query()->create([
                'member_id' => (int) $member->getKey(),
                'claim_id' => $claim instanceof RewardClaim ? (int) $claim->getKey() : null,
                'code' => $this->generateUniqueCode(),
                'currency' => $amount->currency()->value,
                'amount' => $amount->toString(),
                'status' => FreeBetVoucherStatus::Active,
                'expires_at' => $resolvedExpiry,
                'metadata' => $metadata,
            ]);

            AuditLog::query()->create([
                'user_id' => (int) $member->getKey(),
                'action' => AuditAction::Create->value,
                'auditable_type' => FreeBetVoucher::class,
                'auditable_id' => (int) $voucher->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Issued free-bet voucher.',
                'metadata' => [
                    'code' => $voucher->code,
                    'amount' => $voucher->amount,
                    'currency' => $voucher->currency,
                    'claim_id' => $voucher->claim_id,
                    'expires_at' => $voucher->expires_at->toIso8601String(),
                ],
            ]);

            return $voucher;
        });
    }

    /**
     * Validate that a voucher belongs to $member, is active & unexpired, and covers $stake.
     *
     * @throws FinancialException
     */
    public function validateUsable(
        User $member,
        int|string $voucherIdentifier,
        Money $stake,
        ?CarbonInterface $now = null,
    ): FreeBetVoucher {
        $pointInTime = $now ?? Carbon::now();

        $query = FreeBetVoucher::query();
        if (is_int($voucherIdentifier) || ctype_digit((string) $voucherIdentifier)) {
            $query->whereKey((int) $voucherIdentifier);
        } else {
            $query->where('code', trim((string) $voucherIdentifier));
        }

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        /** @var FreeBetVoucher|null $voucher */
        $voucher = $query->first();

        if (! $voucher instanceof FreeBetVoucher || (int) $voucher->member_id !== (int) $member->getKey()) {
            throw FinancialException::withCode(
                'free_bet_voucher_not_found',
                'Free-bet voucher not found for this member.',
            );
        }

        if ($voucher->status === FreeBetVoucherStatus::Consumed || $voucher->consumed_bet_id !== null) {
            throw FinancialException::withCode(
                'free_bet_voucher_already_consumed',
                'This free-bet voucher has already been used.',
            );
        }

        if ($voucher->status !== FreeBetVoucherStatus::Active || $voucher->expires_at->lte($pointInTime)) {
            throw FinancialException::withCode(
                'free_bet_voucher_not_usable',
                'This free-bet voucher is expired or inactive.',
            );
        }

        $voucherCurrency = Currency::from(strtoupper((string) $voucher->currency));
        if ($voucherCurrency !== $stake->currency()) {
            throw FinancialException::withCode(
                'free_bet_voucher_currency_mismatch',
                'Free-bet voucher currency does not match bet currency.',
            );
        }

        $voucherMoney = Money::of((string) $voucher->amount, $voucherCurrency);
        if ($voucherMoney->isLessThan($stake)) {
            throw FinancialException::withCode(
                'free_bet_voucher_insufficient_amount',
                'Free-bet voucher amount does not cover the requested stake.',
                ['voucher_amount' => $voucherMoney->toString(), 'stake' => $stake->toString()],
            );
        }

        return $voucher;
    }

    /**
     * Atomically consume a validated voucher against a placed bet.
     *
     * @throws FinancialException
     */
    public function consume(FreeBetVoucher $voucher, Bet $bet, ?CarbonInterface $now = null): FreeBetVoucher
    {
        $pointInTime = $now ?? Carbon::now();

        return DB::transaction(function () use ($voucher, $bet, $pointInTime): FreeBetVoucher {
            /** @var FreeBetVoucher $locked */
            $locked = FreeBetVoucher::query()
                ->whereKey($voucher->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->member_id !== (int) $bet->user_id) {
                throw FinancialException::withCode(
                    'free_bet_voucher_owner_mismatch',
                    'Free-bet voucher owner does not match bet owner.',
                );
            }

            if ($locked->status === FreeBetVoucherStatus::Consumed && (int) $locked->consumed_bet_id === (int) $bet->getKey()) {
                return $locked;
            }

            $stake = Money::of((string) $bet->stake_amount, $bet->currency);
            /** @var User $owner */
            $owner = User::query()->findOrFail($locked->member_id);
            $this->validateUsable($owner, (int) $locked->getKey(), $stake, $pointInTime);

            $locked->status = FreeBetVoucherStatus::Consumed;
            $locked->consumed_bet_id = (int) $bet->getKey();
            $locked->consumed_at = Carbon::instance($pointInTime->toDateTime());
            $locked->save();

            AuditLog::query()->create([
                'user_id' => (int) $locked->member_id,
                'action' => AuditAction::Update->value,
                'auditable_type' => FreeBetVoucher::class,
                'auditable_id' => (int) $locked->getKey(),
                'risk_level' => RiskLevel::Low->value,
                'description' => 'Consumed free-bet voucher against bet.',
                'metadata' => [
                    'bet_id' => (int) $bet->getKey(),
                    'code' => $locked->code,
                    'amount' => (string) $locked->amount,
                ],
            ]);

            return $locked;
        });
    }

    public function expireDueVouchers(?CarbonInterface $now = null): int
    {
        $pointInTime = $now ?? Carbon::now();

        return DB::transaction(function () use ($pointInTime): int {
            $due = FreeBetVoucher::query()
                ->where('status', FreeBetVoucherStatus::Active->value)
                ->where('expires_at', '<=', $pointInTime)
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($due as $voucher) {
                $voucher->status = FreeBetVoucherStatus::Expired;
                $voucher->save();
                $count++;
            }

            return $count;
        });
    }

    /**
     * @return array{usable_count: int, total_amount: string, currency: string}
     */
    public function usableSummaryForMember(User $member, ?CarbonInterface $now = null): array
    {
        $rows = FreeBetVoucher::query()
            ->where('member_id', $member->getKey())
            ->usable($now)
            ->get(['amount', 'currency']);

        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) $row->amount, 2);
        }

        return [
            'usable_count' => $rows->count(),
            'total_amount' => $total,
            'currency' => strtoupper((string) config('rewards.currency', 'THB')),
        ];
    }

    private function generateUniqueCode(): string
    {
        $prefix = strtoupper((string) config('rewards.free_bet.code_prefix', 'FBV'));

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidate = sprintf('%s-%s', $prefix, strtoupper(bin2hex(random_bytes(6))));
            if (! FreeBetVoucher::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw FinancialException::withCode(
            'free_bet_voucher_code_generation_failed',
            'Unable to generate a unique free-bet voucher code.',
        );
    }
}
