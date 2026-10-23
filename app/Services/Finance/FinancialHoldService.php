<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\FinancialHoldStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\WalletHoldType;
use App\Exceptions\FinancialException;
use App\Exceptions\FinancialHoldException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compliance/settlement financial holds with durable claim ownership.
 */
final class FinancialHoldService
{
    public const DEFAULT_HORIZON_HOURS = 72;

    public function __construct(
        private readonly WalletHoldService $holds,
        private readonly WalletService $wallets,
        private readonly WalletLockService $locks,
        private readonly FinancialHoldOwnership $ownership,
    ) {}

    /**
     * @return array{hold: FinancialHold, replayed: bool}
     *
     * @throws FinancialHoldException
     */
    public function place(FinancialHoldData $raw): array
    {
        $data = $raw->normalized();

        return DB::transaction(fn (): array => $this->placeWithin($data));
    }

    /**
     * @return array{hold: FinancialHold, replayed: bool}
     *
     * @throws FinancialHoldException
     */
    private function placeWithin(FinancialHoldData $data): array
    {
        $walletProbe = Wallet::withTrashed()->whereKey($data->walletId)->lockForUpdate()->first();
        if (! $walletProbe instanceof Wallet || $walletProbe->trashed()) {
            throw FinancialHoldException::notFound('wallet:'.$data->walletId);
        }

        $wallet = $this->locks->lock((int) $walletProbe->getKey());
        $inputHash = $this->ownership->inputHash($data);

        $existingClaim = DB::table('financial_hold_claims')
            ->where('wallet_id', $wallet->id)
            ->where('source_reference', $data->sourceReference)
            ->lockForUpdate()
            ->first();

        $existingHold = FinancialHold::query()
            ->where(function ($query) use ($wallet, $data): void {
                $query->where('hold_key', $data->holdKey())
                    ->orWhere(function ($q) use ($wallet, $data): void {
                        $q->where('wallet_id', $wallet->id)
                            ->where('source_reference', $data->sourceReference);
                    });
            })
            ->lockForUpdate()
            ->selectRaw('financial_holds.*, CAST(amount AS CHAR) AS exact_amount')
            ->first();

        if ($existingHold !== null || $existingClaim !== null) {
            if (! $existingHold instanceof FinancialHold || $existingClaim === null || (int) $existingClaim->financial_hold_id !== (int) $existingHold->id) {
                $this->ownership->refuse();
            }

            $this->ownership->claim($existingHold, $wallet);

            if (! hash_equals((string) $existingClaim->input_hash, $inputHash)
                || (string) $existingHold->hold_key !== $data->holdKey()) {
                throw FinancialHoldException::duplicate($data->holdKey());
            }

            if ($existingClaim->state === 'consumed') {
                $this->ownership->assertDebit($existingHold, $wallet);
            } else {
                $this->ownership->assertState($existingHold, $wallet, (string) $existingClaim->state);
            }

            return ['hold' => $existingHold, 'replayed' => true];
        }

        $money = Money::of($data->amount, Currency::from($data->currency));

        try {
            $this->holds->hold($wallet, $money, WalletHoldType::OtherFinancialHold);
        } catch (InsufficientBalanceException $e) {
            throw FinancialHoldException::amountMismatch($data->holdKey(), $e->availableAmount(), $data->amount);
        } catch (FinancialException) {
            throw FinancialHoldException::amountMismatch($data->holdKey(), 'wallet-unavailable', $data->amount);
        }

        $row = new FinancialHold;
        $row->fill([
            'hold_key' => $data->holdKey(),
            'wallet_id' => $data->walletId,
            'amount' => self::moneyOf($data->amount),
            'currency' => $data->currency,
            'reason' => $data->reason,
            'source_reference' => $data->sourceReference,
            'expires_at' => $data->expiresAt ?? now()->addHours(self::DEFAULT_HORIZON_HOURS)->utc()->toIso8601String(),
            'evidence' => ['placed' => now()->toIso8601String(), 'reason' => $data->reason],
            'metadata' => [],
        ]);
        $row->status = FinancialHoldStatus::Active;
        $row->save();

        $lockedRow = $this->lockHoldRow((int) $row->getKey());

        DB::table('financial_hold_claims')->insert([
            'financial_hold_id' => (int) $lockedRow->id,
            'wallet_id' => (int) $wallet->id,
            'user_id' => (int) $wallet->user_id,
            'source_reference' => (string) $lockedRow->source_reference,
            'amount' => $this->ownership->amount($lockedRow),
            'currency' => (string) $lockedRow->currency,
            'input_hash' => $inputHash,
            'terms_hash' => $this->ownership->termsHash($lockedRow, $wallet),
            'state' => 'active',
            'closed_at' => null,
            'financial_transaction_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->recordAudit($lockedRow, sprintf('Placed hold of %s for [%s]', $data->amount, $data->sourceReference), RiskLevel::High);

        return ['hold' => $lockedRow, 'replayed' => false];
    }

    /**
     * @throws FinancialHoldException
     */
    public function review(FinancialHold $hold, int $operatorUserId, string $note): FinancialHold
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $note): FinancialHold {
            [$wallet, $locked] = $this->lockWalletAndHold($hold);
            $this->ownership->assertState($locked, $wallet, 'active');

            if ($locked->status === FinancialHoldStatus::Reviewed) {
                return $locked;
            }

            if ($locked->status === FinancialHoldStatus::Expired) {
                throw FinancialHoldException::expired((string) $locked->hold_key);
            }

            if (! $locked->status->canTransitionTo(FinancialHoldStatus::Reviewed)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            $locked->status = FinancialHoldStatus::Reviewed;
            $locked->reviewed_at = now();

            $evidence = is_array($locked->evidence) ? $locked->evidence : [];
            $evidence['reviewed'] = ['at' => now()->toIso8601String(), 'by' => $operatorUserId, 'note' => Str::limit(trim($note), 255, '')];
            $locked->evidence = $evidence;
            $locked->save();

            $this->recordAudit($locked, sprintf('Reviewed by #%d (%s)', $operatorUserId, $evidence['reviewed']['note']), RiskLevel::High);

            return $locked;
        });
    }

    /**
     * @throws FinancialHoldException
     */
    public function release(FinancialHold $hold, string $reason): FinancialHold
    {
        return DB::transaction(function () use ($hold, $reason): FinancialHold {
            [$wallet, $locked] = $this->lockWalletAndHold($hold);
            $claim = $this->ownership->claim($locked, $wallet);

            if ($claim->state === 'released') {
                $this->ownership->assertState($locked, $wallet, 'released');

                return $locked;
            }

            $this->ownership->assertState($locked, $wallet, 'active');

            if (! $locked->status->canTransitionTo(FinancialHoldStatus::Released)) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            $amount = $this->ownership->amount($locked);
            $evidence = is_array($locked->evidence) ? $locked->evidence : [];
            $evidence['released'] = ['at' => now()->toIso8601String(), 'reason' => Str::limit(trim($reason), 255, '')];

            $locked->status = FinancialHoldStatus::Released;
            $locked->released_at = now();
            $locked->evidence = $evidence;
            $locked->save();

            DB::table('financial_hold_claims')
                ->where('id', $claim->id)
                ->update([
                    'state' => 'released',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->holds->release(
                $wallet,
                Money::of($amount, $wallet->currency),
            );

            $this->recordAudit($locked, sprintf('Released (%s)', $evidence['released']['reason']), RiskLevel::High);

            return $locked;
        });
    }

    /**
     * @return array{hold: FinancialHold, conversion_reference: string}
     *
     * @throws FinancialHoldException
     */
    public function convert(FinancialHold $hold, int $operatorUserId, string $evidence): array
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $evidence): array {
            [$wallet, $locked] = $this->lockWalletAndHold($hold);
            $claim = $this->ownership->claim($locked, $wallet);

            if ($claim->state === 'consumed') {
                $tx = $this->ownership->assertDebit($locked, $wallet);

                return [
                    'hold' => $locked,
                    'conversion_reference' => (string) $tx->reference_number,
                ];
            }

            $this->ownership->assertState($locked, $wallet, 'active');

            if ($locked->status !== FinancialHoldStatus::Reviewed || $locked->reviewed_at === null) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            $canonicalKey = $this->ownership->canonicalKey($locked);
            if (FinancialTransaction::withTrashed()->where('idempotency_key', $canonicalKey)->lockForUpdate()->exists()) {
                $this->ownership->refuse();
            }

            $amount = $this->ownership->amount($locked);
            $money = Money::of($amount, $wallet->currency);

            $locked->status = FinancialHoldStatus::Converted;
            $locked->converted_at = now();
            $locked->save();

            DB::table('financial_hold_claims')
                ->where('id', $claim->id)
                ->update([
                    'state' => 'consumed',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->holds->consume($wallet, $money);

            $transaction = $this->wallets->debit(
                $wallet,
                $money,
                FinancialTransactionType::Adjustment,
                idempotencyKey: $canonicalKey,
                options: [
                    'reference_type' => FinancialHold::class,
                    'reference_id' => (int) $locked->id,
                    'description' => sprintf('Hold converted: [%s] (by #%d)', $locked->source_reference, $operatorUserId),
                    'metadata' => ['evidence' => $evidence, 'source' => $locked->source_reference],
                ],
            );

            $ev = is_array($locked->evidence) ? $locked->evidence : [];
            $ev['converted'] = [
                'at' => now()->toIso8601String(),
                'by' => $operatorUserId,
                'evidence' => Str::limit(trim($evidence), 255, ''),
                'reference' => (string) $transaction->reference_number,
            ];

            $locked->evidence = $ev;
            $locked->save();

            DB::table('financial_hold_claims')
                ->where('id', $claim->id)
                ->update([
                    'financial_transaction_id' => (int) $transaction->id,
                    'updated_at' => now(),
                ]);

            $reloaded = $this->lockHoldRow((int) $locked->id);
            $this->ownership->assertDebit($reloaded, $wallet);

            $this->recordAudit($reloaded, sprintf('Converted owned hold → %s', $ev['converted']['reference']), RiskLevel::Critical);

            return ['hold' => $reloaded, 'conversion_reference' => $ev['converted']['reference']];
        });
    }

    /**
     * @throws FinancialHoldException
     */
    public function expire(FinancialHold $hold): FinancialHold
    {
        return DB::transaction(function () use ($hold): FinancialHold {
            [$wallet, $locked] = $this->lockWalletAndHold($hold);
            $claim = $this->ownership->claim($locked, $wallet);

            if ($claim->state === 'expired') {
                $this->ownership->assertState($locked, $wallet, 'expired');

                return $locked;
            }

            $this->ownership->assertState($locked, $wallet, 'active');
            app(WithdrawalHoldFloor::class)->assertCovered($wallet);

            if ($locked->status !== FinancialHoldStatus::Reviewed
                || $locked->reviewed_at === null
                || $locked->expires_at === null
                || $locked->expires_at->isFuture()) {
                throw FinancialHoldException::invalidRelease((string) $locked->hold_key, $locked->status->value);
            }

            $amount = $this->ownership->amount($locked);
            $ev = is_array($locked->evidence) ? $locked->evidence : [];
            $ev['expired'] = ['at' => now()->toIso8601String(), 'horizon' => $locked->expires_at?->toIso8601String()];

            $locked->status = FinancialHoldStatus::Expired;
            $locked->expired_at = now();
            $locked->evidence = $ev;
            $locked->save();

            DB::table('financial_hold_claims')
                ->where('id', $claim->id)
                ->update([
                    'state' => 'expired',
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]);

            $this->holds->release($wallet, Money::of($amount, $wallet->currency));

            $this->recordAudit($locked, sprintf('Horizon passed (horizon %s)', (string) $ev['expired']['horizon']), RiskLevel::High);

            return $locked;
        });
    }

    public static function moneyOf(string $amount): string
    {
        if (! extension_loaded('bcmath')) {
            throw new \RuntimeException('BCMath is required for exact monetary arithmetic.');
        }

        return bcadd($amount, '0', 2);
    }

    /**
     * @return array{0: Wallet, 1: FinancialHold}
     */
    private function lockWalletAndHold(FinancialHold $hold): array
    {
        $walletId = (int) (FinancialHold::query()->useWritePdo()->whereKey($hold->getKey())->value('wallet_id') ?? $hold->wallet_id);
        if ($walletId < 1) {
            throw FinancialHoldException::notFound((string) $hold->hold_key);
        }

        $walletProbe = Wallet::withTrashed()->whereKey($walletId)->lockForUpdate()->first();
        if (! $walletProbe instanceof Wallet || $walletProbe->trashed()) {
            throw FinancialHoldException::notFound('wallet:'.$walletId);
        }

        $wallet = $this->locks->lock($walletId);
        $locked = $this->lockHoldRow((int) $hold->getKey());

        return [$wallet, $locked];
    }

    private function lockHoldRow(int $id): FinancialHold
    {
        $locked = FinancialHold::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->selectRaw('financial_holds.*, CAST(amount AS CHAR) AS exact_amount')
            ->first();

        if (! $locked instanceof FinancialHold) {
            throw FinancialHoldException::notFound('hold:'.$id);
        }

        return $locked;
    }

    private function recordAudit(FinancialHold $hold, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => FinancialHold::class,
            'auditable_id' => (int) $hold->getKey(),
            'description' => sprintf('%s (hold %s...)', $description, substr((string) $hold->hold_key, 0, 12)),
            'metadata' => [
                'hold_key' => (string) $hold->hold_key,
                'wallet_id' => (int) $hold->wallet_id,
                'source_reference' => (string) $hold->source_reference,
                'lane' => 'financial-hold',
            ],
        ]);

        $log->save();
    }
}
