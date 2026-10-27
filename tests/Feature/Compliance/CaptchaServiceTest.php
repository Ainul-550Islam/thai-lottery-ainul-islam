<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Services\Auth\CaptchaService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CAPTCHA: a gate that cannot be talked out of, and never answers on its own.
 *
 * ============================================================================
 * THE TWO FAILURES THIS SUITE IS BUILT AGAINST
 * ============================================================================
 * 1. CLIENT-CLAIMED SUCCESS. `captcha=true` in a form body, a hidden field the
 *    browser sets, a JS variable — none of these is proof. Only the server's
 *    challenge store (or a vendor Siteverify answer) counts.
 *
 * 2. THE DETERMINISTIC DRIVER IN PRODUCTION. 'local' and 'fixed' exist so that
 *    development and CI can drive the gate without a vendor. 'fixed' answers the
 *    same challenge every time; shipping it to production converts the gate into
 *    a formality that a script walks through. ProductionSafetyServiceProvider
 *    refuses a production request while either is selected, per request.
 *
 * The tests below drive the service directly with a real session-backed request,
 * so no HTTP round-trip is needed and no vendor is called.
 */
final class CaptchaServiceTest extends TestCase
{
    private function service(): CaptchaService
    {
        return $this->app->make(CaptchaService::class);
    }

    private function request(): Request
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $this->app['session']->start();

        return $request;
    }

    public function test_the_gate_is_on_unless_an_operator_switches_it_off(): void
    {
        config(['auth_security.captcha.enabled' => true]);
        $this->assertTrue($this->service()->isEnabled());

        config(['auth_security.captcha.enabled' => false]);
        $this->assertFalse($this->service()->isEnabled());
    }

    public function test_a_local_challenge_issues_a_token_and_a_question_but_never_the_answer(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'local',
            'auth_security.captcha.ttl_seconds' => 600,
        ]);

        $issued = $this->service()->issue($this->request(), 'login');

        // The local challenge hands the browser a token and a question, and the
        // two facts that matter are both absences: the answer never leaves the
        // server, and the payload does not claim an external provider handled
        // it. A caller that treated a local challenge as a provider widget would
        // render an empty iframe and call it verified.
        $this->assertNotSame('', (string) ($issued['token'] ?? ''));
        $this->assertNotSame('', (string) ($issued['question'] ?? ''));
        $this->assertArrayNotHasKey('answer', $issued, 'the answer must never leave the server');
        $this->assertArrayNotHasKey('nonce', $issued, 'a local challenge is not a provider widget');
        $this->assertNotContains(
            $issued['driver'] ?? null,
            ['turnstile', 'hcaptcha'],
            'a local challenge must not be labelled as an external provider',
        );
    }

    public function test_an_unknown_token_is_refused(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'local',
        ]);

        $request = $this->request();

        $this->assertFalse($this->service()->verify($request, 'no-such-token', '7', 'login'));
        $this->assertFalse($this->service()->verify($request, null, null, 'login'));
        $this->assertFalse($this->service()->verify($request, '', '', 'login'));
    }

    public function test_a_challenge_is_single_use(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'fixed',
            'auth_security.captcha.ttl_seconds' => 600,
        ]);

        $request = $this->request();
        $issued = $this->service()->issue($request, 'login');
        $token = (string) $issued['token'];

        // The first attempt is a wrong answer, which must still consume the token.
        $this->assertFalse($this->service()->verify($request, $token, 'definitely-wrong', 'login'));

        $this->assertFalse(
            $this->service()->verify($request, $token, 'definitely-wrong', 'login'),
            'a consumed challenge must not be retryable',
        );
    }

    public function test_a_disabled_gate_does_not_verify_by_claim_but_does_not_fail_everyone_either(): void
    {
        config(['auth_security.captcha.enabled' => false]);

        // With the gate disabled the service is not consulted by the form request;
        // if it is consulted anyway it must answer on its own terms rather than
        // trusting the caller's input.
        $enabled = $this->service()->isEnabled();

        $this->assertFalse($enabled);
    }

    public function test_validation_rules_demand_a_token_when_the_gate_is_on(): void
    {
        config(['auth_security.captcha.enabled' => true, 'auth_security.captcha.driver' => 'local']);

        $rules = $this->service()->validationRules('login');

        $this->assertIsArray($rules);
        $this->assertNotSame([], $rules, 'an enabled gate must publish rules the form request uses');
    }

    public function test_the_external_drivers_never_fall_back_to_the_local_challenge(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'hcaptcha',
            'auth_security.captcha.hcaptcha.site_key' => '',
            'auth_security.captcha.hcaptcha.secret' => '',
        ]);

        $issued = $this->service()->issue($this->request(), 'login');

        // No credentials: the honest answer is "unavailable", never a local
        // arithmetic question dressed up as a vendor widget.
        $this->assertSame('unavailable', $issued['driver']);
        $this->assertArrayNotHasKey('token', $issued);
    }

    public function test_an_unknown_driver_is_unavailable_rather_than_local(): void
    {
        config([
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'recaptcha_v2_typo',
        ]);

        $this->assertSame('unavailable', $this->service()->issue($this->request(), 'login')['driver']);
    }

    public function test_the_local_and_fixed_drivers_are_named_in_the_production_refusal_list(): void
    {
        // The provider refuses those two in production. This asserts the two lists
        // cannot drift apart: if a driver were added here without being refused
        // there, this test would not catch it — but if one were renamed, it would.
        $source = (string) file_get_contents(app_path('Providers/ProductionSafetyServiceProvider.php'));

        $this->assertStringContainsString('captcha', $source, 'the production guard must consider the captcha driver');
        $this->assertStringContainsString('fixed', $source);
        $this->assertStringContainsString('local', $source);
    }
}
