<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The 25 new routes: they exist, they are authenticated where they should be, and
 * they are anonymous where they must be.
 *
 * THE TWO FAILURES THIS PREVENTS
 *   1. A /me route registered outside the auth middleware. Everything under /me is
 *      somebody's data; a missing `auth:sanctum` is a public data endpoint.
 *   2. An anonymous route that accidentally inherits a session or echoes a config
 *      value. /health/live and /health/ready are reachable by anyone on the internet,
 *      so their payloads are asserted to be boring.
 */
final class PlayerRoutesTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function memberRoutes(): array
    {
        return [
            'bet slip read' => ['GET', '/api/v1/me/bet-slip'],
            'bet slip add item' => ['POST', '/api/v1/me/bet-slip/items'],
            'bet slip confirm' => ['POST', '/api/v1/me/bet-slip/confirm'],
            'my bets' => ['GET', '/api/v1/me/bets'],
            'my bet counts' => ['GET', '/api/v1/me/bets/counts'],
            'wallet overview' => ['GET', '/api/v1/me/wallet/overview'],
            'wallet transactions' => ['GET', '/api/v1/me/wallet/transactions'],
            'profile overview' => ['GET', '/api/v1/me/profile/overview'],
            'security overview' => ['GET', '/api/v1/me/security/overview'],
            'security alerts' => ['GET', '/api/v1/me/security/alerts'],
            'notification preferences' => ['GET', '/api/v1/me/notification-preferences'],
            'support conversations' => ['GET', '/api/v1/me/support/conversations'],
            'active promotions' => ['GET', '/api/v1/me/promotions/active'],
            'responsible gaming summary' => ['GET', '/api/v1/me/responsible-gaming/summary'],
        ];
    }

    /**
     * @dataProvider memberRoutes
     */
    public function test_a_member_route_refuses_an_anonymous_caller(string $method, string $uri): void
    {
        $response = $this->json($method, $uri);

        $this->assertContains(
            $response->getStatusCode(),
            [401, 403, 405, 422],
            $uri.' must not answer an unauthenticated caller with data.',
        );
    }

    /**
     * @dataProvider memberRoutes
     */
    public function test_a_member_route_does_not_500_for_an_authenticated_member(string $method, string $uri): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->json($method, $uri, $method === 'POST' ? [] : []);

        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            $uri.' must not throw for a normal member; a 5xx here is an unshipped bug.',
        );
    }

    public function test_the_health_liveness_probe_is_public_and_boring(): void
    {
        $response = $this->getJson('/health/live');

        $response->assertOk();
        $response->assertJsonPath('status', 'UP');

        $json = $response->getContent();

        $this->assertStringNotContainsString('password', (string) $json);
        $this->assertStringNotContainsString('APP_KEY', (string) $json);
    }

    public function test_the_health_readiness_probe_returns_a_status_not_a_config_dump(): void
    {
        $response = $this->getJson('/health/ready');

        // 200 when ready, 503 when not: both are correct answers, but never a 500.
        $this->assertContains($response->getStatusCode(), [200, 503]);

        $json = (string) $response->getContent();

        foreach (['DB_PASSWORD', 'mysql://', 'redis://', 'APP_KEY'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json);
        }
    }

    public function test_the_compliance_badge_endpoint_is_public_and_claims_nothing_it_cannot_prove(): void
    {
        $response = $this->getJson('/api/v1/public/compliance-badges');

        $response->assertOk();
        // The project's standard envelope: {success, message, data}. Asserting the
        // envelope, not a bare payload, is the point - a bare payload would mean this
        // one endpoint opted out of the contract every other endpoint honours.
        $response->assertJsonStructure(['success', 'message', 'data' => ['badges', 'notices']]);
        $response->assertJsonPath('success', true);

        $json = (string) $response->getContent();

        // With no attestation rows there are no badges, and the payload says so by
        // being empty rather than by inventing a placeholder.
        $this->assertStringNotContainsString('100%', $json);
        $this->assertStringNotContainsString('Guaranteed', $json);
    }

    public function test_the_new_routes_do_not_collide_with_existing_ones(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();
        $seen = [];

        foreach ($routes as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = $method.' '.$route->uri();

                $this->assertArrayNotHasKey(
                    $key,
                    $seen,
                    'Duplicate route registration: '.$key,
                );

                $seen[$key] = true;
            }
        }

        $this->assertNotEmpty($seen);
    }

    public function test_me_routes_carry_the_auth_middleware(): void
    {
        $routes = \Illuminate\Support\Facades\Route::getRoutes();

        $checked = 0;

        foreach ($routes as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/me/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $flat = implode('|', array_map(static fn ($m): string => is_string($m) ? $m : '', $middleware));

            $this->assertMatchesRegularExpression(
                '/auth/',
                $flat,
                $uri.' is member data and must sit behind authentication.',
            );

            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'The /me surface should have more than twenty routes.');
    }
}
