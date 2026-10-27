<?php

declare(strict_types=1);

namespace Tests\Feature\Hardening;

use App\Providers\ProductionSafetyServiceProvider;
use Tests\TestCase;

/**
 * The request-time half of the production safety guard.
 *
 * WHY A BOOT CHECK IS NOT ENOUGH
 * ---------------------------------------------------------------------------
 * `ProductionSafetyServiceProvider::boot()` refuses to start a production
 * process on an unsafe configuration. That is one moment. A configuration can
 * change afterwards — a `config:cache` written by a deploy script, a queue
 * worker started before the change, an ops console running `config()` in a
 * tinker session — and a process that has already booted keeps its own opinion.
 *
 * `surfaceViolations()` is the list that is re-asserted on RouteMatched for every
 * HTTP request. These tests pin each lane it protects, and they pin the property
 * that matters most: it may only ADD refusals to the boot list.
 */
final class RequestTimeSafetyGuardTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function safeProduction(): array
    {
        return [
            'app.debug' => false,
            'session.secure' => true,
            'session.encrypt' => true,
            'session.driver' => 'redis',
            'cache.default' => 'redis',
            'queue.default' => 'redis',
            'sanctum.expiration' => 1440,
            'national_lottery.sources.fixture.enabled' => false,
            'weekly_lottery.sources.fixture.enabled' => false,
            'bingo_lottery.sources.fixture.enabled' => false,
            'pcso_lottery.sources.fixture.enabled' => false,
            'finance.prize_payout.safety_mode' => 'DISABLED',
            'lottery.payouts.auto_process' => false,
            'glo.official_source.mode' => 'official',
            'glo.sources.fall_through_to_fixture' => false,
            // The lanes the request-time list adds.
            'antimalware.driver' => 'clamav',
            'aml.mode' => 'enforce',
            'auth_security.captcha.enabled' => true,
            'auth_security.captcha.driver' => 'turnstile',
            'services.captcha.turnstile.secret' => 'configured-for-this-test',
            'account.verification.provider' => 'manual_review',
        ];
    }

    public function test_a_safe_production_request_reports_no_surface_violation(): void
    {
        config($this->safeProduction());

        $this->assertSame([], ProductionSafetyServiceProvider::surfaceViolations());
        $this->assertSame([], ProductionSafetyServiceProvider::requestViolations());
    }

    public function test_the_request_list_is_a_superset_of_the_boot_list(): void
    {
        config($this->safeProduction());
        config(['app.debug' => true]);

        $boot = ProductionSafetyServiceProvider::violations();
        $request = ProductionSafetyServiceProvider::requestViolations();

        $this->assertNotSame([], $boot);

        foreach ($boot as $violation) {
            $this->assertContains($violation, $request, 'a boot violation must never vanish at request time');
        }
    }

    public function test_the_null_antivirus_driver_is_refused(): void
    {
        config($this->safeProduction());
        config(['antimalware.driver' => 'null']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('ANTIMALWARE_DRIVER', $violations);
    }

    public function test_a_self_answering_captcha_is_refused(): void
    {
        config($this->safeProduction());
        config(['auth_security.captcha.driver' => 'local']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('AUTH_CAPTCHA_DRIVER', $violations);
    }

    public function test_a_fixed_captcha_is_refused(): void
    {
        config($this->safeProduction());
        config(['auth_security.captcha.driver' => 'fixed']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('AUTH_CAPTCHA_DRIVER', $violations);
    }

    public function test_a_real_captcha_with_no_secret_is_refused(): void
    {
        config($this->safeProduction());
        config(['services.captcha.turnstile.secret' => '']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('Siteverify secret is empty', $violations);
    }

    public function test_aml_screening_cannot_be_switched_off(): void
    {
        config($this->safeProduction());
        config(['aml.mode' => 'off']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('AML_MODE', $violations);
    }

    public function test_an_unset_kyc_provider_is_refused(): void
    {
        config($this->safeProduction());
        config(['account.verification.provider' => null]);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('ACCOUNT_KYC_PROVIDER', $violations);
    }

    public function test_an_undeclared_kyc_provider_is_refused(): void
    {
        config($this->safeProduction());
        config(['account.verification.provider' => 'not-a-declared-provider']);

        $violations = implode("\n", ProductionSafetyServiceProvider::surfaceViolations());

        $this->assertStringContainsString('not a declared provider', $violations);
    }

    public function test_assert_request_safe_throws_when_a_lane_is_unsafe(): void
    {
        config($this->safeProduction());
        config(['antimalware.driver' => 'null']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unsafe production configuration');

        ProductionSafetyServiceProvider::assertRequestSafe();
    }
}
