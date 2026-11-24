<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

final class PromptHttpPaymentBoundaryCoverageTest extends TestCase
{
    public function test_financial_mutation_routes_are_not_anonymous_unthrottled_endpoints(): void
    {
        $sensitive = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => preg_match(
                '#(deposit|withdraw|payout|transfer|conversion|webhook)#i',
                $route->uri(),
            ) === 1)
            ->filter(static fn (Route $route): bool => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== [])
            ->reject(static fn (Route $route): bool => str_ends_with($route->uri(), '.html')
                || str_contains($route->getActionName(), 'unsupportedMutation')
                || preg_match('#/(quote|lookup|status)$#i', $route->uri()) === 1);

        self::assertNotEmpty($sensitive);

        foreach ($sensitive as $route) {
            $middleware = $route->gatherMiddleware();
            $hasAuthenticationOrSignature = collect($middleware)->contains(
                static fn (string $name): bool => str_contains($name, 'auth')
                    || str_contains($name, 'signature')
                    || str_contains($name, 'webhook'),
            );
            $hasThrottle = collect($middleware)->contains(
                static fn (string $name): bool => str_contains($name, 'throttle'),
            );

            self::assertTrue($hasAuthenticationOrSignature, "{$route->uri()} lacks authentication/signature middleware");
            self::assertTrue($hasThrottle, "{$route->uri()} lacks throttling");
        }
    }
}
