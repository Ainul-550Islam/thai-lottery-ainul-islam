<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GloClaimChannel;
use App\Enums\GloClaimStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * GLO L6 Official 6-digit Printed Lottery Ticket Model.
 *
 * Dedicated L6 product model extending canonical GLO ticket functionality
 * with strict 6-digit number format validation, series tracking,
 * and tamper-evident reference generation.
 *
 * @property int $id
 * @property int $draw_id
 * @property string $product
 * @property string $ticket_number
 * @property string|null $set_series
 * @property int|null $owner_user_id
 * @property string $ticket_reference
 * @property array<string, mixed>|null $metadata
 */
class GloL6Ticket extends GloTicket
{
    protected $table = 'glo_tickets';

    protected static function booted(): void
    {
        static::addGlobalScope('l6_product', function (Builder $builder): void {
            $builder->where('product', 'l6');
        });

        static::creating(function (GloL6Ticket $ticket): void {
            $ticket->product = 'l6';
            $ticket->ticket_number = sprintf('%06s', preg_replace('/\D/', '', (string) $ticket->ticket_number));
            if (empty($ticket->ticket_reference)) {
                $ticket->ticket_reference = static::buildReference(
                    (int) $ticket->draw_id,
                    'l6',
                    $ticket->ticket_number,
                    $ticket->set_series
                );
            }
        });
    }

    /**
     * Validate that a ticket number is exactly 6 digits.
     */
    public static function isValidNumber(string $number): bool
    {
        return (bool) preg_match('/^\d{6}$/', $number);
    }
}
