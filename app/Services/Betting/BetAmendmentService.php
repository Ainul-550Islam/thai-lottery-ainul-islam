<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetAmendmentResult;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\BetAmendmentStatus;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Exceptions\BetAmendmentException;
use App\Exceptions\BetCancellationException;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Exceptions\InvalidBetAmendmentException;
use App\Exceptions\RiskException;
use App\Models\Bet;
use App\Models\BetAmendment;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\WalletLockService;
use App\Services\Risk\NumberNormalizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bet amendment = refund-and-replace, composed out of the audited primitives.
 */
class BetAmendmentService
{
    public function __construct(
        private readonly BetCancellationService $cancellations,
        private readonly BetPurchaseValidator $validator,
        private readonly BetPurchaseIdempotencyService $purchaseIdempotency,
        private readonly BetAmendmentOwnership $ownership,
        private readonly NumberNormalizationService $numbers,
    ) {}

    /**
     * Apply an amendment: cancel+refund the original, then purchase the replacement.
     *
     * @throws BetAmendmentException
     * @throws InvalidBetAmendmentException
     * @throws BetCancellationException
     */
    public function amend(BetAmendmentData $data): BetAmendmentResult
    {
        if (DB::transactionLevel() > 0) {
            $this->ownership->refuse();
        }

        if (! $data->changesSomething()) {
            throw InvalidBetAmendmentException::nothingToChange(0, ['bet' => $data->betIdentifier]);
        }

        $clientKey = $this->purchaseIdempotency->assertClientKeyUsable($data->clientKey);
        $scopedKey = 'amend-v1-'.$data->userId.'-'.hash('sha256', $clientKey);

        $hintBet = $this->resolveBet($data, false);
        $hintClaim = DB::table('bet_purchase_ownerships')->where('bet_id', $hintBet->getKey())->first();

        if (! $hintClaim) {
            $this->ownership->refuse();
        }

        $hintWalletIds = [(int) $hintClaim->wallet_id];
        foreach (DB::table('agent_commission_payouts as p')
            ->join('bet_commission_ownerships as c', 'c.id', '=', 'p.source_ownership_id')
            ->where('c.purchase_ownership_id', $hintClaim->id)
            ->orderBy('p.id')
            ->pluck('p.wallet_id') as $payoutWalletId) {
            $hintWalletIds[] = (int) $payoutWalletId;
        }
        $hintWalletIds = array_values(array_unique(array_filter($hintWalletIds)));
        sort($hintWalletIds, SORT_NUMERIC);

        $leg1 = DB::transaction(function () use ($data, $clientKey, $scopedKey, $hintBet, $hintClaim, $hintWalletIds): array {
            $lockedWallets = app(WalletLockService::class)->lockMany($hintWalletIds);
            $wallet = $lockedWallets[(int) $hintClaim->wallet_id] ?? app(WalletLockService::class)->lock((int) $hintClaim->wallet_id);
            $bet = $this->resolveBet($data, true);

            if ((int) $bet->getKey() !== (int) $hintBet->getKey()) {
                $this->ownership->refuse();
            }

            $existingOwner = DB::table('bet_amendment_ownerships')
                ->where('purchase_ownership_id', $hintClaim->id)
                ->lockForUpdate()
                ->first();

            $existingAmendments = $this->ownership->rows()
                ->where(static function ($q) use ($bet, $scopedKey, $clientKey): void {
                    $q->where('bet_id', $bet->getKey())
                        ->orWhereIn('idempotency_key', [$scopedKey, $clientKey]);
                })
                ->lockForUpdate()
                ->get();

            if ($existingOwner !== null || $existingAmendments->isNotEmpty()) {
                if ($existingOwner === null || $existingAmendments->count() !== 1) {
                    $this->ownership->refuse();
                }

                /** @var BetAmendment $existingAmendment */
                $existingAmendment = $existingAmendments->first();

                if ((int) $existingAmendment->getKey() !== (int) $existingOwner->amendment_id
                    || (int) $existingOwner->user_id !== $data->userId
                    || (int) $existingAmendment->bet_id !== (int) $bet->getKey()
                    || $existingAmendment->idempotency_key !== $scopedKey) {
                    $this->ownership->refuse();
                }

                $candidateNumber = $this->normaliseReplacementNumber(
                    $data->newNumber ?? (string) $existingOwner->old_number,
                    (string) $existingOwner->market,
                    $bet->type,
                );
                $candidateStake = $this->normaliseReplacementStake(
                    $data->newStake ?? (string) $existingOwner->old_stake,
                );

                if ($candidateStake === null
                    || ! hash_equals(
                        $existingOwner->request_hash,
                        $this->ownership->requestHash($data->userId, (int) $bet->getKey(), $candidateNumber, $candidateStake),
                    )) {
                    throw BetAmendmentException::withCode(
                        'bet_amendment_request_conflict',
                        'The amendment idempotency key is already bound to a different replacement request.',
                    );
                }

                $replacementBet = $this->ownership->terminal($existingOwner, $existingAmendment, $wallet);

                return [
                    'replay' => new BetAmendmentResult(
                        amendment: $existingAmendment,
                        originalBet: $bet,
                        replacementBet: $replacementBet,
                        status: $existingAmendment->status,
                        refundedAmount: (string) $existingOwner->old_stake,
                        chargedAmount: $replacementBet instanceof Bet ? (string) $existingOwner->new_stake : null,
                        currency: (string) $existingOwner->currency,
                        failureReason: $existingAmendment->failure_reason,
                    ),
                ];
            }

            $this->assertEnabled();
            $this->assertAmendable($bet);

            $market = $this->marketOf($bet);
            [, $oldStake, , $item] = app(BetPurchaseOwnership::class)->terms($bet);
            $oldNumber = (string) $item->number;
            $rawNewNumber = $data->newNumber ?? $oldNumber;
            $rawNewStake = $data->newStake ?? $oldStake;

            $canonicalCandidateNumber = $this->normaliseReplacementNumber($rawNewNumber, $market, $bet->type);
            $normalisedCandidateStake = $this->normaliseReplacementStake($rawNewStake);

            if ($canonicalCandidateNumber === $oldNumber
                && $normalisedCandidateStake !== null
                && bccomp($normalisedCandidateStake, $oldStake, 2) === 0) {
                throw InvalidBetAmendmentException::nothingToChange((int) $bet->getKey(), ['bet' => $data->betIdentifier]);
            }

            $cancellation = $this->cancellations->cancel(
                new BetCancellationData(
                    userId: $data->userId,
                    betIdentifier: (string) $bet->getKey(),
                    reason: BetCancellationReason::Amendment,
                    clientKey: $clientKey.':cancel',
                ),
                null,
                true,
            );

            $replacementClientKey = app(IdempotencyService::class)->deterministicKey(
                'amendreplace',
                $data->userId.'|'.$bet->getKey().'|'.$clientKey,
            );

            $purchaseData = new BetPurchaseData(
                userId: $data->userId,
                drawId: (int) $bet->draw_id,
                marketKey: $market,
                rawNumber: $rawNewNumber,
                rawStake: $rawNewStake,
                idempotencyKey: $replacementClientKey,
                metadata: array_filter([
                    'channel' => $data->metadata['channel'] ?? null,
                ]),
            );

            $context = $this->validator->validate($purchaseData);
            $newNumber = $context->canonicalNumber();
            $newStake = $context->stake->toString();

            $replacementBetKey = $this->purchaseIdempotency->betKeyFor($purchaseData);
            $replacementFinancialKey = $this->purchaseIdempotency->financialKeyFor($purchaseData);

            $purchaseClaim = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->getKey())->lockForUpdate()->first();

            if (! $purchaseClaim) {
                $this->ownership->refuse();
            }

            $amendment = BetAmendment::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $data->userId,
                'bet_id' => (int) $bet->getKey(),
                'status' => BetAmendmentStatus::Pending,
                'currency' => $this->currencyValue($bet),
                'old_number' => $oldNumber,
                'old_stake' => $oldStake,
                'new_number' => $newNumber,
                'new_stake' => $newStake,
                'refunded_amount' => $oldStake,
                'charged_amount' => null,
                'idempotency_key' => $scopedKey,
                'expires_at' => null,
                'metadata' => array_filter([
                    'market' => $market,
                    'channel' => $data->metadata['channel'] ?? null,
                    'ignored_client_fields' => $data->ignoredClientFields !== [] ? $data->ignoredClientFields : null,
                ]),
            ]);

            $sourceHash = $this->ownership->sourceHash($purchaseClaim);
            $requestHash = $this->ownership->requestHash($data->userId, (int) $bet->getKey(), $newNumber, $newStake);

            $ownerRow = [
                'amendment_id' => (int) $amendment->getKey(),
                'purchase_ownership_id' => (int) $purchaseClaim->id,
                'wallet_id' => (int) $purchaseClaim->wallet_id,
                'user_id' => $data->userId,
                'draw_id' => (int) $bet->draw_id,
                'currency' => (string) $purchaseClaim->currency,
                'market' => $market,
                'old_number' => $oldNumber,
                'old_stake' => $oldStake,
                'new_number' => $newNumber,
                'new_stake' => $newStake,
                'source_hash' => $sourceHash,
                'request_hash' => $requestHash,
                'replacement_client_key' => $replacementClientKey,
                'replacement_bet_key' => $replacementBetKey,
                'replacement_financial_key' => $replacementFinancialKey,
                'refund_transaction_id' => (int) $cancellation->refund->getKey(),
                'state' => 'attempting',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $ownerRow['terms_hash'] = $this->ownership->termsHash((object) $ownerRow, $amendment);
            DB::table('bet_amendment_ownerships')->insert($ownerRow);

            $permit = BetAmendmentPermit::forFreshClaim($amendment);

            return [
                'replay' => null,
                'amendment' => $amendment,
                'bet' => $bet,
                'wallet_id' => (int) $purchaseClaim->wallet_id,
                'purchaseData' => $purchaseData,
                'permit' => $permit,
            ];
        }, 3);

        if ($leg1['replay'] instanceof BetAmendmentResult) {
            return $leg1['replay'];
        }

        /** @var BetAmendment $amendment */
        $amendment = $leg1['amendment'];
        /** @var Bet $bet */
        $bet = $leg1['bet'];
        $walletId = (int) $leg1['wallet_id'];
        /** @var BetPurchaseData $purchaseData */
        $purchaseData = $leg1['purchaseData'];
        /** @var BetAmendmentPermit $permit */
        $permit = $leg1['permit'];

        try {
            app(BetAmendmentReplacement::class)->purchaseAmendment($purchaseData, $permit);
        } catch (\Throwable $failure) {
            return DB::transaction(function () use ($amendment, $bet, $walletId, $failure): BetAmendmentResult {
                $wallet = app(WalletLockService::class)->lock($walletId);
                $o = DB::table('bet_amendment_ownerships')->where('amendment_id', $amendment->getKey())->lockForUpdate()->first();
                $a = $this->ownership->rows()->whereKey($amendment->getKey())->lockForUpdate()->first();

                if (! $o || ! $a) {
                    $this->ownership->refuse();
                }

                // If the replacement actually committed before the exception was thrown, recover the applied receipt.
                if ($o->state === 'applied') {
                    $replacementBet = $this->ownership->terminal($o, $a, $wallet);

                    return new BetAmendmentResult(
                        amendment: $a,
                        originalBet: $bet->refresh(),
                        replacementBet: $replacementBet,
                        status: $a->status,
                        refundedAmount: (string) $o->old_stake,
                        chargedAmount: (string) $o->new_stake,
                        currency: (string) $o->currency,
                        failureReason: null,
                    );
                }

                if (! ($failure instanceof BetDomainException || $failure instanceof RiskException || $failure instanceof FinancialException)) {
                    $this->ownership->refuse();
                }

                $this->ownership->check($o, $a);
                $this->ownership->original($o, $a, $wallet);
                $this->ownership->assertNoReplacement($o);

                $failureCode = method_exists($failure, 'errorCode') && $failure->errorCode() !== ''
                    ? (string) $failure->errorCode()
                    : 'bet_purchase_refused';
                $stamp = now()->toDateTimeString();

                DB::table('bet_amendment_ownerships')->where('id', $o->id)->update([
                    'state' => 'failed',
                    'failure_code' => $failureCode,
                    'completed_at' => $stamp,
                    'updated_at' => now(),
                ]);

                $a->forceFill([
                    'status' => BetAmendmentStatus::Failed,
                    'failure_reason' => $failureCode,
                ])->save();

                $freshOwner = DB::table('bet_amendment_ownerships')->where('id', $o->id)->lockForUpdate()->first();
                $freshAmendment = $this->ownership->rows()->whereKey($a->getKey())->lockForUpdate()->first();
                $this->ownership->terminal($freshOwner, $freshAmendment, $wallet);

                return new BetAmendmentResult(
                    amendment: $freshAmendment,
                    originalBet: $bet->refresh(),
                    replacementBet: null,
                    status: BetAmendmentStatus::Failed,
                    refundedAmount: (string) $o->old_stake,
                    chargedAmount: null,
                    currency: (string) $o->currency,
                    failureReason: $failureCode,
                );
            }, 3);
        }

        return DB::transaction(function () use ($amendment, $bet, $walletId): BetAmendmentResult {
            $wallet = app(WalletLockService::class)->lock($walletId);
            $o = DB::table('bet_amendment_ownerships')->where('amendment_id', $amendment->getKey())->lockForUpdate()->first();
            $a = $this->ownership->rows()->whereKey($amendment->getKey())->lockForUpdate()->first();

            if (! $o || ! $a) {
                $this->ownership->refuse();
            }

            $replacementBet = $this->ownership->terminal($o, $a, $wallet);

            return new BetAmendmentResult(
                amendment: $a,
                originalBet: $bet->refresh(),
                replacementBet: $replacementBet,
                status: $a->status,
                refundedAmount: (string) $o->old_stake,
                chargedAmount: (string) $o->new_stake,
                currency: (string) $o->currency,
                failureReason: null,
            );
        }, 3);
    }

    /**
     * Backfill stored Expired on pending rows past their expiry moment, excluding owned attempts.
     */
    public function sweepExpired(): int
    {
        return BetAmendment::query()
            ->expiredPending()
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('bet_amendment_ownerships')
                    ->whereColumn('bet_amendment_ownerships.amendment_id', 'bet_amendments.id');
            })
            ->update(['status' => BetAmendmentStatus::Expired->value]);
    }

    private function resolveBet(BetAmendmentData $data, bool $lock): Bet
    {
        $identifier = $data->betIdentifier;

        $query = Bet::query()->where('user_id', $data->userId);
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('bet_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        if ($lock) {
            $query->lockForUpdate();
        }

        /** @var Bet|null $bet */
        $bet = $query->first();

        if ($bet === null) {
            throw BetAmendmentException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertAmendable(Bet $bet): void
    {
        if ($bet->status === BetStatus::Cancelled) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already cancelled');
        }

        if (! $bet->status->canCancel()) {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the bet is already '.$bet->status->value);
        }

        $window = (int) config('lottery.amendment.window_minutes', 60);
        if ($window > 0) {
            $placedAt = $bet->placed_at ?? $bet->created_at;
            if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
                throw BetAmendmentException::windowExpired((int) $bet->getKey(), $window);
            }
        }
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.amendment.enabled', true)) {
            throw BetAmendmentException::disabled();
        }
    }

    private function marketOf(Bet $bet): string
    {
        $market = $bet->metadata['market'] ?? null;

        if (! is_string($market) || $market === '') {
            throw BetAmendmentException::notAmendable((int) $bet->getKey(), 'the original market could not be determined');
        }

        return $market;
    }

    private function normaliseReplacementNumber(string $rawNumber, string $market, BetType $fallbackBetType): string
    {
        $mappedType = config("lottery.markets.{$market}.bet_type");
        $betType = is_string($mappedType) && BetType::tryFrom($mappedType) instanceof BetType
            ? BetType::from($mappedType)
            : $fallbackBetType;

        return $this->numbers->tryNormalize($rawNumber, $betType) ?? trim($rawNumber);
    }

    private function normaliseReplacementStake(string $rawStake): ?string
    {
        $trimmed = trim($rawStake);

        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $trimmed) !== 1 || bccomp($trimmed, '0', 2) <= 0) {
            return null;
        }

        return bcadd($trimmed, '0', 2);
    }

    private function currencyValue(Bet $bet): string
    {
        return $bet->currency instanceof Currency ? $bet->currency->value : (string) $bet->currency;
    }
}
