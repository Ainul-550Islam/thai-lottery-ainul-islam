<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Agent;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Object-level authorization for support cases and agent referral details.
 *
 * A user must only see and reply to their own support cases, and an agent must
 * only open referrals that were produced by that agent.
 */
final class ObjectAuthorizationSupportAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_their_own_support_case(): void
    {
        $owner = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $this->actingAs($owner)
            ->get('/support/'.$case->public_reference)
            ->assertStatus(200);
    }

    public function test_another_user_cannot_open_a_support_case(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);

        $this->actingAs($intruder)
            ->get('/support/'.$case->public_reference)
            ->assertStatus(404);
    }

    public function test_another_user_cannot_reply_to_a_support_case_and_nothing_is_written(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $case = SupportCase::factory()->create(['owner_user_id' => $owner->getKey()]);
        $before = $case->messages()->count();

        $this->actingAs($intruder)
            ->post('/support/'.$case->public_reference.'/reply', ['body' => 'cross-account reply'])
            ->assertStatus(302);

        $this->assertSame($before, $case->fresh()->messages()->count());
    }

    public function test_agent_cannot_open_a_referral_belonging_to_another_agent(): void
    {
        $ownerAgentUser = User::factory()->create();
        $otherAgentUser = User::factory()->create();
        $ownerAgent = Agent::factory()->create(['user_id' => $ownerAgentUser->getKey(), 'status' => 'active']);
        $otherAgent = Agent::factory()->create(['user_id' => $otherAgentUser->getKey(), 'status' => 'active']);

        $referred = User::factory()->create([
            'preferences' => ['referred_by_agent_id' => $ownerAgent->getKey()],
        ]);

        $reference = hash('sha256', 'agent-referral|'.$ownerAgent->getKey().'|'.$referred->getKey());

        $this->actingAs($otherAgentUser)
            ->get('/agent/referrals/'.$reference)
            ->assertStatus(404);
    }
}
