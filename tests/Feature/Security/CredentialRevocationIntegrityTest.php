<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Auth\PasswordLifecycleSessionGuard;
use App\Http\Controllers\Web\AuthController as LegacyAuthController;
use App\Models\SecuritySession;
use App\Models\TotpActionProof;
use App\Models\TotpCredential;
use App\Models\TotpPendingEnrollment;
use App\Models\TotpRecoveryCode;
use App\Models\User;
use App\Services\Auth\AdminPasswordChangeService;
use App\Services\Auth\PasswordChangeService;
use App\Services\Auth\PasswordResetService;
use App\Services\Auth\SessionGenerationRevocationService;
use App\Services\Security\TotpActionProofInvalidationService;
use App\Services\Security\TotpMfaEnrollmentService;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** SQL-backed credential and TOTP revocation: synthetic accounts only. */
final class CredentialRevocationIntegrityTest extends TestCase
{
    use DatabaseTruncation;

    private const OLD = 'CurrentPass123!';
    private const NEW = 'DifferentPass456!';
    private const CODE = 'AABBCCDD-EEFF0011';
    private const UNUSED_CODE = '11223344-55667788';

    private function accountWithCredentials(): array
    {
        $user = User::factory()->create(['password' => self::OLD]);
        $user->forceFill(['remember_token' => 'synthetic-remember'])->save();
        $user->refresh();
        $credential = new TotpCredential;
        $credential->forceFill([
            'user_id' => $user->id, 'enrollment_reference' => str_repeat('a', 32),
            'secret' => str_repeat('A', 32), 'confirmed_at_epoch' => now()->getTimestamp(),
            'last_accepted_step' => 0,
        ])->save();
        foreach ([self::CODE, self::UNUSED_CODE] as $code) {
            $row = new TotpRecoveryCode;
            $row->forceFill([
                'totp_credential_id' => $credential->id, 'batch_reference' => str_repeat('b', 32),
                'code_hash' => hash('sha256', $code), 'issued_at_epoch' => now()->getTimestamp(),
                'consumed_at_epoch' => null,
            ])->save();
        }
        $proof = app(TotpMfaEnrollmentService::class)->verifyRecoveryCode($user, self::CODE, 'synthetic-session', 'withdrawal');
        $this->assertTrue($proof['verified']);
        $this->assertDatabaseCount('totp_action_proofs', 1);
        $pending = new TotpPendingEnrollment;
        $pending->forceFill([
            'user_id' => $user->id, 'reference' => str_repeat('c', 32), 'secret' => str_repeat('B', 32),
            'issued_at_epoch' => now()->getTimestamp(), 'expires_at_epoch' => now()->addMinutes(15)->getTimestamp(),
            'confirmation_attempts' => 0,
        ])->save();
        DB::table('sessions')->insert(['id' => 'synthetic-session-'.$user->id, 'user_id' => $user->id,
            'payload' => base64_encode('synthetic'), 'last_activity' => now()->getTimestamp()]);
        SecuritySession::query()->create(['session_fingerprint' => hash('sha256', 'security-'.$user->id),
            'user_id' => $user->id, 'status' => 'active', 'issued_at' => now(), 'expires_at' => now()->addHour()]);
        $token = $user->createToken('synthetic-auth')->plainTextToken;

        return compact('user', 'credential', 'token', 'proof');
    }

    private function assertRevoked(User $user, int $previousCredentialEpoch, int $previousProofEpoch): void
    {
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check(self::NEW, (string) $fresh->password));
        $this->assertNull($fresh->remember_token);
        $this->assertSame($previousCredentialEpoch + 1, $fresh->credential_epoch);
        $this->assertSame($previousProofEpoch + 1, $fresh->totp_proof_epoch);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'tokenable_type' => User::class]);
        $this->assertDatabaseMissing('security_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseCount('totp_action_proofs', 0);
        $this->assertDatabaseMissing('totp_pending_enrollments', ['user_id' => $user->id]);
        $proofs = app(TotpActionProofInvalidationService::class);
        Cache::flush();
        $this->assertSame($previousProofEpoch + 1, $proofs->generation($user));
        $this->assertFalse($proofs->accepts($user, $previousProofEpoch));
        $this->assertTrue($proofs->accepts($user, $previousProofEpoch + 1));
        $this->assertFalse($proofs->accepts($user, $previousProofEpoch + 2));
        $this->assertSame(TotpActionProofInvalidationService::PASSWORD_CHANGED, $proofs->lastInvalidation($user)['reason']);
    }

    public function test_password_change_revokes_sessions_tokens_and_persisted_totp_proofs_together(): void
    {
        $f = $this->accountWithCredentials();
        $other = User::factory()->create();
        $other->createToken('other-user-token');
        app(PasswordChangeService::class)->change($f['user'], self::OLD, self::NEW);
        $this->assertRevoked($f['user'], 0, 0);
        $this->assertSame(1, $other->tokens()->count(), 'Another user’s API credential must survive.');
        $this->assertSame(1, TotpCredential::query()->where('user_id', $f['user']->id)->count(), 'Password change does not erase the authenticator.');
    }

    public function test_proof_revocation_failure_rolls_back_password_sessions_tokens_and_proof(): void
    {
        $f = $this->accountWithCredentials();
        User::saving(static fn (User $user) => $user->isDirty('totp_proof_epoch') ? false : null);
        try {
            app(PasswordChangeService::class)->change($f['user'], self::OLD, self::NEW);
            $this->fail('A failed durable proof revocation must abort the whole password change.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('TOTP proof revocation could not be persisted.', $exception->getMessage());
        } finally {
            User::flushEventListeners();
        }
        $fresh = $f['user']->fresh();
        $this->assertTrue(Hash::check(self::OLD, (string) $fresh->password));
        $this->assertFalse(Hash::check(self::NEW, (string) $fresh->password));
        $this->assertSame(0, $fresh->credential_epoch);
        $this->assertSame(0, $fresh->totp_proof_epoch);
        $this->assertSame('synthetic-remember', $fresh->remember_token);
        $this->assertDatabaseHas('sessions', ['user_id' => $fresh->id]);
        $this->assertSame(1, $fresh->tokens()->count());
        $this->assertDatabaseHas('security_sessions', ['user_id' => $fresh->id]);
        $this->assertDatabaseCount('totp_action_proofs', 1);
        $this->assertDatabaseHas('totp_pending_enrollments', ['user_id' => $fresh->id]);
    }

    public function test_credential_generation_write_failure_rolls_back_the_password_and_credentials(): void
    {
        $f = $this->accountWithCredentials();
        User::saving(static fn (User $user) => $user->isDirty('credential_epoch') ? false : null);
        try {
            app(PasswordChangeService::class)->change($f['user'], self::OLD, self::NEW);
            $this->fail('Refused generation persistence must abort the password change.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Credential-generation revocation could not be persisted.', $exception->getMessage());
        } finally {
            User::flushEventListeners();
        }
        $fresh = $f['user']->fresh();
        $this->assertTrue(Hash::check(self::OLD, (string) $fresh->password));
        $this->assertSame(0, $fresh->credential_epoch);
        $this->assertSame(0, $fresh->totp_proof_epoch);
        $this->assertSame('synthetic-remember', $fresh->remember_token);
        $this->assertDatabaseHas('sessions', ['user_id' => $fresh->id]);
        $this->assertSame(1, $fresh->tokens()->count());
        $this->assertDatabaseCount('totp_action_proofs', 1);
    }

    public function test_operator_session_revocation_invalidates_remember_me_tokens_too(): void
    {
        $f = $this->accountWithCredentials();
        $operator = User::factory()->create();
        app(SessionGenerationRevocationService::class)->revoke($operator, $f['user']);
        $fresh = $f['user']->fresh();
        $this->assertNull($fresh->remember_token);
        $this->assertSame(1, $fresh->credential_epoch);
        $this->assertTrue(Hash::check(self::OLD, (string) $fresh->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $fresh->id]);
        $this->assertSame(0, $fresh->tokens()->count());
    }

    public function test_reset_consumes_token_and_revokes_even_if_old_optional_flag_is_false(): void
    {
        $f = $this->accountWithCredentials();
        config(['auth_security.password_reset.revoke_sessions' => false]);
        $token = Password::broker()->createToken($f['user']);
        $out = app(PasswordResetService::class)->resetPassword(Request::create('/reset-password', 'POST'), [
            'token' => $token, 'email' => $f['user']->email, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ]);
        $this->assertTrue($out['reset']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $f['user']->email]);
        $this->assertRevoked($f['user'], 0, 0);
        try {
            app(PasswordResetService::class)->resetPassword(Request::create('/reset-password', 'POST'), [
                'token' => $token, 'email' => $f['user']->email, 'password' => self::NEW, 'password_confirmation' => self::NEW,
            ]);
            $this->fail('A consumed reset token must not be reusable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
        }
        $this->assertSame(1, $f['user']->fresh()->credential_epoch);
    }

    public function test_stale_in_flight_user_cannot_mint_a_proof_after_password_change(): void
    {
        $f = $this->accountWithCredentials();
        $stale = User::query()->findOrFail($f['user']->id);
        app(PasswordChangeService::class)->change($f['user'], self::OLD, self::NEW);
        try {
            app(TotpMfaEnrollmentService::class)->verifyRecoveryCode($stale, self::UNUSED_CODE, 'stale-session', 'withdrawal');
            $this->fail('A stale credential generation must not mint a proof.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mfa', $exception->errors());
        }
        $this->assertDatabaseCount('totp_action_proofs', 0);
        $this->assertDatabaseHas('totp_recovery_codes', ['code_hash' => hash('sha256', self::UNUSED_CODE), 'consumed_at_epoch' => null]);
    }

    public function test_api_password_change_revokes_the_bearer_used_for_the_change(): void
    {
        $f = $this->accountWithCredentials();
        $this->withHeader('Authorization', 'Bearer '.$f['token'])->putJson('/api/v1/profile/password', [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertRevoked($f['user'], 0, 0);
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$f['token'])->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_login_stamps_epoch_and_a_recreated_old_session_is_rejected(): void
    {
        $user = User::factory()->create(['password' => self::OLD])->fresh();
        config(['auth_security.captcha.enabled' => false]);
        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => self::OLD])
            ->assertRedirect(route('player.dashboard'));
        $this->assertInstanceOf(PasswordLifecycleSessionGuard::class, Auth::guard('web'));
        $this->assertSame(0, session()->get('credential_epoch_web'));
        $id = session()->getId();
        $this->assertDatabaseHas('sessions', ['id' => $id, 'user_id' => $user->id]);

        // A request already in flight can write back a previously deleted
        // session row. The credential epoch still makes that old row unusable.
        DB::table('users')->where('id', $user->id)->increment('credential_epoch');
        $this->app['auth']->forgetGuards();
        $this->withCookie((string) config('session.cookie'), $id)->get(route('player.profile'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_web_password_change_invalidates_the_current_in_memory_session(): void
    {
        $f = $this->accountWithCredentials();
        config(['auth_security.captcha.enabled' => false]);
        $this->post(route('login.attempt'), ['login' => $f['user']->email, 'password' => self::OLD])
            ->assertRedirect(route('player.dashboard'));
        $id = session()->getId();
        $oldSession = (array) DB::table('sessions')->where('id', $id)->first();
        $this->assertNotEmpty($oldSession);
        $this->withCookie((string) config('session.cookie'), $id)->put(route('player.password.update'), [
            'current_password' => self::OLD, 'new_password' => self::NEW,
        ])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check(self::NEW, (string) $f['user']->fresh()->password));
        $this->assertSame(1, $f['user']->fresh()->credential_epoch);
        $this->assertSame(1, $f['user']->fresh()->totp_proof_epoch);
        $this->assertDatabaseCount('totp_action_proofs', 0);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $f['user']->id, 'tokenable_type' => User::class]);
        $this->assertDatabaseMissing('sessions', ['id' => $id]);

        // Recreate the exact pre-change row as if an in-flight request wrote it
        // back after revocation. Neither the row nor its stolen cookie may work.
        DB::table('sessions')->insert($oldSession);
        $this->app['auth']->forgetGuards();
        $this->get(route('player.profile'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => $f['user']->email, 'password' => self::OLD,
        ])->assertSessionHasErrors('login');
        $this->post(route('login.attempt'), [
            'login' => $f['user']->email, 'password' => self::NEW,
        ])->assertRedirect(route('player.profile'));
        $this->assertAuthenticatedAs($f['user']->fresh());
    }

    public function test_first_party_api_cookie_is_epoch_bound_and_cannot_outlive_password_change(): void
    {
        $user = User::factory()->create(['password' => self::OLD])->fresh();
        config(['auth_security.captcha.enabled' => false, 'sanctum.stateful' => ['localhost']]);
        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => self::OLD])
            ->assertRedirect(route('player.dashboard'));
        $id = session()->getId();
        $oldSession = (array) DB::table('sessions')->where('id', $id)->first();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        $this->withCredentials()->withCookie((string) config('session.cookie'), $id)
            ->withHeader('Origin', 'http://localhost')->getJson('/api/v1/profile')->assertOk();

        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        $this->putJson('/api/v1/profile/password', [
            'current_password' => self::OLD, 'password' => self::NEW, 'password_confirmation' => self::NEW,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseMissing('sessions', ['id' => $id]);
        $this->assertSame(1, $user->fresh()->credential_epoch);

        // Test a real API request carrying a resurrected old cookie, not an
        // in-process actingAs() substitute.
        DB::table('sessions')->insert($oldSession);
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        $this->getJson('/api/v1/profile')->assertUnauthorized();
    }

    public function test_first_party_api_mutations_enforce_csrf_when_not_in_test_bypass_mode(): void
    {
        $user = User::factory()->create(['password' => self::OLD])->fresh();
        config(['auth_security.captcha.enabled' => false, 'sanctum.stateful' => ['localhost'],
            'sanctum.middleware.validate_csrf_token' => StrictCsrfForStatefulTest::class]);
        $this->post(route('login.attempt'), ['login' => $user->email, 'password' => self::OLD])
            ->assertRedirect(route('player.dashboard'));
        $id = session()->getId();
        $csrf = session()->token();
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        $this->withCredentials()->withCookie((string) config('session.cookie'), $id)
            ->withHeader('Origin', 'http://localhost')->putJson('/api/v1/profile/password', [])
            ->assertStatus(419);
        $this->app['auth']->forgetGuards();
        Auth::shouldUse('web');
        // Valid CSRF reaches ordinary input validation, without changing a password.
        $this->withHeader('X-CSRF-TOKEN', $csrf)->putJson('/api/v1/profile/password', [])
            ->assertUnprocessable();
        $this->assertTrue(Hash::check(self::OLD, (string) $user->fresh()->password));
        $this->assertSame(0, $user->fresh()->credential_epoch);
    }

    public function test_admin_password_change_also_revokes_account_credentials_and_proofs(): void
    {
        $f = $this->accountWithCredentials();
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('admin');
        app(AdminPasswordChangeService::class)->change($operator, $f['user'], self::NEW);
        $this->assertRevoked($f['user'], 0, 0);
    }

    public function test_admin_password_change_rejects_duplicate_profile_identity_without_partial_mutation(): void
    {
        $f = $this->accountWithCredentials();
        $this->seed(RolePermissionSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('admin');
        $other = User::factory()->create(['phone' => '+8801700000555']);
        $before = $f['user']->fresh()->only(['name', 'username', 'email', 'phone', 'password', 'credential_epoch', 'totp_proof_epoch']);

        foreach ([
            ['username' => $other->username],
            ['email' => $other->email],
            ['phone' => $other->phone],
        ] as $conflictingIdentity) {
            try {
                app(AdminPasswordChangeService::class)->change($operator, $f['user'], self::NEW,
                    ['name' => 'Must not save'] + $conflictingIdentity);
                $this->fail('A duplicate profile identity must be refused.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(array_key_first($conflictingIdentity), $exception->errors());
            }
            $this->assertSame($before, $f['user']->fresh()->only(array_keys($before)));
            $this->assertDatabaseHas('sessions', ['user_id' => $f['user']->id]);
            $this->assertDatabaseHas('totp_action_proofs', ['totp_credential_id' => $f['credential']->id]);
        }
    }

    public function test_invalid_enrollment_guesses_are_persisted_and_enrollment_is_removed_at_limit(): void
    {
        $user = User::factory()->create()->fresh();
        $mfa = app(TotpMfaEnrollmentService::class);
        $mfa->startEnrollment($user);
        for ($i = 1; $i <= 5; $i++) {
            try {
                $mfa->confirmEnrollment($user, 'not-a-code');
                $this->fail('Invalid TOTP confirmation must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('code', $exception->errors());
            }
            $this->assertSame($i, (int) TotpPendingEnrollment::query()->where('user_id', $user->id)->value('confirmation_attempts'));
        }
        try {
            $mfa->confirmEnrollment($user, 'not-a-code');
            $this->fail('Exhausted enrollment must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertDatabaseMissing('totp_pending_enrollments', ['user_id' => $user->id]);
        $this->assertDatabaseCount('totp_credentials', 0);
    }

    public function test_social_login_never_auto_links_or_issues_a_session_without_reviewed_linking(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $user->forceFill(['provider' => 'google', 'provider_id' => 'old-unverified-link'])->save();
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('request');
        $this->app->instance(Client::class, $client);
        $this->get(route('login'))->assertOk()
            ->assertSee(__('public_pages.social_unavailable_until_link_verified'))
            ->assertDontSee(route('social.redirect', ['provider' => 'google']));
        $this->from('/login')->get(route('social.redirect', ['provider' => 'google']))
            ->assertRedirect('/login')->assertSessionHasErrors('provider');
        $this->from('/login')->get(route('social.callback', ['provider' => 'google', 'state' => 'synthetic', 'code' => 'synthetic']))
            ->assertRedirect('/login')->assertSessionHasErrors('provider');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('old-unverified-link', $user->fresh()->provider_id);
    }

    public function test_unrouted_legacy_registration_is_closed_even_if_called_directly(): void
    {
        try {
            app(LegacyAuthController::class)->register(Request::create('/legacy', 'POST'));
            $this->fail('Legacy registration must not create an unguarded user.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('users', 0);
    }
}

/** Exercise real CSRF verification despite Laravel's PHPUnit bypass. */
final class StrictCsrfForStatefulTest extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
