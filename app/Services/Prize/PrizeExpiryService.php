<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\UnclaimedPrizeData;
use App\Enums\AuditAction;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Per-row locked, non-monetary legacy annotations. Never cancels a payout, releases funds or transfers a disposal balance. */
final class PrizeExpiryService
{
    public function run(string $mode, ?int $chunkSize = null): array
    {
        $chunkSize ??= 200;
        if (! in_array($mode, ['expire', 'sweep', 'window'], true) || $chunkSize < 1 || $chunkSize > 1000) {
            throw new \InvalidArgumentException('A known annotation mode and chunk size 1 to 1000 are required.');
        }
        $query = Payout::query()->where('status', PayoutStatus::Pending->value)->select('id');
        if ($mode === 'expire') {
            $query->whereIn('metadata->claim->status', ['submitted', 'under_review', 'approved']);
        } elseif ($mode === 'sweep') {
            $query->where('metadata->unclaimed->status', 'expired');
        } else {
            $query->where('metadata->claim_window->status', 'open');
        }
        $cutoff = now();
        $events = [];
        $totals = [];
        $skipped = 0;
        $query->orderBy('id')->chunkById($chunkSize, function ($ids) use ($mode, $cutoff, &$events, &$totals, &$skipped): void {
            foreach ($ids as $id) {
                $result = DB::transaction(fn () => $this->annotate((int) $id->id, $mode, $cutoff), 3);
                if ($result === null) {
                    $skipped++;

                    continue;
                }
                $events[] = $result;
                $currency = $result['currency'];
                $totals[$currency] = bcadd($totals[$currency] ?? '0.00', $result['amount'], 2);
            }
        });
        ksort($totals);

        return ['count' => count($events), 'events' => $events, 'totals_by_currency' => $totals,
            'single_currency_total' => count($totals) > 1 ? null : (array_values($totals)[0] ?? '0.00'),
            'skipped_candidates' => $skipped, 'annotation_only' => true, 'executed_at' => $cutoff->toIso8601String()];
    }

    private function annotate(int $id, string $mode, Carbon $cutoff): ?array
    {
        $row = Payout::query()->lockForUpdate()->find($id);
        $guard = app(PrizeClaimGuard::class);
        if (! $row || $guard->protected($row) || bccomp((string) $row->amount, '0', 2) <= 0) {
            return null;
        }
        $m = $row->metadata ?? [];
        $claim = $m['claim'] ?? null;
        if ($mode === 'window') {
            $bet = Bet::withTrashed()->find($row->bet_id);
            if (! $bet || $guard->resolve($bet)?->id !== $row->id) {
                return null;
            }
        }
        if ($mode !== 'window' || (is_array($claim) && (array_key_exists('status', $claim) || array_key_exists('claimant_user_id', $claim)))) {
            if (! is_array($claim)) {
                return null;
            }
            try {
                $guard->assertSnapshot($row, $claim);
            } catch (PrizeClaimException) {
                return null; // Historical missing facts are held, not backfilled or expired by inference.
            }
        }
        $lane = $mode === 'window' ? ($m['claim_window'] ?? null) : $claim;
        $edge = is_array($lane) ? $guard->deadline($lane['window_closes_at'] ?? null) : null;
        if (! $edge || $edge->greaterThan($cutoff)) {
            return null;
        }
        if ($mode === 'window') {
            if (($lane['status'] ?? null) !== 'open' || ! $guard->hasWindowSnapshot($row)) {
                return null;
            }
            $m['claim_window'] = array_merge($lane, ['status' => 'closed', 'closed_via' => 'expired', 'closed_at' => $cutoff->toIso8601String()]);
        } elseif ($mode === 'expire') {
            if (array_key_exists('unclaimed', $m) || ! in_array($claim['status'] ?? null, ['submitted', 'under_review', 'approved'], true)) {
                return null;
            }
            $key = UnclaimedPrizeData::deriveDisposalKey($row->reference_number, (string) $row->amount, $row->currency, $claim['window_closes_at']);
            $m['claim'] = array_merge($claim, ['status' => 'expired', 'expired_at' => $cutoff->toIso8601String()]);
            $m['unclaimed'] = ['status' => 'expired', 'disposal_key' => $key, 'facts_hash' => $guard->fingerprint($row),
                'expired_at' => $cutoff->toIso8601String(), 'swept_at' => null, 'annotation_only' => true];
        } else {
            $disposal = $m['unclaimed'] ?? null;
            $key = UnclaimedPrizeData::deriveDisposalKey($row->reference_number, (string) $row->amount, $row->currency, $claim['window_closes_at']);
            $expiredAt = is_array($disposal) ? $guard->deadline($disposal['expired_at'] ?? null) : null;
            if (! $expiredAt || $expiredAt->lessThan($edge) || $expiredAt->greaterThan($cutoff)
                || ($disposal['expired_at'] ?? null) !== ($claim['expired_at'] ?? null)
                || ! is_array($disposal) || ($disposal['status'] ?? null) !== 'expired' || ($claim['status'] ?? null) !== 'expired'
                || ($disposal['disposal_key'] ?? null) !== $key || ($disposal['facts_hash'] ?? null) !== $guard->fingerprint($row)) {
                return null;
            }
            $m['unclaimed'] = array_merge($disposal, ['status' => 'swept', 'swept_at' => $cutoff->toIso8601String(), 'annotation_only' => true]);
        }
        $row->metadata = $m;
        $row->save();
        AuditLog::query()->create(['action' => AuditAction::Update, 'risk_level' => RiskLevel::High,
            'auditable_type' => Payout::class, 'auditable_id' => $row->id, 'description' => 'Legacy prize '.$mode.' annotation recorded; no funds moved or payout cancelled.',
            'metadata' => ['payout_id' => (int) $row->id, 'mode' => $mode, 'annotation_only' => true, 'currency' => $row->currency->value]]);

        return ['payout_id' => (int) $row->id, 'amount' => (string) $row->amount, 'currency' => $row->currency->value,
            'window_closed_at' => $edge->toIso8601String(), 'disposal_key' => $m['unclaimed']['disposal_key'] ?? null];
    }
}
