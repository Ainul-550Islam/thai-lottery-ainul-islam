<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\Currency;
use App\Enums\GloL6ReservationStatus;
use App\Models\GloL6TicketReservation;
use App\Models\GloTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical API v1 resource for GLO L6 reservations and purchased tickets.
 * Never exposes internal database foreign keys, gateway credentials, or raw PII.
 */
class GloL6TicketPurchaseResource extends JsonResource
{
    /**
     * @param  array{
     *     reservation?: GloL6TicketReservation|null,
     *     ticket?: GloTicket|null,
     *     replayed?: bool
     * }|GloL6TicketReservation|GloTicket  $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reservation = null;
        $ticket = null;
        $replayed = false;

        if ($this->resource instanceof GloL6TicketReservation) {
            $reservation = $this->resource;
            $ticket = $reservation->relationLoaded('gloTicket') ? $reservation->gloTicket : null;
        } elseif ($this->resource instanceof GloTicket) {
            $ticket = $this->resource;
        } elseif (is_array($this->resource)) {
            $reservation = $this->resource['reservation'] ?? null;
            $ticket = $this->resource['ticket'] ?? null;
            $replayed = (bool) ($this->resource['replayed'] ?? false);
        }

        $payload = [
            'product' => 'l6',
            'replayed' => $replayed,
        ];

        if ($reservation instanceof GloL6TicketReservation) {
            $status = $reservation->status instanceof GloL6ReservationStatus
                ? $reservation->status->value
                : (string) $reservation->status;
            $currency = $reservation->currency instanceof Currency
                ? $reservation->currency->value
                : (string) $reservation->currency;

            $payload['reservation'] = [
                'reference' => (string) $reservation->reservation_reference,
                'reservation_reference' => (string) $reservation->reservation_reference,
                'draw_id' => (int) $reservation->draw_id,
                'ticket_number' => (string) $reservation->ticket_number,
                'set_series' => (int) $reservation->set_series,
                'status' => $status,
                'unit_price' => bcadd((string) $reservation->unit_price, '0.00', 2),
                'currency' => $currency,
                'pricing_snapshot' => (array) $reservation->pricing_snapshot,
                'reserved_until' => $reservation->reserved_until?->toIso8601String(),
                'purchased_at' => $reservation->purchased_at?->toIso8601String(),
                'released_at' => $reservation->released_at?->toIso8601String(),
            ];
        }

        if ($ticket instanceof GloTicket) {
            $meta = (array) $ticket->metadata;

            $payload['ticket'] = [
                'ticket_reference' => (string) $ticket->ticket_reference,
                'draw_id' => (int) $ticket->draw_id,
                'product' => (string) $ticket->product,
                'ticket_number' => (string) $ticket->ticket_number,
                'set_series' => $ticket->set_series !== null ? (int) $ticket->set_series : 1,
                'status' => (string) ($meta['status'] ?? 'active'),
                'unit_price' => isset($meta['price_thb'])
                    ? bcadd((string) $meta['price_thb'], '0.00', 2)
                    : (isset($meta['price']) ? bcadd((string) $meta['price'], '0.00', 2) : '80.00'),
                'currency' => (string) ($meta['currency'] ?? 'THB'),
                'digital_seal_hash' => isset($meta['digital_seal_hash']) ? (string) $meta['digital_seal_hash'] : null,
                'pricing_snapshot' => isset($meta['pricing_snapshot']) && is_array($meta['pricing_snapshot'])
                    ? $meta['pricing_snapshot']
                    : null,
                'purchased_at' => $ticket->created_at?->toIso8601String(),
            ];
        }

        return $payload;
    }
}
