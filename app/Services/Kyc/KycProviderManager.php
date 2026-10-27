<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Exceptions\KycProviderException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves the configured KYC provider, or refuses.
 *
 * THE FAIL-CLOSED RULE, STATED ONCE.
 *
 * config('account.verification.provider') is an env-derived string
 * (`ACCOUNT_KYC_PROVIDER`). Three things can be wrong with it:
 *
 *   1. it is empty/unset            → no provider chosen
 *   2. it names a driver we lack    → a typo, or a vendor we never wired
 *   3. it names a driver whose own `isConfigured()` is false → missing credentials
 *
 * All three raise {@see KycProviderException}. None of them falls back to "the
 * permissive one". A KYC lane that silently degrades to manual review when an
 * e-KYC vendor's credentials expire is how an operator ends up believing
 * automated verification is running when it is not — so an unset or broken
 * provider is an error the caller must surface, and the *default* value of the
 * config key (not a fallback at runtime) is what decides that manual review is
 * normal.
 *
 * WHY THE MANAGER HOLDS THE MAPPING AND NOT THE CONTAINER.
 * The mapping lives in config/account.php so an operator can see which driver
 * names exist, and this class only instantiates what config declares. A class
 * named from configuration is resolved through the container so a driver can
 * declare its own dependencies, but it must implement the interface — otherwise
 * it is refused exactly like an unknown name.
 */
final class KycProviderManager
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    /**
     * The configured driver name, or '' when none is set.
     */
    public function configuredName(): string
    {
        $name = $this->config->get('account.verification.provider');

        return is_string($name) ? trim($name) : '';
    }

    /**
     * Resolve a provider by name.
     *
     * @throws KycProviderException on unknown name, missing credentials, or a class
     *                              that does not implement the interface
     */
    public function provider(?string $name = null): KycProviderInterface
    {
        $name = $name === null || trim($name) === '' ? $this->configuredName() : trim($name);

        if ($name === '') {
            throw KycProviderException::notConfigured();
        }

        /** @var array<string, string> $map */
        $map = (array) $this->config->get('account.verification.providers', []);

        if (! isset($map[$name]) || ! is_string($map[$name]) || trim($map[$name]) === '') {
            throw KycProviderException::unknownProvider($name);
        }

        $class = trim($map[$name]);

        if (! class_exists($class)) {
            throw KycProviderException::unknownProvider($name, ['reason' => 'class_missing']);
        }

        $instance = app($class);

        if (! $instance instanceof KycProviderInterface) {
            throw KycProviderException::unknownProvider($name, ['reason' => 'does_not_implement_interface']);
        }

        if (! $instance->isConfigured()) {
            throw KycProviderException::notConfigured([
                'provider' => $name,
                'reason' => 'credentials_missing',
            ]);
        }

        return $instance;
    }

    /**
     * Resolve, or answer null when nothing is configured.
     *
     * Used by presentation surfaces that must render either a provider-shaped
     * sentence or a "manual review" sentence — never by the submission path,
     * which uses {@see provider()} so an unconfigured lane is an error.
     */
    public function providerOrNull(): ?KycProviderInterface
    {
        try {
            return $this->provider();
        } catch (KycProviderException) {
            return null;
        }
    }

    /**
     * Every driver name this application declares, in config order. The admin
     * review page and the tests both read this, so a driver cannot exist without
     * being listed.
     *
     * @return list<string>
     */
    public function declaredDrivers(): array
    {
        return array_values(array_map('strval', array_keys((array) $this->config->get('account.verification.providers', []))));
    }

    /**
     * Whether the resolved provider is a human queue. The public pages print the
     * "a person reviews this" notice only when this is true, so the sentence
     * cannot be shown for an automated provider.
     */
    public function isManualReview(): bool
    {
        $provider = $this->providerOrNull();

        return $provider !== null && $provider->name() === ManualReviewKycProvider::DRIVER;
    }
}
