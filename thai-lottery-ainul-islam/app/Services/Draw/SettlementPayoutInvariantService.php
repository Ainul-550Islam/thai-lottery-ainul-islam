<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Models\Draw;
use App\Models\Payout;
use App\Services\Concerns\GuardedObservation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Did the draw pay out more than it published, and do the counters agree?
 *
 * Three independent records of the same money exist after settlement:
 *   1. `winning_numbers.total_payout`  — the published entitlement per number
 *   2. `payouts.amount`                — what was actually credited
 *   3. `draws.total_payout`            — the denormalised counter on the draw
 *
 * They are written by different code paths. This service compares them and
 * names which one disagrees. It is read-only: it cannot reverse a payout or
 * correct a counter, and it does not try.
 *
 * WHY "MORE THAN PUBLISHED" IS THE CHECK THAT MATTERS
 * Paying a player less than the published prize is a complaint. Paying more
 * than the published prize is money leaving the business that no published
 * result authorises, and it is the direction nobody notices until the
 * reconciliation report is late. So the comparison is deliberately one-sided:
 * `over_published` only fires when the paid total exceeds the published
 * entitlement, and a shortfall is reported as its own, differently named
 * verdict so the two are never confused.
 */
final class SettlementPayoutInvariantService
{
    use GuardedObservation;

    public const GUARD = 'payout_invariant';

    private const SETTLED_STATUSES = [
        DrawStatus::ResultPublished->value,
        DrawStatus::Completed->value,
    ];

    protected function guardKey(): string
    {
        return self::GUARD;
    }

    /**
     * @return array{guard: string, verdict: string, mode: string, draw_id: int, checked: bool, figures: array<string, mixed>, divergences: list<string>}
     */
    public function verify(int $drawId): array
    {
        if ($this->guardMode() === self::GUARD_MODE_OFF) {
            return $this->verdict(self::GUARD, 'off', [
                'draw_id' => $drawId,
                'checked' => false,
                'figures' => [],
                'divergences' => [],
            ]);
        }

        /** @var Draw|null $draw */
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            return $this->finish($drawId, 'draw_not_found', [], ['draw_not_found']);
        }

        if (! in_array($draw->status->value, self::SETTLED_STATUSES, true)) {
            return $this->finish($drawId, 'not_settled_yet', ['status' => $draw->status->value], []);
        }

        $published = $this->amount(DB::table('winning_numbers')
            ->whereNull('deleted_at')
            ->where('draw_id', $drawId)
            ->sum('total_payout'));

        $paid = $this->amount(Payout::query()
            ->where('draw_id', $drawId)
            ->where('status', PayoutStatus::Completed->value)
            ->sum('amount'));

        $counter = $this->amount($draw->total_payout ?? 0);

        $failed = (int) Payout::query()
            ->where('draw_id', $drawId)
            ->where('status', PayoutStatus::Failed->value)
            ->count();

        $foreign = (int) Payout::query()
            ->where('draw_id', $drawId)
            ->where('status', PayoutStatus::Completed->value)
            ->whereNotNull('bet_id')
            ->whereExists(function (Builder $query) use ($drawId): void {
                $query->select(DB::raw(1))
                    ->from('bets')
                    ->whereColumn('bets.id', 'payouts.bet_id')
                    ->where('bets.draw_id', '<>', $drawId);
            })
            ->count();

        $divergences = [];

        if (bccomp($paid, $published, 2) > 0) {
            $divergences[] = 'over_published:paid='.$paid.' published='.$published;
        }

        if (bccomp($paid, $counter, 2) !== 0) {
            $divergences[] = 'counter_mismatch:paid='.$paid.' draw_total_payout='.$counter;
        }

        if ($foreign > 0) {
            $divergences[] = 'completed_payouts_for_another_draw:'.$foreign;
        }

        $verdict = match (true) {
            $divergences !== [] => 'divergent',
            bccomp($paid, $published, 2) < 0 => 'under_published',
            default => 'agree',
        };

        return $this->finish($drawId, $verdict, [
            'status' => $draw->status->value,
            'published_entitlement' => $published,
            'paid_completed' => $paid,
            'draw_total_payout_counter' => $counter,
            'failed_payouts' => $failed,
        ], $divergences);
    }

    /**
     * @param  list<string>  $divergences
     * @return array{guard: string, verdict: string, mode: string, draw_id: int, checked: bool, figures: array<string, mixed>, divergences: list<string>}
     */
    private function finish(int $drawId, string $verdict, array $figures, array $divergences): array
    {
        $this->recordObservation(self::GUARD, $verdict, [
            'draw_id' => $drawId,
            'figures' => $figures,
            'divergences' => $divergences,
        ]);

        if ($verdict === 'divergent' && $this->guardMode() === self::GUARD_MODE_ENFORCE) {
            throw new RuntimeException(
                'Payout invariant diverges for draw '.$drawId.': '.implode('; ', $divergences),
            );
        }

        return $this->verdict(self::GUARD, $verdict, [
            'draw_id' => $drawId,
            'checked' => true,
            'figures' => $figures,
            'divergences' => $divergences,
        ]);
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
