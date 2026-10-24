<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BetCancellationResult;
use App\Enums\AuditAction;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\FinancialReferenceType;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Exceptions\BetAlreadySettledException;
use App\Exceptions\BetCancellationException;
use App\Exceptions\BetCancellationWindowExpiredException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\NumberLimit;
use App\Models\Ticket;
use App\Models\Wallet;
use App\Services\Agent\BetCommissionCancellation;
use App\Services\Draw\DrawCancellationService;
use App\Services\Finance\IdempotencyService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Risk\BetRiskOwnershipFloor;
use App\Services\Risk\RiskAssessmentService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;

/**
 * Player-facing bet cancellation with a full, immediate refund.
 */
class BetCancellationService
{
    private const REFUND_KEY_FORMAT = 'bet-refund-%012d';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly WalletService $wallets,
        private readonly RiskAssessmentService $risk,
    ) {}

    public function refundKeyFor(int $betId): string
    {
        return sprintf(self::REFUND_KEY_FORMAT, $betId);
    }

    /**
     * @param  array<int, Wallet>  $lockedWallets
     */
    public function proveOwnedCancellation(int $betId, array $lockedWallets = []): BetCancellationResult
    {
        return $this->database->transaction(function () use ($betId, $lockedWallets): BetCancellationResult {
            if ($lockedWallets === []) {
                $hintIds = $this->walletIdsForBet($betId, false);
                $lockedWallets = $hintIds === [] ? [] : app(WalletLockService::class)->lockMany($hintIds);
            }

            $bet = Bet::withTrashed()->whereKey($betId)->lockForUpdate()->first();

            if (! $bet instanceof Bet || $bet->trashed()) {
                app(BetAccountingProof::class)->refuse();
            }

            $claimRow = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->getKey())->lockForUpdate()->first();

            if (! $claimRow) {
                app(BetAccountingProof::class)->refuse();
            }

            $walletId = (int) $claimRow->wallet_id;
            $wallet = $lockedWallets[$walletId] ?? app(WalletLockService::class)->lock($walletId);
            $lockedWallets[$walletId] = $wallet;

            $purchase = app(BetPurchaseOwnership::class)->locked($bet, $wallet);
            $reason = BetCancellationReason::tryFrom((string) $bet->cancelled_reason);

            if ($bet->status !== BetStatus::Cancelled
                || $purchase->state !== 'cancelled'
                || $purchase->refund_transaction_id === null
                || ! is_string($purchase->refund_hash ?? null)
                || $purchase->refund_hash === ''
                || ! $bet->cancelled_at
                || ! $purchase->cancelled_at
                || $bet->cancelled_at->toDateTimeString() !== (string) $purchase->cancelled_at
                || ! $reason instanceof BetCancellationReason
                || $purchase->cancelled_reason !== $reason->value) {
                app(BetAccountingProof::class)->refuse();
            }

            [$refund, $refundHash] = app(BetAccountingProof::class)->transaction(
                (int) $purchase->refund_transaction_id,
                $bet,
                $wallet,
                true,
            );

            $expectedRefundKey = app(IdempotencyService::class)->normaliseKey($this->refundKeyFor((int) $bet->getKey()));

            if (! hash_equals($purchase->refund_hash, $refundHash)
                || $refund->idempotency_key !== $expectedRefundKey
                || (int) ($refund->metadata['purchase_ownership_id'] ?? 0) !== (int) $purchase->id
                || (int) ($refund->metadata['original_transaction_id'] ?? 0) !== (int) $purchase->original_transaction_id) {
                app(BetAccountingProof::class)->refuse();
            }

            app(BetCommissionCancellation::class)->assertCancelled($bet, $purchase, $lockedWallets);

            return new BetCancellationResult(
                bet: $bet,
                ticket: $bet->ticket()->first(),
                refund: $refund,
                reason: $reason,
                refundAmount: (string) $purchase->stake_amount,
                currency: $wallet->currency->value,
                replayed: true,
            );
        }, 3);
    }

    /**
     * Cancel a bet and refund its stake in full.
     *
     * @throws BetCancellationException
     * @throws BetAlreadySettledException
     * @throws BetCancellationWindowExpiredException
     */
    public function cancel(
        BetCancellationData $data,
        ?DrawCancellationService $drawCoordinator = null,
        bool $amendmentDispatch = false,
    ): BetCancellationResult {
        if ($drawCoordinator === null) {
            $this->assertEnabled();

            if (! $data->reason->isPlayerInitiated()
                || ($data->reason === BetCancellationReason::Amendment && ! $amendmentDispatch)) {
                throw BetCancellationException::withCode(
                    'bet_cancellation_reason_not_allowed',
                    'The requested cancellation reason is not permitted on this cancellation path.',
                );
            }
        }

        $hintBet = $this->resolveBet($data, false);
        $hintWalletIds = $this->walletIdsForBet((int) $hintBet->getKey(), false);

        return $this->database->transaction(function () use ($data, $drawCoordinator, $hintBet, $hintWalletIds): BetCancellationResult {
            $lockedWallets = $hintWalletIds === [] ? [] : app(WalletLockService::class)->lockMany($hintWalletIds);

            $bet = $this->resolveBet($data, true);

            if ((int) $bet->getKey() !== (int) $hintBet->getKey()) {
                app(BetAccountingProof::class)->refuse();
            }

            $claimRow = DB::table('bet_purchase_ownerships')->where('bet_id', $bet->getKey())->lockForUpdate()->first();

            if (! $claimRow) {
                app(BetAccountingProof::class)->refuse();
            }

            $walletId = (int) $claimRow->wallet_id;
            $wallet = $lockedWallets[$walletId] ?? app(WalletLockService::class)->lock($walletId);
            $lockedWallets[$walletId] = $wallet;

            $purchase = app(BetPurchaseOwnership::class)->locked($bet, $wallet);

            if ($bet->status === BetStatus::Cancelled || $purchase->state === 'cancelled') {
                return $this->proveOwnedCancellation((int) $bet->getKey(), $lockedWallets);
            }

            $this->assertNotSettled($bet);
            $this->assertCancellableStatus($bet);

            $drawCancellationId = null;

            if ($drawCoordinator !== null) {
                $drawCancellationId = $drawCoordinator->assertBetPermit($bet, $data);
            } else {
                $this->assertWithinWindow($bet);
                $this->assertDrawAcceptsCancellation($bet);
            }

            [, $stake, , $item] = app(BetPurchaseOwnership::class)->terms($bet);
            $limit = NumberLimit::query()->whereKey((int) $purchase->number_limit_id)->lockForUpdate()->first();

            if (! $limit instanceof NumberLimit
                || (int) $limit->draw_id !== (int) $bet->draw_id
                || $limit->bet_type !== $bet->type
                || $limit->number !== $item->number) {
                app(BetAccountingProof::class)->refuse();
            }

            app(BetRiskOwnershipFloor::class)->assertCovered($limit);

            $stamp = now();
            $stampString = $stamp->toDateTimeString();

            // 1. Cancel unpaid commissions & compensate policy-bound paid commissions under the locked wallets.
            app(BetCommissionCancellation::class)->cancelUnpaid(
                $bet,
                $purchase,
                $data->reason->value,
                $stampString,
                $lockedWallets,
                $wallet,
            );

            $refundKey = $this->refundKeyFor((int) $bet->getKey());

            if (app(IdempotencyService::class)->existingFor($refundKey) instanceof FinancialTransaction) {
                app(BetAccountingProof::class)->refuse();
            }

            // 2. Refund the stake to the original purchase wallet.
            $refund = $this->wallets->credit(
                $wallet,
                Money::fromDatabase($stake, $wallet->currency),
                FinancialTransactionType::BetRefund,
                $refundKey,
                [
                    'description' => sprintf('Refund for cancelled bet %s', (string) $bet->bet_number),
                    'reference_type' => Bet::class,
                    'reference_id' => (int) $bet->getKey(),
                    'metadata' => array_filter([
                        'purchase_ownership_id' => (int) $purchase->id,
                        'original_transaction_id' => (int) $purchase->original_transaction_id,
                        'draw_cancellation_id' => $drawCancellationId,
                    ], static fn (mixed $v): bool => $v !== null),
                ],
            );

            [, $refundHash] = app(BetAccountingProof::class)->transaction((int) $refund->getKey(), $bet, $wallet, true);

            // 3. Mark bet and purchase ownership cancelled before releasing risk capacity.
            $bet->forceFill([
                'status' => BetStatus::Cancelled,
                'cancelled_at' => $stamp,
                'cancelled_reason' => $data->reason->value,
            ]);
            $bet->save();

            DB::table('bet_purchase_ownerships')->where('id', $purchase->id)->update([
                'state' => 'cancelled',
                'refund_transaction_id' => (int) $refund->getKey(),
                'refund_hash' => $refundHash,
                'cancelled_at' => $stampString,
                'cancelled_reason' => $data->reason->value,
                'updated_at' => $stamp,
            ]);

            // 4. Release risk capacity and verify remaining active owners stay covered.
            $this->risk->releaseBet(
                (int) $bet->draw_id,
                $bet->type,
                [
                    [
                        'number' => (string) $item->number,
                        'stake' => $stake,
                        'multiplier' => (string) $item->payout_multiplier,
                    ],
                ],
            );

            // 5. Sync ticket status and record audit.
            $actorUserId = $drawCancellationId !== null
                ? ((int) DB::table('draw_cancellation_ownerships')->where('id', $drawCancellationId)->value('actor_user_id') ?: (int) $bet->user_id)
                : (int) $bet->user_id;
            $ticket = $this->syncTicket($bet, $stamp);
            $this->recordAudit($bet, $data->reason, $refund, $actorUserId);

            return new BetCancellationResult(
                bet: $bet->refresh(),
                ticket: $ticket,
                refund: $refund,
                reason: $data->reason,
                refundAmount: bcadd((string) $refund->amount, '0', 2),
                currency: $wallet->currency->value,
                replayed: false,
            );
        }, 3);
    }

    /**
     * @return list<int>
     */
    private function walletIdsForBet(int $betId, bool $lock): array
    {
        $claimQuery = DB::table('bet_purchase_ownerships')->where('bet_id', $betId)->select(['id', 'wallet_id']);

        if ($lock) {
            $claimQuery->lockForUpdate();
        }

        $claim = $claimQuery->first();

        if (! $claim) {
            return [];
        }

        $ids = [(int) $claim->wallet_id];

        $payoutQuery = DB::table('agent_commission_payouts as p')
            ->join('bet_commission_ownerships as c', 'c.id', '=', 'p.source_ownership_id')
            ->where('c.purchase_ownership_id', $claim->id)
            ->orderBy('p.id')
            ->select('p.wallet_id');

        if ($lock) {
            $payoutQuery->lockForUpdate();
        }

        foreach ($payoutQuery->get() as $row) {
            $ids[] = (int) $row->wallet_id;
        }

        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function resolveBet(BetCancellationData $data, bool $lock): Bet
    {
        $query = Bet::query()->where('user_id', $data->userId);

        $identifier = $data->betIdentifier;
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
            throw BetCancellationException::betUnavailable($identifier);
        }

        return $bet;
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.cancellation.enabled', true)) {
            throw BetCancellationException::disabled();
        }
    }

    private function assertNotSettled(Bet $bet): void
    {
        if (in_array($bet->status, [BetStatus::Won, BetStatus::Lost, BetStatus::Refunded], true)) {
            throw BetAlreadySettledException::forBet((int) $bet->getKey(), $bet->status->value);
        }
    }

    private function assertCancellableStatus(Bet $bet): void
    {
        if (! $bet->status->canCancel()) {
            throw BetCancellationException::notCancellable($bet->status->value, ['bet_id' => $bet->getKey()]);
        }
    }

    private function assertWithinWindow(Bet $bet): void
    {
        $window = (int) config('lottery.cancellation.window_minutes', 15);
        if ($window <= 0) {
            return;
        }

        $placedAt = $bet->placed_at ?? $bet->created_at;
        if ($placedAt === null || ! $placedAt->copy()->addMinutes($window)->isFuture()) {
            throw BetCancellationWindowExpiredException::forBet(
                (int) $bet->getKey(),
                $placedAt?->toDateTimeString() ?? 'unknown',
                $window,
            );
        }
    }

    private function assertDrawAcceptsCancellation(Bet $bet): void
    {
        /** @var Draw $draw */
        $draw = $bet->draw()->lockForUpdate()->firstOrFail();

        if (! $draw->canAcceptBets()) {
            throw BetCancellationException::drawUnavailable((int) $draw->getKey(), 'the draw no longer accepts changes');
        }

        $closeAt = $draw->betting_close_at ?? $draw->close_at;
        if ($closeAt !== null && now()->greaterThanOrEqualTo($closeAt)) {
            throw BetCancellationException::drawUnavailable((int) $draw->getKey(), 'the betting window for this draw has closed');
        }
    }

    private function syncTicket(Bet $bet, \Illuminate\Support\Carbon $stamp): ?Ticket
    {
        /** @var Ticket|null $ticket */
        $ticket = $bet->ticket()->lockForUpdate()->first();
        if ($ticket === null || $ticket->status === TicketStatus::Cancelled) {
            return $ticket;
        }

        $hasStandingBets = $ticket->bets()
            ->where('status', '!=', BetStatus::Cancelled->value)
            ->exists();

        if (! $hasStandingBets) {
            $ticket->forceFill([
                'status' => TicketStatus::Cancelled,
                'cancelled_at' => $stamp,
            ]);
            $ticket->save();
        }

        return $ticket;
    }

    private function recordAudit(Bet $bet, BetCancellationReason $reason, FinancialTransaction $refund, ?int $actorUserId = null): void
    {
        $log = new AuditLog;
        $log->fill([
            'user_id' => $actorUserId ?? (int) $bet->user_id,
            'action' => AuditAction::CancelBet,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Bet::class,
            'auditable_id' => (int) $bet->getKey(),
            'description' => sprintf(
                'Cancelled bet %s (%s); refunded %s %s via %s.',
                (string) $bet->bet_number,
                $reason->value,
                bcadd((string) $bet->stake_amount, '0', 2),
                (string) ($bet->currency instanceof Currency ? $bet->currency->value : $bet->currency),
                (string) $refund->reference_number,
            ),
            'metadata' => [
                'bet_id' => (int) $bet->getKey(),
                'bet_number' => (string) $bet->bet_number,
                'draw_id' => (int) $bet->draw_id,
                'reason' => $reason->value,
                'refund_transaction_id' => (int) $refund->getKey(),
                'actor_user_id' => $actorUserId ?? (int) $bet->user_id,
            ],
        ]);
        $log->save();
    }
}
