<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\AuditAction;
use App\Enums\DrawStatus;
use App\Enums\RiskLevel;
use App\Exceptions\GloSalesException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\GloN3Sale;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * N3 settlement service (GLO-7/9).
 *
 * Sequence for a draw:
 *  1. Load N3 sales seat — refuse if missing or GLON3_SALES_CONFLICT.
 *  2. pool = gross_sales × pool_rate (allocator; already on the seat row).
 *  3. drawForPool() → CSPRNG numbers + variable baht per winner.
 *  4. Persist the draw's GLO metadata n3 payload (provenance on the seat).
 *  5. Audit. NEVER writes a payout/wallet — money settlement of winners is
 *     the existing payout lane; this service only seats the official N3
 *     result structure and pool split.
 *
 * Idempotent: re-running a settled draw returns the existing structure when
 * metadata already carries an n3 fingerprint matching the seat pool.
 */
class GloN3SettlementService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloN3SaleService $sales,
        private readonly GloN3PrizeCalculator $calculator,
    ) {}

    /**
     * @return array{
     *     draw_id: int,
     *     seat_key: string,
     *     pool: string,
     *     numbers: array<string, list<string>>,
     *     per_winner: array<string, string>,
     *     allocation: array<string, mixed>,
     *     status: string,
     *     idempotent: bool
     * }
     */
    public function settle(Draw $draw, ?User $actor = null, bool $dryRun = false): array
    {
        $drawId = (int) $draw->getKey();
        $seat = $this->sales->seatForDraw($drawId);

        if ($seat === null) {
            throw GloSalesException::seatNotSeated(GloN3Sale::seatKey($drawId, 'n3'));
        }

        if ($seat->isConflicted()) {
            throw GloSalesException::seatConflict($seat->seat_key, (string) ($seat->conflict_gate ?? 'GLON3_SALES_CONFLICT'));
        }

        $pool = bcadd((string) $seat->pool_amount, '0.00', 2);

        if (bccomp($pool, '0.00', 2) <= 0) {
            throw GloSalesException::poolNotComputable('seat pool_amount must be positive');
        }

        // Idempotency fingerprint on draw metadata.
        $fingerprint = hash('sha256', implode('|', ['n3-settle', (string) $drawId, $seat->seat_key, $pool]));
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $tierKey = (string) config('glo.tiers.metadata_key', 'glo');
        $existing = is_array($metadata[$tierKey] ?? null) ? $metadata[$tierKey] : [];

        if (isset($existing['n3_fingerprint']) && $existing['n3_fingerprint'] === $fingerprint) {
            return [
                'draw_id' => $drawId,
                'seat_key' => $seat->seat_key,
                'pool' => $pool,
                'numbers' => $existing['n3_numbers'] ?? [],
                'per_winner' => $existing['n3_per_winner'] ?? [],
                'allocation' => $existing['n3_allocation'] ?? [],
                'status' => 'already_settled',
                'idempotent' => true,
            ];
        }

        $result = $this->calculator->drawForPool($pool);

        if ($dryRun) {
            return [
                'draw_id' => $drawId,
                'seat_key' => $seat->seat_key,
                'pool' => $pool,
                'numbers' => $result['numbers'],
                'per_winner' => $result['per_winner'],
                'allocation' => $result['allocation'],
                'status' => 'dry_run',
                'idempotent' => false,
            ];
        }

        return $this->db->connection()->transaction(function () use ($draw, $drawId, $seat, $pool, $fingerprint, $result, $actor): array {
            $fresh = Draw::query()->whereKey($drawId)->lockForUpdate()->firstOrFail();
            $metadata = is_array($fresh->metadata) ? $fresh->metadata : [];
            $tierKey = (string) config('glo.tiers.metadata_key', 'glo');
            $lane = is_array($metadata[$tierKey] ?? null) ? $metadata[$tierKey] : [];

            if (isset($lane['n3_fingerprint']) && $lane['n3_fingerprint'] === $fingerprint) {
                return [
                    'draw_id' => $drawId,
                    'seat_key' => $seat->seat_key,
                    'pool' => $pool,
                    'numbers' => $lane['n3_numbers'] ?? [],
                    'per_winner' => $lane['n3_per_winner'] ?? [],
                    'allocation' => $lane['n3_allocation'] ?? [],
                    'status' => 'already_settled',
                    'idempotent' => true,
                ];
            }

            $lane['n3_fingerprint'] = $fingerprint;
            $lane['n3_numbers'] = $result['numbers'];
            $lane['n3_per_winner'] = $result['per_winner'];
            $lane['n3_allocation'] = $result['allocation'];
            $lane['n3_pool'] = $pool;
            $lane['n3_engine'] = $result['engine'];
            $lane['n3_settled_at'] = now()->toIso8601String();
            $metadata[$tierKey] = $lane;
            $fresh->metadata = $metadata;
            $fresh->save();

            AuditLog::create([
                'user_id' => $actor?->getKey(),
                'action' => AuditAction::Update,
                'risk_level' => RiskLevel::High,
                'auditable_type' => Draw::class,
                'auditable_id' => $drawId,
                'description' => 'glo_n3_settled',
                'metadata' => [
                    'action_type' => 'glo_n3_settled',
                    'draw_id' => $drawId,
                    'seat_key' => $seat->seat_key,
                    'pool' => $pool,
                    'engine' => $result['engine'],
                    // Digit strings only — no int-cast.
                    'groups' => array_map('count', $result['numbers']),
                ],
            ]);

            return [
                'draw_id' => $drawId,
                'seat_key' => $seat->seat_key,
                'pool' => $pool,
                'numbers' => $result['numbers'],
                'per_winner' => $result['per_winner'],
                'allocation' => $result['allocation'],
                'status' => 'settled',
                'idempotent' => false,
            ];
        });
    }
}
