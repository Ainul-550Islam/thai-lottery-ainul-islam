<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\LoginEventOutcome;
use App\Enums\MfaChallengeStatus;
use App\Events\MfaChallengeVerified;
use App\Http\Resources\LoginEventResource;
use App\Models\LoginEvent;
use App\Models\MfaChallenge;
use App\Models\User;
use App\Services\Security\LoginHistory\LoginEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class LoginHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_all_four_outcomes_hashes_unknown_identifiers_enforces_owner_isolation_and_prunes(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $admin = User::factory()->create();

        $recorder = app(LoginEventRecorder::class);

        $successEvent = $recorder->record(
            member: $owner,
            identifier: 'owner@example.com',
            outcome: LoginEventOutcome::Success,
            ip: '203.0.113.45',
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/125.0.0.0 Safari/537.36',
        );

        // Failed login for unknown identifier: member_id is null, identifier stored ONLY as HMAC hash
        $unknownFailed = $recorder->record(
            member: null,
            identifier: 'nonexistent-secret@example.com',
            outcome: LoginEventOutcome::Failed,
            ip: '198.51.100.22',
            userAgent: 'curl/8.5.0',
        );

        $this->assertNull($unknownFailed->member_id);
        $this->assertNotSame('nonexistent-secret@example.com', $unknownFailed->identifier_hash);
        $this->assertSame(64, strlen($unknownFailed->identifier_hash));

        // Failed + Blocked outcomes via recorder + MFA required via real Pending MfaChallenge creation
        $recorder->record($owner, 'owner@example.com', LoginEventOutcome::Failed, '203.0.113.46', 'Chrome/125.0');
        $recorder->record($owner, 'owner@example.com', LoginEventOutcome::Blocked, '203.0.113.45', 'Chrome/125.0');
        MfaChallenge::query()->create([
            'challenge_key' => 'mfa-chal-pending-0000',
            'user_id' => $owner->getKey(),
            'status' => MfaChallengeStatus::Pending,
            'channel' => 'totp',
            'secret_fingerprint' => hash('sha256', 'totp-secret-pending'),
            'attempts' => 0,
            'issued_at' => Carbon::now()->subSeconds(30),
            'expires_at' => Carbon::now()->addMinutes(4),
        ]);

        // Existing MfaChallengeVerified event records successful post-MFA login
        $challenge = MfaChallenge::query()->create([
            'challenge_key' => 'mfa-chal-0001',
            'user_id' => $owner->getKey(),
            'status' => MfaChallengeStatus::Verified,
            'channel' => 'totp',
            'secret_fingerprint' => hash('sha256', 'totp-secret'),
            'attempts' => 1,
            'issued_at' => Carbon::now()->subMinute(),
            'expires_at' => Carbon::now()->addMinutes(4),
            'verified_at' => Carbon::now(),
        ]);
        event(new MfaChallengeVerified($challenge, Carbon::now()->toIso8601String()));

        // Event for another member -> must NEVER appear in $owner's API response
        $recorder->record($other, 'other@example.com', LoginEventOutcome::Success, '192.0.2.99', 'Firefox/126.0');

        Sanctum::actingAs($owner);

        $history = $this->getJson('/api/v1/me/security/login-history?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.per_page', 2);

        // Owner sees unmasked IP on full list
        $fullHistory = $this->getJson('/api/v1/me/security/login-history?per_page=10')
            ->assertOk();
        $this->assertSame('203.0.113.45', $fullHistory->json('data.items.4.ip'));

        // Admin / non-owner resource view sees masked IP only
        $adminRequest = Request::create('/admin', 'GET');
        $adminRequest->setUserResolver(static fn (): User => $admin);
        $maskedView = (new LoginEventResource($successEvent))->resolve($adminRequest);
        $this->assertSame('203.0.113.***', $maskedView['ip']);

        $this->getJson('/api/v1/me/security/login-summary')
            ->assertOk()
            ->assertJsonPath('data.successful_logins_30d', 2)
            ->assertJsonPath('data.failed_attempts_30d', 1)
            ->assertJsonPath('data.blocked_attempts_30d', 1)
            ->assertJsonPath('data.mfa_challenges_30d', 1)
            ->assertJsonPath('data.distinct_ips_30d', 3)
            ->assertJsonPath('data.distinct_countries_30d', 0);

        // Retention prune command removes events older than configured retention_days (180d)
        $oldEvent = $recorder->record(
            member: $owner,
            identifier: 'owner@example.com',
            outcome: LoginEventOutcome::Success,
            ip: '203.0.113.1',
            userAgent: 'Safari/605.1',
            occurredAt: Carbon::now()->subDays(185),
        );

        $this->artisan('security:prune-login-events')->assertExitCode(0);
        $this->assertNull(LoginEvent::query()->find($oldEvent->getKey()));
    }
}
