<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Exceptions\KycProviderException;
use App\Services\Kyc\KycProviderInterface;
use App\Services\Kyc\KycProviderManager;
use App\Services\Kyc\ManualReviewKycProvider;
use Tests\TestCase;

/**
 * KYC provider resolution: resolve, or refuse. Never degrade.
 *
 * ============================================================================
 * THE FAILURE THIS SUITE PREVENTS
 * ============================================================================
 * Three wrong configurations all end in the same place if the resolver is
 * permissive:
 *
 *   - ACCOUNT_KYC_PROVIDER is unset;
 *   - it names a driver that does not exist;
 *   - it names a driver whose credentials are missing.
 *
 * A resolver that "falls back to manual review" turns each of those into a
 * running platform where an operator believes automated e-KYC is verifying
 * documents, while a human queue quietly absorbs the intake — or worse, while
 * nothing absorbs it. The difference between the two states is invisible from
 * the dashboard, which is exactly why the code has to be the thing that refuses.
 *
 * So: `provider()` throws, `providerOrNull()` is the only door that may answer
 * null, and the null answer is reserved for surfaces that must render a sentence
 * ("we cannot verify right now") rather than start a verification.
 */
final class KycProviderManagerTest extends TestCase
{
    private function manager(): KycProviderManager
    {
        return $this->app->make(KycProviderManager::class);
    }

    public function test_the_default_configuration_resolves_to_the_manual_review_queue(): void
    {
        config([
            'account.verification.provider' => 'manual_review',
            'account.verification.providers' => [
                'manual_review' => ManualReviewKycProvider::class,
            ],
        ]);

        $provider = $this->manager()->provider();

        $this->assertInstanceOf(KycProviderInterface::class, $provider);
        $this->assertSame(ManualReviewKycProvider::DRIVER, $provider->name());
        $this->assertTrue($provider->isConfigured(), 'a human review queue needs no credentials');
        $this->assertTrue($this->manager()->isManualReview());
    }

    public function test_an_unset_provider_is_an_error_and_not_an_invitation_to_guess(): void
    {
        config(['account.verification.provider' => '']);

        $this->expectException(KycProviderException::class);

        $this->manager()->provider();
    }

    public function test_a_whitespace_only_provider_name_is_treated_as_unset(): void
    {
        config(['account.verification.provider' => "   \t "]);

        $this->assertSame('', $this->manager()->configuredName());

        $this->expectException(KycProviderException::class);
        $this->manager()->provider();
    }

    public function test_an_unknown_driver_name_is_refused(): void
    {
        config([
            'account.verification.provider' => 'vendor_we_never_wired',
            'account.verification.providers' => [
                'manual_review' => ManualReviewKycProvider::class,
            ],
        ]);

        $this->expectException(KycProviderException::class);

        $this->manager()->provider();
    }

    public function test_a_class_that_does_not_implement_the_interface_is_refused(): void
    {
        config([
            'account.verification.provider' => 'impostor',
            'account.verification.providers' => [
                'impostor' => \stdClass::class,
            ],
        ]);

        $this->expectException(KycProviderException::class);

        $this->manager()->provider();
    }

    public function test_provider_or_null_is_the_only_call_that_may_answer_null(): void
    {
        config(['account.verification.provider' => 'not_configured_at_all']);

        $this->assertNull($this->manager()->providerOrNull());
        $this->assertFalse($this->manager()->isManualReview(), 'an unresolvable provider is not manual review');

        // And the throwing door still throws in the same state.
        $this->expectException(KycProviderException::class);
        $this->manager()->provider();
    }

    public function test_every_declared_driver_is_listed_and_resolvable(): void
    {
        $manager = $this->manager();
        $declared = $manager->declaredDrivers();

        $this->assertNotSame([], $declared, 'config must declare at least the manual review driver');
        $this->assertContains(ManualReviewKycProvider::DRIVER, $declared);

        foreach ($declared as $driver) {
            $provider = $manager->provider($driver);

            $this->assertSame($driver, $provider->name(), 'the resolved provider must answer to its own name');
        }
    }

    public function test_the_container_binding_resolves_through_the_throwing_door(): void
    {
        // The interface binding is what a submission controller type-hints. It must
        // resolve through provider(), so an unconfigured lane raises at the moment
        // of use rather than handing the controller a null it might store against.
        config([
            'account.verification.provider' => 'manual_review',
            'account.verification.providers' => ['manual_review' => ManualReviewKycProvider::class],
        ]);

        $resolved = $this->app->make(KycProviderInterface::class);

        $this->assertInstanceOf(ManualReviewKycProvider::class, $resolved);

        // And with the lane unconfigured, the same resolution fails closed.
        config(['account.verification.provider' => '']);

        $this->expectException(KycProviderException::class);
        $this->app->make(KycProviderInterface::class);
    }
}
