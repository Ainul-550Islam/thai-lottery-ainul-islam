<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\Models\Bet;
use App\Models\Ticket;
use App\Services\Security\ResponsibleGamingService;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/**
 * P1-17: Browser Bet Slip purchase web transport integration tests.
 *
 * Proves browser purchase success, insufficient balance, closed draw,
 * responsible gaming limit rejection, and idempotent replay without duplicating business logic.
 */
final class BetPurchaseWebTest extends ApiPurchaseTestCase
{
    private const URI = '/bet/purchase';

    public function test_unauthenticated_request_is_rejected(): void
    {
        $fixture = $this->fixture();

        $response = $this->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->id,
            'client_key' => $this->key('unauth-web-test'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
            ],
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_player_can_purchase_bets_successfully(): void
    {
        $fixture = $this->fixture('500.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'])->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->id,
            'client_key' => $this->key('web-purchase-success'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '20.00'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('report.purchased', 1);
        $response->assertJsonPath('report.total_charged', '20.00');

        $this->assertSame(1, Bet::query()->count());
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_insufficient_wallet_balance_refuses_purchase(): void
    {
        $fixture = $this->fixture('5.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $response = $this->actingAs($fixture['user'])->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->id,
            'client_key' => $this->key('insufficient-balance-web'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '50.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_closed_draw_is_refused(): void
    {
        $fixture = $this->fixture('500.00');
        $closed = $this->closedDraw();

        $response = $this->actingAs($fixture['user'])->postJson(self::URI, [
            'draw_id' => (int) $closed->id,
            'client_key' => $this->key('closed-draw-web'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '10.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_responsible_gaming_single_bet_limit_rejection(): void
    {
        $fixture = $this->fixture('1000.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '123');

        $rgService = app(ResponsibleGamingService::class);
        $rgService->setLimits($fixture['user'], singleBetLimit: '15.00');

        $response = $this->actingAs($fixture['user'])->postJson(self::URI, [
            'draw_id' => (int) $fixture['draw']->id,
            'client_key' => $this->key('rg-limit-breach'),
            'items' => [
                ['market' => '3d_direct', 'number' => '123', 'stake' => '25.00'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_idempotent_replay_returns_success_without_second_debit(): void
    {
        $fixture = $this->fixture('500.00');
        $this->ensureLimit($fixture['draw'], '3d_direct', '456');

        $clientKey = $this->key('web-idempotency-key-test');

        $payload = [
            'draw_id' => (int) $fixture['draw']->id,
            'client_key' => $clientKey,
            'items' => [
                ['market' => '3d_direct', 'number' => '456', 'stake' => '10.00'],
            ],
        ];

        // First purchase
        $first = $this->actingAs($fixture['user'])->postJson(self::URI, $payload);
        $first->assertStatus(201);
        $first->assertJsonPath('report.purchased', 1);

        $wallet = $fixture['wallet']->fresh();
        $this->assertSame('490.00', (string) $wallet->balance);

        // Replay identical client key
        $second = $this->actingAs($fixture['user'])->postJson(self::URI, $payload);
        $second->assertStatus(200);
        $second->assertJsonPath('report.replayed', 1);

        // Balance remains unchanged (no duplicate debit)
        $this->assertSame('490.00', (string) $fixture['wallet']->fresh()->balance);
        $this->assertSame(1, Bet::query()->count());
    }
}
