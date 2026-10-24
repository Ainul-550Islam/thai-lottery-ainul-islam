<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class GloL6PurchaseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422,
        private readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param  array<string, mixed>  $capability
     */
    public static function notConfigured(array $capability): self
    {
        return new self(
            errorCode: 'glo_l6_not_configured',
            message: (string) ($capability['reason'] ?? 'GLO L6 ticket sales are not configured for this environment.'),
            httpStatus: 503,
            context: [
                'status' => 'NOT_CONFIGURED',
                'missing' => array_values((array) ($capability['missing'] ?? [])),
            ],
        );
    }

    public static function drawNotOpen(int $drawId): self
    {
        return new self(
            errorCode: 'glo_l6_draw_not_open',
            message: 'The selected GLO L6 draw is not open for ticket reservation or purchase.',
            httpStatus: 422,
            context: ['draw_id' => $drawId],
        );
    }

    public static function seatNotConfigured(int $drawId, int $series): self
    {
        return new self(
            errorCode: 'glo_l6_inventory_not_seated',
            message: 'No authorized GLO L6 series inventory is seated for the requested draw and series.',
            httpStatus: 503,
            context: ['draw_id' => $drawId, 'set_series' => $series],
        );
    }

    public static function seriesOversold(int $drawId, int $series, int $capacity): self
    {
        return new self(
            errorCode: 'glo_l6_series_oversold',
            message: 'All authorized GLO L6 tickets in this series allocation have been exhausted.',
            httpStatus: 409,
            context: ['draw_id' => $drawId, 'set_series' => $series, 'capacity' => $capacity],
        );
    }

    public static function ticketAlreadyAllocated(string $ticketNumber, int $series): self
    {
        return new self(
            errorCode: 'glo_l6_ticket_already_allocated',
            message: sprintf('GLO L6 ticket %s (series %d) is already reserved or sold.', $ticketNumber, $series),
            httpStatus: 409,
            context: ['ticket_number' => $ticketNumber, 'set_series' => $series],
        );
    }

    public static function idempotencyConflict(string $idempotencyKey): self
    {
        return new self(
            errorCode: 'glo_l6_idempotency_conflict',
            message: 'Idempotency key was already used with different GLO L6 reservation or purchase parameters.',
            httpStatus: 409,
            context: ['idempotency_key_conflict' => true],
        );
    }

    public static function reservationNotFound(): self
    {
        return new self(
            errorCode: 'glo_l6_reservation_not_found',
            message: 'GLO L6 ticket reservation was not found.',
            httpStatus: 404,
        );
    }

    public static function reservationExpired(string $reference): self
    {
        return new self(
            errorCode: 'glo_l6_reservation_expired',
            message: 'The GLO L6 ticket reservation has expired and its hold was released.',
            httpStatus: 409,
            context: ['reservation_reference' => $reference],
        );
    }

    public static function invalidReservationState(string $reference, string $currentState): self
    {
        return new self(
            errorCode: 'glo_l6_invalid_reservation_state',
            message: sprintf('Reservation [%s] cannot transition from state [%s].', $reference, $currentState),
            httpStatus: 409,
            context: ['reservation_reference' => $reference, 'state' => $currentState],
        );
    }

    public static function pricingMismatch(string $expected, string $actual): self
    {
        return new self(
            errorCode: 'glo_l6_pricing_mismatch',
            message: 'Submitted expected unit price does not match the authoritative GLO L6 pricing snapshot.',
            httpStatus: 422,
            context: ['expected_unit_price' => $expected, 'authoritative_unit_price' => $actual],
        );
    }

    public static function invalidSelection(string $reason): self
    {
        return new self(
            errorCode: 'glo_l6_invalid_selection',
            message: $reason,
            httpStatus: 422,
        );
    }
}
