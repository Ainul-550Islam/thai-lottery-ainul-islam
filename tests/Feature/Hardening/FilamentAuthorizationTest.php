<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Support\Admin\AdminAccess;
use Tests\TestCase;

/**
 * Every operator surface must declare who may open it.
 *
 * ============================================================================
 * THE FALLBACK IS THE VULNERABILITY
 * ============================================================================
 * Filament's default for a resource with no authorisation method is "yes" for
 * anybody the panel admits. The panel admits three roles — super-admin, admin
 * and auditor — and the auditor role exists to LOOK at money without moving it.
 * A resource that forgets `canViewAny()` therefore hands a read-only account a
 * create form.
 *
 * This suite walks the Filament directory and fails on any surface that has not
 * answered the question. It does not try to judge whether the answer is the
 * RIGHT permission for that screen — that is a policy review — but it does
 * require that the answer comes from AdminAccess, so no screen can invent an
 * authorisation rule of its own.
 */
final class FilamentAuthorizationTest extends TestCase
{
    /**
     * @return list<string> every Filament resource class in the application
     */
    private function resourceClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Filament/Resources/*.php')) ?: [] as $file) {
            $classes[] = 'App\\Filament\\Resources\\'.basename($file, '.php');
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return list<string> every Filament page class in the application
     */
    private function pageClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Filament/Pages/*.php')) ?: [] as $file) {
            $classes[] = 'App\\Filament\\Pages\\'.basename($file, '.php');
        }

        sort($classes);

        return $classes;
    }

    public function test_the_console_actually_has_resources_and_pages_to_guard(): void
    {
        $this->assertNotSame([], $this->resourceClasses(), 'the console must expose resources');
        $this->assertNotSame([], $this->pageClasses(), 'the console must expose pages');
    }

    public function test_every_resource_declares_can_view_any(): void
    {
        $missing = [];

        foreach ($this->resourceClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $method = $reflection->getMethod('canViewAny');

            if ($method->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these Filament resources inherit canViewAny() from the framework, so anyone the panel admits can open them:\n  - "
            .implode("\n  - ", $missing),
        );
    }

    public function test_every_page_declares_can_access(): void
    {
        $missing = [];

        foreach ($this->pageClasses() as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (! $reflection->hasMethod('canAccess')) {
                $missing[] = $class;

                continue;
            }

            if ($reflection->getMethod('canAccess')->getDeclaringClass()->getName() !== $class) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "these Filament pages inherit canAccess() from the framework:\n  - ".implode("\n  - ", $missing),
        );
    }

    public function test_authorisation_comes_from_admin_access_and_not_from_a_fresh_rule(): void
    {
        // One place decides what a permission means. A screen that inspects roles
        // directly drifts from the seeded permission catalogue the first time a
        // role name changes.
        $offenders = [];

        foreach (array_merge($this->resourceClasses(), $this->pageClasses()) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $file = (new \ReflectionClass($class))->getFileName();

            if (! is_string($file)) {
                continue;
            }

            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'AdminAccess')) {
                $offenders[] = $class;

                continue;
            }

            // A super-admin override is allowed, and only that one. AdminAccess
            // already grants super-admin a blanket bypass, so an explicit
            // `hasRole('super-admin')` guard is a belt-and-braces check on an
            // action, not a second authorisation rule. Any OTHER role name, or a
            // direct permission check, bypasses the catalogue and is refused here.
            if (preg_match('/hasPermissionTo\s*\(/', $source) === 1) {
                $offenders[] = $class;

                continue;
            }

            if (preg_match_all('/hasRole\s*\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $roles) > 0) {
                foreach ($roles[1] as $role) {
                    if ($role !== 'super-admin') {
                        $offenders[] = $class;

                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "these Filament surfaces authorise themselves instead of asking AdminAccess:\n  - ".implode("\n  - ", $offenders),
        );
    }

    public function test_a_guest_is_refused_by_every_permission_question(): void
    {
        auth()->logout();

        foreach ([
            AdminAccess::VIEW_DASHBOARD,
            AdminAccess::MANAGE_PAYOUTS,
            AdminAccess::MANAGE_WALLET,
            AdminAccess::VIEW_AUDIT_LOGS,
            AdminAccess::MANAGE_SYSTEM_SETTINGS,
            AdminAccess::MANAGE_GLO_PRIZE_CLAIMS,
        ] as $permission) {
            $this->assertFalse(AdminAccess::current($permission), 'a guest must not hold '.$permission);
            $this->assertFalse(AdminAccess::allows(null, $permission));
        }

        $this->assertFalse(AdminAccess::canAccessPanel(null));
    }

    public function test_the_panel_permission_map_covers_every_declared_panel(): void
    {
        $map = AdminAccess::panelPermissions();

        $this->assertNotSame([], $map);

        foreach ($map as $panel => $permission) {
            $this->assertNotSame('', (string) $panel);
            $this->assertNotSame('', (string) $permission, $panel.' must resolve to a permission');
        }

        // An unknown panel must not resolve to "no permission required".
        $this->assertSame(AdminAccess::VIEW_DASHBOARD, AdminAccess::panelPermission('a_panel_nobody_declared'));
    }

    public function test_the_auditor_role_is_declared_and_is_not_an_admin_role(): void
    {
        $this->assertContains('auditor', AdminAccess::PANEL_ROLES);
        $this->assertNotContains('auditor', ['super-admin', 'admin'], 'the auditor role is not an admin role');
    }
}
