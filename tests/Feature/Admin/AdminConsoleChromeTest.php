<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminConsoleNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The LottoFin executive console's chrome must tell the truth about where the operator
 * is, who the operator is, and what the operator may open.
 *
 * Before this suite existed the console chrome was untested - no test in the repository
 * requested any of its ~115 admin routes - and it was wrong in four separate ways:
 *
 *   1. The sidebar's `active` class and "Active" badge were hardcoded on the Dashboard
 *      item, so every panel and the release-operations screen reported Dashboard as the
 *      current page while the real one went unmarked.
 *   2. All eight links rendered unconditionally. The routes are gated by a coarse
 *      `can:access-admin`, but the controller then requires a distinct seeded permission
 *      per panel, so an operator was shown links that 403'd on click.
 *   3. The header named every operator "Admin / Somchai" beside a stock photograph of a
 *      person fetched from Unsplash - a fabricated identity on an audit-sensitive screen.
 *   4. Two controls were fabricated: a notification bell whose badge was a literal `1`
 *      with no feed behind it, and a floating help button that no script bound and no
 *      href pointed anywhere.
 *
 * Fixes 1 and 2 share one mechanism: AdminConsoleNav holds the items as data, and both
 * the sidebar and the controller ask AdminAccess::mayOpenPanel() - a single predicate -
 * so "the nav offers it but the controller refuses it" cannot be expressed. Test 3 below
 * is the proof, and it checks both halves for every item rather than assuming one
 * implies the other.
 */
final class AdminConsoleChromeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Every permission the navigation can ask about, so a grant is never refused for
        // the incidental reason that the row does not exist yet.
        foreach (AdminAccess::panelPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (AdminAccess::PANEL_ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * @param  list<string>  $permissions
     */
    private function operator(array $permissions, string $role = 'admin', string $name = 'Desk Operator'): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'status' => UserStatus::Active,
        ]);

        $user->assignRole($role);

        // 'view dashboard' is part of canAccessPanel() itself: without it an operator
        // cannot reach ANY console route, so the per-panel permissions under test would
        // never be exercised.
        $user->givePermissionTo(AdminAccess::VIEW_DASHBOARD);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    /**
     * Parse the rendered sidebar into label => ['href' => ..., 'active' => ...].
     *
     * Read from the response rather than from AdminConsoleNav, because the point is what
     * the operator is actually shown.
     *
     * @return array<string, array{href: string, active: bool}>
     */
    private function sidebar(string $html): array
    {
        $items = [];

        $pattern = '/<a href="([^"]+)" class="lf-nav-item([^"]*)"( aria-current="page")?>(?:(?!<\/a>).)*?<span>([^<]+)<\/span>/s';

        if (preg_match_all($pattern, $html, $m, PREG_SET_ORDER) === false) {
            return $items;
        }

        foreach ($m as $match) {
            // Blade escapes on output, so a label stored as "Number Limits & Risk" arrives
            // as "Number Limits &amp; Risk". Decoding here is what a browser does, and
            // without it the comparison against AdminConsoleNav::items() never matches.
            $items[html_entity_decode(trim($match[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8')] = [
                'href' => $match[1],
                'active' => trim($match[2]) === 'active' || $match[3] !== '',
            ];
        }

        return $items;
    }

    // ------------------------------------------------------------------
    // 1. Where you are
    // ------------------------------------------------------------------

    #[Test]
    public function the_sidebar_marks_only_the_panel_the_operator_is_actually_on(): void
    {
        $this->withoutVite(); // asserts page content, not the compiled asset tags
        $operator = $this->operator([], 'super-admin');

        $byLabel = [];

        foreach (AdminConsoleNav::items() as $item) {
            $byLabel[$item['label']] = $item;
        }

        foreach ($byLabel as $label => $item) {
            $response = $this->actingAs($operator)->get(route($item['route']));

            $response->assertOk();

            $rendered = $this->sidebar($response->getContent());

            $this->assertArrayHasKey(
                $label,
                $rendered,
                sprintf('Visiting %s did not render a sidebar entry for %s.', $item['route'], $label),
            );

            $active = array_keys(array_filter($rendered, static fn (array $r): bool => $r['active']));

            $this->assertSame(
                [$label],
                $active,
                sprintf(
                    'On %s the sidebar marked %s as current instead of exactly %s.',
                    $item['route'],
                    $active === [] ? 'nothing' : implode(', ', $active),
                    $label,
                ),
            );

            // The class and the ARIA attribute have to agree, or screen readers and
            // sighted operators are told different things.
            $this->assertSame(
                1,
                substr_count($response->getContent(), 'aria-current="page"'),
                sprintf('On %s exactly one item must carry aria-current="page".', $item['route']),
            );
        }
    }

    #[Test]
    public function the_sidebar_marks_nothing_as_current_on_a_screen_outside_the_panel_vocabulary(): void
    {
        $this->withoutVite(); // asserts page content, not the compiled asset tags
        // admin/release-operations extends the same layout but is rendered by a different
        // controller that passes no $panel. Defaulting that to 'dashboard' would put the
        // bug back, so the absence is asserted rather than assumed.
        $operator = $this->operator([], 'super-admin');

        $response = $this->actingAs($operator)->get(route('admin.runtime.index'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringNotContainsString('aria-current="page"', $html);
        $this->assertStringNotContainsString('lf-nav-item active', $html);
        $this->assertStringNotContainsString('lf-active-badge', $html);

        $rendered = $this->sidebar($html);

        $this->assertNotEmpty($rendered, 'The sidebar disappeared entirely, which is not the same as nothing being current.');

        foreach ($rendered as $label => $item) {
            $this->assertFalse($item['active'], sprintf('%s must not claim to be current on release-operations.', $label));
        }
    }

    // ------------------------------------------------------------------
    // 2. What you may open - the anti-drift proof
    // ------------------------------------------------------------------

    #[Test]
    public function an_operator_is_never_offered_a_panel_the_controller_would_refuse(): void
    {
        foreach (AdminConsoleNav::items() as $item) {
            $permission = AdminAccess::panelPermission($item['panel']);

            if ($permission === AdminAccess::VIEW_DASHBOARD) {
                // Every operator holds this by definition of canAccessPanel(), so there is
                // no denied case to construct. Covered by the two tests above.
                continue;
            }

            // (a) With the permission: the link is offered AND the panel is served.
            $allowed = $this->operator([$permission]);

            $html = $this->actingAs($allowed)->get(route('admin.dashboard'))->getContent();
            $rendered = $this->sidebar($html);

            $this->assertArrayHasKey(
                $item['label'],
                $rendered,
                sprintf('An operator holding %s was not offered %s.', $permission, $item['label']),
            );

            $this->actingAs($allowed)->get(route($item['route']))->assertOk();

            // (b) Without it: the link is withheld AND the panel is refused. Both halves,
            // because the defect being guarded against is precisely their disagreement.
            $denied = $this->operator([]);

            $html = $this->actingAs($denied)->get(route('admin.dashboard'))->getContent();

            $this->assertArrayNotHasKey(
                $item['label'],
                $this->sidebar($html),
                sprintf('An operator without %s was still offered %s.', $permission, $item['label']),
            );

            $this->actingAs($denied)->get(route($item['route']))->assertForbidden();
        }
    }

    #[Test]
    public function every_navigation_item_names_a_real_route_and_a_seeded_permission(): void
    {
        $catalogue = (array) config('permission.permissions');

        $this->assertNotEmpty($catalogue, 'The seeded permission catalogue is empty, so this test proves nothing.');

        foreach (AdminConsoleNav::items() as $item) {
            $this->assertTrue(
                Route::has($item['route']),
                sprintf('Nav item %s points at route %s, which does not exist.', $item['label'], $item['route']),
            );

            $permission = AdminAccess::panelPermission($item['panel']);

            $this->assertContains(
                $permission,
                $catalogue,
                sprintf('Nav item %s requires %s, which is not in config/permission.php.', $item['label'], $permission),
            );

            $this->assertNotSame('', $item['label']);
            $this->assertNotSame('', $item['icon']);
            $this->assertContains($item['group'], [AdminConsoleNav::GROUP_PRIMARY, AdminConsoleNav::GROUP_FINANCIAL]);
        }

        // No two items may resolve to the same panel, or the active flag would mark both.
        $panels = array_column(AdminConsoleNav::items(), 'panel');

        $this->assertSame(
            array_values(array_unique($panels)),
            $panels,
            'Two nav items share a panel, so both would render as current.',
        );
    }

    #[Test]
    public function the_panel_permission_map_agrees_with_what_the_console_can_resolve(): void
    {
        $catalogue = (array) config('permission.permissions');

        foreach (AdminAccess::panelPermissions() as $panel => $permission) {
            $this->assertContains(
                $permission,
                $catalogue,
                sprintf('Panel %s requires %s, which is not seeded.', $panel, $permission),
            );
        }

        // An unknown panel falls back to the dashboard permission rather than to nothing,
        // so a new route cannot accidentally become world-readable inside the console.
        $this->assertSame(AdminAccess::VIEW_DASHBOARD, AdminAccess::panelPermission('a-panel-that-does-not-exist'));

        // The navigation is a subset of the panel vocabulary: every item's panel is one the
        // authorizer knows about.
        $known = AdminAccess::panelPermissions();

        foreach (AdminConsoleNav::items() as $item) {
            $this->assertArrayHasKey($item['panel'], $known);
        }
    }

    #[Test]
    public function the_navigation_offers_nothing_to_someone_who_is_not_an_operator(): void
    {
        $this->assertSame([], AdminConsoleNav::visibleFor(null));

        $player = User::factory()->create(['status' => UserStatus::Active]);

        $this->assertSame([], AdminConsoleNav::visibleFor($player));

        // A suspended operator loses the sidebar immediately rather than at the next
        // session, because canAccessPanel() checks status on every call.
        $suspended = $this->operator([], 'super-admin');
        $suspended->forceFill(['status' => UserStatus::Suspended])->save();

        $this->assertSame([], AdminConsoleNav::visibleFor($suspended->fresh()));
    }

    // ------------------------------------------------------------------
    // 3. Who you are
    // ------------------------------------------------------------------

    #[Test]
    public function the_console_names_the_operator_who_is_logged_in(): void
    {
        $this->withoutVite(); // asserts page content, not the compiled asset tags
        $operator = $this->operator([], 'super-admin', 'Nusrat Jahan');

        $html = $this->actingAs($operator)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Nusrat Jahan', $html);
        $this->assertStringContainsString('NJ', $html, 'The avatar initials were not derived from the operator name.');
        $this->assertStringContainsString('super-admin', $html, 'The operator role was not shown.');

        // The fabricated identity, and the stock photograph that went with it.
        $this->assertStringNotContainsString('Somchai', $html);
        $this->assertStringNotContainsString('images.unsplash.com', $html);
        $this->assertStringNotContainsString('<img', $this->headerRegion($html));

        // A different operator sees a different name: the assertion above would pass on a
        // hardcoded value that happened to match, this one cannot.
        $other = $this->operator([], 'admin', 'Anwar Hossain');

        $second = $this->actingAs($other)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('Anwar Hossain', $second);
        $this->assertStringContainsString('AH', $second);
        $this->assertStringNotContainsString('Nusrat Jahan', $second);
    }

    // ------------------------------------------------------------------
    // 4. No fabricated controls
    // ------------------------------------------------------------------

    #[Test]
    public function the_chrome_carries_no_control_that_does_nothing(): void
    {
        $this->withoutVite(); // asserts page content, not the compiled asset tags
        $operator = $this->operator([], 'super-admin');

        $html = $this->actingAs($operator)->get(route('admin.dashboard'))->assertOk()->getContent();

        // The bell and its literal count of one unread notification.
        $this->assertStringNotContainsString('lf-notification-btn', $html);
        $this->assertStringNotContainsString('lf-notification-badge', $html);

        // The floating help button, which no script bound and no href served.
        $this->assertStringNotContainsString('lf-floating-help', $html);

        // The profile block used to carry `cursor: pointer` and a chevron for a dropdown
        // that has never existed in the markup or in admin-lottofin.js.
        $header = $this->headerRegion($html);
        $this->assertStringNotContainsString('M19 9l-7 7-7-7', $header, 'The fake dropdown chevron is still rendered.');

        // The controls that DO work must still be there.
        $this->assertStringContainsString('id="sidebarToggle"', $html);
        $this->assertStringContainsString('id="adminSidebar"', $html);
        $this->assertStringContainsString(route('logout'), $html);
    }

    #[Test]
    public function the_source_of_the_layout_contains_no_hardcoded_identity_or_count(): void
    {
        // Complements the rendered assertions above by checking the template itself, so a
        // value hidden behind a conditional cannot slip through. Comments are stripped
        // first: the template legitimately mentions what it removed and why.
        $contents = (string) file_get_contents(resource_path('views/layouts/admin.blade.php'));

        $blank = static function (array $m): string {
            return str_repeat("\n", substr_count((string) $m[0], "\n"));
        };

        // Blade comments first, then PHP line comments - the explanation of what was
        // removed lives in a @php block, so stripping only {{-- --}} left it visible to
        // this check. The (?<!:) guard keeps a URL's // from being treated as a comment.
        $contents = (string) preg_replace_callback('/\{\{--.*?(?:--\}\}|$)/s', $blank, $contents);
        $contents = (string) preg_replace_callback('/(?<!:)\/\/[^\n]*/', $blank, $contents);

        foreach (['Somchai', 'images.unsplash.com', 'lf-notification-badge', 'lf-floating-help'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $contents,
                sprintf('%s is still present in the layout outside a comment.', $forbidden),
            );
        }

        // The sidebar must be rendered from the nav class, not written out by hand again.
        $this->assertStringContainsString('AdminConsoleNav::visibleFor', $contents);
        $this->assertStringContainsString('$navItems', $contents);

        // And it must not invent a default panel, which is how the old bug would return.
        $this->assertStringNotContainsString("\$panel ?? 'dashboard'", $contents);
        $this->assertStringContainsString('$panel ?? null', $contents);
    }

    #[Test]
    public function the_dead_styles_are_gone_and_the_avatar_style_suits_an_element_that_is_not_an_image(): void
    {
        $css = (string) file_get_contents(resource_path('css/admin-lottofin.css'));

        foreach (['.lf-notification-btn', '.lf-notification-badge', '.lf-floating-help'] as $dead) {
            $this->assertStringNotContainsString(
                $dead,
                $css,
                sprintf('%s styles a control that no longer exists.', $dead),
            );
        }

        // The avatar is now a <span> holding initials; object-fit only applies to replaced
        // elements such as <img>, so leaving it would be styling nothing.
        $block = $this->cssRule($css, '.lf-user-avatar');

        $this->assertNotSame('', $block, '.lf-user-avatar has no rule.');
        $this->assertStringNotContainsString('object-fit', $block);
        $this->assertStringContainsString('display: flex', $block);
        $this->assertStringContainsString('justify-content: center', $block);

        // It must use the design system's own tokens rather than new literals.
        $this->assertStringContainsString('var(--lf-bg-card-hover)', $block);
        $this->assertStringContainsString('var(--lf-text-primary)', $block);

        // The profile block is no longer clickable.
        $this->assertStringNotContainsString('cursor: pointer', $this->cssRule($css, '.lf-user-profile'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * The rendered top header, isolated so assertions about it cannot be satisfied by
     * something elsewhere on the page.
     */
    private function headerRegion(string $html): string
    {
        $start = strpos($html, 'lf-top-header');

        if ($start === false) {
            return '';
        }

        $end = strpos($html, 'lf-page-body', $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    private function cssRule(string $css, string $selector): string
    {
        $at = strpos($css, $selector.' {');

        if ($at === false) {
            return '';
        }

        $end = strpos($css, '}', $at);

        return $end === false ? '' : substr($css, $at, $end - $at + 1);
    }
}
