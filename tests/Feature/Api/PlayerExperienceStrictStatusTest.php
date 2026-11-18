<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Ops\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Exact-status companions to PlayerRoutesTest.
 *
 * WHY A SECOND FILE
 * PlayerRoutesTest accepts [401, 403, 405, 422] for an anonymous caller and
 * [200, 503] for readiness. Those ranges catch "returned data", but they would also
 * accept a route that answers 403 or 422 BEFORE authentication, which is a different
 * and worse bug (the route reached application code without a token). These tests
 * state the contract exactly: anonymous means 401, and a stale scheduler heartbeat
 * means 503 with the scheduler named as the reason.
 *
 * Nothing in the existing file is changed.
 */
final class PlayerExperienceStrictStatusTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * Every authenticated Batch 3 route, with a concrete URI.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function authenticatedRoutes(): array
    {
        return [
            'GET bet-slip' => ['GET', '/api/v1/me/bet-slip'],
            'POST bet-slip items' => ['POST', '/api/v1/me/bet-slip/items'],
            'DELETE bet-slip item' => ['DELETE', '/api/v1/me/bet-slip/items/1'],
            'DELETE bet-slip' => ['DELETE', '/api/v1/me/bet-slip'],
            'POST bet-slip confirm' => ['POST', '/api/v1/me/bet-slip/confirm'],
            'GET bets' => ['GET', '/api/v1/me/bets'],
            'GET bets counts' => ['GET', '/api/v1/me/bets/counts'],
            'GET bets show' => ['GET', '/api/v1/me/bets/1'],
            'GET wallet overview' => ['GET', '/api/v1/me/wallet/overview'],
            'GET wallet transactions' => ['GET', '/api/v1/me/wallet/transactions'],
            'GET profile overview' => ['GET', '/api/v1/me/profile/overview'],
            'GET security overview' => ['GET', '/api/v1/me/security/overview'],
            'GET security alerts' => ['GET', '/api/v1/me/security/alerts'],
            'GET notification preferences' => ['GET', '/api/v1/me/notification-preferences'],
            'PUT notification preferences' => ['PUT', '/api/v1/me/notification-preferences'],
            'GET support conversations' => ['GET', '/api/v1/me/support/conversations'],
            'POST support conversations' => ['POST', '/api/v1/me/support/conversations'],
            'GET support messages' => ['GET', '/api/v1/me/support/conversations/1/messages'],
            'POST support messages' => ['POST', '/api/v1/me/support/conversations/1/messages'],
            'POST support rating' => ['POST', '/api/v1/me/support/conversations/1/rating'],
            'GET promotions active' => ['GET', '/api/v1/me/promotions/active'],
            'GET responsible gaming summary' => ['GET', '/api/v1/me/responsible-gaming/summary'],
        ];
    }

    /**
     * @dataProvider authenticatedRoutes
     */
    public function test_an_anonymous_caller_gets_exactly_401(string $method, string $uri): void
    {
        $response = $this->json($method, $uri, $method === 'GET' ? [] : ['x' => 'y']);

        $response->assertStatus(401);
    }

    public function test_the_public_badge_endpoint_needs_no_token(): void
    {
        $this->getJson('/api/v1/public/compliance-badges')->assertOk();
    }

    public function test_a_stale_scheduler_heartbeat_makes_readiness_503_and_names_the_scheduler(): void
    {
        app(SchedulerHeartbeat::class)->beat(now()->subHours(4));

        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('ready', false);
        $response->assertJsonPath('checks.scheduler.ok', false);
        $response->assertJsonPath('checks.scheduler.reason', 'scheduler_heartbeat_stale');
    }

    public function test_a_missing_scheduler_heartbeat_makes_readiness_503(): void
    {
        $response = $this->getJson('/health/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('checks.scheduler.ok', false);
        $response->assertJsonPath('checks.scheduler.reason', 'scheduler_heartbeat_missing');
    }

    public function test_a_fresh_heartbeat_clears_the_scheduler_check(): void
    {
        app(SchedulerHeartbeat::class)->beat();

        $response = $this->getJson('/health/ready');

        // Other checks (payment / result provider CONFIG) may still be red in a test
        // environment, so only the scheduler's own verdict is asserted here.
        $response->assertJsonPath('checks.scheduler.ok', true);
    }

    public function test_liveness_is_always_200_even_when_readiness_is_503(): void
    {
        $this->getJson('/health/ready')->assertStatus(503);
        $this->getJson('/health/live')->assertOk()->assertJsonPath('status', 'UP');
    }
}
