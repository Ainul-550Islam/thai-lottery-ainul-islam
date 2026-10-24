<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\DTOs\Finance\WalletReservationData;
use App\DTOs\Lottery\GloL6PricingSnapshotData;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\GloL6ReservationStatus;
use App\Enums\LedgerEntryPurpose;
use App\Enums\RiskLevel;
use App\Enums\WalletReservationStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\GloL6PurchaseException;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\GloL6ActiveAllocation;
use App\Models\GloL6Sale;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletReservation;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Transactional GLO L6 ticket reservation, confirmation, immediate purchase,
 * expiration reclamation, and cancelled-draw reversal pipeline.
 *
 * Financial & concurrency invariants:
 *  - Fail-closed via GloL6PurchaseCapabilityService::assertPurchasable() before any write.
 *  - Deterministic lock hierarchy: Buyer Wallet -> Draw -> GloL6Sale -> Reservation/Slot.
 *  - Zero direct $wallet->balance mutations; all holds/debits/reversals flow through
 *    WalletReservationService, WalletService, and FinancialReversalService.
 *  - Unique active allocation slot enforced at both pessimistic-lock and DB UNIQUE
 *    index levels (`glo_l6_active_alloc_slot_unique`).
 *  - Immutable pricing snapshot (`GloL6PricingSnapshotData`) sealed at reservation time
 *    and cryptographically verified at confirmation time.
 */
final class GloL6TransactionalPurchaseService
{
    public function __construct(
        private readonly GloL6PurchaseCapabilityService $capability,
        private readonly WalletLockService $walletLock,
        private readonly WalletService $walletService,
        private readonly WalletReservationService $walletReservations,
        private readonly FinancialTransactionService $transactionExecutor,
        private readonly FinancialReversalService $reversals,
        private readonly ResponsibleGamingEnforcementService $responsibleGaming,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Reserve a specific 6-digit GLO L6 ticket slot and place a matching wallet hold.
     *
     * @return array{reservation: GloL6TicketReservation, replayed: bool}
     *
     * @throws GloL6PurchaseException
     * @throws FinancialException
     */
    public function reserveTicket(
        User $buyer,
        int $drawId,
        string $ticketNumber,
        int $series,
        string $idempotencyKey,
        ?string $expectedUnitPrice = null,
    ): array {
        $normalizedNumber = $this->normalizeTicketNumber($ticketNumber);
        $normalizedSeries = $this->normalizeSeries($series);
        $cleanKey = $this->normalizeIdempotencyKey($idempotencyKey);

        /** @var Draw|null $draw */
        $draw = Draw::query()->find($drawId);
        if (! $draw instanceof Draw) {
            throw GloL6PurchaseException::drawNotOpen($drawId);
        }

        $pricingSnapshot = $this->capability->assertPurchasable($draw, $normalizedSeries);

        if ($expectedUnitPrice !== null) {
            $normalizedExpected = $this->normalizeExpectedPrice($expectedUnitPrice);
            if (bccomp($normalizedExpected, $pricingSnapshot->unitPrice, 2) !== 0) {
                throw GloL6PurchaseException::pricingMismatch($normalizedExpected, $pricingSnapshot->unitPrice);
            }
        }

        $fingerprint = $this->computeRequestFingerprint(
            userId: (int) $buyer->getKey(),
            drawId: $drawId,
            ticketNumber: $normalizedNumber,
            series: $normalizedSeries,
            unitPrice: $pricingSnapshot->unitPrice,
            currency: $pricingSnapshot->currency->value,
        );

        return $this->transactionExecutor->executeWithDeadlockRetry(
            fn (): array => $this->walletLock->withinTransaction(
                function () use (
                    $buyer,
                    $drawId,
                    $normalizedNumber,
                    $normalizedSeries,
                    $cleanKey,
                    $pricingSnapshot,
                    $fingerprint,
                ): array {
                    $wallet = $this->resolveAndLockBuyerMainWallet($buyer);

                    /** @var GloL6TicketReservation|null $existing */
                    $existing = GloL6TicketReservation::query()
                        ->where('user_id', (int) $buyer->getKey())
                        ->where('idempotency_key', $cleanKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof GloL6TicketReservation) {
                        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                            throw GloL6PurchaseException::idempotencyConflict($cleanKey);
                        }

                        return ['reservation' => $existing, 'replayed' => true];
                    }

                    $this->responsibleGaming->assertBetAllowed(
                        $buyer,
                        $pricingSnapshot->unitPrice,
                        $pricingSnapshot->currency->value,
                    );

                    $lockedDraw = $this->lockOpenDrawOrFail($drawId);
                    $lockedSale = $this->lockSeatedSeriesOrFail((int) $lockedDraw->getKey(), $normalizedSeries);

                    // Reclaim any wall-clock-expired reservation on this exact slot before allocation.
                    $this->reclaimExpiredSlotInsideTransaction(
                        drawId: (int) $lockedDraw->getKey(),
                        ticketNumber: $normalizedNumber,
                        series: $normalizedSeries,
                    );

                    $this->assertSlotAndSeriesCapacityAvailable(
                        drawId: (int) $lockedDraw->getKey(),
                        series: $normalizedSeries,
                        ticketNumber: $normalizedNumber,
                        capacity: (int) $lockedSale->units_sold,
                    );

                    $ttlSeconds = max(30, min(3600, (int) config('glo.l6.purchase.reservation_ttl_seconds', 300)));
                    $expiresAt = now()->addSeconds($ttlSeconds);
                    $reservationRef = $this->generateReservationReference((int) $lockedDraw->getKey(), $normalizedSeries);

                    $walletHoldResult = $this->walletReservations->reserve(new WalletReservationData(
                        walletId: (int) $wallet->getKey(),
                        reference: $reservationRef,
                        amount: $pricingSnapshot->unitPrice,
                        currency: $pricingSnapshot->currency->value,
                        purpose: LedgerEntryPurpose::BetStake,
                        expiresAt: $expiresAt->toIso8601String(),
                        description: sprintf(
                            'GLO L6 ticket reservation %s (draw #%d, series %d)',
                            $normalizedNumber,
                            (int) $lockedDraw->getKey(),
                            $normalizedSeries,
                        ),
                    ));

                    /** @var WalletReservation $walletHold */
                    $walletHold = $walletHoldResult['reservation'];

                    $reservation = GloL6TicketReservation::query()->create([
                        'reservation_reference' => $reservationRef,
                        'draw_id' => (int) $lockedDraw->getKey(),
                        'glo_l6_sale_id' => (int) $lockedSale->getKey(),
                        'user_id' => (int) $buyer->getKey(),
                        'wallet_id' => (int) $wallet->getKey(),
                        'wallet_reservation_id' => (int) $walletHold->getKey(),
                        'ticket_number' => $normalizedNumber,
                        'set_series' => $normalizedSeries,
                        'status' => GloL6ReservationStatus::Reserved,
                        'unit_price' => $pricingSnapshot->unitPrice,
                        'currency' => $pricingSnapshot->currency,
                        'pricing_snapshot' => $pricingSnapshot->toArray(),
                        'idempotency_key' => $cleanKey,
                        'request_fingerprint' => $fingerprint,
                        'reserved_until' => $expiresAt,
                        'metadata' => [
                            'inventory_provider_mode' => (string) config('glo.l6.purchase.inventory_provider_mode', 'not_configured'),
                        ],
                    ]);

                    try {
                        GloL6ActiveAllocation::query()->create([
                            'draw_id' => (int) $lockedDraw->getKey(),
                            'glo_l6_sale_id' => (int) $lockedSale->getKey(),
                            'ticket_number' => $normalizedNumber,
                            'set_series' => $normalizedSeries,
                            'state' => 'reserved',
                            'owner_user_id' => (int) $buyer->getKey(),
                            'reservation_id' => (int) $reservation->getKey(),
                            'glo_ticket_id' => null,
                            'pricing_snapshot_hash' => $pricingSnapshot->snapshotHash,
                            'reserved_until' => $expiresAt,
                            'allocated_at' => now(),
                        ]);
                    } catch (UniqueConstraintViolationException|QueryException $e) {
                        if ($this->isUniqueConstraintViolation($e)) {
                            throw GloL6PurchaseException::ticketAlreadyAllocated($normalizedNumber, $normalizedSeries);
                        }

                        throw $e;
                    }

                    $this->auditLog->log(
                        userId: (int) $buyer->getKey(),
                        action: AuditAction::Create,
                        riskLevel: RiskLevel::Medium,
                        auditable: $reservation,
                        description: sprintf('Reserved GLO L6 ticket %s (series %d) for draw #%d', $normalizedNumber, $normalizedSeries, (int) $lockedDraw->getKey()),
                        metadata: [
                            'reservation_reference' => $reservationRef,
                            'draw_id' => (int) $lockedDraw->getKey(),
                            'set_series' => $normalizedSeries,
                            'unit_price' => $pricingSnapshot->unitPrice,
                            'currency' => $pricingSnapshot->currency->value,
                            'snapshot_hash' => $pricingSnapshot->snapshotHash,
                        ],
                    );

                    return ['reservation' => $reservation, 'replayed' => false];
                }
            )
        );
    }

    /**
     * Confirm an active reservation: verify immutable pricing snapshot, consume the
     * wallet hold into a balanced double-entry debit, and issue an authoritative GloTicket.
     *
     * @return array{reservation: GloL6TicketReservation, ticket: GloTicket, replayed: bool}
     *
     * @throws GloL6PurchaseException
     * @throws FinancialException
     */
    public function confirmReservation(
        User $buyer,
        string $reservationReference,
        string $idempotencyKey,
    ): array {
        $cleanKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $cleanRef = trim($reservationReference);

        if ($cleanRef === '') {
            throw GloL6PurchaseException::reservationNotFound();
        }

        /** @var GloL6TicketReservation|null $target */
        $target = GloL6TicketReservation::query()
            ->where('reservation_reference', $cleanRef)
            ->first();

        if (! $target instanceof GloL6TicketReservation || (int) $target->user_id !== (int) $buyer->getKey()) {
            throw GloL6PurchaseException::reservationNotFound();
        }

        if ($target->status !== GloL6ReservationStatus::Purchased) {
            /** @var Draw|null $draw */
            $draw = Draw::query()->find((int) $target->draw_id);
            $this->capability->assertPurchasable($draw, (int) $target->set_series);
        }

        $outcome = $this->transactionExecutor->executeWithDeadlockRetry(
            fn (): array => $this->walletLock->withinTransaction(
                function () use ($buyer, $cleanRef, $cleanKey): array {
                    $this->resolveAndLockBuyerMainWallet($buyer);

                    /** @var GloL6TicketReservation|null $reservation */
                    $reservation = GloL6TicketReservation::query()
                        ->where('reservation_reference', $cleanRef)
                        ->where('user_id', (int) $buyer->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $reservation instanceof GloL6TicketReservation) {
                        throw GloL6PurchaseException::reservationNotFound();
                    }

                    if ($reservation->status === GloL6ReservationStatus::Purchased) {
                        if ((string) $reservation->purchase_idempotency_key !== $cleanKey) {
                            throw GloL6PurchaseException::idempotencyConflict($cleanKey);
                        }

                        /** @var GloTicket $existingTicket */
                        $existingTicket = GloTicket::query()->findOrFail((int) $reservation->glo_ticket_id);

                        return [
                            'status' => 'purchased',
                            'reservation' => $reservation,
                            'ticket' => $existingTicket,
                            'replayed' => true,
                        ];
                    }

                    if ($reservation->status !== GloL6ReservationStatus::Reserved) {
                        throw GloL6PurchaseException::invalidReservationState(
                            $reservation->reservation_reference,
                            $reservation->status->value,
                        );
                    }

                    if ($reservation->reserved_until !== null && $reservation->reserved_until->isPast()) {
                        $this->expireReservationInsideTransaction($reservation, 'Reservation TTL elapsed before confirmation.');

                        return [
                            'status' => 'expired',
                            'reservation' => $reservation,
                        ];
                    }

                    /** @var Draw|null $lockedDraw */
                    $lockedDraw = Draw::query()->whereKey((int) $reservation->draw_id)->lockForUpdate()->first();
                    $drawStatus = $lockedDraw?->status instanceof DrawStatus
                        ? $lockedDraw->status
                        : DrawStatus::tryFrom((string) ($lockedDraw?->status ?? ''));

                    if (! $lockedDraw instanceof Draw || $drawStatus !== DrawStatus::Open || ! $lockedDraw->isOpen()) {
                        $this->releaseReservationInsideTransaction(
                            $reservation,
                            GloL6ReservationStatus::Released,
                            'Draw closed before reservation confirmation.',
                        );

                        return [
                            'status' => 'draw_closed',
                            'draw_id' => (int) $reservation->draw_id,
                        ];
                    }

                    // Verify cryptographic hash of the sealed pricing snapshot (cannot drift).
                    $snapshot = $reservation->verifiedPricingSnapshot();

                    $this->responsibleGaming->assertBetAllowed(
                        $buyer,
                        $snapshot->unitPrice,
                        $snapshot->currency->value,
                    );

                    /** @var GloL6ActiveAllocation|null $allocation */
                    $allocation = GloL6ActiveAllocation::query()
                        ->where('reservation_id', (int) $reservation->getKey())
                        ->lockForUpdate()
                        ->first();

                    if (! $allocation instanceof GloL6ActiveAllocation || $allocation->state !== 'reserved') {
                        throw GloL6PurchaseException::invalidReservationState(
                            $reservation->reservation_reference,
                            $reservation->status->value,
                        );
                    }

                    /** @var WalletReservation $walletHold */
                    $walletHold = WalletReservation::query()
                        ->lockForUpdate()
                        ->findOrFail((int) $reservation->wallet_reservation_id);

                    /** @var WalletReservation $consumedHold */
                    $consumedHold = $this->walletReservations->consume(
                        $walletHold,
                        sprintf(
                            'GLO L6 ticket purchase %s (series %d, draw #%d)',
                            $reservation->ticket_number,
                            (int) $reservation->set_series,
                            (int) $reservation->draw_id,
                        ),
                    );

                    $debitKey = sprintf('wallet-resv-cons:%s', substr((string) $consumedHold->reservation_key, 0, 48));
                    /** @var FinancialTransaction $financialTx */
                    $financialTx = FinancialTransaction::query()
                        ->where('idempotency_key', $debitKey)
                        ->firstOrFail();

                    $ticketIdempotencyKey = sprintf('glo-l6-resv-ticket:%d', (int) $reservation->getKey());
                    $digitalSealHash = hash('sha256', sprintf(
                        'GLO|L6|%d|%d|%s|%d|%s|%s',
                        (int) $reservation->draw_id,
                        (int) $buyer->getKey(),
                        (string) $reservation->ticket_number,
                        (int) $reservation->set_series,
                        $reservation->reservation_reference,
                        $snapshot->snapshotHash,
                    ));

                    try {
                        $ticket = GloTicket::query()->create([
                            'draw_id' => (int) $reservation->draw_id,
                            'product' => 'l6',
                            'owner_user_id' => (int) $buyer->getKey(),
                            'ticket_number' => (string) $reservation->ticket_number,
                            'set_series' => (string) $reservation->set_series,
                            'ticket_reference' => $this->generateTicketReference(
                                (int) $reservation->draw_id,
                                (int) $reservation->set_series,
                                (string) $reservation->ticket_number,
                            ),
                            'price' => $snapshot->unitPrice,
                            'currency' => $snapshot->currency,
                            'status' => 'active',
                            'digital_seal_hash' => $digitalSealHash,
                            'purchased_at' => now(),
                            'metadata' => [
                                'reservation_reference' => $reservation->reservation_reference,
                                'client_idempotency_key' => $ticketIdempotencyKey,
                                'pricing_snapshot' => $snapshot->toArray(),
                                'price_thb' => $snapshot->unitPrice,
                                'financial_transaction_id' => (int) $financialTx->getKey(),
                                'purchased_from' => 'wallet_l6_transactional',
                            ],
                        ]);
                    } catch (UniqueConstraintViolationException|QueryException $e) {
                        if ($this->isUniqueConstraintViolation($e)) {
                            throw GloL6PurchaseException::ticketAlreadyAllocated(
                                (string) $reservation->ticket_number,
                                (int) $reservation->set_series,
                            );
                        }

                        throw $e;
                    }

                    $allocation->state = 'sold';
                    $allocation->glo_ticket_id = (int) $ticket->getKey();
                    $allocation->reserved_until = null;
                    $allocation->save();

                    $reservation->status = GloL6ReservationStatus::Purchased;
                    $reservation->purchase_idempotency_key = $cleanKey;
                    $reservation->purchased_at = now();
                    $reservation->glo_ticket_id = (int) $ticket->getKey();
                    $reservation->financial_transaction_id = (int) $financialTx->getKey();
                    $reservation->save();

                    $this->auditLog->log(
                        userId: (int) $buyer->getKey(),
                        action: AuditAction::Create,
                        riskLevel: RiskLevel::High,
                        auditable: $ticket,
                        description: sprintf(
                            'Purchased GLO L6 ticket %s (series %d) via reservation %s',
                            $ticket->ticket_number,
                            (int) $ticket->set_series,
                            $reservation->reservation_reference,
                        ),
                        metadata: [
                            'reservation_reference' => $reservation->reservation_reference,
                            'ticket_reference' => $ticket->ticket_reference,
                            'draw_id' => (int) $ticket->draw_id,
                            'set_series' => (int) $ticket->set_series,
                            'unit_price' => $snapshot->unitPrice,
                            'financial_transaction_id' => (int) $financialTx->getKey(),
                            'snapshot_hash' => $snapshot->snapshotHash,
                        ],
                    );

                    return [
                        'status' => 'purchased',
                        'reservation' => $reservation,
                        'ticket' => $ticket,
                        'replayed' => false,
                    ];
                }
            )
        );

        if ($outcome['status'] === 'expired') {
            throw GloL6PurchaseException::reservationExpired($cleanRef);
        }

        if ($outcome['status'] === 'draw_closed') {
            throw GloL6PurchaseException::drawNotOpen((int) $outcome['draw_id']);
        }

        return [
            'reservation' => $outcome['reservation'],
            'ticket' => $outcome['ticket'],
            'replayed' => $outcome['replayed'],
        ];
    }

    /**
     * Atomic one-step GLO L6 purchase (reserve + confirm inside a single DB transaction).
     *
     * @return array{reservation: GloL6TicketReservation, ticket: GloTicket, replayed: bool}
     */
    public function purchaseImmediate(
        User $buyer,
        int $drawId,
        string $ticketNumber,
        int $series,
        string $idempotencyKey,
        ?string $expectedUnitPrice = null,
    ): array {
        $cleanKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $reserveKey = 'imm-resv:'.$cleanKey;
        $confirmKey = 'imm-conf:'.$cleanKey;

        return $this->transactionExecutor->executeWithDeadlockRetry(
            fn (): array => $this->walletLock->withinTransaction(
                function () use ($buyer, $drawId, $ticketNumber, $series, $reserveKey, $confirmKey, $expectedUnitPrice): array {
                    $reserved = $this->reserveTicket(
                        buyer: $buyer,
                        drawId: $drawId,
                        ticketNumber: $ticketNumber,
                        series: $series,
                        idempotencyKey: $reserveKey,
                        expectedUnitPrice: $expectedUnitPrice,
                    );

                    /** @var GloL6TicketReservation $reservation */
                    $reservation = $reserved['reservation'];

                    $confirmed = $this->confirmReservation(
                        buyer: $buyer,
                        reservationReference: $reservation->reservation_reference,
                        idempotencyKey: $confirmKey,
                    );

                    return [
                        'reservation' => $confirmed['reservation'],
                        'ticket' => $confirmed['ticket'],
                        'replayed' => $reserved['replayed'] && $confirmed['replayed'],
                    ];
                }
            )
        );
    }

    /**
     * Explicitly cancel/release an active unconsumed reservation and return funds to available balance.
     */
    public function releaseReservation(User $actor, string $reservationReference, ?string $reason = null): GloL6TicketReservation
    {
        $cleanRef = trim($reservationReference);
        if ($cleanRef === '') {
            throw GloL6PurchaseException::reservationNotFound();
        }

        return $this->transactionExecutor->executeWithDeadlockRetry(
            fn (): GloL6TicketReservation => $this->walletLock->withinTransaction(
                function () use ($actor, $cleanRef, $reason): GloL6TicketReservation {
                    /** @var GloL6TicketReservation|null $reservation */
                    $reservation = GloL6TicketReservation::query()
                        ->where('reservation_reference', $cleanRef)
                        ->lockForUpdate()
                        ->first();

                    if (! $reservation instanceof GloL6TicketReservation) {
                        throw GloL6PurchaseException::reservationNotFound();
                    }

                    $isOwner = (int) $reservation->user_id === (int) $actor->getKey();
                    $isAdmin = $actor->isSuperAdmin() || $actor->isAdmin();

                    if (! $isOwner && ! $isAdmin) {
                        throw GloL6PurchaseException::reservationNotFound();
                    }

                    if ($reservation->status === GloL6ReservationStatus::Released) {
                        return $reservation;
                    }

                    if ($reservation->status !== GloL6ReservationStatus::Reserved) {
                        throw GloL6PurchaseException::invalidReservationState(
                            $reservation->reservation_reference,
                            $reservation->status->value,
                        );
                    }

                    /** @var Wallet $wallet */
                    $wallet = Wallet::query()->findOrFail((int) $reservation->wallet_id);
                    $this->walletLock->lock((int) $wallet->getKey());

                    $this->releaseReservationInsideTransaction(
                        $reservation,
                        GloL6ReservationStatus::Released,
                        $reason ?? 'Released by actor.',
                    );

                    return $reservation;
                }
            )
        );
    }

    /**
     * Sweep and reclaim expired GLO L6 reservations, releasing wallet holds and freeing slots.
     */
    public function reclaimExpiredReservations(int $limit = 100): int
    {
        $expiredIds = GloL6TicketReservation::query()
            ->where('status', GloL6ReservationStatus::Reserved->value)
            ->where('reserved_until', '<=', now())
            ->orderBy('reserved_until')
            ->limit(max(1, $limit))
            ->pluck('id')
            ->all();

        $reclaimed = 0;

        foreach ($expiredIds as $id) {
            $didExpire = $this->transactionExecutor->executeWithDeadlockRetry(
                fn (): bool => $this->walletLock->withinTransaction(function () use ($id): bool {
                    /** @var GloL6TicketReservation|null $reservation */
                    $reservation = GloL6TicketReservation::query()->lockForUpdate()->find((int) $id);
                    if (! $reservation instanceof GloL6TicketReservation) {
                        return false;
                    }

                    if ($reservation->status !== GloL6ReservationStatus::Reserved || ! $reservation->reserved_until?->isPast()) {
                        return false;
                    }

                    /** @var Wallet|null $wallet */
                    $wallet = Wallet::query()->find((int) $reservation->wallet_id);
                    if ($wallet instanceof Wallet) {
                        $this->walletLock->lock((int) $wallet->getKey());
                    }

                    $this->expireReservationInsideTransaction($reservation, 'Expired during GLO L6 reservation sweep.');

                    return true;
                })
            );

            if ($didExpire) {
                $reclaimed++;
            }
        }

        return $reclaimed;
    }

    /**
     * Compensate and void sold GLO L6 tickets on a cancelled draw via append-only
     * double-entry reversals (never mutating or deleting historical ledger entries).
     *
     * @return int Number of refunded tickets
     */
    public function refundCancelledDrawTickets(Draw $draw, ?int $actorUserId = null): int
    {
        $freshDraw = Draw::query()->findOrFail((int) $draw->getKey());
        $status = $freshDraw->status instanceof DrawStatus
            ? $freshDraw->status
            : DrawStatus::tryFrom((string) $freshDraw->status);

        if ($status !== DrawStatus::Cancelled) {
            throw GloL6PurchaseException::invalidSelection('Refund rollback is only permitted for cancelled draws.');
        }

        $reservations = GloL6TicketReservation::query()
            ->where('draw_id', (int) $freshDraw->getKey())
            ->whereIn('status', [
                GloL6ReservationStatus::Reserved->value,
                GloL6ReservationStatus::Purchased->value,
            ])
            ->orderBy('id')
            ->get();

        $refundedCount = 0;

        foreach ($reservations as $item) {
            $didRefund = $this->transactionExecutor->executeWithDeadlockRetry(
                fn (): bool => $this->walletLock->withinTransaction(
                    function () use ($item, $actorUserId, $freshDraw): bool {
                        /** @var GloL6TicketReservation|null $locked */
                        $locked = GloL6TicketReservation::query()->lockForUpdate()->find((int) $item->getKey());
                        if (! $locked instanceof GloL6TicketReservation) {
                            return false;
                        }

                        /** @var Wallet $wallet */
                        $wallet = Wallet::query()->findOrFail((int) $locked->wallet_id);
                        $this->walletLock->lock((int) $wallet->getKey());

                        if ($locked->status === GloL6ReservationStatus::Reserved) {
                            $this->releaseReservationInsideTransaction(
                                $locked,
                                GloL6ReservationStatus::RolledBack,
                                sprintf('Draw #%d cancelled; reservation hold released.', (int) $freshDraw->getKey()),
                            );

                            return false;
                        }

                        if ($locked->status !== GloL6ReservationStatus::Purchased || $locked->financial_transaction_id === null) {
                            return false;
                        }

                        /** @var FinancialTransaction $originalTx */
                        $originalTx = FinancialTransaction::query()->findOrFail((int) $locked->financial_transaction_id);

                        $reversalTx = $this->reversals->reverse(
                            original: $originalTx,
                            actorUserId: $actorUserId,
                            idempotencyKey: null,
                            reason: sprintf('Cancelled GLO L6 draw #%d ticket refund', (int) $freshDraw->getKey()),
                        );

                        if ($locked->glo_ticket_id !== null) {
                            /** @var GloTicket|null $ticket */
                            $ticket = GloTicket::query()->lockForUpdate()->find((int) $locked->glo_ticket_id);
                            if ($ticket instanceof GloTicket) {
                                $meta = (array) $ticket->metadata;
                                $meta['status'] = 'cancelled';
                                $meta['cancelled_at'] = now()->toIso8601String();
                                $meta['refund_financial_transaction_id'] = (int) $reversalTx->getKey();
                                $ticket->metadata = $meta;
                                $ticket->save();
                            }
                        }

                        GloL6ActiveAllocation::query()
                            ->where('reservation_id', (int) $locked->getKey())
                            ->delete();

                        $locked->status = GloL6ReservationStatus::Refunded;
                        $locked->refund_financial_transaction_id = (int) $reversalTx->getKey();
                        $locked->released_at = now();
                        $locked->failure_reason = 'Cancelled draw refund completed.';
                        $locked->save();

                        return true;
                    }
                )
            );

            if ($didRefund) {
                $refundedCount++;
            }
        }

        return $refundedCount;
    }

    private function resolveAndLockBuyerMainWallet(User $buyer): Wallet
    {
        if (! $buyer->isActive()) {
            throw GloL6PurchaseException::invalidSelection('Inactive or suspended accounts cannot reserve or purchase GLO L6 tickets.');
        }

        $wallet = $this->walletService->getOrCreateWallet($buyer, Currency::THB);

        return $this->walletLock->lock((int) $wallet->getKey());
    }

    private function lockOpenDrawOrFail(int $drawId): Draw
    {
        /** @var Draw|null $draw */
        $draw = Draw::query()->whereKey($drawId)->lockForUpdate()->first();
        if (! $draw instanceof Draw) {
            throw GloL6PurchaseException::drawNotOpen($drawId);
        }

        $status = $draw->status instanceof DrawStatus
            ? $draw->status
            : DrawStatus::tryFrom((string) $draw->status);

        if ($status !== DrawStatus::Open || ! $draw->isOpen()) {
            throw GloL6PurchaseException::drawNotOpen($drawId);
        }

        return $draw;
    }

    private function lockSeatedSeriesOrFail(int $drawId, int $series): GloL6Sale
    {
        /** @var GloL6Sale|null $sale */
        $sale = GloL6Sale::query()
            ->where('draw_id', $drawId)
            ->where('product', 'l6')
            ->where('series_number', $series)
            ->lockForUpdate()
            ->first();

        if (! $sale instanceof GloL6Sale || (int) $sale->units_sold <= 0) {
            throw GloL6PurchaseException::seatNotConfigured($drawId, $series);
        }

        return $sale;
    }

    private function assertSlotAndSeriesCapacityAvailable(
        int $drawId,
        int $series,
        string $ticketNumber,
        int $capacity,
    ): void {
        $alreadyAllocatedSlot = GloL6ActiveAllocation::query()
            ->where('draw_id', $drawId)
            ->where('ticket_number', $ticketNumber)
            ->where('set_series', $series)
            ->lockForUpdate()
            ->exists();

        if ($alreadyAllocatedSlot) {
            throw GloL6PurchaseException::ticketAlreadyAllocated($ticketNumber, $series);
        }

        $legacyTicketExists = GloTicket::query()
            ->where('draw_id', $drawId)
            ->where('product', 'l6')
            ->where('ticket_number', $ticketNumber)
            ->where('set_series', (string) $series)
            ->lockForUpdate()
            ->exists();

        if ($legacyTicketExists) {
            throw GloL6PurchaseException::ticketAlreadyAllocated($ticketNumber, $series);
        }

        $activeCountInSeries = GloL6ActiveAllocation::query()
            ->where('draw_id', $drawId)
            ->where('set_series', $series)
            ->count();

        $untrackedSoldInSeries = GloTicket::query()
            ->where('draw_id', $drawId)
            ->where('product', 'l6')
            ->where('set_series', (string) $series)
            ->whereNotExists(static function ($sub) use ($drawId, $series): void {
                $sub->selectRaw('1')
                    ->from('glo_l6_active_allocations')
                    ->whereColumn('glo_l6_active_allocations.glo_ticket_id', 'glo_tickets.id')
                    ->where('glo_l6_active_allocations.draw_id', $drawId)
                    ->where('glo_l6_active_allocations.set_series', $series);
            })
            ->count();

        if (($activeCountInSeries + $untrackedSoldInSeries) >= $capacity) {
            throw GloL6PurchaseException::seriesOversold($drawId, $series, $capacity);
        }
    }

    private function reclaimExpiredSlotInsideTransaction(int $drawId, string $ticketNumber, int $series): void
    {
        /** @var GloL6ActiveAllocation|null $staleSlot */
        $staleSlot = GloL6ActiveAllocation::query()
            ->where('draw_id', $drawId)
            ->where('ticket_number', $ticketNumber)
            ->where('set_series', $series)
            ->where('state', 'reserved')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', now())
            ->lockForUpdate()
            ->first();

        if (! $staleSlot instanceof GloL6ActiveAllocation) {
            return;
        }

        if ($staleSlot->reservation_id !== null) {
            /** @var GloL6TicketReservation|null $staleResv */
            $staleResv = GloL6TicketReservation::query()->lockForUpdate()->find((int) $staleSlot->reservation_id);
            if ($staleResv instanceof GloL6TicketReservation && $staleResv->status === GloL6ReservationStatus::Reserved) {
                $this->expireReservationInsideTransaction($staleResv, 'Reclaimed expired slot for new allocation.');

                return;
            }
        }

        $staleSlot->delete();
    }

    private function expireReservationInsideTransaction(GloL6TicketReservation $reservation, string $reason): void
    {
        if ($reservation->wallet_reservation_id !== null) {
            /** @var WalletReservation|null $hold */
            $hold = WalletReservation::query()->lockForUpdate()->find((int) $reservation->wallet_reservation_id);
            if ($hold instanceof WalletReservation && $hold->status === WalletReservationStatus::Reserved) {
                if ($hold->expires_at !== null && $hold->expires_at->isPast()) {
                    $this->walletReservations->expire($hold);
                } else {
                    $this->walletReservations->release($hold, $reason);
                }
            }
        }

        GloL6ActiveAllocation::query()
            ->where('reservation_id', (int) $reservation->getKey())
            ->where('state', 'reserved')
            ->delete();

        $reservation->status = GloL6ReservationStatus::Expired;
        $reservation->released_at = now();
        $reservation->failure_reason = $reason;
        $reservation->save();
    }

    private function releaseReservationInsideTransaction(
        GloL6TicketReservation $reservation,
        GloL6ReservationStatus $terminalStatus,
        string $reason,
    ): void {
        if ($reservation->wallet_reservation_id !== null) {
            /** @var WalletReservation|null $hold */
            $hold = WalletReservation::query()->lockForUpdate()->find((int) $reservation->wallet_reservation_id);
            if ($hold instanceof WalletReservation && $hold->status === WalletReservationStatus::Reserved) {
                $this->walletReservations->release($hold, $reason);
            }
        }

        GloL6ActiveAllocation::query()
            ->where('reservation_id', (int) $reservation->getKey())
            ->where('state', 'reserved')
            ->delete();

        $reservation->status = $terminalStatus;
        $reservation->released_at = now();
        $reservation->failure_reason = $reason;
        $reservation->save();
    }

    private function normalizeTicketNumber(string $ticketNumber): string
    {
        $trimmed = trim($ticketNumber);
        if (preg_match('/^\d{6}$/', $trimmed) !== 1) {
            throw GloL6PurchaseException::invalidSelection('GLO L6 ticket_number must be an exact 6-digit numeric string (000000-999999).');
        }

        return $trimmed;
    }

    private function normalizeSeries(int $series): int
    {
        if ($series < 1 || $series > 100) {
            throw GloL6PurchaseException::invalidSelection('GLO L6 set_series must be between 1 and 100.');
        }

        return $series;
    }

    private function normalizeIdempotencyKey(string $idempotencyKey): string
    {
        $trimmed = trim($idempotencyKey);
        if ($trimmed === '' || strlen($trimmed) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $trimmed) !== 1) {
            throw GloL6PurchaseException::invalidSelection(
                'Idempotency key must be 1..128 characters containing only [A-Za-z0-9._:-].'
            );
        }

        return $trimmed;
    }

    private function normalizeExpectedPrice(string $expected): string
    {
        $trimmed = trim($expected);
        if (preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $trimmed) !== 1) {
            throw GloL6PurchaseException::invalidSelection('expected_unit_price must be a valid decimal string with up to 2 decimals.');
        }

        return bcadd($trimmed, '0.00', 2);
    }

    private function computeRequestFingerprint(
        int $userId,
        int $drawId,
        string $ticketNumber,
        int $series,
        string $unitPrice,
        string $currency,
    ): string {
        return hash('sha256', implode('|', [
            (string) $userId,
            (string) $drawId,
            $ticketNumber,
            (string) $series,
            $unitPrice,
            strtoupper($currency),
        ]));
    }

    private function generateReservationReference(int $drawId, int $series): string
    {
        return sprintf('GL6R-%d-S%02d-%s', $drawId, $series, strtoupper(Str::random(14)));
    }

    private function generateTicketReference(int $drawId, int $series, string $ticketNumber): string
    {
        return sprintf('GL6T-%d-S%02d-%s-%s', $drawId, $series, $ticketNumber, strtoupper(Str::random(10)));
    }

    private function isUniqueConstraintViolation(Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        if ($e instanceof QueryException) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);

            return in_array($sqlState, ['23000', '23505'], true)
                || in_array($driverCode, [19, 1062, 1555, 2067], true)
                || str_contains(strtolower($e->getMessage()), 'unique');
        }

        return false;
    }
}
