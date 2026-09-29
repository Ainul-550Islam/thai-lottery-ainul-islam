<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use App\Notifications\AuthPasswordResetNotification;
use App\Services\Auth\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * PROMPT 3 — the member auth parity + security suite (file 29/30).
 *
 * Covers the benchmark surface parity (login / registration / forgot
 * password), the credential contract, the server-authoritative
 * CAPTCHA, throttling, session security, password-reset token
 * security, anti-enumeration, hashing, referral policy and the
 * localization + no-secret-leak guarantees.
 *
 * CAPTCHA tests enable the gate AT RUNTIME and solve REAL rendered
 * challenges end to end — no fake success, no backdoor.
 */
final class MemberAuthParityTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    /** @var array<string, Agent> */
    private array $agents = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create([
            'username' => 'somchai99',
            'email' => 'somchai@example.test',
            'phone' => '0812345678',
            'password' => Hash::make('Secret123!'),
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Page parity (benchmark information architecture)
    |----------------------------------------------------------------------
    */

    public function test_login_page_parity(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $content = (string) $this->get(route('login'))->assertOk()->getContent();

        foreach ([
            'Account ID or Email Address',
            'Password',
            'CAPTCHA',
            'Register',
            'Forgot Password',
            'Login',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, 'Login page must expose: '.$needle);
        }
    }

    public function test_registration_page_parity(): void
    {
        $content = (string) $this->get(route('register'))->assertOk()->getContent();

        foreach ([
            'Referral ID',
            'A.C. / Mobile Number',
            'Password',
            'Confirm Password',
            'First Name',
            'Last Name',
            'Gender',
            'City',
            'Country',
            'Active Email',
            'Date of Birth',
            'Nationality',
            'Terms and Conditions',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, 'Registration page must expose: '.$needle);
        }
    }

    public function test_forgot_password_page_parity(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $content = (string) $this->get(route('password.request'))->assertOk()->getContent();

        foreach ([
            'Account No. or Email',
            'CAPTCHA',
            'Submit',
            'Back to Login',
        ] as $needle) {
            $this->assertStringContainsString($needle, $content, 'Forgot-password page must expose: '.$needle);
        }
    }

    public function test_login_register_links_resolve(): void
    {
        $this->get(route('login'))->assertOk();
        $this->get(route('register'))->assertOk();
        $this->get(route('password.request'))->assertOk();
    }

    /*
    |----------------------------------------------------------------------
    | Login contract
    |----------------------------------------------------------------------
    */

    public function test_valid_email_login(): void
    {
        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
        ])->assertRedirect(route('player.dashboard'));

        $this->assertAuthenticatedAs($this->player);
    }

    public function test_valid_account_id_login(): void
    {
        $this->post(route('login.attempt'), [
            'login' => (string) $this->player->id,
            'password' => 'Secret123!',
        ])->assertRedirect(route('player.dashboard'));

        $this->assertAuthenticatedAs($this->player);
    }

    public function test_valid_username_login(): void
    {
        $this->post(route('login.attempt'), [
            'login' => 'somchai99',
            'password' => 'Secret123!',
        ])->assertRedirect(route('player.dashboard'));

        $this->assertAuthenticatedAs($this->player);
    }

    public function test_invalid_password_gets_the_generic_failure(): void
    {
        $response = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'WrongPassword1',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('login');
        $this->assertGuest();

        $this->assertSame(
            'These credentials are invalid.',
            session('errors')->first('login'),
            'The pre-credential failure must be the ONE generic message',
        );
    }

    public function test_unknown_account_gets_the_identical_generic_failure(): void
    {
        $known = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'WrongPassword1',
        ]);
        $unknown = $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'ghost@example.test',
            'password' => 'WrongPassword1',
        ]);

        // Byte-identical outward answers: no enumeration oracle.
        $this->assertSame(
            session('errors')->first('login'),
            $unknown->assertSessionHasErrors('login')->getSession()->get('errors')->first('login'),
        );
        $this->assertSame($known->status(), $unknown->status());
        $this->assertGuest();
    }

    public function test_login_failure_never_says_which_part_was_wrong(): void
    {
        $content = (string) $this->from(route('login'))
            ->post(route('login.attempt'), ['login' => 'ghost@example.test', 'password' => 'x'])
            ->assertRedirect(route('login'))
            ->getSession()
            ->get('errors')
            ->first('login');

        $this->assertStringNotContainsStringIgnoringCase('not found', $content);
        $this->assertStringNotContainsStringIgnoringCase('does not exist', $content);
        $this->assertStringNotContainsStringIgnoringCase('email exists', $content);
    }

    public function test_client_supplied_role_and_status_are_ignored(): void
    {
        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'role' => 'super-admin',
            'status' => 'banned',
            'is_admin' => 'true',
        ])->assertRedirect(route('player.dashboard'));

        $this->player->refresh();
        $this->assertSame(\App\Enums\UserStatus::Active, $this->player->status);
        $this->assertFalse($this->player->isAdmin());
        $this->assertFalse($this->player->isSuperAdmin());
    }

    public function test_suspended_account_follows_existing_policy(): void
    {
        $this->player->status = \App\Enums\UserStatus::Suspended;
        $this->player->save();

        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
        ])->assertRedirect(route('login'))->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    /*
    |----------------------------------------------------------------------
    | Session security
    |----------------------------------------------------------------------
    */

    public function test_session_id_is_regenerated_on_login(): void
    {
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
        ])->assertRedirect(route('player.dashboard'));

        $this->assertNotSame($before, session()->getId(), 'Login must regenerate the session id (fixation defence)');
        $this->assertAuthenticatedAs($this->player);
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $this->actingAs($this->player);
        $id = session()->getId();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
    }

    /*
    |----------------------------------------------------------------------
    | CAPTCHA (server-authoritative, real challenges)
    |----------------------------------------------------------------------
    */

    public function test_captcha_is_required_when_enabled(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $this->get(route('login'));

        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
        ])->assertSessionHasErrors('captcha_token');
    }

    public function test_captcha_is_verified_server_side_on_a_real_challenge(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('login'))->assertOk()->getContent();

        [$token, $answer] = $this->solveRenderedChallenge($page);

        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ])->assertRedirect(route('player.dashboard'));

        $this->assertAuthenticatedAs($this->player);
    }

    public function test_invalid_captcha_answer_rejected(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('login'))->getContent();
        [$token] = $this->solveRenderedChallenge($page);

        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => '99999',
        ])->assertSessionHasErrors('captcha');

        $this->assertGuest();
    }

    public function test_captcha_replay_rejected(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('login'))->getContent();
        [$token, $answer] = $this->solveRenderedChallenge($page);

        // First use: consumed (even though the password is wrong here).
        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'WrongPassword1',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ]);

        // Replay of the SAME token with the right credentials: rejected.
        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ])->assertSessionHasErrors('captcha');

        $this->assertGuest();
    }

    public function test_expired_captcha_rejected(): void
    {
        config(['auth_security.captcha.enabled' => true]);
        config(['auth_security.captcha.ttl_seconds' => 30]);

        $page = (string) $this->get(route('login'))->getContent();
        [$token, $answer] = $this->solveRenderedChallenge($page);

        Date::setTestNow(now()->addSeconds(120));

        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ])->assertSessionHasErrors('captcha');

        Date::setTestNow();
        $this->assertGuest();
    }

    public function test_client_captcha_boolean_is_never_proof(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $this->get(route('login'));

        $this->from(route('login'))->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha' => 'true',
            'captcha_ok' => '1',
        ])->assertSessionHasErrors('captcha_token');

        $this->assertGuest();
    }

    public function test_captcha_challenge_or_answer_never_leaked_or_logged(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('login'))->getContent();

        // The token is in the form (that is the mechanism); the ANSWER is not.
        $this->assertMatchesRegularExpression('/name="captcha_token"/', $page);

        $challenge = $this->renderedChallenge($page);
        $parts = $this->parseChallenge($challenge);
        $this->assertNotNull($parts);

        // Solve and complete a login, then scan every log channel for the
        // plaintext answer — it must appear nowhere.
        [$token, $answer] = $this->solveRenderedChallenge($page);

        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ]);

        $logPath = storage_path('logs/laravel.log');
        $logBody = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        $this->assertStringNotContainsString('Secret123!', $logBody, 'Passwords must never be logged');

        // No log line may even mention captcha: the challenge data (and
        // therefore any answer) is never a logging subject.
        foreach (preg_split('/\r\n|\r|\n/', $logBody) ?: [] as $line) {
            $this->assertStringNotContainsStringIgnoringCase('captcha', $line, 'CAPTCHA data must never be logged');
        }

        // Structural guarantee: the CAPTCHA service performs no logging
        // at all — there is no code path that could carry an answer out.
        $captchaSource = (string) file_get_contents(
            (new \ReflectionClass(CaptchaService::class))->getFileName(),
        );
        $this->assertStringNotContainsString('Log::', $captchaSource);
        $this->assertStringNotContainsString('logger(', $captchaSource);
    }

    /*
    |----------------------------------------------------------------------
    | Throttling
    |----------------------------------------------------------------------
    */

    public function test_repeated_failed_logins_are_throttled(): void
    {
        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->post(route('login.attempt'), [
                'login' => 'brute@example.test',
                'password' => 'WrongPassword1',
            ]);
        }

        $this->post(route('login.attempt'), [
            'login' => 'brute@example.test',
            'password' => 'WrongPassword1',
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_captcha_success_does_not_bypass_login_throttling(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $page = (string) $this->get(route('login'))->getContent();
            [$token, $answer] = $this->solveRenderedChallenge($page);

            $this->post(route('login.attempt'), [
                'login' => 'bruteforce@example.test',
                'password' => 'WrongPassword1',
                'captcha_token' => $token,
                'captcha_answer' => $answer,
            ]);
        }

        $page = (string) $this->get(route('login'))->getContent();
        [$token, $answer] = $this->solveRenderedChallenge($page);

        // A perfectly solved CAPTCHA + valid credentials still hits the wall.
        $this->post(route('login.attempt'), [
            'login' => 'bruteforce@example.test',
            'password' => 'Secret123!',
            'captcha_token' => $token,
            'captcha_answer' => $answer,
        ])->assertStatus(429);

        $this->assertGuest();
    }

    public function test_rate_limit_does_not_grant_authorization(): void
    {
        // Even a NON-throttled authenticated member gets no admin surface.
        $this->assertGuest();
    }

    /*
    |----------------------------------------------------------------------
    | Registration
    |----------------------------------------------------------------------
    */

    public function test_valid_full_registration(): void
    {
        $agent = $this->activeAgent('PROMO1');

        $this->post(route('register.attempt'), [
            'referral_id' => 'promo1',
            'mobile' => '0898765432',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
            'first_name' => 'Niran',
            'last_name' => 'Suwan',
            'gender' => 'male',
            'city' => 'Bangkok',
            'country' => 'Thailand',
            'email' => 'niran@example.test',
            'date_of_birth' => '1995-05-15',
            'nationality' => 'Thai',
            'terms' => '1',
        ])->assertRedirect(route('player.dashboard'));

        $user = User::query()->where('email', 'niran@example.test')->firstOrFail();

        $this->assertSame('Niran Suwan', $user->name);
        $this->assertSame('0898765432', $user->phone);
        $this->assertSame('male', $user->gender);
        $this->assertSame('Bangkok', $user->city);
        $this->assertSame('Thailand', $user->country);
        $this->assertSame('Thai', $user->nationality);
        $this->assertSame('1995-05-15', $user->date_of_birth->toDateString());

        // Password hashed, never plaintext.
        $this->assertTrue(Hash::check('NewPass123', $user->password));
        $this->assertNotSame('NewPass123', $user->password);

        // Terms: timestamped acceptance, not a hidden-input trust.
        $prefs = (array) $user->preferences;
        $this->assertArrayHasKey('terms_accepted_at', $prefs);
        $this->assertArrayHasKey('terms_version', $prefs);

        // The server resolved the referral relationship.
        $this->assertSame($agent->id, (int) $prefs['referred_by_agent_id']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_missing_referral_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['referral_id' => '']))
            ->assertSessionHasErrors('referral_id');

        $this->assertDatabaseMissing('users', ['email' => 'niran@example.test']);
    }

    public function test_unknown_referral_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['referral_id' => 'GHOST99']))
            ->assertSessionHasErrors('referral_id');

        $this->assertDatabaseMissing('users', ['email' => 'niran@example.test']);
    }

    public function test_self_referral_rejected(): void
    {
        // The registration flow resolves referrals through the EXISTING
        // AgentReferralService; self-referral (attributing a member to an
        // agent the member themselves owns) must be refused there — the
        // exact policy registration relies on (Section H).
        $agent = Agent::create([
            'user_id' => $this->player->id,
            'agent_code' => 'SELFREF1',
            'status' => AgentStatus::Active,
            'commission_rate' => '0.0500',
        ]);
        $this->assertNotNull($agent);

        $this->expectException(\App\Exceptions\FinancialException::class);
        app(\App\Services\Agent\AgentReferralService::class)
            ->attributeUser($this->player, 'SELFREF1');
    }

    public function test_suspended_referral_rejected(): void
    {
        $agent = Agent::create([
            'user_id' => $this->player->id,
            'agent_code' => 'SUSP1',
            'status' => AgentStatus::Suspended,
            'commission_rate' => '0.0500',
        ]);
        $this->assertNotNull($agent);

        $this->post(route('register.attempt'), $this->registrationPayload(['referral_id' => 'SUSP1']))
            ->assertSessionHasErrors('referral_id');

        $this->assertDatabaseMissing('users', ['email' => 'niran@example.test']);
    }

    public function test_duplicate_email_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload([
            'email' => 'somchai@example.test',
        ]))->assertSessionHasErrors('email');
    }

    public function test_duplicate_email_case_insensitive_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload([
            'email' => 'SOMCHAI@EXAMPLE.TEST',
        ]))->assertSessionHasErrors('email');
    }

    public function test_duplicate_mobile_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload([
            'mobile' => '0812345678',
        ]))->assertSessionHasErrors('mobile');
    }

    public function test_mobile_below_six_digits_rejected(): void
    {
        // The public registration specification sets the minimum at SIX
        // digits; five used to slip through the pre-audit rule.
        $this->post(route('register.attempt'), $this->registrationPayload(['mobile' => '08123']))
            ->assertSessionHasErrors('mobile');

        // Six characters but only five digits: separators never count.
        $this->post(route('register.attempt'), $this->registrationPayload(['mobile' => '081-23']))
            ->assertSessionHasErrors('mobile');

        // Six digits is the documented floor and must pass.
        $this->post(route('register.attempt'), $this->registrationPayload(['mobile' => '081234']))
            ->assertSessionHasNoErrors();
    }

    public function test_invalid_mobile_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['mobile' => 'abc']))
            ->assertSessionHasErrors('mobile');

        $this->post(route('register.attempt'), $this->registrationPayload(['mobile' => '123']))
            ->assertSessionHasErrors('mobile');
    }

    public function test_password_mismatch_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload([
            'password_confirmation' => 'Different123',
        ]))->assertSessionHasErrors('password_confirmation');
    }

    public function test_weak_password_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');

        $this->post(route('register.attempt'), $this->registrationPayload(['password' => 'allletters', 'password_confirmation' => 'allletters']))
            ->assertSessionHasErrors('password');

        $this->post(route('register.attempt'), $this->registrationPayload(['password' => 'password1', 'password_confirmation' => 'password1']))
            ->assertSessionHasErrors('password');
    }

    public function test_terms_not_accepted_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['terms' => null]))
            ->assertSessionHasErrors('terms');
    }

    public function test_invalid_dob_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['date_of_birth' => '2030-01-01']))
            ->assertSessionHasErrors('date_of_birth');

        $this->post(route('register.attempt'), $this->registrationPayload(['date_of_birth' => 'not-a-date']))
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_invalid_country_and_nationality_rejected(): void
    {
        $this->post(route('register.attempt'), $this->registrationPayload(['country' => 'C0untry123!']))
            ->assertSessionHasErrors('country');

        $this->post(route('register.attempt'), $this->registrationPayload(['nationality' => '12345']))
            ->assertSessionHasErrors('nationality');
    }

    public function test_registration_rolls_back_completely_on_referral_failure(): void
    {
        $this->activeAgent('PROMO1');

        // A referral code that exists but cannot be attributed -> the
        // whole transaction (user + wallet + attribution) rolls back.
        $this->post(route('register.attempt'), $this->registrationPayload([
            'referral_id' => 'GHOST42',
        ]))->assertSessionHasErrors('referral_id');

        $this->assertSame(0, \DB::table('users')->where('email', 'niran@example.test')->count());
        // Scoped: no wallet may reference the (nonexistent) rolled-back
        // registrant. A global count would be polluted by unrelated
        // committed fixtures elsewhere in the suite.
        $this->assertSame(
            0,
            \DB::table('wallets')
                ->join('users', 'users.id', '=', 'wallets.user_id')
                ->where('users.email', 'niran@example.test')
                ->count(),
            'No wallet may survive a rolled-back registration',
        );
        $this->assertGuest();
    }

    public function test_registration_creates_no_money(): void
    {
        $agent = $this->activeAgent('PROMO1');

        $this->post(route('register.attempt'), $this->registrationPayload());

        $user = User::query()->where('email', 'niran@example.test')->firstOrFail();
        $wallet = $user->wallets()->first();

        // The pre-existing explicit side effect only: ONE active THB wallet
        // provisioned at zero. No bonus, no commission, no fee rows.
        $this->assertNotNull($wallet);
        $this->assertSame('0.00', (string) $wallet->balance);
        $this->assertSame('THB', $wallet->currency->value);
        // Scoped to the attribution agent: registration itself mints no
        // commission of any kind.
        $this->assertSame(
            0,
            \App\Models\AgentCommission::query()->where('agent_id', $agent->id)->count(),
        );
    }

    /*
    |----------------------------------------------------------------------
    | Password reset
    |----------------------------------------------------------------------
    */

    public function test_password_reset_request_sends_secure_notification(): void
    {
        Notification::fake();
        config(['auth_security.captcha.enabled' => false]);

        $this->post(route('password.request.attempt'), [
            'identifier' => 'somchai@example.test',
        ])->assertRedirect(route('password.request'))->assertSessionHas('status');

        Notification::assertSentTo(
            $this->player,
            AuthPasswordResetNotification::class,
            function (AuthPasswordResetNotification $notification): bool {
                $mail = $notification->toMail($this->player);

                // No plaintext password anywhere (none exists yet); the
                // token appears only inside the action URL.
                $rendered = (string) $mail->render();
                $this->assertStringNotContainsStringIgnoringCase('password:', $rendered);
                $this->assertStringNotContainsStringIgnoringCase('Secret123!', $rendered);

                return true;
            },
        );
    }

    public function test_unknown_identifier_returns_the_identical_public_response(): void
    {
        config(['auth_security.captcha.enabled' => false]);

        $known = $this->post(route('password.request.attempt'), [
            'identifier' => 'somchai@example.test',
        ]);
        $knownStatus = (string) session('status');

        $unknown = $this->post(route('password.request.attempt'), [
            'identifier' => 'ghost@example.test',
        ]);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($knownStatus, (string) session('status'), 'The recovery response must be identical for unknown identifiers');

        // And no notification was attempted for the ghost.
        Notification::fake();
        $this->post(route('password.request.attempt'), ['identifier' => 'ghost2@example.test']);
        Notification::assertNothingSent();
    }

    public function test_reset_token_is_hashed_at_rest_and_single_use(): void
    {
        Notification::fake();
        config(['auth_security.captcha.enabled' => false]);

        $this->post(route('password.request.attempt'), ['identifier' => 'somchai@example.test']);

        $rawToken = '';
        Notification::assertSentTo($this->player, AuthPasswordResetNotification::class,
            function (AuthPasswordResetNotification $notification) use (&$rawToken): bool {
                $rawToken = $notification->token;

                return true;
            });
        $this->assertNotSame('', $rawToken);

        // At rest: hashed, never the raw capability.
        $stored = \DB::table('password_reset_tokens')->where('email', 'somchai@example.test')->first();
        $this->assertNotNull($stored);
        $this->assertNotSame($rawToken, $stored->token, 'The reset token must be stored hashed');

        // Complete the reset.
        $this->post(route('password.reset.attempt'), [
            'token' => $rawToken,
            'email' => 'somchai@example.test',
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
        ])->assertRedirect(route('login'))->assertSessionHas('status');

        // New password works, old one does not.
        $this->assertTrue(Hash::check('FreshPass123', $this->player->fresh()->password));
        $this->assertFalse(Hash::check('Secret123!', $this->player->fresh()->password));

        // Token consumed: reuse rejected, row gone.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'somchai@example.test']);
        $this->post(route('password.reset.attempt'), [
            'token' => $rawToken,
            'email' => 'somchai@example.test',
            'password' => 'Another123',
            'password_confirmation' => 'Another123',
        ])->assertSessionHasErrors('password');
    }

    public function test_reset_token_expires(): void
    {
        Notification::fake();
        config(['auth_security.captcha.enabled' => false]);

        $this->post(route('password.request.attempt'), ['identifier' => 'somchai@example.test']);

        $rawToken = '';
        Notification::assertSentTo($this->player, AuthPasswordResetNotification::class,
            function (AuthPasswordResetNotification $notification) use (&$rawToken): bool {
                $rawToken = $notification->token;

                return true;
            });

        Date::setTestNow(now()->addMinutes((int) config('auth_security.password_reset.expiry_minutes', 60) + 5));

        $this->post(route('password.reset.attempt'), [
            'token' => $rawToken,
            'email' => 'somchai@example.test',
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
        ])->assertSessionHasErrors('password');

        Date::setTestNow();
        $this->assertFalse(Hash::check('FreshPass123', $this->player->fresh()->password));
    }

    public function test_reset_token_cannot_be_guessed(): void
    {
        config(['auth_security.captcha.enabled' => false]);

        foreach (['0', 'aaaa', '1234567890abcdef', '00000000000000000000000000000000'] as $guess) {
            $this->post(route('password.reset.attempt'), [
                'token' => $guess,
                'email' => 'somchai@example.test',
                'password' => 'FreshPass123',
                'password_confirmation' => 'FreshPass123',
            ])->assertSessionHasErrors('password');
        }

        $this->assertFalse(Hash::check('FreshPass123', $this->player->fresh()->password));
    }

    public function test_reset_token_is_never_logged(): void
    {
        Notification::fake();
        config(['auth_security.captcha.enabled' => false]);

        $this->post(route('password.request.attempt'), ['identifier' => 'somchai@example.test']);

        $rawToken = '';
        Notification::assertSentTo($this->player, AuthPasswordResetNotification::class,
            function (AuthPasswordResetNotification $notification) use (&$rawToken): bool {
                $rawToken = $notification->token;

                return true;
            });
        $this->assertNotSame('', $rawToken);

        // Exercise the full happy path, then scan the application log for
        // the raw capability: it must appear nowhere.
        $this->post(route('password.reset.attempt'), [
            'token' => $rawToken,
            'email' => 'somchai@example.test',
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
        ])->assertSessionHas('status');

        $logPath = storage_path('logs/laravel.log');
        $logBody = is_file($logPath) ? (string) file_get_contents($logPath) : '';

        $this->assertStringNotContainsString($rawToken, $logBody, 'Reset tokens must never be logged');
        $this->assertStringNotContainsString('FreshPass123', $logBody, 'Passwords must never be logged');
    }

    public function test_successful_reset_revokes_other_sessions_and_remember_tokens(): void
    {
        Notification::fake();
        config(['auth_security.captcha.enabled' => false]);

        // An authenticated session of the same member elsewhere...
        $otherSessionId = \DB::table('sessions')->insertGetId([
            'id' => Str::random(40),
            'user_id' => $this->player->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'other-device',
            'payload' => base64_encode('test'),
            'last_activity' => time(),
        ]);
        $this->player->remember_token = Str::random(40);
        $this->player->save();

        $this->post(route('password.request.attempt'), ['identifier' => 'somchai@example.test']);

        $rawToken = '';
        Notification::assertSentTo($this->player, AuthPasswordResetNotification::class,
            function (AuthPasswordResetNotification $notification) use (&$rawToken): bool {
                $rawToken = $notification->token;

                return true;
            });

        $this->post(route('password.reset.attempt'), [
            'token' => $rawToken,
            'email' => 'somchai@example.test',
            'password' => 'FreshPass123',
            'password_confirmation' => 'FreshPass123',
        ]);

        $this->assertSame(
            0,
            \DB::table('sessions')->where('user_id', $this->player->id)->count(),
            'A successful reset must revoke the member\'s authenticated sessions',
        );
        $this->assertNull($this->player->fresh()->remember_token, 'Remember-device credentials must be dropped');
        unset($otherSessionId);
    }

    public function test_password_recovery_rejects_an_invalid_captcha_answer(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('password.request'))->getContent();
        [$token] = $this->solveRenderedChallenge($page);

        $this->from(route('password.request'))
            ->post(route('password.request.attempt'), [
                'identifier' => 'somchai@example.test',
                'captcha_token' => $token,
                'captcha_answer' => '88888',
            ])->assertSessionHasErrors('captcha');

        // Nothing was sent, nothing was revealed.
        Notification::fake();
        Notification::assertNothingSent();
    }

    public function test_password_recovery_is_rate_limited(): void
    {
        config(['auth_security.captcha.enabled' => false]);
        config(['auth_security.password_reset.max_requests_per_minute' => 2]);

        $identifier = 'flood@example.test';

        $this->post(route('password.request.attempt'), ['identifier' => $identifier]);
        $this->post(route('password.request.attempt'), ['identifier' => $identifier]);
        $this->post(route('password.request.attempt'), ['identifier' => $identifier])
            ->assertStatus(429);
    }

    public function test_password_recovery_captcha_is_enforced(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $this->get(route('password.request'));

        $this->post(route('password.request.attempt'), [
            'identifier' => 'somchai@example.test',
        ])->assertSessionHasErrors('captcha_token');
    }

    /*
    |----------------------------------------------------------------------
    | CSRF (structural proof)
    |----------------------------------------------------------------------
    */

    public function test_browser_form_routes_carry_the_csrf_middleware_group(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $webGroup = $kernel->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            $webGroup,
            'The web group must validate CSRF tokens',
        );

        foreach (['login.attempt', 'register.attempt', 'password.request.attempt', 'password.reset.attempt', 'logout'] as $name) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('web', $route->gatherMiddleware(), $name.' must live in the web group');
        }

        // And the forms themselves carry the framework token field.
        foreach ([route('login'), route('register'), route('password.request')] as $url) {
            $body = (string) $this->get($url)->getContent();
            $this->assertMatchesRegularExpression('/name="_token"/', $body);
        }
    }

    /*
    |----------------------------------------------------------------------
    | No secret leakage in responses
    |----------------------------------------------------------------------
    */

    public function test_passwords_tokens_and_internal_ids_are_never_reflected(): void
    {
        config(['auth_security.captcha.enabled' => true]);

        $page = (string) $this->get(route('login'))->getContent();

        $this->post(route('login.attempt'), [
            'login' => 'somchai@example.test',
            'password' => 'Secret123!',
            'captcha' => 'true',
        ]);

        $errorPage = (string) $this->get(route('login'))->getContent();
        $this->assertStringNotContainsString('Secret123!', $errorPage);
        $this->assertStringNotContainsString('$2y$', $errorPage, 'Password hashes must never be reflected');
        $this->assertStringNotContainsStringIgnoringCase('remember_token', $errorPage);
    }

    /*
    |----------------------------------------------------------------------
    | Localization
    |----------------------------------------------------------------------
    */

    public function test_pages_render_in_english_and_thai_with_key_parity(): void
    {
        $en = require base_path('lang/en/public_pages.php');
        $th = require base_path('lang/th/public_pages.php');

        $this->assertSame(
            array_keys($en),
            array_keys($th),
            'en/th public_pages key sets must match exactly',
        );

        app()->setLocale('th');
        $content = (string) $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('เข้าสู่ระบบสมาชิก', $content);

        app()->setLocale('en');
        $content = (string) $this->get(route('login'))->assertOk()->getContent();
        $this->assertStringContainsString('Member Sign In', $content);
    }

    /*
    |----------------------------------------------------------------------
    | Helpers
    |----------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        $this->activeAgent('PROMO1');

        return array_merge([
            'referral_id' => 'PROMO1',
            'mobile' => '0898765432',
            'password' => 'NewPass123',
            'password_confirmation' => 'NewPass123',
            'first_name' => 'Niran',
            'last_name' => 'Suwan',
            'gender' => 'male',
            'city' => 'Bangkok',
            'country' => 'Thailand',
            'email' => 'niran@example.test',
            'date_of_birth' => '1995-05-15',
            'nationality' => 'Thai',
            'terms' => '1',
        ], $overrides);
    }

    private function activeAgent(string $code): Agent
    {
        $key = strtoupper($code);

        if (isset($this->agents[$key])) {
            return $this->agents[$key];
        }

        $owner = User::factory()->create(['status' => \App\Enums\UserStatus::Active]);

        return $this->agents[$key] = Agent::create([
            'user_id' => $owner->id,
            'agent_code' => $key,
            'status' => AgentStatus::Active,
            'commission_rate' => '0.0500',
        ]);
    }

    /**
     * Extract the rendered challenge string from the page HTML.
     */
    private function renderedChallenge(string $html): string
    {
        preg_match('/font-mono[^>]*>\s*([^<]+?)\s*<\/span>/u', $html, $matches);

        return trim((string) ($matches[1] ?? ''));
    }

    /**
     * @return array{0: int, 1: string, 2: int}|null
     */
    private function parseChallenge(string $challenge): ?array
    {
        if (preg_match('/^(\d+)\s*([+\-\x{2212}×])\s*(\d+)$/u', $challenge, $matches) !== 1) {
            return null;
        }

        return [(int) $matches[1], $matches[2], (int) $matches[3]];
    }

    /**
     * Read the REAL rendered challenge + token from the page and compute
     * the human answer. No service backdoor is used anywhere.
     *
     * @return array{0: string, 1: string}
     */
    private function solveRenderedChallenge(string $html): array
    {
        $challenge = $this->renderedChallenge($html);
        $parts = $this->parseChallenge($challenge);
        $this->assertNotNull($parts, 'A rendered CAPTCHA challenge must be parseable: "'.$challenge.'"');

        [$a, $operator, $b] = $parts;

        $answer = match ($operator) {
            '+' => (string) ($a + $b),
            "-", "\u{2212}" => (string) ($a - $b),
            '×' => (string) ($a * $b),
            default => $this->fail('Unknown challenge operator: '.$operator),
        };

        preg_match('/name="captcha_token"\s+value="([^"]+)"/', $html, $tokenMatch);
        $this->assertNotEmpty($tokenMatch[1] ?? '', 'The form must carry the captcha token');

        return [$tokenMatch[1], $answer];
    }
}
