<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AgentStatus;
use App\Enums\UserStatus;
use App\Models\Agent;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Auth\Google\GoogleOidcClient;
use App\Services\Auth\Google\GoogleProfile;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Same fixtures as MemberAuthParityTest (RefreshDatabase, Agent::create, LegalDocumentService).
 * Run: php artisan test --filter=GoogleSignInTest
 */
final class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private function configureGoogle(): void
    {
        config([
            'services.google.enabled' => true,
            'services.google.client_id' => 'test-client.apps.googleusercontent.com',
            'services.google.client_secret' => 'secret',
            'services.google.redirect' => 'https://example.test/social/google/callback',
        ]);
    }

    private function fakeProfile(string $sub, string $email): GoogleProfile
    {
        return new GoogleProfile($sub, $email, true, 'Test Person', null);
    }

    private function mockCallback(GoogleProfile $profile): void
    {
        $this->mock(GoogleOidcClient::class, function ($mock) use ($profile): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('profileFromCallback')->andReturn($profile);
        });
    }

    private function linkIdentity(User $user, string $sub): void
    {
        $identity = new SocialIdentity;
        $identity->user_id = $user->id;
        $identity->provider = 'google';
        $identity->provider_subject = $sub;
        $identity->email_at_link = $user->email;
        $identity->email_verified = true;
        $identity->linked_via = 'signup';
        $identity->save();
    }

    private function activeAgent(string $code): Agent
    {
        $owner = User::factory()->create(['status' => UserStatus::Active]);

        return Agent::create([
            'user_id' => $owner->id,
            'agent_code' => strtoupper($code),
            'status' => AgentStatus::Active,
            'commission_rate' => '0.0500',
        ]);
    }

    /** @param array<string, string> $override @return array<string, string> */
    private function confirmPayload(array $override = []): array
    {
        $legal = app(LegalDocumentService::class);
        $locale = (string) app()->getLocale();
        $terms = $legal->currentData('terms', $locale);
        $privacy = $legal->currentData('privacy', $locale);

        return array_merge([
            'referral_id' => 'promo1',
            'date_of_birth' => '1995-05-15',
            'age_attestation' => '1',
            'terms' => '1',
            'privacy_notice' => '1',
            'terms_version' => $terms['version'],
            'terms_hash' => $terms['content_hash'],
            'privacy_version' => $privacy['version'],
            'privacy_hash' => $privacy['content_hash'],
        ], $override);
    }

    /* ---------------- login page: keeps the pre-existing closed-notice contract ---------------- */

    public function test_login_page_keeps_the_closed_notice_while_google_is_off(): void
    {
        config(['services.google.enabled' => false]);

        $this->get(route('login'))->assertOk()
            ->assertSee(__('public_pages.social_unavailable_until_link_verified'))
            ->assertDontSee(route('social.redirect', ['provider' => 'google']), false);
    }

    public function test_login_page_offers_the_google_button_only_when_enabled(): void
    {
        $this->configureGoogle();

        $this->get(route('login'))->assertOk()
            ->assertSee(route('social.redirect', ['provider' => 'google']), false)
            ->assertDontSee(__('public_pages.social_unavailable_until_link_verified'));
    }

    /* ---------------- OIDC mechanics ---------------- */

    public function test_redirect_carries_state_nonce_and_pkce(): void
    {
        $this->configureGoogle();

        $location = (string) $this->get(route('social.redirect', ['provider' => 'google']))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        $this->assertSame('S256', $q['code_challenge_method'] ?? null);
        $this->assertSame(48, strlen((string) ($q['state'] ?? '')));
        $this->assertNotEmpty($q['nonce'] ?? null);
        $this->assertSame('openid email profile', $q['scope'] ?? null);
    }

    public function test_forged_state_is_rejected_and_nobody_is_logged_in(): void
    {
        $this->configureGoogle();
        $this->get(route('social.redirect', ['provider' => 'google']));

        $this->get(route('social.callback', ['provider' => 'google', 'state' => 'forged', 'code' => 'x']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_callback_without_a_started_flow_is_rejected(): void
    {
        $this->configureGoogle();

        $this->get(route('social.callback', ['provider' => 'google', 'state' => 'a', 'code' => 'b']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_facebook_and_x_stay_closed(): void
    {
        $this->configureGoogle();

        $this->get(route('social.redirect', ['provider' => 'facebook']))->assertSessionHasErrors('provider');
        $this->get(route('social.redirect', ['provider' => 'twitter']))->assertSessionHasErrors('provider');
    }

    /* ---------------- identity resolution ---------------- */

    public function test_linked_google_identity_logs_the_member_in(): void
    {
        $this->configureGoogle();
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $this->linkIdentity($user, 'sub-123');
        $this->mockCallback($this->fakeProfile('sub-123', 'whatever-google-says@example.test'));

        $this->get(route('social.callback', ['provider' => 'google']))->assertRedirect(route('player.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_matching_email_alone_never_logs_in_or_links(): void
    {
        $this->configureGoogle();
        $existing = User::factory()->create(['status' => UserStatus::Active]);
        $this->mockCallback($this->fakeProfile('sub-unlinked', $existing->email));

        $this->get(route('social.callback', ['provider' => 'google']))
            ->assertRedirect(route('login'))->assertSessionHasErrors('login');

        $this->assertGuest();
        $this->assertSame(0, SocialIdentity::query()->count());
    }

    public function test_suspended_member_cannot_sign_in_with_google(): void
    {
        $this->configureGoogle();
        $user = User::factory()->create(['status' => UserStatus::Suspended]);
        $this->linkIdentity($user, 'sub-suspended');
        $this->mockCallback($this->fakeProfile('sub-suspended', $user->email));

        $this->from(route('login'))->get(route('social.callback', ['provider' => 'google']))->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_unknown_google_account_is_parked_for_signup_not_created(): void
    {
        $this->configureGoogle();
        $this->mockCallback($this->fakeProfile('sub-new', 'brand.new@example.test'));

        $this->get(route('social.callback', ['provider' => 'google']))->assertRedirect(route('social.google.confirm'));

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'brand.new@example.test']);
    }

    /* ---------------- sign-up completion ---------------- */

    public function test_new_member_completes_signup_with_referral_age_and_consent(): void
    {
        $this->configureGoogle();
        $agent = $this->activeAgent('PROMO1');
        $this->mockCallback($this->fakeProfile('sub-new-1', 'new.member@example.test'));
        $this->get(route('social.callback', ['provider' => 'google']))->assertRedirect(route('social.google.confirm'));

        $this->post(route('social.google.complete'), $this->confirmPayload())->assertRedirect(route('player.dashboard'));

        $user = User::query()->where('email', 'new.member@example.test')->firstOrFail();
        $this->assertSame('Test Person', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('social_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_subject' => 'sub-new-1', 'linked_via' => 'signup']);
        $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'currency' => 'THB']);
        $this->assertEquals(0.0, (float) DB::table('wallets')->where('user_id', $user->id)->value('balance'), 'No bonus money on sign-up.');
        $this->assertDatabaseHas('age_verifications', ['user_id' => $user->id, 'status' => 'eligible', 'method' => 'self_declaration', 'source' => 'registration']);
        $this->assertSame(2, DB::table('user_consents')->where('user_id', $user->id)->count());
        $this->assertSame($agent->id, (int) ((array) $user->preferences)['referred_by_agent_id']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_unknown_referral_rolls_everything_back(): void
    {
        $this->configureGoogle();
        $this->mockCallback($this->fakeProfile('sub-ghost', 'ghost.ref@example.test'));
        $this->get(route('social.callback', ['provider' => 'google']));

        $this->post(route('social.google.complete'), $this->confirmPayload(['referral_id' => 'GHOST99']))
            ->assertSessionHasErrors('referral_id');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ghost.ref@example.test']);
        $this->assertSame(0, SocialIdentity::query()->count());
    }

    public function test_under_age_date_of_birth_is_refused(): void
    {
        $this->configureGoogle();
        $this->activeAgent('PROMO1');
        $this->mockCallback($this->fakeProfile('sub-young', 'young@example.test'));
        $this->get(route('social.callback', ['provider' => 'google']));

        $this->post(route('social.google.complete'), $this->confirmPayload(['date_of_birth' => now()->subYears(10)->toDateString()]))
            ->assertSessionHasErrors('date_of_birth');

        $this->assertDatabaseMissing('users', ['email' => 'young@example.test']);
    }

    public function test_confirm_post_without_a_verified_google_session_is_refused(): void
    {
        $this->configureGoogle();
        $this->activeAgent('PROMO1');

        $this->post(route('social.google.complete'), $this->confirmPayload())->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame(0, SocialIdentity::query()->count());
    }
}
