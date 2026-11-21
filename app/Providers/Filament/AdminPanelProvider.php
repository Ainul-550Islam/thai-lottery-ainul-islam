<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\OperationsDashboard;
use App\Http\Middleware\FilamentAuthenticateBoundSession;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The operator panel (Phase 6).
 *
 * WHY THIS EXISTS
 * Phases 1-5.1 built a complete domain - wallets, a double-entry ledger, risk limits, a
 * betting engine, a draw lifecycle - that no human could reach. Approving a deposit,
 * publishing a result, setting a number limit or reading the audit trail all required a
 * developer with a tinker session. This panel is that missing surface.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No screen writes a model that a domain service owns. Every state change (open a
 *   draw, approve a withdrawal, credit a wallet) is delegated to the existing, tested
 *   service. The panel is an interface, not a second implementation of the rules.
 * - No screen invents a permission. Authorization reads the seeded catalogue through
 *   App\Support\Admin\AdminAccess.
 * - No player-facing route lives here. Phase 7 (the public frontend) is still unbuilt;
 *   this panel is reachable at /admin only.
 * - Database notifications, multi-tenancy and the agent portal are not enabled.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * Navy-tinted gray scale taken from the LOTTOFIN admin design tokens
     * (resources/css/admin-lottofin.css): 950 = canvas, 900 = card, 800 = border,
     * 500/400 = dim/muted text, 50 = primary text. Filament paints every dark
     * surface from this scale, so the whole panel follows it.
     *
     * @var array<int, string>
     */
    private const GRAY = [
        50 => '248, 250, 252',
        100 => '230, 235, 241',
        200 => '205, 213, 223',
        300 => '170, 182, 197',
        400 => '132, 146, 166',
        500 => '92, 107, 125',
        600 => '70, 88, 110',
        700 => '44, 62, 82',
        800 => '31, 45, 61',
        900 => '19, 28, 40',
        950 => '11, 17, 24',
    ];

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Thai Lottery — Operations')
            // Dark only, like the LOTTOFIN design. Pass false as 2nd argument to give operators a switch.
            ->darkMode(true, true)
            ->colors([
                'primary' => Color::Emerald,
                'gray' => self::GRAY,
                'danger' => Color::Rose,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'info' => Color::Cyan,
            ])
            ->maxContentWidth(MaxWidth::Full)
            // Skin: public/css/admin-theme.css, loaded after Filament's own CSS, cache-busted by mtime.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                static fn (): string => '<link rel="stylesheet" href="'.e(asset('css/admin-theme.css')).'?v='.(is_file(public_path('css/admin-theme.css')) ? (int) filemtime(public_path('css/admin-theme.css')) : 0).'">',
            )
            ->navigationGroups([
                NavigationGroup::make('Lottery'),
                NavigationGroup::make('Finance'),
                NavigationGroup::make('Risk'),
                NavigationGroup::make('People'),
                NavigationGroup::make('Compliance'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                OperationsDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                FilamentAuthenticateBoundSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
