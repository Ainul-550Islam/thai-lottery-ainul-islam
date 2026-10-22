<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\User;

/**
 * The LottoFin executive console's sidebar navigation, defined once.
 *
 * WHY THIS CLASS EXISTS
 * The sidebar used to be eight near-identical blocks of markup in
 * resources/views/layouts/admin.blade.php, and it had two defects that only show up
 * when the navigation is treated as data:
 *
 *   1. Every link rendered unconditionally. The routes behind them are gated only by
 *      the coarse `can:access-admin` middleware; the fine-grained check happens later,
 *      in LottoFinExecutiveDashboardController::authorizePanel(), which requires a
 *      distinct seeded permission per panel (VIEW_DRAWS, RECONCILE_LEDGER, MANAGE_WALLET
 *      and so on). So an operator holding access-admin but not the panel's permission
 *      was shown a link that 403'd on click. A navigation that offers destinations the
 *      reader may not open is worse than no navigation: it advertises the existence of
 *      gated surfaces and wastes a round trip proving it.
 *
 *   2. The `active` class and the "Active" badge were hardcoded on the Dashboard item.
 *      On every other panel - and on release-operations, the other view that extends
 *      this layout - the sidebar still claimed Dashboard was the current page while the
 *      real one went unmarked. The layout is handed the resolved $panel by the
 *      controller and ignored it.
 *
 * Both are fixed by having one list of items, each carrying the panel it opens, and by
 * asking AdminAccess::mayOpenPanel() - the very predicate the controller uses - whether
 * to render it. The navigation and the authorizer therefore cannot disagree, because
 * they are no longer two implementations of one rule.
 *
 * The icon data is the `d` attribute of each item's stroke path. All eight share the
 * same wrapper (fill=none, stroke=currentColor, viewBox 0 0 24 24, round caps and
 * joins, stroke-width 2), which is why only the path data is stored here.
 */
final class AdminConsoleNav
{
    /**
     * The item above the "Financial" section header.
     */
    public const GROUP_PRIMARY = 'Primary';

    /**
     * The items under the "Financial" section header.
     */
    public const GROUP_FINANCIAL = 'Financial';

    /**
     * Every console navigation item, in display order.
     *
     * `panel` is the value LottoFinExecutiveDashboardController resolves for that route,
     * so an item's required permission is AdminAccess::panelPermission(item.panel) -
     * derived, never restated.
     *
     * @return list<array{label: string, route: string, panel: string, group: string, icon: string}>
     */
    public static function items(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'route' => 'admin.dashboard',
                'panel' => 'dashboard',
                'group' => self::GROUP_PRIMARY,
                'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            ],
            [
                'label' => 'Draw Lifecycle',
                'route' => 'admin.draws.index',
                'panel' => 'draws',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            ],
            [
                'label' => 'Number Limits & Risk',
                'route' => 'admin.risk.index',
                'panel' => 'risk',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M17 14v6m-3-3h6M6 10h2a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2zm10 0h2a2 2 0 002-2V6a2 2 0 00-2-2h-2a2 2 0 00-2 2v2a2 2 0 002 2zM6 20h2a2 2 0 002-2v-2a2 2 0 00-2-2H6a2 2 0 00-2 2v2a2 2 0 002 2z',
            ],
            [
                'label' => 'Bets Ledger',
                'route' => 'admin.bets.index',
                'panel' => 'bets',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
            ],
            [
                'label' => 'Wallet Controls',
                'route' => 'admin.wallets.index',
                'panel' => 'wallets',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z',
            ],
            [
                'label' => 'Ledger Accounts',
                'route' => 'admin.ledger.index',
                'panel' => 'ledger',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4',
            ],
            [
                'label' => 'Double-Entry Reconciliation',
                'route' => 'admin.reconciliation.index',
                'panel' => 'reconciliation',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4',
            ],
            [
                'label' => 'Audit Logs',
                'route' => 'admin.audits.index',
                'panel' => 'audits',
                'group' => self::GROUP_FINANCIAL,
                'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
            ],
        ];
    }

    /**
     * The items this operator may actually open, with the active flag resolved.
     *
     * $currentPanel is the panel the controller resolved for the request. It is nullable
     * because resources/views/admin/release-operations.blade.php also extends this
     * layout and is rendered by a different controller that passes no panel - on that
     * screen nothing in the sidebar is current, which is the truth, and is why the
     * caller must not default this to 'dashboard'.
     *
     * @return list<array{label: string, route: string, panel: string, group: string, icon: string, active: bool}>
     */
    public static function visibleFor(?User $user, ?string $currentPanel = null): array
    {
        $visible = [];

        foreach (self::items() as $item) {
            if (! AdminAccess::mayOpenPanel($user, $item['panel'])) {
                continue;
            }

            $item['active'] = $currentPanel !== null && $currentPanel === $item['panel'];

            $visible[] = $item;
        }

        return $visible;
    }

    /**
     * The section headers that have at least one visible item beneath them.
     *
     * Rendered separately from the items because the sidebar's "Financial" label is a
     * section header rather than a link. A header with nothing under it would be a
     * promise the sidebar does not keep, so empty groups are dropped.
     *
     * @param  list<array{group: string}>  $visible
     * @return list<string>
     */
    public static function groupsPresent(array $visible): array
    {
        $present = [];

        foreach ($visible as $item) {
            $present[$item['group']] = true;
        }

        return array_keys($present);
    }
}
