<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\GloSalesException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloL6Sale;
use App\Models\GloN3Sale;
use App\Models\GloSalesReconciliation;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * GLO sales reconciliation (GLO-9).
 *
 * Compares expected seat totals (from seated sales rows + optional expected
 * input) against recorded sales for each product and writes a
 * GloSalesReconciliation row.
 *
 * Conflict gate: when N3 sales carry GLON3_SALES_CONFLICT (or expected vs
 * recorded disagree on a conflicted seat), status=conflicted with
 * conflict_gate=GLON3_SALES_CONFLICT. Auto-settlement of that seat must not
 * proceed until an operator clears the gate.
 *
 * Money: BCMath variance = recorded − expected (scale 2, may be negative).
 */
class GloSalesReconciliationService
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Reconcile one (draw, product).
     *
     * @param array{expected_seats?: int, expected_gross?: string} $expected
     * @return array{
     *     reconciliation: GloSalesReconciliation,
     *     status: string,
     *     conflict_gate: string|null,
     *     variance_gross: string,
     *     blocked: bool
     * }
     */
    public function reconcile(
        Draw $draw,
        string $product,
        array $expected = [],
        ?User $actor = null,
    ): array {
        $drawId = (int) $draw->getKey();
        $gate = (string) config('glo.reconciliation.conflict_gate', 'GLON3_SALES_CONFLICT');

        if (! in_array($product, ['l6', 'n3'], true)) {
            throw GloSalesException::invalidUnits('unknown product '.$product);
        }

        return $this->db->connection()->transaction(function () use ($draw, $drawId, $product, $expected, $actor, $gate): array {
            $recordedSeats = 0;
            $recordedGross = '0.00';
            $seatConflicted = false;
            $seatGate = null;

            if ($product === 'n3') {
                $seat = GloN3Sale::query()
                    ->where('seat_key', GloN3Sale::seatKey($drawId, 'n3'))
                    ->lockForUpdate()
                    ->first();

                if ($seat !== null) {
                    $recordedSeats = (int) $seat->seats_sold;
                    $recordedGross = bcadd((string) $seat->gross_sales, '0.00', 2);
                    $seatConflicted = $seat->isConflicted();
                    $seatGate = $seat->conflict_gate;
                }
            } else {
                $seat = GloL6Sale::query()
                    ->where('seat_key', GloL6Sale::seatKey($drawId, 'l6'))
                    ->lockForUpdate()
                    ->first();

                if ($seat !== null) {
                    $recordedSeats = (int) $seat->units_sold;
                    $recordedGross = bcadd((string) $seat->gross_sales, '0.00', 2);
                }
            }

            $expectedSeats = (int) ($expected['expected_seats'] ?? $recordedSeats);
            $expectedGross = isset($expected['expected_gross'])
                ? bcadd((string) $expected['expected_gross'], '0.00', 2)
                : $recordedGross;

            $variance = bcsub($recordedGross, $expectedGross, 2);
            $seatsMatch = $expectedSeats === $recordedSeats;
            $moneyMatch = bccomp($variance, '0.00', 2) === 0;

            $status = 'matched';
            $conflictGate = null;

            if ($seatConflicted) {
                $status = 'conflicted';
                $conflictGate = (string) ($seatGate ?? $gate);
            } elseif (! $seatsMatch || ! $moneyMatch) {
                // N3 mismatches always seat the documented gate; L6 uses the
                // same gate name so operators have one vocabulary.
                $status = 'conflicted';
                $conflictGate = $gate;
            }

            $reconciliation = GloSalesReconciliation::create([
                'reconciliation_reference' => 'GLOREC-'.Str::upper(Str::random(16)),
                'draw_id' => $drawId,
                'product' => $product,
                'expected_seats' => $expectedSeats,
                'recorded_seats' => $recordedSeats,
                'expected_gross' => $expectedGross,
                'recorded_gross' => $recordedGross,
                'variance_gross' => $variance,
                'status' => $status,
                'conflict_gate' => $conflictGate,
                'details' => [
                    'seats_match' => $seatsMatch,
                    'money_match' => $moneyMatch,
                    'seat_key' => $product === 'n3'
                        ? GloN3Sale::seatKey($drawId, 'n3')
                        : GloL6Sale::seatKey($drawId, 'l6'),
                ],
                'reconciled_by' => $actor?->getKey(),
                'reconciled_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'action' => AuditAction::Reconcile,
                'risk_level' => $status === 'matched' ? RiskLevel::Medium : RiskLevel::High,
                'auditable_type' => GloSalesReconciliation::class,
                'auditable_id' => $reconciliation->getKey(),
                'description' => 'glo_sales_reconciled',
                'metadata' => [
                    'action_type' => 'glo_sales_reconciled',
                    'draw_id' => $drawId,
                    'product' => $product,
                    'status' => $status,
                    'conflict_gate' => $conflictGate,
                    'variance_gross' => $variance,
                    'reconciliation_reference' => $reconciliation->reconciliation_reference,
                ],
            ]);

            return [
                'reconciliation' => $reconciliation,
                'status' => $status,
                'conflict_gate' => $conflictGate,
                'variance_gross' => $variance,
                'blocked' => $status === 'conflicted',
            ];
        });
    }

    /**
     * Reconcile both products for a draw.
     *
     * @return array{n3: array<string, mixed>|null, l6: array<string, mixed>|null}
     */
    public function reconcileDraw(Draw $draw, ?User $actor = null): array
    {
        $drawId = (int) $draw->getKey();
        $out = ['n3' => null, 'l6' => null];

        if (GloN3Sale::query()->where('seat_key', GloN3Sale::seatKey($drawId, 'n3'))->exists()) {
            $out['n3'] = $this->reconcile($draw, 'n3', [], $actor);
        }

        if (GloL6Sale::query()->where('seat_key', GloL6Sale::seatKey($drawId, 'l6'))->exists()) {
            $out['l6'] = $this->reconcile($draw, 'l6', [], $actor);
        }

        return $out;
    }
}
