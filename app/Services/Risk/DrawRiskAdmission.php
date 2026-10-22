<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Exceptions\RiskConfigurationException;
use App\Models\Draw;
use Illuminate\Support\Facades\DB;

/** Current draw-first authority for NEW risk, never for refunds or historical replay. */
final class DrawRiskAdmission
{
    public function lock(int $drawId): Draw
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->refuse($drawId, 'transaction_required');
        }
        // Acquire before any number-limit lock. A quote, a validated purchase
        // context and a caller's old REPEATABLE READ snapshot grant no admission.
        $draw = Draw::query()->whereKey($drawId)->lockForUpdate()->first();
        if (! $draw) {
            $this->refuse($drawId, 'draw_missing');
        }
        $clock = now();
        if (! $draw->canAcceptBets() || ! $draw->betting_open_at || ! $draw->betting_close_at
            || $draw->betting_open_at->greaterThan($clock) || ! $draw->betting_close_at->greaterThan($clock)
            || $draw->closed_at !== null || $draw->drawn_at !== null
            || $draw->result_published_at !== null || $draw->completed_at !== null) {
            $this->refuse($drawId, 'draw_not_open');
        }
        // Durable cancellation is a permanent admission barrier, even if a
        // mutable draw status was incorrectly reopened. Incomplete/corrupt owners
        // are review, never permission. Do not backfill or repair any history.
        if (DB::table('draw_cancellation_ownerships')->where('draw_id', $drawId)->lockForUpdate()->first()) {
            $this->refuse($drawId, 'draw_cancellation_exists');
        }
        if (DB::table('payouts')->where('draw_id', $drawId)->lockForUpdate()->first()
            || DB::table('settlement_runs')->where('draw_id', $drawId)->lockForUpdate()->first()) {
            $this->refuse($drawId, 'draw_settlement_history');
        }

        return $draw;
    }

    private function refuse(int $drawId, string $reason): never
    {
        throw RiskConfigurationException::withCode('DRAW_RISK_ADMISSION_REFUSED',
            'Current locked draw admission is required before accepting new risk.', ['draw_id' => $drawId, 'reason' => $reason]);
    }
}
