<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\AuditAction;
use App\Enums\FinancialHoldStatus;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\WalletHoldType;
use App\Exceptions\FinancialHoldException;
use App\Models\AuditLog;
use App\Models\FinancialHold;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local holds own durable claims. Every mutation locks WALLET before its hold and claim. */
final class FinancialHoldService
{
    public const DEFAULT_HORIZON_HOURS = 72;

    public function __construct(
        private readonly WalletHoldService $holds,
        private readonly WalletService $wallets,
    ) {}

    /** @return array{hold: FinancialHold, replayed: bool} */
    public function place(FinancialHoldData $data): array
    {
        $data = FinancialHoldData::fromInput($data->walletId, $data->amount, $data->currency,
            $data->reason, $data->sourceReference, $data->expiresAt);

        // A savepoint is required even when the caller catches an audit failure.
        return DB::transaction(function () use ($data): array {
            $wallet = app(WalletLockService::class)->lock($data->walletId);
            $owner = app(FinancialHoldOwnership::class);
            app(WithdrawalHoldFloor::class)->assertCovered($wallet);
            if ($wallet->currency->value !== $data->currency) {
                $owner->refuse();
            }
            $row = FinancialHold::query()->where('wallet_id', $wallet->id)
                ->where('source_reference', $data->sourceReference)->lockForUpdate()
                ->selectRaw('financial_holds.*, CAST(amount AS CHAR) AS exact_amount')->first();
            if ($row !== null) {
                $claim = $owner->claim($row, $wallet);
                if (! hash_equals($claim->input_hash, $owner->inputHash($data))) {
                    $owner->refuse();
                }
                $this->assertOriginalState($row, $wallet);

                return ['hold' => $row, 'replayed' => true];
            }
            if (DB::table('financial_hold_claims')->where('wallet_id', $wallet->id)
                ->where('source_reference', $data->sourceReference)->lockForUpdate()->exists()) {
                $owner->refuse();
            }
            $this->holds->hold($wallet, Money::of($data->amount, $wallet->currency), WalletHoldType::OtherFinancialHold);
            $row = new FinancialHold;
            $row->fill([
                'hold_key' => $data->holdKey(), 'wallet_id' => $wallet->id,
                'amount' => $data->amount, 'currency' => $data->currency,
                'reason' => $data->reason, 'source_reference' => $data->sourceReference,
                'expires_at' => $data->expiresAt ?? now()->addHours(self::DEFAULT_HORIZON_HOURS),
                'evidence' => ['placed' => now()->toIso8601String(), 'reason' => $data->reason],
                'metadata' => [],
            ]);
            $row->status = FinancialHoldStatus::Active;
            $row->save();
            $row = $this->readHold((int) $row->id);
            DB::table('financial_hold_claims')->insert([
                'financial_hold_id' => $row->id, 'wallet_id' => $wallet->id, 'user_id' => $wallet->user_id,
                'amount' => $data->amount, 'currency' => $data->currency, 'source_reference' => $data->sourceReference,
                'terms_hash' => $owner->termsHash($row, $wallet), 'input_hash' => $owner->inputHash($data),
                'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            app(WithdrawalHoldFloor::class)->assertCovered($wallet->fresh());
            $this->recordAudit($row, 'Placed local financial hold.', RiskLevel::High);

            return ['hold' => $row, 'replayed' => false];
        });
    }

    public function review(FinancialHold $hold, int $operatorUserId, string $note): FinancialHold
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $note): FinancialHold {
            [$row, $wallet] = $this->lockOriginal($hold);
            $this->assertOriginalState($row, $wallet);
            if ($row->status === FinancialHoldStatus::Reviewed) {
                return $row;
            }
            if ($row->status !== FinancialHoldStatus::Active) {
                throw FinancialHoldException::invalidRelease($row->hold_key, $row->status->value);
            }
            $evidence = $row->evidence ?? [];
            $evidence['reviewed'] = ['at' => now()->toIso8601String(), 'by' => $operatorUserId,
                'note' => Str::limit(trim($note), 255, '')];
            $row->forceFill(['status' => FinancialHoldStatus::Reviewed, 'reviewed_at' => now(), 'evidence' => $evidence])->save();
            $this->recordAudit($row, 'Reviewed local financial hold.', RiskLevel::High);

            return $row;
        });
    }

    public function release(FinancialHold $hold, string $reason): FinancialHold
    {
        return $this->closeWithoutDebit($hold, 'released', $reason);
    }

    public function expire(FinancialHold $hold): FinancialHold
    {
        return $this->closeWithoutDebit($hold, 'expired', 'Reviewed local hold horizon reached.');
    }

    private function closeWithoutDebit(FinancialHold $hold, string $state, string $reason): FinancialHold
    {
        return DB::transaction(function () use ($hold, $state, $reason): FinancialHold {
            [$row, $wallet] = $this->lockOriginal($hold);
            $this->assertOriginalState($row, $wallet);
            $target = $state === 'released' ? FinancialHoldStatus::Released : FinancialHoldStatus::Expired;
            if ($row->status === $target) {
                return $row;
            }
            if (! $row->status->occupiesWallet()
                || ($state === 'expired' && ($row->status !== FinancialHoldStatus::Reviewed
                    || $row->expires_at === null || $row->expires_at->isFuture()))) {
                throw FinancialHoldException::invalidRelease($row->hold_key, $row->status->value);
            }
            $ev = $row->evidence ?? [];
            $ev[$state] = ['at' => now()->toIso8601String(), 'reason' => Str::limit(trim($reason), 255, '')];
            $timestamp = $state === 'released' ? 'released_at' : 'expired_at';
            // Close only this proved owner before delegating mechanics. Other floors remain live.
            $row->forceFill(['status' => $target, $timestamp => now(), 'evidence' => $ev])->save();
            DB::table('financial_hold_claims')->where('financial_hold_id', $row->id)
                ->update(['state' => $state, 'closed_at' => now(), 'updated_at' => now()]);
            $this->holds->release($wallet, Money::of(app(FinancialHoldOwnership::class)->amount($row), $wallet->currency));
            app(FinancialHoldOwnership::class)->assertState($row, $wallet, $state);
            $this->recordAudit($row, 'Closed local financial hold: '.$state.'.', RiskLevel::High);

            return $row;
        });
    }

    /** @return array{hold: FinancialHold, conversion_reference: string} */
    public function convert(FinancialHold $hold, int $operatorUserId, string $evidence): array
    {
        return DB::transaction(function () use ($hold, $operatorUserId, $evidence): array {
            [$row, $wallet] = $this->lockOriginal($hold);
            $owner = app(FinancialHoldOwnership::class);
            $this->assertOriginalState($row, $wallet);
            if ($row->status === FinancialHoldStatus::Converted) {
                return ['hold' => $row, 'conversion_reference' => $owner->assertDebit($row, $wallet)->reference_number];
            }
            if ($row->status !== FinancialHoldStatus::Reviewed) {
                throw FinancialHoldException::invalidRelease($row->hold_key, $row->status->value);
            }
            $key = $owner->canonicalKey($row);
            if (FinancialTransaction::withTrashed()->where('idempotency_key', $key)->lockForUpdate()->exists()) {
                $owner->refuse();
            }
            $amount = Money::of($owner->amount($row), $wallet->currency);
            $row->forceFill(['status' => FinancialHoldStatus::Converted, 'converted_at' => now()])->save();
            DB::table('financial_hold_claims')->where('financial_hold_id', $row->id)
                ->update(['state' => 'consumed', 'closed_at' => now(), 'updated_at' => now()]);
            $this->holds->consume($wallet, $amount);
            $transaction = $this->wallets->debit($wallet, $amount, FinancialTransactionType::Adjustment, $key, [
                'description' => 'Reviewed local financial hold conversion.',
                'reference_type' => FinancialHold::class, 'reference_id' => (int) $row->id,
                'metadata' => ['financial_hold_id' => (int) $row->id, 'reviewed_by' => $operatorUserId],
            ]);
            app(WithdrawalHoldFloor::class)->assertCovered($wallet->fresh());
            DB::table('financial_hold_claims')->where('financial_hold_id', $row->id)
                ->update(['financial_transaction_id' => $transaction->id, 'updated_at' => now()]);
            $ev = $row->evidence ?? [];
            $ev['converted'] = ['at' => now()->toIso8601String(), 'by' => $operatorUserId,
                'evidence' => Str::limit(trim($evidence), 255, ''), 'reference' => $transaction->reference_number];
            $row->forceFill(['evidence' => $ev])->save();
            $owner->assertDebit($row, $wallet);
            $this->recordAudit($row, 'Converted owned local financial hold.', RiskLevel::Critical);

            return ['hold' => $row, 'conversion_reference' => $transaction->reference_number];
        });
    }

    /** @return array{FinancialHold, Wallet} */
    private function lockOriginal(FinancialHold $presented): array
    {
        $walletId = FinancialHold::query()->useWritePdo()->whereKey($presented->id)->value('wallet_id');
        if ($walletId === null) {
            throw FinancialHoldException::notFound($presented->hold_key);
        }
        $wallet = app(WalletLockService::class)->lock((int) $walletId);
        $row = $this->readHold((int) $presented->id);
        $this->assertOriginalState($row, $wallet);
        app(WithdrawalHoldFloor::class)->assertCovered($wallet);

        return [$row, $wallet];
    }

    private function readHold(int $id): FinancialHold
    {
        return FinancialHold::query()->whereKey($id)->lockForUpdate()
            ->selectRaw('financial_holds.*, CAST(amount AS CHAR) AS exact_amount')->firstOrFail();
    }

    private function assertOriginalState(FinancialHold $row, Wallet $wallet): void
    {
        $owner = app(FinancialHoldOwnership::class);
        if ($row->status === FinancialHoldStatus::Converted) {
            $owner->assertDebit($row, $wallet);

            return;
        }
        $owner->assertState($row, $wallet, match ($row->status) {
            FinancialHoldStatus::Active, FinancialHoldStatus::Reviewed => 'active',
            FinancialHoldStatus::Released => 'released', FinancialHoldStatus::Expired => 'expired',
            default => 'invalid',
        });
    }

    public static function moneyOf(string $amount): string
    {
        Money::assertExactArithmeticIsAvailable();

        return bcadd($amount, '0', 2);
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
