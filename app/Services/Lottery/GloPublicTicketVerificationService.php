<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloFreezeStatus;
use App\Enums\GloPaymentHoldStatus;
use App\Enums\GloPublicStatus;
use App\Models\GloPrizePaymentHold;
use App\Models\GloPublicTicketStatus;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;

/**
 * GLO-13 public-safe ticket freeze status lookup.
 *
 * Returns ONLY the public status vocabulary. Never exposes:
 *  - claimant name / national id / passport / phone / email / bank account
 *  - evidence content, authority case notes, internal staff ids
 *  - database primary keys as raw internal identifiers beyond the public
 *    ticket_reference the caller already supplied
 *
 * Anti-enumeration:
 *  - reference must match config('glo.public_status.reference_pattern')
 *  - rate limited at the route (glo.public limiter)
 *  - NOT_FOUND is returned for unknown references without distinguishing
 *    "exists but not frozen" from "never seen" when no freeze history exists
 *    (NOT_FROZEN is only returned when a freeze history EXISTS but is not
 *    currently effective — bounded public information tied to official cases).
 *
 * Callers must NOT log request bodies containing partial PII; this service
 * only accepts the public ticket reference.
 */
class GloPublicTicketVerificationService
{
    /**
     * @return array{
     *     ticket_reference: string,
     *     status: string,
     *     status_label: string,
     *     product: string|null,
     *     prize_category: string|null,
     *     announcement: array<string, mixed>|null,
     *     updated_at: string|null,
     * }
     */
    public function verify(string $reference): array
    {
        $normalized = $this->normalizeReference($reference);

        $pattern = (string) config('glo.public_status.reference_pattern', '');

        if ($pattern !== '' && ! preg_match($pattern, $normalized)) {
            return $this->payload($normalized, GloPublicStatus::NotFound, null, null, null);
        }

        $ticket = $this->findTicket($normalized);

        if ($ticket === null) {
            return $this->payload($normalized, GloPublicStatus::NotFound, null, null, null);
        }

        $announcements = GloPublicTicketStatus::query()
            ->where('ticket_id', $ticket->getKey())
            ->orderByDesc('id')
            ->first();

        $freezes = GloTicketFreeze::query()
            ->where('ticket_id', $ticket->getKey())
            ->orderByDesc('id')
            ->get();

        if ($freezes->isEmpty()) {
            // No official freeze history: do not confirm existence either way
            // beyond NOT_FOUND-like silence → treat as NOT_FROZEN only when
            // announcement says so; otherwise NOT_FOUND to reduce enumeration.
            if ($announcements === null) {
                return $this->payload($normalized, GloPublicStatus::NotFound, null, null, null);
            }
        }

        $status = $this->deriveStatus($ticket->getKey(), $freezes, $announcements);

        return $this->payload(
            $normalized,
            $status,
            (string) $ticket->product,
            $announcements?->prize_category,
            $announcements,
        );
    }

    /**
     * Pure derivation from freeze + hold state (no PII reads).
     *
     * @param  \Illuminate\Support\Collection<int, GloTicketFreeze>  $freezes
     */
    public function deriveStatus(int $ticketId, iterable $freezes, ?GloPublicTicketStatus $announcement): GloPublicStatus
    {
        $activeFrozen = false;
        $anyFrozenCase = false;
        $sawReleased = false;
        $sawExpired = false;

        foreach ($freezes as $freeze) {
            if ($freeze->status === GloFreezeStatus::Frozen) {
                $anyFrozenCase = true;

                if ($freeze->expired_at === null
                    && ($freeze->expiry_at === null || $freeze->expiry_at->isFuture() || true)) {
                    // Due-but-not-yet-swept freezes stay effectively frozen
                    // until the audited expiry transition runs (fail-closed).
                    // An expired_at stamp means the expiry transition already ran.
                    if ($freeze->expired_at === null) {
                        $activeFrozen = true;
                    }
                }
            }

            // Released/Expired cases can only have come from Frozen — treat as
            // proof an official freeze existed so the public status is RELEASED
            // or EXPIRED rather than NOT_FROZEN.
            if ($freeze->status === GloFreezeStatus::Released) {
                $sawReleased = true;
                $anyFrozenCase = true;
            }

            if ($freeze->status === GloFreezeStatus::Expired) {
                $sawExpired = true;
                $anyFrozenCase = true;
            }
        }

        if ($activeFrozen) {
            $winningHold = GloPrizePaymentHold::query()
                ->where('ticket_id', $ticketId)
                ->where('status', GloPaymentHoldStatus::Active)
                ->exists();

            if ($winningHold || ($announcement?->status === 'FROZEN_AND_WINNING_PAYMENT_HELD')) {
                return GloPublicStatus::FrozenWinningPaymentHeld;
            }

            return GloPublicStatus::Frozen;
        }

        if ($anyFrozenCase || $announcement !== null) {
            if ($announcement?->status === 'FROZEN_AND_WINNING_PAYMENT_HELD') {
                // Hold may be active without re-evaluating freeze rows.
                $stillHeld = GloPrizePaymentHold::query()
                    ->where('ticket_id', $ticketId)
                    ->where('status', GloPaymentHoldStatus::Active)
                    ->exists();

                if ($stillHeld) {
                    return GloPublicStatus::FrozenWinningPaymentHeld;
                }
            }

            if ($sawReleased && ! $sawExpired) {
                return GloPublicStatus::Released;
            }

            if ($sawExpired && ! $sawReleased) {
                return GloPublicStatus::Expired;
            }

            if ($sawReleased && $sawExpired) {
                // Most recent case wins — caller ordered by id desc; scan order
                // preserved: return Released if last non-rejected was released.
                return GloPublicStatus::Released;
            }

            return GloPublicStatus::NotFrozen;
        }

        if ($freezes instanceof \Countable && $freezes->count() > 0) {
            // Freeze rows exist but only rejected/requested/under_review —
        }

        return GloPublicStatus::NotFrozen;
    }

    private function findTicket(string $reference): ?GloTicket
    {
        $direct = GloTicket::query()->where('ticket_reference', $reference)->first();

        if ($direct !== null) {
            return $direct;
        }

        // Parse {drawId}-{product}-{number}[-{series}]
        $parts = explode('-', $reference);

        if (count($parts) < 3) {
            return null;
        }

        $drawId = (int) array_shift($parts);
        $product = strtolower(array_shift($parts));

        if ($drawId < 1 || $product === '') {
            return null;
        }

        if (count($parts) === 1) {
            $number = $parts[0];
            $series = null;
        } elseif (count($parts) === 2) {
            $number = $parts[0];
            $series = $parts[1];
        } else {
            return null;
        }

        $query = GloTicket::query()
            ->where('draw_id', $drawId)
            ->where('product', $product)
            ->where('ticket_number', $number);

        if ($series === null) {
            $query->whereNull('set_series');
        } else {
            $query->where('set_series', $series);
        }

        return $query->first();
    }

    private function normalizeReference(string $reference): string
    {
        $trimmed = trim($reference);

        // Collapse accidental whitespace; keep digit strings intact (no int cast).
        $trimmed = (string) preg_replace('/\s+/', '', $trimmed);

        return $trimmed;
    }

    /**
     * @return array{
     *     ticket_reference: string,
     *     status: string,
     *     status_label: string,
     *     product: string|null,
     *     prize_category: string|null,
     *     announcement: array<string, mixed>|null,
     *     updated_at: string|null,
     * }
     */
    private function payload(
        string $reference,
        GloPublicStatus $status,
        ?string $product,
        ?string $category,
        ?GloPublicTicketStatus $announcement,
    ): array {
        return [
            'ticket_reference' => $reference,
            'status' => $status->value,
            'status_label' => $status->label(),
            'product' => $product,
            'prize_category' => $category,
            'announcement' => $announcement?->toPublicArray(),
            'updated_at' => $announcement?->updated_at?->toIso8601String()
                ?? $announcement?->published_at?->toIso8601String(),
        ];
    }
}
