<?php

declare(strict_types=1);

namespace Tests\Feature\Results;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * The public prize-check endpoint.
 *
 * WHAT IS ASSERTED, AND WHY EACH ONE MATTERS
 * ---------------------------------------------------------------------------
 * The endpoint answers with money. The tests below pin the contract that makes
 * that safe regardless of what is in the database:
 *
 *   1. the route is POST-only and reachable without a session (a visitor checks
 *      a ticket before signing in, or never signs in at all);
 *   2. a malformed ticket is a 422 and is the ONLY 4xx the visitor causes;
 *   3. every expected absence is a 200 carrying `state`, never a 500 — an
 *      outage of the result source must not look like a broken site;
 *   4. no response ever contains a number the database does not hold.
 */
final class PrizeCheckEndpointTest extends TestCase
{
    public function test_the_prize_check_route_is_post_only(): void
    {
        $this->assertTrue(RouteFacade::has('api.v1.glo.prize-check'));

        $route = RouteFacade::getRoutes()->getByName('api.v1.glo.prize-check');

        $this->assertNotNull($route);
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('api/v1/glo/prize-check', $route->uri());
    }

    public function test_a_seven_digit_number_is_refused_with_a_422_and_a_code(): void
    {
        $response = $this->postJson('/api/v1/glo/prize-check', ['number' => '1234567']);

        $response->assertStatus(422);
        $response->assertJsonPath('status', 'INVALID_INPUT');
        $response->assertJsonPath('state', 'INVALID_TICKET');
    }

    public function test_a_non_numeric_number_is_refused_with_a_422(): void
    {
        $response = $this->postJson('/api/v1/glo/prize-check', ['number' => 'ABCDEF']);

        $response->assertStatus(422);
        $response->assertJsonPath('state', 'INVALID_TICKET');
    }

    public function test_the_response_never_carries_a_client_supplied_amount(): void
    {
        // The client cannot ask for a prize. Anything the response states about
        // money is derived server-side, so an ignored input field proves the
        // request body is not a lever.
        $response = $this->postJson('/api/v1/glo/prize-check', [
            'number' => '042042',
            'amount' => '999999.00',
            'tier' => '1',
        ]);

        $payload = (string) $response->getContent();

        $this->assertStringNotContainsString('999999', $payload);
        $this->assertNotSame(500, $response->getStatusCode(), 'an absence must never be a server error');
    }

    public function test_an_empty_database_answers_a_state_rather_than_an_error(): void
    {
        $response = $this->postJson('/api/v1/glo/prize-check', ['number' => '042042']);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('data.state', 'NOT_CONFIGURED');
        // And nothing resembling a prize is attached to it.
        $response->assertJsonPath('data.tier', null);
        $response->assertJsonPath('data.verdict', null);
    }

    public function test_an_unknown_draw_is_an_absence_not_a_crash(): void
    {
        $response = $this->postJson('/api/v1/glo/prize-check', [
            'number' => '042042',
            'draw' => 'NO-SUCH-DRAW',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.state', 'NOT_CONFIGURED');
        $response->assertJsonPath('data.reason', 'unknown_draw');
    }
}
