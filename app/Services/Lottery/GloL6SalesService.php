<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\GloSalesException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloL6Sale;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

/**
 * GLO L6 sales seat service (GLO-9 analogue of the N3 seat).
 *
 * One seat per (draw_id, product l6). Units integer, money BCMath strings.
 * Unique (draw_id, product) + seat_key under transaction/lockForUpdate.
 */
class GloL6SalesService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloL6ProportionalPrizeCalculator $calculator,
    ) {}

    /**
     * @param array{units_sold?: int, gross_sales?: string, source_reference?: string|null, provenance?: string} $input
     */
    public function seatSales(Draw $draw, array $input, ?User $actor = null): GloL6Sale
    {
        $drawId = (int) $draw->getKey();
        $seatKey = GloL6Sale::seatKey($drawId, 'l6');
        $unitsFull = (int) config('glo.l6.full_sale_units', 1000000);

        $unitsSold = (int) ($input['units_sold'] ?? 0);

        if ($unitsSold < 0) {
            throw GloSalesException::invalidUnits('units_sold must be non-negative');
        }

        if ($unitsSold > $unitsFull) {
            throw GloSalesException::overCapacity($unitsSold, $unitsFull);
        }

        $gross = isset($input['gross_sales'])
            ? bcadd((string) $input['gross_sales'], '0.00', 2)
            : $this->calculator->grossForUnits($unitsSold);

        return $this->db->connection()->transaction(function () use ($draw, $drawId, $seatKey, $unitsSold, $unitsFull, $gross, $input, $actor): GloL6Sale {
            $existing = GloL6Sale::query()
                ->where('seat_key', $seatKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->units_sold = $unitsSold;
                $existing->gross_sales = $gross;
                $existing->source_reference = $input['source_reference'] ?? $existing->source_reference;
                $existing->save();

                $this->audit($actor, $existing, 'glo_l6_sales_updated');

                return $existing;
            }

            $sale = GloL6Sale::create([
                'draw_id' => $drawId,
                'product' => 'l6',
                'seat_key' => $seatKey,
                'units_sold' => $unitsSold,
                'units_full' => $unitsFull,
                'gross_sales' => $gross,
                'ticket_price' => $this->calculator->ticketPrice(),
                'source_reference' => $input['source_reference'] ?? null,
                'provenance' => $input['provenance'] ?? 'operator_seat',
                'metadata' => ['draw_number' => $draw->draw_number],
            ]);

            $this->audit($actor, $sale, 'glo_l6_sales_seated');

            return $sale;
        });
    }

    public function seatForDraw(int $drawId): ?GloL6Sale
    {
        return GloL6Sale::query()
            ->where('seat_key', GloL6Sale::seatKey($drawId, 'l6'))
            ->first();
    }

    /**
     * Proportional prize breakdown for the draw's seated sales.
     *
     * @return array<string, mixed>
     */
    public function proportionalForDraw(int $drawId): array
    {
        $seat = $this->seatForDraw($drawId);

        if ($seat === null) {
            throw GloSalesException::seatNotSeated(GloL6Sale::seatKey($drawId, 'l6'));
        }

        return $this->calculator->proportionalBreakdown((int) $seat->units_sold, (int) $seat->units_full);
    }

    private function audit(?User $actor, GloL6Sale $sale, string $type): void
    {
        AuditLog::create([
            'user_id' => $actor?->getKey(),
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => GloL6Sale::class,
            'auditable_id' => $sale->getKey(),
            'description' => $type,
            'metadata' => [
                'action_type' => $type,
                'seat_key' => $sale->seat_key,
                'units_sold' => $sale->units_sold,
                'gross_sales' => $sale->gross_sales,
            ],
        ]);
    }
}
