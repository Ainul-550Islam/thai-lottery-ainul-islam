<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Services\Concerns\GuardedObservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Which result is the authority, and do the copies still agree with it?
 *
 * THE AUTHORITY, as the schema itself states
 * `2024_01_03_000300_create_winning_numbers_table.php` says the normalised
 * `winning_numbers` table is where results live, and `Draw` deliberately
 * declares no winning-numbers column so nothing can hold a second copy. But
 * `draw_results` (a Thai-lottery-shaped summary row: first/second/third
 * prize, totals) is written alongside it, and it carries its own
 * `total_payout` and `total_winners` counters.
 *
 * Two rows describing one draw can drift: a republication, a manual fix, a
 * partial write. Nothing in the current code asks whether they still say the
 * same thing. This service asks, and says which side it trusts.
 *
 * IT DOES NOT REPAIR ANYTHING. It reads. In `log` mode it writes an audit row
 * and returns the verdict; in `enforce` mode a divergence also raises so a
 * scheduled run fails loudly. Repair is an operator decision reached with the
 * divergence in hand, not something a checker should do quietly.
 */
final class ResultSourceAuthorityService
{
    use GuardedObservation;

    public const GUARD = 'result_authority';

    /** Statuses whose result must exist and must agree. */
    private const SETTLED_STATUSES = [
        DrawStatus::ResultPublished->value,
        DrawStatus::Completed->value,
    ];

    protected function guardKey(): string
    {
        return self::GUARD;
    }

    /**
     * @return array{guard: string, verdict: string, mode: string, draw_id: int, checked: bool, authority: array<string, mixed>, divergences: list<string>}
     */
    public function verify(int $drawId): array
    {
        $mode = $this->guardMode();

        if ($mode === self::GUARD_MODE_OFF) {
            return $this->verdict(self::GUARD, 'off', [
                'draw_id' => $drawId,
                'checked' => false,
                'authority' => [],
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

        $authority = DB::table('winning_numbers')
            ->whereNull('deleted_at')
            ->where('draw_id', $drawId)
            ->selectRaw('COUNT(*) as row_count')
            ->selectRaw('COALESCE(SUM(total_payout), 0) as payout')
            ->selectRaw('COALESCE(SUM(total_winners), 0) as winners')
            ->first();

        $summary = DB::table('draw_results')
            ->whereNull('deleted_at')
            ->where('draw_id', $drawId)
            ->first();

        $rows = (int) ($authority->row_count ?? 0);
        $authorityPayout = $this->amount($authority->payout ?? 0);
        $authorityWinners = (int) ($authority->winners ?? 0);

        $divergences = [];

        if ($rows === 0) {
            $divergences[] = 'authority_has_no_winning_numbers';
        }

        if ($summary === null) {
            $divergences[] = 'draw_results_row_missing';
        } else {
            $summaryPayout = $this->amount($summary->total_payout ?? 0);
            $summaryWinners = (int) ($summary->total_winners ?? 0);

            if (bccomp($authorityPayout, $summaryPayout, 2) !== 0) {
                $divergences[] = 'total_payout_disagrees:winning_numbers='.$authorityPayout.' draw_results='.$summaryPayout;
            }

            if ($authorityWinners !== $summaryWinners) {
                $divergences[] = 'total_winners_disagrees:winning_numbers='.$authorityWinners.' draw_results='.$summaryWinners;
            }

            /*
             * NOT CHECKED, AND WHY: an earlier draft of this guard also required
             * `draw_results.first_prize` to appear among the authority's
             * `number` values. The suite proved that is false for a ThreeD draw,
             * where the authority stores the derived forms (007, 07) rather than
             * the six-digit first prize. Comparing what the two rows were never
             * designed to hold in common would report a divergence on every
             * healthy draw, and a guard that cries wolf gets switched off.
             */
        }

        $verdict = $divergences === [] ? 'agree' : 'divergent';

        return $this->finish($drawId, $verdict, [
            'status' => $draw->status->value,
            'winning_number_rows' => $rows,
            'winning_numbers_total_payout' => $authorityPayout,
            'winning_numbers_total_winners' => $authorityWinners,
            'draw_results_total_payout' => $summary === null ? null : $this->amount($summary->total_payout ?? 0),
            'draw_results_total_winners' => $summary === null ? null : (int) ($summary->total_winners ?? 0),
        ], $divergences);
    }

    /**
     * @param  list<string>  $divergences
     * @return array{guard: string, verdict: string, mode: string, draw_id: int, checked: bool, authority: array<string, mixed>, divergences: list<string>}
     */
    private function finish(int $drawId, string $verdict, array $authority, array $divergences): array
    {
        $this->recordObservation(self::GUARD, $verdict, [
            'draw_id' => $drawId,
            'authority' => $authority,
            'divergences' => $divergences,
        ]);

        if ($verdict === 'divergent' && $this->guardMode() === self::GUARD_MODE_ENFORCE) {
            throw new RuntimeException(
                'Result authority diverges for draw '.$drawId.': '.implode('; ', $divergences),
            );
        }

        return $this->verdict(self::GUARD, $verdict, [
            'draw_id' => $drawId,
            'checked' => true,
            'authority' => $authority,
            'divergences' => $divergences,
        ]);
    }

    private function amount(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
