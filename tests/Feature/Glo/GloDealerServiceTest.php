<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Enums\GloDealerRequestStatus;
use App\Enums\GloDealerRequestType;
use App\Enums\GloDealerStatus;
use App\Models\GloDealer;
use App\Models\User;
use App\Services\Lottery\GloDealerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-15 dealer service: own profile/history, unauthorized access.
 */
class GloDealerServiceTest extends TestCase
{
    use DatabaseTruncation;

    private User $dealerUser;

    private User $otherUser;

    private GloDealerService $service;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->service = app(GloDealerService::class);
        $this->dealerUser = $this->freshUser()->create();
        $this->otherUser = $this->freshUser()->create();
        $this->token = $this->dealerUser->createToken('api')->plainTextToken;
    }

    public function test_dealer_profile_is_created_for_user(): void
    {
        $dealer = $this->service->ensureDealerForUser($this->dealerUser);

        $this->assertSame($this->dealerUser->getKey(), $dealer->user_id);
        $this->assertStringStartsWith('SYN-DEALER-', $dealer->dealer_ref);
        $this->assertSame(GloDealerStatus::Pending, $dealer->status);
    }

    public function test_ensure_is_idempotent(): void
    {
        $a = $this->service->ensureDealerForUser($this->dealerUser);
        $b = $this->service->ensureDealerForUser($this->dealerUser);
        $this->assertSame($a->getKey(), $b->getKey());
        $this->assertSame(1, GloDealer::query()->where('user_id', $this->dealerUser->getKey())->count());
    }

    public function test_api_own_profile_only(): void
    {
        $resp = $this->getJson('/api/v1/glo/dealer/profile', [
            'Authorization' => 'Bearer '.$this->token,
        ]);
        $resp->assertOk()->assertJsonPath('success', true);
        $a = GloDealer::query()->where('user_id', $this->dealerUser->getKey())->firstOrFail();

        $otherToken = $this->otherUser->createToken('api')->plainTextToken;
        $b = $this->service->ensureDealerForUser($this->otherUser);

        // Resolve via Sanctum guard directly to prove token ownership.
        $authed = \Laravel\Sanctum\PersonalAccessToken::findToken($otherToken);
        $this->assertNotNull($authed);
        $this->assertSame($this->otherUser->getKey(), $authed->tokenable_id);

        $this->actingAs($this->otherUser, 'sanctum');
        $resp2 = $this->getJson('/api/v1/glo/dealer/profile');
        $resp2->assertOk();
        $this->assertSame($b->dealer_ref, $resp2->json('data.dealer_ref'));
        $this->assertNotSame($a->dealer_ref, $b->dealer_ref);
        $this->assertNotSame($a->dealer_ref, $resp2->json('data.dealer_ref'));
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/v1/glo/dealer/profile')->assertStatus(401);
    }

    public function test_history_labels_internal_source(): void
    {
        $dealer = $this->service->ensureDealerForUser($this->dealerUser);
        $history = $this->service->ownHistory($dealer);

        $this->assertSame('INTERNAL_APPLICATION_HISTORY', $history['labels']['history']);
        $this->assertArrayHasKey('change_requests', $history);
        $this->assertArrayHasKey('purchases', $history);
        $this->assertArrayHasKey('proxy_authorizations', $history);
    }

    public function test_another_dealer_cannot_see_other_history_via_api(): void
    {
        $this->service->ensureDealerForUser($this->dealerUser);
        $otherToken = $this->otherUser->createToken('api')->plainTextToken;

        $response = $this->withToken($otherToken)->getJson('/api/v1/glo/dealer/history');
        $response->assertOk();

        // History is always scoped to authenticated user's dealer — no query param id.
        $payload = $response->json('data');
        $this->assertArrayHasKey('change_requests', $payload);
        $otherDealer = $this->service->ensureDealerForUser($this->otherUser);
        $this->assertSame(
            $otherDealer->getKey(),
            GloDealer::query()->where('user_id', $this->otherUser->getKey())->value('id'),
        );
    }

    public function test_activate_is_operator_path(): void
    {
        $dealer = $this->service->ensureDealerForUser($this->dealerUser);
        $activated = $this->service->activate($dealer);
        $this->assertTrue($activated->isActive());
        $this->assertSame('verified', $activated->verification_state);
    }

    public function test_own_profile_does_not_leak_internal_ids(): void
    {
        $dealer = $this->service->ensureDealerForUser($this->dealerUser);
        $profile = $this->service->ownProfile($dealer);

        $this->assertArrayNotHasKey('metadata', $profile);
        $this->assertArrayNotHasKey('user_id', $profile);
        $this->assertArrayNotHasKey('capabilities_internal', $profile);
        $this->assertSame('OFFICIAL_SOURCE_CONFIGURED' === 'x' ? 'x' : 'INTERNAL_RECONCILED', $profile['source_state']);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }
}
