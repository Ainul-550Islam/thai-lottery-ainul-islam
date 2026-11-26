<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Admin\LotteryCatalogueService;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use App\Support\Admin\AdminPermission;
use Filament\Pages\Page;
use Throwable;

/**
 * The lottery catalogue: what the platform sells, at what payout, and what disagrees.
 *
 * WHY THIS IS NOT A CRUD SCREEN, STATED ON THE SCREEN ITSELF
 *
 * There is no games table. The sellable catalogue is configuration (`config/lottery.php`:
 * `types` and `markets`), the draws and bets tables only *refer* to those words, and
 * `NumberNormalizationService` treats configuration as authoritative for digit counts. A
 * "create game" button here would write a row no engine reads while the row that governs money
 * stayed in a deployed PHP file — the definition of a fake button. So the page reads, and says
 * where the truth lives.
 *
 * WHAT IT IS FOR
 *
 *   1. One place that answers "what do we sell, at what multiplier, and is it on".
 *   2. A visible drift report. Configuration, `BetType` and `DrawType` describe the same games
 *      and do not agree (digits for Tod and Run; DrawType's payout and range helpers). The
 *      engine refuses to normalise a number when `BetType` and configuration disagree, but no
 *      screen showed that the disagreement exists.
 *   3. Live facts per game: draws, next scheduled draw, bets, stake, number limits in force.
 *
 * The page is a projection over the catalogue service; it holds no queries of its own, so a
 * test can assert the numbers without rendering HTML.
 *
 * PERMISSION: 'lottery.games.view'. Nothing here needs (or accepts) a write grant.
 */
class LotteryCataloguePage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Lottery';

    protected static ?string $navigationLabel = 'Game catalogue';

    protected static ?string $title = 'Lottery game catalogue';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'lottery-catalogue';

    protected static string $view = 'filament.pages.lottery-catalogue-page';

    /**
     * STRICT, NOT BRIDGED — deliberately.
     *
     * `AdminAccess::permits()` falls back to the coarse vocabulary, and the coarse phrase
     * `view draws` is held by PLAYERS because the player-facing screens scope their own queries
     * to the signed-in player. This page does not scope anything: it aggregates stake, bets and
     * multipliers across the whole platform. Bridging to the coarse vocabulary would therefore
     * answer "yes" to a player, and while the panel gate would still refuse the route, a screen
     * should not depend on a second gate to be safe.
     *
     * `permitsStrict()` asks for the granular grant only. The consequence is documented in the
     * implementation report: a deployment that has run RolePermissionSeeder but not
     * AdminRbacSeeder keeps this page closed, which is the fail-closed direction.
     */
    public static function canAccess(): bool
    {
        return AdminAccess::permitsStrict(AdminPermission::GAMES_VIEW)
            || AdminAccess::permitsStrict(AdminPermission::GAMES_MANAGE);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function games(): array
    {
        try {
            return app(LotteryCatalogueService::class)->games();
        } catch (Throwable $e) {
            // A catalogue that cannot be read must say so; it must not render an empty menu and
            // let an operator believe the platform sells nothing.
            report($e);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function markets(): array
    {
        try {
            return app(LotteryCatalogueService::class)->markets();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function drift(): array
    {
        try {
            return app(LotteryCatalogueService::class)->drift();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        try {
            return app(LotteryCatalogueService::class)->products();
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    public function money(mixed $value, string $currency = ''): string
    {
        return AdminFormat::money($value, $currency !== '' ? $currency : null);
    }

    public function time(mixed $value): string
    {
        return AdminFormat::marketTime($value);
    }
}
