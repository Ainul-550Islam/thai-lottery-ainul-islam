<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\GloSalesException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloN3Sale;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO N3 sales seat service (GLO-9).
 *
 * One seat per (draw_id, product n3). Operations:
 *  - openSeat / recordSales: seat sales rows under lock; pool = gross × rate
 *  - closeSeat: terminal for ordinary writes
 *  - flagConflict: seats GLON3_SALES_CONFLICT — blocks auto reconciliation
 *
 * Concurrency: DB transaction + lockForUpdate + unique seat_key.
 * Money: BCMath strings only.
 */
class GloN3SaleService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloN3PrizePoolAllocator $allocator,
    ) {}

    /**
     * Create or update the N3 sales seat for a draw.
     *
     * @param array{seats_sold?: int, gross_sales?: string, source_reference?: string|null, provenance?: string} $input
     */
    public function seatSales(Draw $draw, array $input, ?User $actor = null): GloN3Sale
    {
        $drawId = (int) $draw->getKey();
        $seatKey = GloN3Sale::seatKey($drawId, 'n3');

        $seatsSold = (int) ($input['seats_sold'] ?? 0);

        if ($seatsSold < 0) {
            throw GloSalesException::invalidUnits('seats_sold must be non-negative');
        }

        $gross = isset($input['gross_sales'])
            ? bcadd((string) $input['gross_sales'], '0.00', 2)
            : bcmul($this->allocator->ticketPrice(), (string) $seatsSold, 2);

        // Gross must always equal seats × ticket_price when seats are provided.
        if (isset($input['seats_sold'])) {
            $expected = bcmul($this->allocator->ticketPrice(), (string) $seatsSold, 2);

            if (bccomp($expected, $gross, 2) !== 0) {
                throw GloSalesException::invalidUnits('gross_sales does not match seats_sold × ticket_price');
            }
        }

        $pool = $this->allocator->poolFromGrossSales($gross);

        return $this->db->connection()->transaction(function () use ($draw, $drawId, $seatKey, $seatsSold, $gross, $pool, $input, $actor): GloN3Sale {
            $existing = GloN3Sale::query()
                ->where('seat_key', $seatKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->isConflicted()) {
                    throw GloSalesException::seatConflict($seatKey, (string) ($existing->conflict_gate ?? 'GLON3_SALES_CONFLICT'));
                }

                if ($existing->isClosed()) {
                    throw GloSalesException::seatAlreadySeated($seatKey.' (closed)');
                }

                $existing->seats_sold = $seatsSold;
                $existing->gross_sales = $gross;
                $existing->pool_amount = $pool;
                $existing->source_reference = $input['source_reference'] ?? $existing->source_reference;
                $existing->save();

                $this->audit($actor, $existing, 'glo_n3_sales_updated');

                return $existing;
            }

            $sale = GloN3Sale::create([
                'draw_id' => $drawId,
                'product' => 'n3',
                'seat_key' => $seatKey,
                'seats_sold' => $seatsSold,
                'seats_full' => (int) ($input['seats_full'] ?? 0),
                'gross_sales' => $gross,
                'pool_amount' => $pool,
                'ticket_price' => $this->allocator->ticketPrice(),
                'seat_state' => 'open',
                'conflict_gate' => null,
                'source_reference' => $input['source_reference'] ?? null,
                'provenance' => $input['provenance'] ?? 'operator_seat',
                'metadata' => ['draw_number' => $draw->draw_number],
            ]);

            $this->audit($actor, $sale, 'glo_n3_sales_seated');

            return $sale;
        });
    }

    public function closeSeat(GloN3Sale $sale, ?User $actor = null): GloN3Sale
    {
        return $this->db->connection()->transaction(function () use ($sale, $actor): GloN3Sale {
            $fresh = GloN3Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isConflicted()) {
                throw GloSalesException::seatConflict($fresh->seat_key, (string) $fresh->conflict_gate);
            }

            if ($fresh->isClosed()) {
                return $fresh;
            }

            $fresh->seat_state = 'closed';
            $fresh->save();
            $this->audit($actor, $fresh, 'glo_n3_sales_closed');

            return $fresh;
        });
    }

    /**
     * Seat GLON3_SALES_CONFLICT on the N3 sales row (idempotent per seat).
     */
    public function flagConflict(GloN3Sale $sale, string $gate, ?string $reason = null, ?User $actor = null): GloN3Sale
    {
        return $this->db->connection()->transaction(function () use ($sale, $gate, $reason, $actor): GloN3Sale {
            $fresh = GloN3Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

            $fresh->seat_state = 'conflicted';
            $fresh->conflict_gate = $gate;
            $meta = is_array($fresh->metadata) ? $fresh->metadata : [];
            $meta['conflict_reason'] = $reason;
            $meta['conflicted_at'] = now()->toIso8601String();
            $fresh->metadata = $meta;
            $fresh->save();

            $this->audit($actor, $fresh, 'glo_n3_sales_conflict', RiskLevel::High);

            return $fresh;
        });
    }

    public function seatForDraw(int $drawId): ?GloN3Sale
    {
        return GloN3Sale::query()
            ->where('seat_key', GloN3Sale::seatKey($drawId, 'n3'))
            ->first();
    }

    private function audit(?User $actor, GloN3Sale $sale, string $type, RiskLevel $risk = RiskLevel::High): void
    {
        AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => AuditAction::Update,
            'risk_level' => $risk,
            'auditable_type' => GloN3Sale::class,
            'auditable_id' => $sale->getKey(),
            'description' => $type,
            'metadata' => [
                'action_type' => $type,
                'seat_key' => $sale->seat_key,
                'seat_state' => $sale->seat_state,
                'conflict_gate' => $sale->conflict_gate,
                'pool_amount' => $sale->pool_amount,
                'seats_sold' => $sale->seats_sold,
            ],
        ]);
    }
}
