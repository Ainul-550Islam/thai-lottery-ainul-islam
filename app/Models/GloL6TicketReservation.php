<?php

declare(strict_types=1);

namespace App\Models;

use App\DTOs\Lottery\GloL6PricingSnapshotData;
use App\Enums\Currency;
use App\Enums\GloL6ReservationStatus;
use App\Exceptions\GloL6PurchaseException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class GloL6TicketReservation extends Model
{
    protected $table = 'glo_l6_ticket_reservations';

    protected $fillable = [
        'reservation_reference',
        'draw_id',
        'glo_l6_sale_id',
        'user_id',
        'wallet_id',
        'wallet_reservation_id',
        'ticket_number',
        'set_series',
        'status',
        'unit_price',
        'currency',
        'pricing_snapshot',
        'idempotency_key',
        'purchase_idempotency_key',
        'request_fingerprint',
        'reserved_until',
        'purchased_at',
        'released_at',
        'glo_ticket_id',
        'financial_transaction_id',
        'refund_financial_transaction_id',
        'failure_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'draw_id' => 'integer',
            'glo_l6_sale_id' => 'integer',
            'user_id' => 'integer',
            'wallet_id' => 'integer',
            'wallet_reservation_id' => 'integer',
            'set_series' => 'integer',
            'status' => GloL6ReservationStatus::class,
            'unit_price' => 'string',
            'currency' => Currency::class,
            'pricing_snapshot' => 'array',
            'reserved_until' => 'datetime',
            'purchased_at' => 'datetime',
            'released_at' => 'datetime',
            'glo_ticket_id' => 'integer',
            'financial_transaction_id' => 'integer',
            'refund_financial_transaction_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (self $reservation): void {
            $immutableColumns = [
                'reservation_reference',
                'draw_id',
                'user_id',
                'wallet_id',
                'ticket_number',
                'set_series',
                'unit_price',
                'currency',
                'pricing_snapshot',
                'idempotency_key',
                'request_fingerprint',
            ];

            foreach ($immutableColumns as $column) {
                if ($reservation->isDirty($column)) {
                    throw new LogicException(sprintf(
                        'Immutable GLO L6 reservation attribute [%s] cannot be modified after creation.',
                        $column,
                    ));
                }
            }
        });
    }

    public function verifiedPricingSnapshot(): GloL6PricingSnapshotData
    {
        try {
            $snapshot = GloL6PricingSnapshotData::fromArray((array) $this->pricing_snapshot);
        } catch (\InvalidArgumentException) {
            $raw = (array) $this->pricing_snapshot;
            throw GloL6PurchaseException::pricingMismatch(
                (string) ($raw['unit_price'] ?? 'tampered'),
                bcadd((string) $this->unit_price, '0.00', 2),
            );
        }

        if (bccomp($snapshot->unitPrice, (string) $this->unit_price, 2) !== 0) {
            throw GloL6PurchaseException::pricingMismatch(
                $snapshot->unitPrice,
                bcadd((string) $this->unit_price, '0.00', 2),
            );
        }

        return $snapshot;
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function gloTicket(): BelongsTo
    {
        return $this->belongsTo(GloTicket::class, 'glo_ticket_id');
    }
}
