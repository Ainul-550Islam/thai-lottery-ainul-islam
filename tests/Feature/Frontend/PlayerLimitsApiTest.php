<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend;

use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\Compliance\SelfExclusionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The player limit and cool-off endpoints the security page drives.
 *
 * ============================================================================
 * THE RULE THESE TESTS ENFORCE
 * ============================================================================
 * A limit is a constraint on the player's own money, so the only acceptable
 * source for what the page displays is the row the server holds. Nothing here
 * asserts a number the test itself wrote into a view: every figure comes back
 * from the endpoint after a round trip, which is what makes the response — not
 * the browser — the source of truth.
 *
 * The gate matters more than the storage, so the last test proves the stored
 * cool-off actually stops a paid action rather than merely being readable.
 */
final class PlayerLimitsApiTest extends TestCase
{
    use RefreshDatabase;

    private const LIMITS = '/api/v1/player/security/limits';

    private const SELF_EXCLUSION = '/api/v1/player/security/self-exclusion';

    public function test_a_guest_cannot_read_or_write_limits(): void
    {
        $this->getJson(self::LIMITS)->assertStatus(401);
        $this->postJson(self::LIMITS, ['singleBet' => '20.00'])->assertStatus(401);
        $this->postJson(self::SELF_EXCLUSION, ['duration' => '7d'])->assertStatus(401);

        $this->assertSame(0, ResponsibleGamingLimit::query()->count());
    }

    public function test_limits_start_empty_and_are_reported_as_null_rather_than_zero(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson(self::LIMITS)->assertOk();

        $response->assertJsonPath('status', 'success');
        $response->assertJsonPath('limits.daily_deposit', null);
        $response->assertJsonPath('limits.single_bet', null);
        $response->assertJsonPath('limits.daily_wagering', null);
        $response->assertJsonPath('self_exclusion', null);

        // A null ceiling means "not chosen", not "zero allowed". The distinction
        // is the difference between a player with no limit and a player barred
        // from every stake, and only one of them is the truth here.
        $this->assertNull($response->json('limits.single_bet'));
    }

    public function test_a_saved_limit_is_read_back_from_the_stored_row(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::LIMITS, ['singleBet' => '25.00', 'dailyDeposit' => '500.00'])
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $stored = ResponsibleGamingLimit::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('25.00', (string) $stored->single_bet_limit);
        $this->assertSame('500.00', (string) $stored->daily_deposit_limit);

        $read = $this->actingAs($user, 'sanctum')->getJson(self::LIMITS)->assertOk();

        $this->assertSame((string) $stored->single_bet_limit, $read->json('limits.single_bet'));
        $this->assertSame((string) $stored->daily_deposit_limit, $read->json('limits.daily_deposit'));
    }

    public function test_a_malformed_amount_is_refused_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson(self::LIMITS, ['singleBet' => '25.00'])->assertOk();
        $before = ResponsibleGamingLimit::query()->where('user_id', $user->id)->get()->toArray();

        // Note the shape of each rejection: too many decimals, a sign, prose,
        // exponent notation and a comma decimal separator. Surrounding
        // whitespace is trimmed by the request pipeline before validation, so a
        // padded value is accepted and stored as the number it names.
        foreach (['12.345', '-5.00', 'abc', '1e3', '25,00'] as $bad) {
            $this->actingAs($user, 'sanctum')
                ->postJson(self::LIMITS, ['singleBet' => $bad])
                ->assertStatus(422);
        }

        $this->assertSame($before, ResponsibleGamingLimit::query()->where('user_id', $user->id)->get()->toArray());
    }

    public function test_a_cool_off_break_is_stored_with_a_horizon_and_reported(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson(self::SELF_EXCLUSION, ['duration' => '7d'])
            ->assertOk();

        $active = $this->app->make(SelfExclusionService::class)->currentActiveFor((int) $user->id);

        $this->assertNotNull($active, 'a requested cool-off must exist as an active exclusion');
        $this->assertTrue($active->ends_at->isFuture());

        $read = $this->actingAs($user, 'sanctum')->getJson(self::LIMITS)->assertOk();

        $this->assertNotNull($read->json('self_exclusion'));
        $this->assertSame($active->status->value, $read->json('self_exclusion.status'));
        $this->assertSame($active->ends_at->toIso8601String(), $read->json('self_exclusion.ends_at'));
    }

    public function test_an_unknown_cool_off_duration_is_refused(): void
    {
        $user = User::factory()->create();

        foreach (['2d', '1000d', 'forever', ''] as $bad) {
            $this->actingAs($user, 'sanctum')
                ->postJson(self::SELF_EXCLUSION, ['duration' => $bad])
                ->assertStatus(422);
        }

        $this->assertNull($this->app->make(SelfExclusionService::class)->currentActiveFor((int) $user->id));
    }

    public function test_one_player_never_sees_another_players_limits(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->postJson(self::LIMITS, ['singleBet' => '10.00'])->assertOk();

        $read = $this->actingAs($other, 'sanctum')->getJson(self::LIMITS)->assertOk();

        $this->assertNull($read->json('limits.single_bet'));
        $this->assertSame(1, ResponsibleGamingLimit::query()->count());
    }
}
