<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\DrawStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\LiabilityExposureService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Pages\Page;

/**
 * What the operator currently OWES on live digit-game draws, per number.
 *
 * WHY THIS SCREEN EXISTS AT ALL
 * A digit game's risk is not the number of bets; it is how many of them sit on the same
 * number. Ten thousand spread bets are a normal evening. Four hundred on "0042" at 9000x
 * is an existential draw, and no bet list shows that — the list is sorted by time, and the
 * concentration is invisible in it. This page shows the counter that actually decides
 * whether the next bet is accepted: reserved potential payout per (draw, number, market),
 * against the two caps the operator configured.
 *
 * WHY IT READS THROUGH LiabilityExposureService AND NOT THE TABLE
 * That service is the sole writer of those counters and the only place that knows how to
 * turn them into headroom. A page that summed the table itself would eventually round
 * differently, or include a row it should not, and an operator would size a draw against a
 * number no other component agrees with.
 *
 * WHAT IT SHOWS WHEN NOTHING IS OPEN
 * Nothing, with a reason. "No open draw" and "an open draw with no bets yet" are different
 * facts, and an operator needs to know which one they are looking at before concluding
 * that liability is zero.
 *
 * READ-ONLY, DELIBERATELY
 * There is no control here that changes a cap or releases a reservation. Caps are an
 * operator configuration decision (config/lottery_games.php, reviewed like code) and
 * releases happen through cancellation. An admin screen that could edit a liability
 * ceiling would be a way to raise the ceiling at the moment it binds, which is exactly the
 * moment it must not be raisable.
 */
class GameLiabilityPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'Game liability';

    protected static ?string $title = 'Digit game liability exposure';

    protected static ?int $navigationSort = 41;

    protected static string $view = 'filament.pages.game-liability-page';

    /**
     * Risk officers and draw operators both need this, and nobody else does.
     */
    public static function canAccess(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_RISK_ALERTS,
            AdminAccess::VIEW_DRAWS,
        ]);
    }

    /**
     * One row per declared game, with its live exposure or the reason there is none.
     *
     * @return list<array<string, mixed>>
     */
    public function games(): array
    {
        $registry = app(GameRegistry::class);
        $availability = app(GameAvailabilityService::class);
        $exposure = app(LiabilityExposureService::class);

        $rows = [];

        foreach ($registry->codes() as $code) {
            $definition = $registry->find($code);
            $capability = $availability->capability($code);
            $draw = $this->openDraw($code);

            $row = [
                'code' => $code,
                'name' => $definition?->name ?? $code,
                'sellable' => $capability->isSellable(),
                'reason' => $capability->reason,
                'draw_id' => $draw instanceof Draw ? (int) $draw->getKey() : null,
                'draw_number' => $draw instanceof Draw ? (string) $draw->draw_number : null,
                'draws_at' => $draw instanceof Draw ? AdminFormat::marketTime($draw->scheduled_at) : null,
                'per_number_cap' => null,
                'per_draw_cap' => null,
                'draw_reserved' => null,
                'draw_remaining' => null,
                'numbers' => [],
                'note' => null,
            ];

            if (! $draw instanceof Draw) {
                $row['note'] = 'No open draw: there is no live liability for this game.';

                $rows[] = $row;

                continue;
            }

            try {
                $headroom = $exposure->headroom((int) $draw->getKey(), $code);
            } catch (LotteryGameException $e) {
                // A cap that is still REQUIRED_INPUT has no headroom to compute. This is a
                // configuration fact, reported as such rather than shown as zero.
                $row['note'] = 'Liability caps are not configured ('.$e->errorCode().').';

                $rows[] = $row;

                continue;
            }

            $row['per_number_cap'] = AdminFormat::money($headroom['per_number_cap']);
            $row['per_draw_cap'] = AdminFormat::money($headroom['per_draw_cap']);
            $row['draw_reserved'] = AdminFormat::money($headroom['draw_reserved']);
            $row['draw_remaining'] = AdminFormat::money($headroom['draw_remaining']);

            $counters = [];

            foreach ($headroom['numbers'] as $key => $numbers) {
                $counters[] = [
                    'selection' => (string) $key,
                    'raw' => (string) $numbers['reserved'],
                    'reserved' => AdminFormat::money($numbers['reserved']),
                    'remaining' => AdminFormat::money($numbers['remaining']),
                    'remaining_raw' => (string) $numbers['remaining'],
                ];
            }

            // Worst concentrations first: that is the question the page exists to answer.
            // bcmath, not floats — these are money strings.
            usort(
                $counters,
                static fn (array $left, array $right): int => bccomp($right['raw'], $left['raw'], 2),
            );

            $row['numbers'] = $counters;

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The draw of a game that is currently open, if any.
     */
    private function openDraw(string $code): ?Draw
    {
        $draw = Draw::query()
            ->where('game_type', $code)
            ->where('status', DrawStatus::Open->value)
            ->orderBy('scheduled_at')
            ->first();

        return $draw instanceof Draw ? $draw : null;
    }
}
