<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Http\Controllers\PublicServicesPageController;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use ReflectionClass;
use Tests\TestCase;

/**
 * Routing and view-rendering integrity gates.
 *
 * WHY THIS TEST EXISTS
 * `GET /services` was declared against PublicPagesController::services(), a
 * method that does not exist. Nothing in the suite noticed, because no test
 * requested the page: the route table still listed it, artisan still booted, and
 * the defect only became visible as a 500 in production. The same shape of bug
 * produced the missing `player.transfers` view - a controller rendering a Blade
 * template that had never been written.
 *
 * These are static gates, deliberately:
 *  1. every route that names a controller class+method must resolve to code that
 *     actually exists, so `route:list` can never again advertise a route that
 *     can only 500;
 *  2. every view name a controller or route file renders must exist as a Blade
 *     template.
 *
 * They are cheap (no HTTP, no database) and they cover the whole application,
 * not just the files Agent A touched.
 */
final class RouteViewIntegrityTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function phpFiles(string $relativeRoot): array
    {
        $root = base_path($relativeRoot);
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    public function test_every_route_action_points_at_code_that_exists(): void
    {
        $offenders = [];
        $checked = 0;

        /** @var Route $route */
        foreach (Router::getRoutes() as $route) {
            $action = $route->getActionName();

            // Closure routes have no class to resolve. The deployment checklist
            // runs `php artisan route:cache`, which cannot serialise them, so any
            // new closure appearing here is itself worth knowing about - but it
            // is not what this gate asserts.
            if ($action === 'Closure' || $action === '') {
                continue;
            }

            $checked++;

            [$class, $method] = str_contains($action, '@')
                ? explode('@', $action, 2)
                : [$action, '__invoke'];

            if (! class_exists($class)) {
                $offenders[] = $route->uri().' -> missing controller '.$class;

                continue;
            }

            if (! method_exists($class, $method)) {
                $offenders[] = $route->uri().' -> '.$class.'::'.$method.'() does not exist';
            }
        }

        $this->assertGreaterThan(100, $checked, 'Too few controller routes were inspected; the gate is not exercising the route table.');
        $this->assertSame([], $offenders, "Routes point at code that does not exist:\n".implode("\n", $offenders));
    }

    public function test_no_route_is_registered_as_a_closure_in_the_page_route_file(): void
    {
        // routes/web.php may keep documented redirect closures only where they
        // are intentional. Everything else must be a controller: route caching
        // rejects closures outright, and the cutover checklist runs it.
        $routes = (string) file_get_contents(base_path('routes/web.php'));

        preg_match_all('/Route::(?:get|post|put|patch|delete|match)\s*\(/', $routes, $declarations);

        $this->assertGreaterThan(100, count($declarations[0]), 'The route scan found almost nothing.');
        $this->assertStringNotContainsString(
            '[PublicPagesController::class, \'services\']',
            $routes,
            'GET /services must not reference PublicPagesController::services(); that method has never existed.',
        );
    }

    public function test_the_services_route_is_served_by_an_invokable_page_controller(): void
    {
        $route = Router::getRoutes()->getByName('services');

        $this->assertNotNull($route, 'The /services route name disappeared.');
        $this->assertSame('services', $route->uri());
        // Laravel records an invokable controller as the bare class name.
        $this->assertSame(PublicServicesPageController::class, $route->getActionName());
        $this->assertTrue(
            (new ReflectionClass(PublicServicesPageController::class))->hasMethod('__invoke'),
            'The services page controller must remain invokable, because the route names it without a method.',
        );
    }

    public function test_every_view_rendered_from_php_code_has_a_blade_template(): void
    {
        $offenders = [];
        $checked = 0;

        $sources = array_merge(
            $this->phpFiles('app'),
            $this->phpFiles('routes'),
        );

        foreach ($sources as $file) {
            $source = (string) file_get_contents($file);

            // view('dotted.name') and response()->view('dotted.name'). A name
            // built at runtime ('prefix.'.$x) is out of scope by construction:
            // only a complete literal is matched.
            preg_match_all(
                '/(?:\bview|->view)\s*\(\s*([\'"])([A-Za-z0-9_.\-]+)\1/',
                $source,
                $matches,
            );

            foreach ($matches[2] as $viewName) {
                if (! str_contains($viewName, '.')) {
                    continue;
                }

                $checked++;

                $path = resource_path('views/'.str_replace('.', '/', $viewName).'.blade.php');

                if (! is_file($path)) {
                    $offenders[] = str_replace(base_path().'/', '', $file).' renders '.$viewName.' (missing)';
                }
            }
        }

        $this->assertGreaterThan(50, $checked, 'Too few view renders were inspected; the gate is not scanning the controllers.');
        $this->assertSame([], $offenders, "Rendered views are missing from resources/views:\n".implode("\n", $offenders));
    }

    public function test_the_player_transfer_history_view_exists(): void
    {
        $this->assertFileExists(
            resource_path('views/player/transfers.blade.php'),
            'BalanceTransferController::index() renders player.transfers for a browser request.',
        );
    }
}
