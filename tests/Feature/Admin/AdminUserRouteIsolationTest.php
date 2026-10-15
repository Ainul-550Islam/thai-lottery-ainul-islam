<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The legacy read-only projection must not replace Filament's user list.
 * Laravel indexes routes by method and URI, not by their distinct names.
 */
final class AdminUserRouteIsolationTest extends TestCase
{
    public function test_the_two_named_user_lists_resolve_to_distinct_real_routes(): void
    {
        $routes = app('router')->getRoutes();
        $projection = $routes->getByName('admin.users.index');
        $resource = $routes->getByName('filament.admin.resources.users.index');

        $this->assertNotNull($projection);
        $this->assertNotNull($resource);
        $this->assertSame('admin/legacy/users', $projection->uri());
        $this->assertSame('admin/users', $resource->uri());
        $this->assertSame(LottoFinExecutiveDashboardController::class.'@index', $projection->getActionName());
        $this->assertNotSame($projection->getActionName(), $resource->getActionName());
        $this->assertContains('admin.auth', $projection->middleware());
        $this->assertContains('can:access-admin', $projection->middleware());
        $this->assertSame(
            'admin.users.index',
            $routes->match(Request::create('/admin/legacy/users', 'GET'))->getName(),
        );
        $this->assertSame(
            'filament.admin.resources.users.index',
            $routes->match(Request::create('/admin/users', 'GET'))->getName(),
        );
    }
}
